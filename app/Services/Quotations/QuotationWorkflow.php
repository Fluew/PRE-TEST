<?php

namespace App\Services\Quotations;

use App\Enums\EmailStatus;
use App\Enums\QuotationStatus;
use App\Exceptions\WorkflowException;
use App\Models\NotificationLog;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentPdf;
use App\Services\Notifications\CustomerNotifier;
use App\Services\Sales\SalesOrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quotation lifecycle: Pending → Preparing → For Approval → Sent → Accepted → Converted.
 *
 * The decisions stay with people (Admin approval, the customer's answer recorded by staff);
 * what follows them is automatic: approving sends the quotation to the customer, and recording
 * the customer's acceptance creates the Sales Order. If an automatic step cannot run, the
 * decision is still saved and the manual button (Send / Generate Sales Order) stays available.
 */
class QuotationWorkflow
{
    public const DECISION_SOURCES = ['email' => 'Email reply', 'phone' => 'Phone call', 'in_person' => 'In person', 'purchase_order' => 'Purchase order', 'other' => 'Other'];

    public function __construct(private CustomerNotifier $notifier, private DocumentPdf $pdf) {}

    /** Move between the working statuses (Preparing, For Approval, Rejected by us). */
    public function changeStatus(Quotation $quotation, QuotationStatus $to, User $user, ?string $remarks = null): Quotation
    {
        if (! in_array($to, QuotationStatus::manual(), true)) {
            throw new WorkflowException("\"{$to->label()}\" is set with its own action, not by changing the status.");
        }

        return DB::transaction(function () use ($quotation, $to, $user, $remarks) {
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            $from = $quotation->status;
            if ($from === $to) {
                return $quotation; // nothing changes, nothing is recorded
            }
            $this->guard($quotation, $to);
            if ($to === QuotationStatus::Rejected && $from === QuotationStatus::Sent) {
                throw new WorkflowException('Record the customer\'s answer for a sent quotation with "Customer answer".');
            }
            if ($to === QuotationStatus::ForApproval) {
                $this->assertPriced($quotation);
            }

            $changes = ['status' => $to];
            if ($to === QuotationStatus::ForApproval) {
                $changes['submitted_for_approval_at'] = now();
            }
            // Back to Preparing: any earlier approval and customer answer no longer apply.
            if ($to === QuotationStatus::Preparing) {
                $changes += ['approved_by_id' => null, 'approved_at' => null, 'customer_decision_at' => null, 'customer_decision_by_id' => null, 'customer_decision_source' => null, 'customer_decision_remarks' => null];
            }
            $quotation->update($changes);

            AuditLogger::record('quotation.status_changed', "Quotation {$quotation->quotation_number}: {$from->label()} → {$to->label()}.".($remarks ? " Remarks: {$remarks}" : ''), $quotation, ['from' => $from->value, 'to' => $to->value, 'remarks' => $remarks], $user);

            return $quotation;
        });
    }

    /**
     * Admin approval, then the quotation is sent to the customer automatically. The approval is
     * saved even when sending cannot happen (e.g. no email address); the reason is returned.
     *
     * @return array{0: Quotation, 1: ?string} the quotation and why it was not sent (null if sent)
     */
    public function approve(Quotation $quotation, User $user, ?string $remarks = null): array
    {
        if (! $user->isAdmin()) {
            throw new WorkflowException('Only an administrator can approve quotations.');
        }

        $quotation = DB::transaction(function () use ($quotation, $user, $remarks) {
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($quotation->status !== QuotationStatus::ForApproval || $quotation->approved_at) {
                throw new WorkflowException($quotation->approved_at
                    ? 'This quotation is already approved.'
                    : "Only quotations that are For Approval can be approved. This one is {$quotation->status->label()}.");
            }
            $this->assertPriced($quotation);

            $quotation->update(['approved_by_id' => $user->id, 'approved_at' => now()]);
            AuditLogger::record('quotation.approved', "Quotation {$quotation->quotation_number} approved by {$user->name}.".($remarks ? " Remarks: {$remarks}" : ''), $quotation, ['grand_total' => (float) $quotation->grand_total, 'remarks' => $remarks], $user);

            return $quotation;
        });

        try {
            return [$this->send($quotation, $user, automatic: true), null];
        } catch (WorkflowException $e) {
            AuditLogger::record('quotation.send_skipped', "Quotation {$quotation->quotation_number} was approved but not sent automatically: {$e->getMessage()}", $quotation, [], $user, 'failure', 'warning');

            return [$quotation->fresh(), $e->getMessage()];
        }
    }

    /** Email the approved quotation (as a PDF) to the customer. Can be sent again later. */
    public function send(Quotation $quotation, User $user, bool $automatic = false): Quotation
    {
        return DB::transaction(function () use ($quotation, $user, $automatic) {
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();

            $approved = $quotation->status === QuotationStatus::ForApproval && $quotation->approved_at;
            if (! $approved && $quotation->status !== QuotationStatus::Sent) {
                throw new WorkflowException('Only approved quotations can be sent to the customer.');
            }
            if ($quotation->valid_until && $quotation->valid_until->toDateString() < now()->toDateString()) {
                throw new WorkflowException('This quotation has passed its validity date. Revise it before sending.');
            }
            $email = trim((string) ($quotation->email ?: $quotation->client?->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new WorkflowException('The quotation has no valid customer email address. Add one, then use Send.', 'email');
            }
            if ($quotation->email_status === EmailStatus::Pending) {
                throw new WorkflowException('This quotation is already being sent. Wait for the result before sending it again.');
            }

            // The PDF that was sent is kept, so later changes never alter what the customer received.
            $path = $this->pdf->store($this->pdf->quotation($quotation), 'quotations', $quotation->quotation_number);
            $sends = NotificationLog::where('notifiable_type', 'Quotation')->where('notifiable_id', $quotation->id)->where('type', 'quotation_sent')->count();

            $from = $quotation->status;
            $quotation->update([
                'status' => QuotationStatus::Sent,
                'sent_at' => now(),
                'sent_by_id' => $user->id,
                'sent_pdf_path' => $path,
                'email_status' => EmailStatus::Pending,
            ]);

            $this->notifier->notify('quotation_sent', $quotation, "quotation_sent:{$quotation->id}:".($sends + 1));
            AuditLogger::record('quotation.sent', "Quotation {$quotation->quotation_number} ".($automatic ? 'sent automatically after approval' : ($from === QuotationStatus::Sent ? 'sent again' : 'sent'))." to {$email}.", $quotation, ['to' => $email, 'send_number' => $sends + 1], $user);

            return $quotation;
        });
    }

    /**
     * Record the customer's answer, as received by staff (customers have no accounts). An
     * acceptance is confirmed to the customer by email and creates the Sales Order.
     *
     * @param  array{fulfillment_type?: string, address?: ?string, delivery_instructions?: ?string}  $orderOptions
     * @return array{0: Quotation, 1: ?Sale, 2: list<string>, 3: ?string} quotation, order, stock warnings, why no order was created
     */
    public function recordDecision(Quotation $quotation, bool $accepted, string $source, ?string $remarks, User $user, ?string $decidedOn = null, array $orderOptions = []): array
    {
        if (! array_key_exists($source, self::DECISION_SOURCES)) {
            throw new WorkflowException('Choose how the customer gave their answer.', 'source');
        }

        $quotation = DB::transaction(function () use ($quotation, $accepted, $source, $remarks, $user, $decidedOn) {
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($quotation->status !== QuotationStatus::Sent) {
                throw new WorkflowException('The customer\'s answer can only be recorded for a quotation that was sent to them.');
            }
            if ($accepted && $quotation->valid_until && $quotation->valid_until->toDateString() < now()->toDateString()) {
                throw new WorkflowException('This quotation expired on '.$quotation->valid_until->format('M d, Y').'. Revise and send it again before accepting.');
            }

            $to = $accepted ? QuotationStatus::Accepted : QuotationStatus::Rejected;
            $quotation->update([
                'status' => $to,
                'customer_decision_at' => $decidedOn ? Carbon::parse($decidedOn) : now(),
                'customer_decision_by_id' => $user->id,
                'customer_decision_source' => $source,
                'customer_decision_remarks' => $remarks,
            ]);
            AuditLogger::record($accepted ? 'quotation.accepted' : 'quotation.rejected', "Customer {$to->label()} quotation {$quotation->quotation_number} (".self::DECISION_SOURCES[$source].'), recorded by '.$user->name.'.', $quotation, ['source' => $source, 'remarks' => $remarks], $user);
            if ($accepted) {
                $this->notifier->notify('quotation_accepted', $quotation, "quotation_accepted:{$quotation->id}");
            }

            return $quotation;
        });

        if (! $accepted) {
            return [$quotation, null, [], null];
        }

        // Next stage: the Sales Order, from the accepted quotation's details.
        try {
            [$sale, $warnings] = app(SalesOrderService::class)->createFromQuotation($quotation, $user, $orderOptions);

            return [$quotation->fresh(), $sale, $warnings, null];
        } catch (WorkflowException $e) {
            AuditLogger::record('quotation.conversion_failed', "Sales Order not created automatically for {$quotation->quotation_number}: {$e->getMessage()}", $quotation, [], $user, 'failure', 'warning');

            return [$quotation->fresh(), null, [], $e->getMessage()];
        }
    }

    /** Approved (not yet sent) or sent quotations past their validity date become Expired. */
    public function expireDue(int $teamId): int
    {
        $due = Quotation::where('team_id', $teamId)
            ->where(fn ($q) => $q->where('status', QuotationStatus::Sent->value)
                ->orWhere(fn ($qq) => $qq->where('status', QuotationStatus::ForApproval->value)->whereNotNull('approved_at')))
            ->whereDate('valid_until', '<', now()->toDateString())
            ->get();

        foreach ($due as $quotation) {
            $quotation->update(['status' => QuotationStatus::Expired]);
            AuditLogger::record('quotation.expired', "Quotation {$quotation->quotation_number} expired (valid until {$quotation->valid_until?->format('M d, Y')}).", $quotation);
        }

        return $due->count();
    }

    private function guard(Quotation $quotation, QuotationStatus $to): void
    {
        if (! $quotation->status->canMoveTo($to)) {
            throw new WorkflowException("A quotation that is {$quotation->status->label()} cannot be changed to {$to->label()}.");
        }
    }

    private function assertPriced(Quotation $quotation): void
    {
        $items = $quotation->items()->get();
        if ($items->isEmpty()) {
            throw new WorkflowException('Add at least one product before sending the quotation for approval.');
        }
        $unpriced = $items->filter(fn ($i) => (float) $i->unit_price <= 0)->pluck('product_name');
        if ($unpriced->isNotEmpty()) {
            throw new WorkflowException('Set a selling price for every line first. Missing: '.$unpriced->take(5)->implode(', ').'.');
        }
    }
}
