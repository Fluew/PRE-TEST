<?php

namespace App\Services\Procurement;

use App\Events\Procurement\CommercialInvoiceVerified;
use App\Events\Procurement\InboundDeliveryRecorded;
use App\Events\Procurement\InventoryUpdated;
use App\Events\Procurement\PurchaseOrderCreated;
use App\Events\Procurement\PurchaseOrderSent;
use App\Events\Procurement\PurchaseRequestApproved;
use App\Events\Procurement\PurchaseRequestRejected;
use App\Events\Procurement\PurchaseRequestSubmitted;
use App\Events\Procurement\QualityInspectionCompleted;
use App\Exceptions\WorkflowException;
use App\Models\CommercialInvoice;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Inventory\ProductPrioritizer;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The Inventory-to-Procurement workflow. Every state change goes through here, inside a
 * transaction with the affected rows locked, so a step can never happen twice or out of order:
 *
 *   AHP reorder recommendation → Purchase Request (staff) → approval (admin)
 *   → Purchase Order (from the approved request only) → sent to supplier
 *   → Inbound Delivery (actual quantities) → Inspection (accepted / rejected)
 *   → Commercial Invoice (checked against PO, delivery and inspection) → verification
 *   → inventory posting of the accepted quantity, exactly once.
 *
 * An AHP recommendation never creates an order or changes stock by itself.
 */
class ProcurementWorkflow
{
    public function __construct(private ProductPrioritizer $prioritizer, private WorkflowNotifier $notifier) {}

    // ─────────────────────────── Purchase Requests ───────────────────────────

    /**
     * Create one Purchase Request per selected product. The AHP score, forecast, stock and
     * recommended quantity are taken from the server-side calculation at submission time,
     * never from the browser.
     *
     * @param  list<array{product_id: int, quantity: int, justification: string, supplier_id?: int|null}>  $lines
     * @return Collection<int, PurchaseRequest>
     */
    public function submitRequests(Team $team, User $user, array $lines, bool $asDraft = false): Collection
    {
        if ($lines === []) {
            throw new WorkflowException('Select at least one product.', 'lines');
        }
        $ids = array_map(fn ($l) => (int) $l['product_id'], $lines);
        if (count($ids) !== count(array_unique($ids))) {
            throw new WorkflowException('Each product can appear only once in a submission.', 'lines');
        }

        $priorities = $this->prioritizer->forTeam($team->id);

        $created = DB::transaction(function () use ($team, $user, $lines, $asDraft, $priorities) {
            $out = collect();
            foreach ($lines as $i => $line) {
                $product = Product::where('team_id', $team->id)->where('is_active', true)->find($line['product_id'])
                    ?? throw new WorkflowException('A selected product is not an active product of this team.', "lines.{$i}.product_id");
                $qty = (int) $line['quantity'];
                if ($qty < 1) {
                    throw new WorkflowException("Requested quantity for {$product->name} must be at least 1.", "lines.{$i}.quantity");
                }
                $justification = trim((string) ($line['justification'] ?? ''));
                if ($justification === '') {
                    throw new WorkflowException("Give a purchase justification for {$product->name}.", "lines.{$i}.justification");
                }
                $open = PurchaseRequest::where('team_id', $team->id)->where('product_id', $product->id)
                    ->whereIn('status', [PurchaseRequest::DRAFT, PurchaseRequest::PENDING, PurchaseRequest::APPROVED])->value('request_number');
                if ($open) {
                    throw new WorkflowException("{$product->name} already has an open purchase request ({$open}).", "lines.{$i}.product_id");
                }

                $p = $priorities[$product->id] ?? null;
                $stock = $p ? collect($p['criteria'])->firstWhere('key', 'stock')['inputs']['available'] : (int) $product->stock;

                $pr = PurchaseRequest::create([
                    'team_id' => $team->id,
                    'request_number' => PurchaseRequest::generateRequestNumber($team->id),
                    'product_id' => $product->id,
                    'supplier_id' => $line['supplier_id'] ?? null,
                    'requested_by_id' => $user->id,
                    'quantity' => $qty,
                    'current_stock' => $stock,
                    'forecast_quantity' => $p['reorder']['forecast'] ?? null,
                    'ahp_score' => $p['score'] ?? null,
                    'ahp_classification' => $p['classification'] ?? null,
                    'recommended_quantity' => $p && $p['reorder']['needed'] ? $p['reorder']['suggested_quantity'] : null,
                    'estimated_cost' => round(((float) ($product->cost_price ?: $product->price)) * $qty, 2),
                    'status' => $asDraft ? PurchaseRequest::DRAFT : PurchaseRequest::PENDING,
                    'justification' => $justification,
                    'requested_at' => now(),
                    'submitted_at' => $asDraft ? null : now(),
                ]);
                AuditLogger::record('purchase_request.'.($asDraft ? 'drafted' : 'submitted'), "{$pr->request_number}: {$qty} × {$product->name} requested.", $pr, [
                    'recommended_quantity' => $pr->recommended_quantity, 'ahp_score' => $pr->ahp_score, 'forecast' => $pr->forecast_quantity, 'current_stock' => $stock,
                ], $user);
                $out->push($pr);
            }

            return $out;
        });

        if (! $asDraft) {
            $created->each(fn (PurchaseRequest $pr) => PurchaseRequestSubmitted::dispatch($pr, $user));
        }

        return $created;
    }

