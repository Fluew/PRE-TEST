<?php

namespace App\Services\Sales;

use App\Exceptions\WorkflowException;
use App\Models\Sale;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumbers;
use App\Services\Documents\DocumentPdf;
use App\Services\Notifications\CustomerNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sales invoices are issued when the order is confirmed (the company's process). Each invoicing
 * event issues one invoice (issue_key is unique). Issued invoices are never edited: a correction
 * cancels the invoice with a reason and issues a replacement that points to it.
 */
class InvoiceService
{
    public function __construct(private DocumentPdf $pdf, private CustomerNotifier $notifier) {}

    public function issue(Sale $sale, string $event, ?User $user = null, ?SalesInvoice $replaces = null): SalesInvoice
    {
        $key = "{$event}:{$sale->id}";
        if ($existing = SalesInvoice::where('team_id', $sale->team_id)->where('issue_key', $key)->first()) {
            return $existing;
        }

        $sale->loadMissing(['items', 'quotation']);
        $invoice = SalesInvoice::create([
            'team_id' => $sale->team_id,
            'sale_id' => $sale->id,
            'invoice_number' => DocumentNumbers::next($sale->team_id, DocumentNumbers::SALES_INVOICE),
            'status' => SalesInvoice::ISSUED,
            'issue_key' => $key,
            'customer' => [
                'name' => $sale->client_name,
                'contact_person' => $sale->contact_person,
                'email' => $sale->email,
                'phone' => $sale->phone,
                'address' => $sale->address,
            ],
            'items' => $sale->items->map(fn ($i) => [
                'sku' => $i->sku,
                'product_name' => $i->product_name,
                'quantity' => $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'discount' => (float) $i->discount,
                'subtotal' => (float) $i->subtotal,
            ])->values()->all(),
            'subtotal' => $sale->subtotal,
            'discount' => $sale->discount,
            'tax_rate' => $sale->quotation?->tax_rate ?? 0,
            'tax_amount' => $sale->tax_amount,
            'grand_total' => $sale->grand_total,
            'issued_at' => now(),
            'issued_by_id' => $user?->id,
            'replaces_invoice_id' => $replaces?->id,
        ]);

        $sale->forceFill(['invoice_number' => $invoice->invoice_number])->save();
        AuditLogger::record('invoice.issued', "Sales invoice {$invoice->invoice_number} issued for {$sale->order_number}".($replaces ? " (replaces {$replaces->invoice_number})" : '').'.', $sale, ['invoice_id' => $invoice->id, 'event' => $event], $user);

        DB::afterCommit(fn () => $this->storePdf($invoice));

        return $invoice;
    }

    /** Cancel an issued invoice, optionally issuing a corrected replacement (emailed to the customer). */
    public function cancel(SalesInvoice $invoice, string $reason, User $user, bool $reissue): ?SalesInvoice
    {
        if (! $user->isAdmin()) {
            throw new WorkflowException('Only an administrator can cancel an invoice.');
        }

        return DB::transaction(function () use ($invoice, $reason, $user, $reissue) {
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== SalesInvoice::ISSUED) {
                throw new WorkflowException("Invoice {$invoice->invoice_number} is already cancelled.");
            }
            $sale = Sale::whereKey($invoice->sale_id)->lockForUpdate()->firstOrFail();

            $invoice->update(['status' => SalesInvoice::CANCELLED, 'cancelled_at' => now(), 'cancelled_by_id' => $user->id, 'cancellation_reason' => $reason]);
            AuditLogger::record('invoice.cancelled', "Sales invoice {$invoice->invoice_number} cancelled: {$reason}", $sale, ['invoice_id' => $invoice->id], $user);

            if (! $reissue) {
                $sale->forceFill(['invoice_number' => null])->save();

                return null;
            }

            $replacement = $this->issue($sale, "reissue-{$invoice->id}", $user, $invoice);
            $this->notifier->notify('invoice_issued', $replacement, "invoice_issued:{$replacement->id}");

            return $replacement;
        });
    }

    /** Store the issued PDF once; cancelled invoices are rendered fresh, with the cancellation shown. */
    public function storePdf(SalesInvoice $invoice): void
    {
        $invoice->refresh();
        if ($invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)) {
            return;
        }
        $invoice->forceFill(['pdf_path' => $this->pdf->store($this->pdf->invoice($invoice), 'invoices', $invoice->invoice_number)])->save();
    }
}
