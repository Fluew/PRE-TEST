<?php

namespace App\Http\Controllers;

use App\Models\CommercialInvoice;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Team;
use App\Services\Procurement\ProcurementWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HTTP side of the Inventory-to-Procurement workflow. Validation and team scoping only;
 * every business rule lives in ProcurementWorkflow.
 */
class ProcurementWorkflowController extends Controller
{
    public function __construct(private ProcurementWorkflow $workflow) {}

    /** Staff submit (or draft) Purchase Requests from the AHP reorder recommendations. */
    public function submitRequests(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.justification' => ['required', 'string', 'max:1000'],
            'lines.*.supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $team->id)],
            'draft' => ['nullable', 'boolean'],
        ]);

        return $this->runWorkflow(function () use ($team, $request, $data) {
            $prs = $this->workflow->submitRequests($team, $request->user(), $data['lines'], (bool) ($data['draft'] ?? false));

            return ($data['draft'] ?? false)
                ? 'Saved as draft: '.$prs->pluck('request_number')->implode(', ').'.'
                : 'Submitted for admin approval: '.$prs->pluck('request_number')->implode(', ').'.';
        });
    }

    public function submitDraft(Request $request): RedirectResponse
    {
        $pr = $this->find($request, PurchaseRequest::class, 'purchaseRequest');

        return $this->runWorkflow(function () use ($pr, $request) {
            $this->workflow->submitDraft($pr, $request->user());

            return "{$pr->request_number} submitted for approval.";
        });
    }

    public function review(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only an administrator can approve or reject purchase requests.');
        $pr = $this->find($request, PurchaseRequest::class, 'purchaseRequest');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(function () use ($pr, $request, $data) {
            $this->workflow->review($pr, $request->user(), $data['decision'] === 'approve', $data['remarks'] ?? null);

            return "{$pr->request_number} ".($data['decision'] === 'approve' ? 'approved.' : 'rejected.');
        });
    }

    /** Staff confirm the draft order created on approval: supplier, quantity, agreed price, terms. */
    public function updateDraftPurchaseOrder(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        $po = $this->find($request, PurchaseOrder::class, 'purchaseOrder');
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $team->id)],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'po_date' => ['nullable', 'date', 'before_or_equal:today'],
            'promised_delivery_date' => ['nullable', 'date', 'after_or_equal:today'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'quantity_adjustment_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(fn () => 'Draft purchase order '.$this->workflow->updateDraftPurchaseOrder($po, $request->user(), $data)->po_number.' saved. Send it when the supplier terms are final.');
    }

    /** The supplier's invoice document, from private storage. */
    public function invoiceAttachment(Request $request): StreamedResponse
    {
        $ci = $this->find($request, CommercialInvoice::class, 'commercialInvoice');
        abort_unless($ci->attachment_path && Storage::disk('local')->exists($ci->attachment_path), 404);

        return Storage::disk('local')->download($ci->attachment_path, $ci->attachment_name ?: 'invoice');
    }

    public function sendPurchaseOrder(Request $request): RedirectResponse
    {
        $po = $this->find($request, PurchaseOrder::class, 'purchaseOrder');

        return $this->runWorkflow(function () use ($po, $request) {
            $this->workflow->sendPurchaseOrder($po, $request->user());

            return "Purchase order {$po->po_number} marked as sent to the supplier.";
        });
    }

    public function cancelPurchaseOrder(Request $request): RedirectResponse
    {
        $po = $this->find($request, PurchaseOrder::class, 'purchaseOrder');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->runWorkflow(function () use ($po, $request, $data) {
            $this->workflow->cancelPurchaseOrder($po, $request->user(), $data['reason']);

            return "Purchase order {$po->po_number} cancelled.";
        });
    }

    public function closePurchaseOrder(Request $request): RedirectResponse
    {
        $po = $this->find($request, PurchaseOrder::class, 'purchaseOrder');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->runWorkflow(function () use ($po, $request, $data) {
            $this->workflow->closePurchaseOrder($po, $request->user(), $data['reason'] ?? null);

            return "Purchase order {$po->po_number} closed.";
        });
    }

    /** Everything about one order, for the Purchase Order workspace panel. */
    public function workspace(Request $request): JsonResponse
    {
        $po = $this->find($request, PurchaseOrder::class, 'purchaseOrder');
        $po->load(['supplier:id,name', 'product:id,name,sku,unit,stock', 'preparedBy:id,name',
            'purchaseRequest.requestedBy:id,name', 'purchaseRequest.reviewedBy:id,name']);

        $deliveries = Delivery::where('purchase_order_id', $po->id)->with(['inspectedBy:id,name', 'receivedBy:id,name', 'postedBy:id,name'])
            ->orderBy('delivery_date')->orderBy('id')->get()
            ->map(fn (Delivery $d) => $d->only(['id', 'delivery_number', 'supplier_document_number', 'delivery_date', 'quantity', 'accepted_quantity', 'rejected_quantity',
                'inspection_number', 'inspected_on', 'inspection_remarks', 'rejection_reason', 'rejection_category', 'damaged_quantity', 'defective_quantity', 'resolution_status', 'resolution_remarks', 'over_receipt_reason',
                'posted_quantity', 'posted_at', 'notes']) + [
                    'inspection_status' => $d->inspectionStatus(),
                    'inspected_by' => $d->inspectedBy?->name,
                    'received_by' => $d->receivedBy?->name,
                    'posted_by' => $d->postedBy?->name,
                ]);

        $invoices = CommercialInvoice::where('purchase_order_id', $po->id)->with(['recordedBy:id,name', 'verifiedBy:id,name', 'delivery:id,delivery_number'])
            ->orderBy('invoice_date')->get()
            ->map(fn (CommercialInvoice $ci) => $ci->only(['id', 'invoice_number', 'invoice_date', 'quantity', 'unit_cost', 'tax_amount', 'total_cost', 'payment_terms', 'status',
                'verified_at', 'verification_remarks', 'delivery_id', 'attachment_name']) + [
                    // Live check for open invoices; the stored result for verified ones.
                    'discrepancies' => in_array($ci->status, [CommercialInvoice::PENDING, CommercialInvoice::FLAGGED], true) ? $this->workflow->checkInvoice($ci) : ($ci->discrepancies ?? []),
                    'delivery_number' => $ci->delivery?->delivery_number,
                    'recorded_by' => $ci->recordedBy?->name,
                    'verified_by' => $ci->verifiedBy?->name,
                ]);

        return response()->json([
            'purchase_order' => $po,
            'summary' => $this->workflow->summary($po),
            'deliveries' => $deliveries,
            'invoices' => $invoices,
            'rejection_categories' => Delivery::REJECTION_CATEGORIES,
            'suppliers' => Supplier::where('team_id', $po->team_id)->where('status', 'Active')->orderBy('name')->get(['id', 'name']),
            'resolutions' => Delivery::RESOLUTIONS,
        ]);
    }

    public function recordDelivery(Request $request): RedirectResponse
    {
        $po = $this->find($request, PurchaseOrder::class, 'purchaseOrder');
        $data = $request->validate([
            'delivery_date' => ['required', 'date', 'before_or_equal:today'],
            'quantity' => ['required', 'integer', 'min:1'],
            'supplier_document_number' => ['nullable', 'string', 'max:100'],
            'delivery_number' => ['nullable', 'string', 'max:100'],
            'over_receipt_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(fn () => 'Delivery '.$this->workflow->recordDelivery($po, $request->user(), $data)->delivery_number.' recorded. Inspect it next.');
    }

    public function inspect(Request $request): RedirectResponse
    {
        $delivery = $this->find($request, Delivery::class, 'delivery');
        $data = $request->validate([
            'accepted_quantity' => ['required', 'integer', 'min:0'],
            'rejected_quantity' => ['required', 'integer', 'min:0'],
            'inspected_on' => ['required', 'date', 'after_or_equal:'.$delivery->delivery_date->toDateString(), 'before_or_equal:today'],
            'inspection_remarks' => ['nullable', 'string', 'max:1000'],
            'rejection_reason' => ['nullable', 'required_unless:rejected_quantity,0', 'string', 'max:1000'],
            'rejection_category' => ['nullable', Rule::in(Delivery::REJECTION_CATEGORIES)],
            'damaged_quantity' => ['nullable', 'integer', 'min:0'],
            'defective_quantity' => ['nullable', 'integer', 'min:0'],
        ], [
            'rejection_reason.required_unless' => 'Give the defect or rejection reason for the rejected units.',
        ]);

        return $this->runWorkflow(function () use ($delivery, $request, $data) {
            $this->workflow->inspect($delivery, $request->user(), $data);

            return "Inspection recorded for delivery {$delivery->delivery_number}.";
        });
    }

    public function resolveRejection(Request $request): RedirectResponse
    {
        $delivery = $this->find($request, Delivery::class, 'delivery');
        $data = $request->validate([
            'resolution_status' => ['required', Rule::in(Delivery::RESOLUTIONS)],
            'resolution_remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(function () use ($delivery, $request, $data) {
            $this->workflow->resolveRejection($delivery, $request->user(), $data['resolution_status'], $data['resolution_remarks'] ?? null);

            return "Resolution recorded for delivery {$delivery->delivery_number}.";
        });
    }

    public function postToInventory(Request $request): RedirectResponse
    {
        $delivery = $this->find($request, Delivery::class, 'delivery');

        return $this->runWorkflow(fn () => $this->workflow->postToInventory($delivery, $request->user())->quantity." accepted units of {$delivery->delivery_number} added to inventory.");
    }

    public function recordInvoice(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        $data = $request->validate([
            'purchase_order_id' => ['required', 'integer', Rule::exists('purchase_orders', 'id')->where('team_id', $team->id)],
            'delivery_id' => ['required', 'integer', Rule::exists('deliveries', 'id')->where('team_id', $team->id)],
            'invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'max:25600', 'mimes:pdf,png,jpg,jpeg'],
        ]);
        unset($data['attachment']);

        return $this->runWorkflow(function () use ($team, $request, $data) {
            $ci = $this->workflow->recordInvoice($team, $request->user(), $data, $request->file('attachment'));
            $n = count($ci->discrepancies ?? []);

            return "Invoice {$ci->invoice_number} recorded".($n ? " with {$n} discrepancy flag(s) to resolve." : '; it matches the order and inspection. Verify it next.');
        });
    }

    public function correctInvoice(Request $request): RedirectResponse
    {
        $ci = $this->find($request, CommercialInvoice::class, 'commercialInvoice');
        $data = $request->validate([
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'tax_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(function () use ($ci, $request, $data) {
            $ci = $this->workflow->correctInvoice($ci, $request->user(), $data);

            return "Invoice {$ci->invoice_number} corrected; ".(count($ci->discrepancies ?? []) ? 'some discrepancies remain.' : 'it now matches.');
        });
    }

    public function verifyInvoice(Request $request): RedirectResponse
    {
        $ci = $this->find($request, CommercialInvoice::class, 'commercialInvoice');
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']]);

        return $this->runWorkflow(function () use ($ci, $request, $data) {
            $this->workflow->verifyInvoice($ci, $request->user(), $data['remarks'] ?? null);

            return "Invoice {$ci->invoice_number} verified.";
        });
    }

    public function flagInvoice(Request $request): RedirectResponse
    {
        $ci = $this->find($request, CommercialInvoice::class, 'commercialInvoice');
        $data = $request->validate(['remarks' => ['required', 'string', 'max:1000']]);

        return $this->runWorkflow(function () use ($ci, $request, $data) {
            $this->workflow->flagInvoice($ci, $request->user(), $data['remarks']);

            return "Invoice {$ci->invoice_number} flagged.";
        });
    }

    private function team(Request $request): Team
    {
        $param = $request->route('current_team');
        $team = $param instanceof Team ? $param : (is_string($param) && $param !== '' ? Team::where('slug', $param)->first() : null);
        $team ??= $request->user()?->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');

        return $team;
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function find(Request $request, string $class, string $param): Model
    {
        $value = $request->route($param);
        $id = $value instanceof Model ? $value->getKey() : $value;
        abort_unless(is_numeric($id), 404);

        return $class::where('team_id', $this->team($request)->id)->findOrFail((int) $id);
    }
}