    public function submitDraft(PurchaseRequest $pr, User $user): void
    {
        DB::transaction(function () use ($pr, $user) {
            $pr = PurchaseRequest::lockForUpdate()->findOrFail($pr->id);
            if ($pr->status !== PurchaseRequest::DRAFT) {
                throw new WorkflowException("{$pr->request_number} is {$pr->status}; only a draft can be submitted.");
            }
            if ($pr->requested_by_id !== $user->id && ! $user->isAdmin()) {
                throw new WorkflowException('Only the staff member who drafted this request can submit it.');
            }
            $pr->update(['status' => PurchaseRequest::PENDING, 'submitted_at' => now()]);
            AuditLogger::record('purchase_request.submitted', "{$pr->request_number} submitted for approval.", $pr, [], $user);
            PurchaseRequestSubmitted::dispatch($pr, $user);
        });
    }

    public function review(PurchaseRequest $pr, User $admin, bool $approve, ?string $remarks): PurchaseRequest
    {
        if (! $admin->isAdmin()) {
            throw new WorkflowException('Only an administrator can approve or reject purchase requests.');
        }
        $remarks = trim((string) $remarks) ?: null;
        if (! $approve && ! $remarks) {
            throw new WorkflowException('Give the reason for rejecting this request.', 'remarks');
        }

        $pr = DB::transaction(function () use ($pr, $admin, $approve, $remarks) {
            $pr = PurchaseRequest::lockForUpdate()->findOrFail($pr->id);
            if ($pr->status !== PurchaseRequest::PENDING) {
                throw new WorkflowException("{$pr->request_number} is {$pr->status}; only a request pending approval can be reviewed.");
            }
            $pr->update([
                'status' => $approve ? PurchaseRequest::APPROVED : PurchaseRequest::REJECTED,
                'reviewed_by_id' => $admin->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ]);
            AuditLogger::record($approve ? 'purchase_request.approved' : 'purchase_request.rejected',
                "{$pr->request_number} ".($approve ? 'approved' : 'rejected').' by '.$admin->name.($remarks ? ": {$remarks}" : '.'), $pr, [], $admin);

            if ($approve) {
                PurchaseRequestApproved::dispatch($pr, $admin);
                // Approval moves straight on: the draft order is created in the same transaction,
                // so an approved request can never be left without its purchase order.
                $po = $this->draftPurchaseOrder($pr, $admin);
                PurchaseOrderCreated::dispatch($po, $admin, true);
            } else {
                PurchaseRequestRejected::dispatch($pr, $admin);
            }

            return $pr;
        });

        return $pr->fresh();
    }

    // ─────────────────────────── Purchase Orders ───────────────────────────

    /**
     * The draft Purchase Order for a just-approved request (called inside the approval
     * transaction). One order per request: the PR row is locked and purchase_request_id is unique.
     *
     * Pre-filled from records only, for staff to confirm before sending:
     *  - supplier: the one named on the request, else the product's usual supplier, else the
     *    supplier with the lowest verified, unexpired quote for the product;
     *  - price: that supplier's latest verified, unexpired quote in the product's unit;
     *    otherwise left at 0 and the order cannot be sent until staff enter the agreed price.
     */
    private function draftPurchaseOrder(PurchaseRequest $pr, User $by): PurchaseOrder
    {
        if (PurchaseOrder::withTrashed()->where('purchase_request_id', $pr->id)->exists()) {
            throw new WorkflowException("{$pr->request_number} already has a purchase order.");
        }
        $product = $pr->product ?? throw new WorkflowException('The product of this request no longer exists.');
        $quotes = SupplierQuote::where('team_id', $pr->team_id)->where('product_id', $product->id)->whereNotNull('verified_at')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->whereRaw('LOWER(unit) = ?', [mb_strtolower((string) ($product->unit ?: 'pcs'))])
            ->whereHas('supplier', fn ($s) => $s->where('status', 'Active'))
            ->orderByDesc('quoted_on')->get();

        $supplierId = $pr->supplier_id ?: $product->supplier_id;
        $supplierSource = $pr->supplier_id ? 'named on the purchase request' : ($product->supplier_id ? "the product's usual supplier" : null);
        if (! $supplierId && $quotes->isNotEmpty()) {
            $supplierId = $quotes->sortBy('unit_price')->first()->supplier_id;
            $supplierSource = 'lowest verified quote';
        }
        if ($supplierId && ! Supplier::where('team_id', $pr->team_id)->where('status', 'Active')->whereKey($supplierId)->exists()) {
            [$supplierId, $supplierSource] = [null, null];
        }
        $quote = $supplierId ? $quotes->firstWhere('supplier_id', $supplierId) : null;
        $unitCost = $quote ? round((float) $quote->unit_price, 2) : 0.0;

        $prefill = array_filter([
            $supplierSource ? "Supplier pre-filled ({$supplierSource})." : 'Supplier to be chosen.',
            $quote ? "Price pre-filled from verified quote {$quote->reference} of {$quote->quoted_on->format('Y-m-d')}." : 'Agreed price to be entered.',
        ]);

        $po = PurchaseOrder::create([
            'team_id' => $pr->team_id,
            'purchase_request_id' => $pr->id,
            'po_date' => today(),
            'po_number' => PurchaseOrder::generatePoNumber($pr->team_id),
            'quantity' => $pr->quantity,
            'product_id' => $product->id,
            'item_sku' => $product->sku,
            'description' => $product->name,
            'unit_of_measure' => strtoupper($product->unit ?: 'PCS'),
            'unit_cost' => $unitCost,
            'total_cost' => round($pr->quantity * $unitCost, 2),
            'supplier_id' => $supplierId,
            'prepared_by_id' => $by->id,
            'status' => PurchaseOrder::DRAFT,
            'notes' => implode(' ', $prefill),
        ]);
        $pr->update(['status' => PurchaseRequest::CONVERTED, 'ordered_at' => now()]);
        AuditLogger::record('purchase_order.created', "Draft purchase order {$po->po_number} created automatically from approved {$pr->request_number}. ".implode(' ', $prefill), $po,
            ['purchase_request' => $pr->request_number, 'automatic' => true, 'quote_id' => $quote?->id], $by);

        return $po;
    }

    /**
     * Staff review of a draft order: confirm or change the supplier, quantity, agreed price and
     * terms. A quantity different from the approved one needs a reason; more needs an admin.
     *
     * @param  array{supplier_id: int, quantity: int, unit_cost: float|string, po_date?: ?string, promised_delivery_date?: ?string, payment_terms?: ?string, quantity_adjustment_reason?: ?string, notes?: ?string}  $data
     */
    public function updateDraftPurchaseOrder(PurchaseOrder $po, User $user, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $user, $data) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            if ($po->status !== PurchaseOrder::DRAFT) {
                throw new WorkflowException("Purchase order {$po->po_number} is {$po->status}; only a draft can be changed.");
            }
            $supplier = Supplier::where('team_id', $po->team_id)->where('status', 'Active')->find($data['supplier_id'])
                ?? throw new WorkflowException('Select an active registered supplier.', 'supplier_id');
            $approved = (int) ($po->purchaseRequest?->quantity ?? $po->quantity);
            $qty = (int) $data['quantity'];
            $reason = trim((string) ($data['quantity_adjustment_reason'] ?? '')) ?: null;
            if ($qty < 1) {
                throw new WorkflowException('Ordered quantity must be at least 1.', 'quantity');
            }
            if ($qty !== $approved) {
                if (! $reason) {
                    throw new WorkflowException("The ordered quantity differs from the approved {$approved}; give the reason for the adjustment.", 'quantity_adjustment_reason');
                }
                if ($qty > $approved && ! $user->isAdmin()) {
                    throw new WorkflowException("Ordering more than the approved {$approved} units needs an administrator.", 'quantity');
                }
            }
            $unitCost = round((float) $data['unit_cost'], 2);
            if ($unitCost <= 0) {
                throw new WorkflowException('Enter the agreed unit price.', 'unit_cost');
            }

            $before = $po->only(['supplier_id', 'quantity', 'unit_cost', 'payment_terms', 'promised_delivery_date']);
            $po->update([
                'supplier_id' => $supplier->id,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => round($qty * $unitCost, 2),
                'po_date' => $data['po_date'] ?? $po->po_date,
                'promised_delivery_date' => $data['promised_delivery_date'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'quantity_adjustment_reason' => $qty !== $approved ? $reason : null,
                'notes' => $data['notes'] ?? $po->notes,
            ]);
            $po->purchaseRequest?->update(['supplier_id' => $supplier->id]);
            AuditLogger::record('purchase_order.draft_updated', "Draft purchase order {$po->po_number} confirmed: {$qty} × {$unitCost} from {$supplier->name}.", $po, ['before' => $before], $user);

            return $po;
        });
    }

    public function sendPurchaseOrder(PurchaseOrder $po, User $user): void
    {
        DB::transaction(function () use ($po, $user) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            if ($po->status !== PurchaseOrder::DRAFT) {
                throw new WorkflowException("Purchase order {$po->po_number} is {$po->status}; only a draft can be sent.");
            }
            if (! $po->supplier_id || ! $po->product_id) {
                throw new WorkflowException('Confirm the supplier of the purchase order before sending it.', 'supplier_id');
            }
            if ((float) $po->unit_cost <= 0) {
                throw new WorkflowException('Enter the agreed unit price before sending the purchase order.', 'unit_cost');
            }
            $po->update(['status' => PurchaseOrder::SENT, 'sent_at' => now()]);
            AuditLogger::record('purchase_order.sent', "Purchase order {$po->po_number} sent to the supplier.", $po, [], $user);
            PurchaseOrderSent::dispatch($po, $user);
        });
    }

    public function cancelPurchaseOrder(PurchaseOrder $po, User $user, ?string $reason): void
    {
        $reason = trim((string) $reason) ?: throw new WorkflowException('Give the reason for cancelling.', 'reason');
        DB::transaction(function () use ($po, $user, $reason) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            if (! in_array($po->status, [PurchaseOrder::DRAFT, PurchaseOrder::SENT], true)) {
                throw new WorkflowException("Purchase order {$po->po_number} is {$po->status} and cannot be cancelled.");
            }
            if ($po->deliveries()->exists()) {
                throw new WorkflowException('Goods were already received against this order; close it instead of cancelling.');
            }
            $po->update(['status' => PurchaseOrder::CANCELLED, 'cancellation_reason' => $reason]);
            AuditLogger::record('purchase_order.cancelled', "Purchase order {$po->po_number} cancelled: {$reason}", $po, [], $user);
        });
    }

    /**
     * Close a fully delivered order once everything accepted is posted and invoiced. An admin may
     * also close a partially delivered order (the supplier will not deliver the rest), with a reason.
     */
    public function closePurchaseOrder(PurchaseOrder $po, User $user, ?string $reason = null): void
    {
        DB::transaction(function () use ($po, $user, $reason) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            $reason = trim((string) $reason) ?: null;
            if ($po->status === PurchaseOrder::PARTIAL) {
                if (! $user->isAdmin() || ! $reason) {
                    throw new WorkflowException('Only an administrator can close a partially delivered order, with a reason.', 'reason');
                }
            } elseif ($po->status !== PurchaseOrder::FULL) {
                throw new WorkflowException("Purchase order {$po->po_number} is {$po->status} and cannot be closed.");
            }
            $s = $this->summary($po);
            if ($s['uninspected'] > 0 || $s['awaiting_posting'] > 0) {
                throw new WorkflowException('Inspect every delivery and post all accepted units to inventory before closing.');
            }
            $po->update(['status' => PurchaseOrder::CLOSED, 'closed_at' => now(), 'notes' => $reason ? trim(($po->notes ? $po->notes."\n" : '')."Closed short: {$reason}") : $po->notes]);
            AuditLogger::record('purchase_order.closed', "Purchase order {$po->po_number} closed".($reason ? ": {$reason}" : '.'), $po, $s, $user);
        });
    }

    /**
     * Quantities of an order: delivered, inspected, accepted, rejected, posted to stock, still open.
     *
     * @return array{ordered: int, delivered: int, uninspected: int, accepted: int, rejected: int, posted: int, awaiting_posting: int, remaining: int, verified_invoice_quantity: int}
     */
    public function summary(PurchaseOrder $po): array
    {
        $deliveries = Delivery::where('purchase_order_id', $po->id)->get();
        $accepted = (int) $deliveries->sum(fn ($d) => (int) $d->accepted_quantity);
        $uninspected = (int) $deliveries->whereNull('inspection_result')->sum('quantity');
        $posted = (int) $deliveries->sum(fn ($d) => (int) $d->posted_quantity);

        return [
            'ordered' => (int) $po->quantity,
            'delivered' => (int) $deliveries->sum('quantity'),
            'uninspected' => $uninspected,
            'accepted' => $accepted,
            'rejected' => (int) $deliveries->sum(fn ($d) => (int) $d->rejected_quantity),
            'posted' => $posted,
            'awaiting_posting' => (int) $deliveries->whereNull('posted_at')->sum(fn ($d) => (int) $d->accepted_quantity),
            // Rejected units do not count as delivered: the supplier still owes them.
            'remaining' => max(0, (int) $po->quantity - $accepted - $uninspected),
            'verified_invoice_quantity' => (int) CommercialInvoice::where('purchase_order_id', $po->id)->where('status', CommercialInvoice::VERIFIED)->sum('quantity'),
        ];
    }

    /** Delivery status from actual cumulative records: accepted units, not the fact that a delivery exists. */
    public function refreshPurchaseOrderStatus(PurchaseOrder $po, ?User $user = null): void
    {
        $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
        if (! in_array($po->status, [PurchaseOrder::SENT, PurchaseOrder::PARTIAL, PurchaseOrder::FULL], true)) {
            return;
        }
        $s = $this->summary($po);
        $status = match (true) {
            $s['accepted'] >= $s['ordered'] => PurchaseOrder::FULL,
            $s['delivered'] > 0 => PurchaseOrder::PARTIAL,
            default => PurchaseOrder::SENT,
        };
        if ($status !== $po->status) {
            $old = $po->status;
            $po->update(['status' => $status]);
            AuditLogger::record('purchase_order.status_changed', "Purchase order {$po->po_number}: {$old} → {$status} ({$s['accepted']} of {$s['ordered']} accepted).", $po, $s, $user);
        }
    }

    // ─────────────────────────── Inbound deliveries & inspection ───────────────────────────

    /**
     * Record goods actually received against a sent order. Quantities above what is still open
     * need an administrator and a recorded reason.
     *
     * @param  array{delivery_date: string, quantity: int, supplier_document_number?: ?string, notes?: ?string, over_receipt_reason?: ?string, delivery_number?: ?string}  $data
     */
    public function recordDelivery(PurchaseOrder $po, User $user, array $data): Delivery
    {
        [$delivery, $remaining] = DB::transaction(function () use ($po, $user, $data) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            if (! in_array($po->status, PurchaseOrder::RECEIVABLE, true)) {
                throw new WorkflowException("Purchase order {$po->po_number} is {$po->status}. Goods can be received only on an order sent to the supplier.", 'purchase_order_id');
            }
            if (Carbon::parse($data['delivery_date'])->lt($po->po_date)) {
                throw new WorkflowException('The delivery date cannot be before the purchase order date.', 'delivery_date');
            }
            $qty = (int) $data['quantity'];
            if ($qty < 1) {
                throw new WorkflowException('Delivered quantity must be at least 1.', 'quantity');
            }
            $remaining = $this->summary($po)['remaining'];
            $reason = trim((string) ($data['over_receipt_reason'] ?? '')) ?: null;
            if ($qty > $remaining) {
                if (! $user->isAdmin() || ! $reason) {
                    throw new WorkflowException("Only {$remaining} units are still open on {$po->po_number}. Receiving more needs an administrator and a reason.", 'quantity');
                }
            }

            $delivery = Delivery::create([
                'team_id' => $po->team_id,
                'delivery_date' => $data['delivery_date'],
                'delivery_number' => trim((string) ($data['delivery_number'] ?? '')) ?: Delivery::generateDeliveryNumber($po->team_id, $data['delivery_date']),
                'supplier_document_number' => $data['supplier_document_number'] ?? null,
                'purchase_order_id' => $po->id,
                'purchase_order_number' => $po->po_number,
                'quantity' => $qty,
                'over_receipt_reason' => $qty > $remaining ? $reason : null,
                'over_receipt_approved_by_id' => $qty > $remaining ? $user->id : null,
                'unit' => $po->unit_of_measure ?: 'PCS',
                'description' => $po->description,
                'supplier_id' => $po->supplier_id,
                'received_by_id' => $user->id,
                'status' => 'Delivered',
                'notes' => $data['notes'] ?? null,
            ]);
            AuditLogger::record('inbound_delivery.recorded', "Delivery {$delivery->delivery_number}: {$qty} units received against {$po->po_number} ({$remaining} were open).", $delivery,
                ['ordered' => $po->quantity, 'open_before' => $remaining, 'over_receipt_reason' => $delivery->over_receipt_reason], $user);
            $this->refreshPurchaseOrderStatus($po, $user);
            // The delivery itself is the quality-inspection task: "Pending Inspection" until inspected.
            InboundDeliveryRecorded::dispatch($delivery, $user, $remaining);

            return [$delivery, $remaining];
        });

        return $delivery;
    }

    /**
     * Goods-receipt inspection. The result is derived from the quantities, never entered as a rating.
     * Once recorded, only an administrator may correct it, and never after stock was posted.
     *
     * Rejected units may be broken down into damaged and defective; the rest are rejected for
     * another reason (wrong item, nonconforming…), given by the category. Rejected units are held:
     * they never reach stock.
     *
     * @param  array{accepted_quantity: int, rejected_quantity: int, damaged_quantity?: ?int, defective_quantity?: ?int, inspected_on: string, inspection_remarks?: ?string, rejection_reason?: ?string, rejection_category?: ?string}  $data
     */
    public function inspect(Delivery $delivery, User $user, array $data): Delivery
    {
        $delivery = DB::transaction(function () use ($delivery, $user, $data) {
            $delivery = Delivery::lockForUpdate()->findOrFail($delivery->id);
            if ($delivery->posted_at) {
                throw new WorkflowException('Accepted units of this delivery were already posted to inventory, so the inspection can no longer change.');
            }
            if ($delivery->inspection_result !== null && ! $user->isAdmin()) {
                throw new WorkflowException('This delivery was already inspected. Only an administrator can correct the inspection.', 'inspection');
            }
            $accepted = (int) $data['accepted_quantity'];
            $rejected = (int) $data['rejected_quantity'];
            if ((int) $delivery->quantity !== $accepted + $rejected) {
                throw new WorkflowException("Accepted plus rejected units must equal the {$delivery->quantity} units received.", 'accepted_quantity');
            }
            $damaged = max(0, (int) ($data['damaged_quantity'] ?? 0));
            $defective = max(0, (int) ($data['defective_quantity'] ?? 0));
            if ($damaged + $defective > $rejected) {
                throw new WorkflowException("Damaged plus defective units ({$damaged} + {$defective}) cannot be more than the {$rejected} rejected.", 'damaged_quantity');
            }
            $category = $data['rejection_category'] ?? null;
            if ($rejected > 0 && ! $category) {
                // Fully explained by the breakdown: the larger part names the category.
                $category = $damaged + $defective === $rejected ? ($damaged >= $defective ? 'damaged' : 'defective') : null;
            }
            if ($rejected > 0 && (! trim((string) ($data['rejection_reason'] ?? '')) || ! $category)) {
                throw new WorkflowException('Give the rejection category and reason for the rejected units.', 'rejection_reason');
            }
            $result = match (true) {
                $rejected === 0 => 'accepted',
                $accepted === 0 => 'rejected',
                default => 'partially_accepted',
            };

            $before = $delivery->only(['accepted_quantity', 'rejected_quantity', 'inspection_result', 'inspected_on']);
            $delivery->update([
                'accepted_quantity' => $accepted,
                'rejected_quantity' => $rejected,
                'inspection_result' => $result,
                'inspection_number' => $delivery->inspection_number ?: 'INSP-'.$delivery->delivery_number,
                'inspected_on' => $data['inspected_on'],
                'inspected_by_id' => $user->id,
                'inspection_remarks' => $data['inspection_remarks'] ?? null,
                'rejection_reason' => $rejected > 0 ? $data['rejection_reason'] : null,
                'rejection_category' => $rejected > 0 ? $category : null,
                'damaged_quantity' => $rejected > 0 ? $damaged : null,
                'defective_quantity' => $rejected > 0 ? $defective : null,
                'resolution_status' => $rejected > 0 ? $delivery->resolution_status : null,
            ]);
            AuditLogger::record(
                $before['inspection_result'] ? 'inbound_delivery.inspection_corrected' : 'inbound_delivery.inspected',
                "Delivery {$delivery->delivery_number} inspected: {$accepted} accepted, {$rejected} rejected.",
                $delivery,
                ['before' => $before['inspection_result'] ? $before : null, 'result' => $result],
                $user,
            );
            if ($delivery->purchaseOrder) {
                $this->refreshPurchaseOrderStatus($delivery->purchaseOrder, $user);
            }
            QualityInspectionCompleted::dispatch($delivery, $user);

            return $delivery;
        });

        return $delivery;
    }

    /** Record how rejected units were settled with the supplier (return, replacement, credit…). */
    public function resolveRejection(Delivery $delivery, User $user, string $resolution, ?string $remarks): void
    {
        if (! in_array($resolution, Delivery::RESOLUTIONS, true)) {
            throw new WorkflowException('Choose how the rejected units were resolved.', 'resolution_status');
        }
        DB::transaction(function () use ($delivery, $user, $resolution, $remarks) {
            $delivery = Delivery::lockForUpdate()->findOrFail($delivery->id);
            if ((int) $delivery->rejected_quantity === 0) {
                throw new WorkflowException('This delivery has no rejected units.');
            }
            $delivery->update(['resolution_status' => $resolution, 'resolution_remarks' => $remarks, 'resolved_at' => now()]);
            AuditLogger::record('inbound_delivery.rejection_resolved', "Delivery {$delivery->delivery_number}: {$delivery->rejected_quantity} rejected units {$resolution}.", $delivery, ['remarks' => $remarks], $user);
        });
    }

    // ─────────────────────────── Commercial invoices ───────────────────────────

    /**
     * @param  array{purchase_order_id: int, delivery_id: int, invoice_number: string, invoice_date: string, quantity: int, unit_cost: float|string, tax_amount?: float|string|null, payment_terms?: ?string, notes?: ?string}  $data
     */
    public function recordInvoice(Team $team, User $user, array $data, ?UploadedFile $attachment = null): CommercialInvoice
    {
        // The supplier's invoice document is kept on the private disk; removed again if saving fails.
        $stored = $attachment ? Storage::disk('local')->putFileAs("invoices/{$team->id}", $attachment, Str::uuid().'.'.(strtolower($attachment->getClientOriginalExtension()) ?: 'pdf')) : null;
        $data['attachment_path'] = $stored;
        $data['attachment_name'] = $attachment ? mb_substr($attachment->getClientOriginalName(), 0, 255) : null;

        try {
            return $this->storeInvoice($team, $user, $data);
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $e;
        }
    }

    private function storeInvoice(Team $team, User $user, array $data): CommercialInvoice
    {
        $invoice = DB::transaction(function () use ($team, $user, $data) {
            $po = PurchaseOrder::where('team_id', $team->id)->lockForUpdate()->find($data['purchase_order_id'])
                ?? throw new WorkflowException('Select the purchase order this invoice is for.', 'purchase_order_id');
            if (in_array($po->status, [PurchaseOrder::DRAFT, PurchaseOrder::CANCELLED], true)) {
                throw new WorkflowException("Purchase order {$po->po_number} is {$po->status}; an invoice cannot be recorded against it.", 'purchase_order_id');
            }
            $delivery = Delivery::where('team_id', $team->id)->find($data['delivery_id'])
                ?? throw new WorkflowException('Select the inbound delivery this invoice covers.', 'delivery_id');

            $qty = (int) $data['quantity'];
            $unit = round((float) $data['unit_cost'], 2);
            $tax = round((float) ($data['tax_amount'] ?? 0), 2);
            $invoice = CommercialInvoice::create([
                'team_id' => $team->id,
                'purchase_order_id' => $po->id,
                'delivery_id' => $delivery->id,
                'product_id' => $po->product_id,
                'invoice_date' => $data['invoice_date'],
                'invoice_number' => trim($data['invoice_number']),
                'quantity' => $qty,
                'description' => $po->description,
                'unit_cost' => $unit,
                'total_cost' => round($qty * $unit + $tax, 2),
                'tax_amount' => $tax,
                'payment_terms' => $data['payment_terms'] ?? $po->payment_terms,
                'supplier_id' => $po->supplier_id,
                'recorded_by_id' => $user->id,
                'status' => CommercialInvoice::PENDING,
                'notes' => $data['notes'] ?? null,
                'attachment_path' => $data['attachment_path'] ?? null,
                'attachment_name' => $data['attachment_name'] ?? null,
            ]);
            $invoice->update(['discrepancies' => $this->checkInvoice($invoice)]);
            AuditLogger::record('commercial_invoice.recorded', "Invoice {$invoice->invoice_number} recorded for {$po->po_number} / {$delivery->delivery_number}: {$qty} × {$unit}.", $invoice, ['discrepancies' => $invoice->discrepancies], $user);

            return $invoice;
        });

        $flags = count($invoice->discrepancies ?? []);
        $this->notifier->notify($invoice->team, ['admin', 'staff_procurement'], 'commercial_invoice.needs_verification', 'Invoice requires verification',
            "Invoice {$invoice->invoice_number} was recorded".($flags ? " with {$flags} discrepancy flag(s)." : ' and matches the order and inspection.'), $user, 'procurement?tab=commercial_invoices');

        return $invoice;
    }

    /**
     * Correct an unverified invoice's quantities or prices (e.g. the supplier sent a corrected
     * invoice). The checks run again and the before/after values are audited.
     *
     * @param  array{quantity: int, unit_cost: float|string, tax_amount?: float|string|null, invoice_number?: ?string, remarks: string}  $data
     */
    public function correctInvoice(CommercialInvoice $invoice, User $user, array $data): CommercialInvoice
    {
        return DB::transaction(function () use ($invoice, $user, $data) {
            $invoice = CommercialInvoice::lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($invoice->status, [CommercialInvoice::PENDING, CommercialInvoice::FLAGGED], true)) {
                throw new WorkflowException("Invoice {$invoice->invoice_number} is {$invoice->status} and cannot be changed.");
            }
            $remarks = trim((string) ($data['remarks'] ?? '')) ?: throw new WorkflowException('Say why the invoice is being corrected.', 'remarks');
            $before = $invoice->only(['invoice_number', 'quantity', 'unit_cost', 'tax_amount', 'total_cost']);
            $qty = (int) $data['quantity'];
            $unit = round((float) $data['unit_cost'], 2);
            $tax = round((float) ($data['tax_amount'] ?? $invoice->tax_amount), 2);
            $invoice->fill([
                'invoice_number' => trim((string) ($data['invoice_number'] ?? '')) ?: $invoice->invoice_number,
                'quantity' => $qty, 'unit_cost' => $unit, 'tax_amount' => $tax, 'total_cost' => round($qty * $unit + $tax, 2),
                'status' => CommercialInvoice::PENDING,
            ])->save();
            $invoice->update(['discrepancies' => $this->checkInvoice($invoice)]);
            AuditLogger::record('commercial_invoice.corrected', "Invoice {$invoice->invoice_number} corrected: {$remarks}", $invoice, ['before' => $before, 'discrepancies' => $invoice->discrepancies], $user);

            return $invoice;
        });
    }

    /**
     * Three-way match: Purchase Order (agreed quantity and price) ↔ Inbound Delivery and
     * Inspection (received and accepted quantities) ↔ Commercial Invoice (what is charged).
     *
     * @return list<array{code: string, message: string, overridable: bool}>
     */
    public function checkInvoice(CommercialInvoice $invoice): array
    {
        $flags = [];
        $add = function (string $code, string $message, bool $overridable = false) use (&$flags) {
            $flags[] = compact('code', 'message', 'overridable');
        };

        $po = $invoice->purchase_order_id ? PurchaseOrder::withTrashed()->find($invoice->purchase_order_id) : null;
        $delivery = $invoice->delivery_id ? Delivery::withTrashed()->find($invoice->delivery_id) : null;

        if (! $po) {
            $add('no_purchase_order', 'Not linked to a purchase order.');
        }
        if (! $delivery) {
            $add('no_delivery', 'Not linked to an inbound delivery.');
        }
        if ($po && $delivery && (int) $delivery->purchase_order_id !== (int) $po->id) {
            $add('po_mismatch', "Delivery {$delivery->delivery_number} belongs to a different purchase order than {$po->po_number}.");
        }
        if ($po && $invoice->supplier_id && (int) $invoice->supplier_id !== (int) $po->supplier_id) {
            $add('supplier_mismatch', 'The invoice supplier is not the purchase order supplier.');
        }
        if ($delivery && $delivery->inspection_result === null) {
            $add('not_inspected', "Delivery {$delivery->delivery_number} has not been inspected yet.");
        }
        if ($delivery && $delivery->inspection_result !== null && (int) $invoice->quantity !== (int) $delivery->accepted_quantity) {
            $add('quantity', "Invoiced {$invoice->quantity} units, but {$delivery->accepted_quantity} were accepted at inspection (".(int) $delivery->rejected_quantity.' rejected).', true);
        }
        if ($po && abs((float) $invoice->unit_cost - (float) $po->unit_cost) >= 0.005) {
            $add('price', 'Invoiced unit price '.number_format((float) $invoice->unit_cost, 2).' differs from the agreed PO price '.number_format((float) $po->unit_cost, 2).'.', true);
        }
        $dupe = CommercialInvoice::where('team_id', $invoice->team_id)->where('id', '!=', $invoice->id)
            ->where('supplier_id', $invoice->supplier_id)->where('invoice_number', $invoice->invoice_number)
            ->where('status', '!=', CommercialInvoice::CANCELLED)->exists();
        if ($dupe) {
            $add('duplicate_reference', "Invoice number {$invoice->invoice_number} was already recorded for this supplier.");
        }
        if ($delivery && CommercialInvoice::where('delivery_id', $delivery->id)->where('id', '!=', $invoice->id)->where('status', '!=', CommercialInvoice::CANCELLED)->exists()) {
            $add('delivery_already_invoiced', "Another invoice is already recorded for delivery {$delivery->delivery_number}.");
        }

        return $flags;
    }

    /**
     * Verify an invoice. Never automatic. Blocking discrepancies (no PO, wrong PO, not inspected,
     * duplicate reference) must be fixed first; a quantity or price difference may be accepted
     * only by an administrator, with remarks. Recording or verifying an invoice is not payment.
     */
    public function verifyInvoice(CommercialInvoice $invoice, User $user, ?string $remarks): CommercialInvoice
    {
        $invoice = DB::transaction(function () use ($invoice, $user, $remarks) {
            $invoice = CommercialInvoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === CommercialInvoice::VERIFIED) {
                throw new WorkflowException("Invoice {$invoice->invoice_number} is already verified.");
            }
            if (! in_array($invoice->status, [CommercialInvoice::PENDING, CommercialInvoice::FLAGGED], true)) {
                throw new WorkflowException("Invoice {$invoice->invoice_number} is {$invoice->status} and cannot be verified.");
            }
            $flags = $this->checkInvoice($invoice);
            $remarks = trim((string) $remarks) ?: null;
            $blocking = array_values(array_filter($flags, fn ($f) => ! $f['overridable']));
            if ($blocking) {
                throw new WorkflowException('Cannot verify: '.implode(' ', array_column($blocking, 'message')));
            }
            if ($flags && (! $user->isAdmin() || ! $remarks)) {
                throw new WorkflowException('This invoice has discrepancies ('.implode(' ', array_column($flags, 'message')).') Correct the invoice, or an administrator may accept the difference with remarks.', 'remarks');
            }
            $invoice->update(['status' => CommercialInvoice::VERIFIED, 'discrepancies' => $flags, 'verified_by_id' => $user->id, 'verified_at' => now(), 'verification_remarks' => $remarks]);
            AuditLogger::record('commercial_invoice.verified', "Invoice {$invoice->invoice_number} verified".($flags ? ' with accepted differences: '.$remarks : '.'), $invoice, ['accepted_discrepancies' => $flags], $user);
            CommercialInvoiceVerified::dispatch($invoice, $user);

            // Every receiving condition is now met (inspected, accepted units, verified invoice):
            // post the accepted units to stock in the same transaction, so verification and
            // stock can never disagree. Rejected units are not posted.
            $delivery = Delivery::lockForUpdate()->find($invoice->delivery_id);
            if ($delivery && ! $delivery->posted_at && $delivery->inspection_result !== null && (int) $delivery->accepted_quantity > 0) {
                $movement = $this->postAccepted($delivery, $user, $invoice, true);
                InventoryUpdated::dispatch($delivery->fresh(), $user, $movement);
            }

            return $invoice;
        });

        return $invoice;
    }

    public function flagInvoice(CommercialInvoice $invoice, User $user, ?string $remarks): void
    {
        $remarks = trim((string) $remarks) ?: throw new WorkflowException('Describe the problem with this invoice.', 'remarks');
        $invoice = DB::transaction(function () use ($invoice, $user, $remarks) {
            $invoice = CommercialInvoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== CommercialInvoice::PENDING) {
                throw new WorkflowException("Invoice {$invoice->invoice_number} is {$invoice->status}; only a pending invoice can be flagged.");
            }
            $invoice->update(['status' => CommercialInvoice::FLAGGED, 'verification_remarks' => $remarks]);
            AuditLogger::record('commercial_invoice.flagged', "Invoice {$invoice->invoice_number} flagged: {$remarks}", $invoice, [], $user, 'success', 'warning');

            return $invoice;
        });
        $this->notifier->notify($invoice->team, ['admin', 'staff_procurement'], 'commercial_invoice.flagged', 'Invoice flagged', "Invoice {$invoice->invoice_number} was flagged: {$remarks}", $user, 'procurement?tab=commercial_invoices');
    }

    // ─────────────────────────── Inventory posting ───────────────────────────

    /**
     * Manual posting: a fallback for deliveries whose invoice was verified before automatic
     * posting existed. Normally verification posts the stock itself (verifyInvoice). Same rules:
     * inspection recorded, accepted units, a verified invoice, and never twice
     * (stock_movements.movement_key is unique).
     */
    public function postToInventory(Delivery $delivery, User $user): StockMovement
    {
        return DB::transaction(function () use ($delivery, $user) {
            $delivery = Delivery::lockForUpdate()->findOrFail($delivery->id);
            if ($delivery->posted_at) {
                throw new WorkflowException("Delivery {$delivery->delivery_number} was already posted to inventory on {$delivery->posted_at->format('M j, Y')}.");
            }
            if ($delivery->inspection_result === null) {
                throw new WorkflowException('Inspect the delivery before posting to inventory.');
            }
            $accepted = (int) $delivery->accepted_quantity;
            if ($accepted < 1) {
                throw new WorkflowException('No units were accepted at inspection, so nothing can be posted.');
            }
            $po = $delivery->purchase_order_id ? PurchaseOrder::find($delivery->purchase_order_id) : null;
            if (! $po || ! $po->product_id) {
                throw new WorkflowException('The delivery must belong to a purchase order with a product.');
            }
            $invoice = CommercialInvoice::where('delivery_id', $delivery->id)->where('status', CommercialInvoice::VERIFIED)->first()
                ?? throw new WorkflowException('Verify the supplier invoice for this delivery before posting to inventory.');

            $movement = $this->postAccepted($delivery, $user, $invoice, false);
            InventoryUpdated::dispatch($delivery->fresh(), $user, $movement);

            return $movement;
        });
    }

    /** Call inside a transaction with the delivery row locked. */
    private function postAccepted(Delivery $delivery, User $user, CommercialInvoice $invoice, bool $automatic): StockMovement
    {
        $accepted = (int) $delivery->accepted_quantity;
        $po = PurchaseOrder::find($delivery->purchase_order_id);
        if (! $po || ! $po->product_id) {
            throw new WorkflowException('The delivery must belong to a purchase order with a product.');
        }

        $product = Product::lockForUpdate()->findOrFail($po->product_id);
        $prev = (int) $product->stock;
        $product->stock = $prev + $accepted;
        $product->save();
        if (method_exists($product, 'updateCalculatedStatus')) {
            $product->updateCalculatedStatus();
        }

        $movement = StockMovement::create([
            'team_id' => $delivery->team_id,
            'product_id' => $product->id,
            'user_id' => $user->id,
            'supplier_id' => $po->supplier_id,
            'movement_key' => "inbound-delivery:{$delivery->id}",
            'type' => 'stock_in',
            'quantity' => $accepted,
            'previous_stock' => $prev,
            'new_stock' => $prev + $accepted,
            'reference_number' => $delivery->delivery_number,
            'reason' => 'Accepted supplier delivery',
            'remarks' => "PO {$po->po_number} · Delivery {$delivery->delivery_number} · Inspection {$delivery->inspection_number} · Invoice {$invoice->invoice_number}"
                .((int) $delivery->rejected_quantity > 0 ? " · {$delivery->rejected_quantity} rejected units excluded" : ''),
            'movement_date' => now(),
        ]);
        $delivery->update(['posted_quantity' => $accepted, 'posted_at' => now(), 'posted_by_id' => $user->id, 'stock_movement_id' => $movement->id]);
        AuditLogger::record('inventory.received', ($automatic ? '[Automatic] ' : '')."{$accepted} × {$product->name} added to stock from delivery {$delivery->delivery_number} ({$prev} → ".($prev + $accepted).').', $delivery,
            ['purchase_order' => $po->po_number, 'invoice' => $invoice->invoice_number, 'inspection' => $delivery->inspection_number, 'rejected_excluded' => (int) $delivery->rejected_quantity, 'automatic' => $automatic], $user);

        return $movement;

    }
}
