<?php

use App\Enums\TeamRole;
use App\Events\Procurement\AHPPrioritizationRecalculated;
use App\Events\Procurement\CommercialInvoiceVerified;
use App\Events\Procurement\InboundDeliveryRecorded;
use App\Events\Procurement\InventoryUpdated;
use App\Events\Procurement\PurchaseOrderCreated;
use App\Events\Procurement\PurchaseOrderSent;
use App\Events\Procurement\PurchaseRequestApproved;
use App\Events\Procurement\PurchaseRequestRejected;
use App\Events\Procurement\PurchaseRequestSubmitted;
use App\Events\Procurement\QualityInspectionCompleted;
use App\Events\Procurement\SupplierEvaluationUpdated;
use App\Jobs\RecalculateEvaluations;
use App\Models\CommercialInvoice;
use App\Models\Delivery;
use App\Models\EvaluationSnapshot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\Team;
use App\Models\User;
use App\Services\Inventory\ProductPrioritizer;
use App\Services\Suppliers\SupplierEvaluator;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * The full Inventory-to-Procurement cycle through the HTTP endpoints, with the automatic steps:
 * approval drafts the PO, invoice verification posts stock, and evaluations are recalculated.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    Storage::fake('local');
    $this->team = Team::factory()->create(['slug' => 'proc-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->staff = User::factory()->create(['role' => 'staff_procurement', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->staff, ['role' => TeamRole::Member->value]);
    $this->adminCookies = createTrustedDeviceFor($this->admin)['cookies'];
    $this->staffCookies = createTrustedDeviceFor($this->staff)['cookies'];

    $this->supplier = Supplier::create(['team_id' => $this->team->id, 'name' => 'Volt Supply', 'status' => 'Active']);
    $this->product = Product::create(['team_id' => $this->team->id, 'sku' => 'CB-40', 'name' => 'Circuit Breaker 40A', 'price' => 20, 'cost_price' => 10,
        'stock' => 5, 'reorder_level' => 10, 'reorder_quantity' => 100, 'is_active' => true, 'unit' => 'pcs', 'supplier_id' => $this->supplier->id]);
    $this->product->forceFill(['created_at' => '2025-01-01'])->saveQuietly();
    foreach (['2026-07-05' => 40, '2026-08-05' => 50, '2026-09-05' => 60] as $date => $qty) {
        StockMovement::create(['team_id' => $this->team->id, 'product_id' => $this->product->id, 'type' => 'sale', 'quantity' => -$qty, 'previous_stock' => 0, 'new_stock' => 0, 'movement_date' => $date, 'reference_number' => "S-{$date}"]);
    }
    $this->url = "/{$this->team->slug}";
});

afterEach(fn () => Carbon::setTestNow());

function asStaff($t) { return $t->actingAs($t->staff)->withCookies($t->staffCookies); }
function asAdmin($t) { return $t->actingAs($t->admin)->withCookies($t->adminCookies); }

function approvedRequest($t, int $qty = 100): PurchaseRequest
{
    asStaff($t)->post("{$t->url}/procurement/requests", ['lines' => [['product_id' => $t->product->id, 'quantity' => $qty, 'justification' => 'Below reorder level.']]]);
    $pr = PurchaseRequest::latest('id')->first();
    asAdmin($t)->post("{$t->url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve']);

    return $pr->fresh();
}

test('the complete cycle advances automatically after each human step', function () {
    Event::fake([
        PurchaseRequestSubmitted::class, PurchaseRequestApproved::class, PurchaseOrderCreated::class, PurchaseOrderSent::class,
        InboundDeliveryRecorded::class, QualityInspectionCompleted::class, CommercialInvoiceVerified::class, InventoryUpdated::class,
    ]);
    $url = $this->url;

    // 1. AHP recommends replenishment (5 in stock, forecast 50).
    $priority = app(ProductPrioritizer::class)->forTeam($this->team->id)[$this->product->id];
    expect($priority['reorder']['needed'])->toBeTrue()->and($priority['reorder']['suggested_quantity'])->toBe(100)
        ->and(PurchaseRequest::count())->toBe(0); // recommendations never create requests by themselves

    // 2. Staff submit the request: Pending Approval.
    asStaff($this)->post("{$url}/procurement/requests", ['lines' => [['product_id' => $this->product->id, 'quantity' => 100, 'justification' => 'Below reorder level; forecast 50/month.']]])
        ->assertSessionHasNoErrors();
    $pr = PurchaseRequest::sole();
    expect($pr->status)->toBe(PurchaseRequest::PENDING)->and($pr->recommended_quantity)->toBe(100);
    Event::assertDispatched(PurchaseRequestSubmitted::class);
    asStaff($this)->post("{$url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve'])->assertForbidden();

    // 3–4. Admin approves: the draft PO is created at once, pre-filled from records, once.
    SupplierQuote::create(['team_id' => $this->team->id, 'supplier_id' => $this->supplier->id, 'product_id' => $this->product->id, 'reference' => 'Q-1', 'unit_price' => 9.5, 'unit' => 'pcs', 'quoted_on' => '2026-10-01', 'verified_at' => now()]);
    asAdmin($this)->post("{$url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve', 'remarks' => 'OK'])->assertSessionHasNoErrors();
    asAdmin($this)->post("{$url}/procurement/requests/{$pr->id}/review", ['decision' => 'approve'])->assertSessionHasErrors();
    $po = PurchaseOrder::sole();
    expect($pr->fresh()->status)->toBe(PurchaseRequest::CONVERTED)->and($pr->fresh()->reviewed_by_id)->toBe($this->admin->id)
        ->and($po->purchase_request_id)->toBe($pr->id)->and($po->status)->toBe(PurchaseOrder::DRAFT)
        ->and($po->supplier_id)->toBe($this->supplier->id)->and((float) $po->unit_cost)->toBe(9.5)->and($po->quantity)->toBe(100);
    Event::assertDispatched(PurchaseRequestApproved::class);
    Event::assertDispatched(PurchaseOrderCreated::class, fn ($e) => $e->automatic && $e->purchaseOrder->is($po));

    // 5. Staff confirm the terms and send. Goods cannot be received on a draft.
    asStaff($this)->post("{$url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-08', 'quantity' => 10])->assertSessionHasErrors();
    asStaff($this)->patch("{$url}/procurement/purchase-orders/{$po->id}/draft", ['supplier_id' => $this->supplier->id, 'quantity' => 100, 'unit_cost' => 9.5, 'payment_terms' => '30 days', 'po_date' => '2026-10-01', 'promised_delivery_date' => '2026-10-20'])
        ->assertSessionHasNoErrors();
    asStaff($this)->post("{$url}/procurement/purchase-orders/{$po->id}/send")->assertSessionHasNoErrors();
    expect($po->fresh()->status)->toBe(PurchaseOrder::SENT)->and($po->fresh()->payment_terms)->toBe('30 days');
    Event::assertDispatched(PurchaseOrderSent::class);

    // 6. Partial delivery: an inspection task opens; the PO is not "delivered".
    asStaff($this)->post("{$url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-08', 'quantity' => 60, 'supplier_document_number' => 'DR-7781'])
        ->assertSessionHasNoErrors();
    $delivery = Delivery::sole();
    expect($delivery->inspectionStatus())->toBe('Pending Inspection')->and($po->fresh()->status)->toBe(PurchaseOrder::PARTIAL)->and($this->product->fresh()->stock)->toBe(5);
    Event::assertDispatched(InboundDeliveryRecorded::class, fn ($e) => $e->openBefore === 100);
    asStaff($this)->post("{$url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-09', 'quantity' => 41])->assertSessionHasErrors('quantity');

    // 7. Inspection: 4 damaged + 2 defective are rejected and held.
    asStaff($this)->post("{$url}/deliveries/{$delivery->id}/inspection", ['accepted_quantity' => 54, 'rejected_quantity' => 6, 'damaged_quantity' => 4, 'defective_quantity' => 3, 'inspected_on' => '2026-10-09', 'rejection_reason' => 'Burnt contacts'])
        ->assertSessionHasErrors('damaged_quantity');
    asStaff($this)->post("{$url}/deliveries/{$delivery->id}/inspection", ['accepted_quantity' => 54, 'rejected_quantity' => 6, 'damaged_quantity' => 4, 'defective_quantity' => 2, 'inspected_on' => '2026-10-09', 'rejection_reason' => 'Burnt contacts, cracked casings'])
        ->assertSessionHasNoErrors();
    $delivery->refresh();
    expect($delivery->damaged_quantity)->toBe(4)->and($delivery->defective_quantity)->toBe(2)->and($delivery->rejection_category)->toBe('damaged')
        ->and($delivery->inspectionStatus())->toBe('Pending Resolution');
    Event::assertDispatched(QualityInspectionCompleted::class);

    // 8–9. Invoice (with its document) charges all 60 at a higher price: held, not verifiable by staff.
    $pdf = UploadedFile::fake()->create('INV-5501.pdf', 40, 'application/pdf');
    asStaff($this)->post("{$url}/procurement/invoices", ['purchase_order_id' => $po->id, 'delivery_id' => $delivery->id, 'invoice_number' => 'INV-5501', 'invoice_date' => '2026-10-09', 'quantity' => 60, 'unit_cost' => 9.9, 'attachment' => $pdf])
        ->assertSessionHasNoErrors();
    $invoice = CommercialInvoice::sole();
    expect(array_column($invoice->discrepancies, 'code'))->toEqual(['quantity', 'price'])->and($invoice->attachment_name)->toBe('INV-5501.pdf');
    Storage::disk('local')->assertExists($invoice->attachment_path);
    asStaff($this)->get("{$url}/procurement/invoices/{$invoice->id}/attachment")->assertOk();
    asStaff($this)->post("{$url}/procurement/invoices/{$invoice->id}/verify")->assertSessionHasErrors('remarks');
    expect($this->product->fresh()->stock)->toBe(5);

    // Corrected invoice → staff verify → stock posted automatically: 54 accepted, 6 held.
    asStaff($this)->patch("{$url}/procurement/invoices/{$invoice->id}", ['quantity' => 54, 'unit_cost' => 9.5, 'remarks' => 'Corrected invoice for accepted units'])->assertSessionHasNoErrors();
    asStaff($this)->post("{$url}/procurement/invoices/{$invoice->id}/verify")->assertSessionHasNoErrors();
    expect($invoice->fresh()->status)->toBe(CommercialInvoice::VERIFIED)
        ->and($this->product->fresh()->stock)->toBe(59)
        ->and($delivery->fresh()->posted_quantity)->toBe(54)
        ->and(StockMovement::where('movement_key', "inbound-delivery:{$delivery->id}")->count())->toBe(1);
    Event::assertDispatched(CommercialInvoiceVerified::class);
    Event::assertDispatched(InventoryUpdated::class, fn ($e) => $e->movement?->quantity === 54);

    // No second posting: repeated verify or a manual post are refused.
    asStaff($this)->post("{$url}/procurement/invoices/{$invoice->id}/verify")->assertSessionHasErrors();
    asStaff($this)->post("{$url}/deliveries/{$delivery->id}/post-inventory")->assertSessionHasErrors();
    expect($this->product->fresh()->stock)->toBe(59);
    asAdmin($this)->post("{$url}/deliveries/{$delivery->id}/inspection", ['accepted_quantity' => 60, 'rejected_quantity' => 0, 'inspected_on' => '2026-10-09'])->assertSessionHasErrors();

    // 10. AHP reflects the new stock; demand still comes from sales, not the purchase.
    $after = app(ProductPrioritizer::class)->forTeam($this->team->id)[$this->product->id];
    expect(collect($after['criteria'])->firstWhere('key', 'stock')['inputs']['available'])->toBe(59)
        ->and($after['reorder']['forecast'])->toBe(50)->and($after['reorder']['needed'])->toBeFalse();

    // Second delivery completes the order (46 still owed: 40 open + 6 rejected).
    asStaff($this)->post("{$url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-14', 'quantity' => 46])->assertSessionHasNoErrors();
    $second = Delivery::latest('id')->first();
    asStaff($this)->post("{$url}/deliveries/{$second->id}/inspection", ['accepted_quantity' => 46, 'rejected_quantity' => 0, 'inspected_on' => '2026-10-14']);
    expect($po->fresh()->status)->toBe(PurchaseOrder::FULL);

    // 11. Supplier Evaluation reads the same delivery and inspection records.
    $quality = collect(app(SupplierEvaluator::class)->forTeam($this->team->id)[$this->supplier->id]['criteria'])->firstWhere('key', 'quality');
    expect($quality['score'])->toEqual(round(100 / 106 * 100, 2));
});

test('workflow events queue an evaluation refresh that snapshots only changed results', function () {
    Queue::fake();
    $pr = approvedRequest($this);
    $po = PurchaseOrder::sole();
    asStaff($this)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", ['supplier_id' => $this->supplier->id, 'quantity' => 100, 'unit_cost' => 9]);
    asStaff($this)->post("{$this->url}/procurement/purchase-orders/{$po->id}/send");
    asStaff($this)->post("{$this->url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-15', 'quantity' => 10]);
    Queue::assertPushed(RecalculateEvaluations::class, fn ($j) => $j->trigger === 'inbound_delivery.recorded' && $j->teamId === $this->team->id);

    Event::fake([AHPPrioritizationRecalculated::class, SupplierEvaluationUpdated::class]);
    $job = new RecalculateEvaluations($this->team->id, 'inventory.updated', 'D-1');
    $job->handle(app(ProductPrioritizer::class), app(SupplierEvaluator::class));
    $first = EvaluationSnapshot::count();
    expect($first)->toBeGreaterThan(0)
        ->and(EvaluationSnapshot::where('kind', 'product_priority')->where('subject_id', $this->product->id)->value('trigger'))->toBe('inventory.updated')
        // Incomplete supplier evaluation stays incomplete: no invented score.
        ->and(EvaluationSnapshot::where('kind', 'supplier_evaluation')->value('score'))->toBeNull();
    Event::assertDispatched(AHPPrioritizationRecalculated::class);
    Event::assertDispatched(SupplierEvaluationUpdated::class);

    $job->handle(app(ProductPrioritizer::class), app(SupplierEvaluator::class));
    expect(EvaluationSnapshot::count())->toBe($first); // nothing changed → no new snapshot
    expect($job->tries)->toBe(3);
});

test('a rejected request stops: no purchase order is created', function () {
    Event::fake([PurchaseRequestRejected::class, PurchaseOrderCreated::class]);
    asStaff($this)->post("{$this->url}/procurement/requests", ['lines' => [['product_id' => $this->product->id, 'quantity' => 20, 'justification' => 'Restock']]]);
    $pr = PurchaseRequest::sole();

    asAdmin($this)->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'reject'])->assertSessionHasErrors('remarks');
    asAdmin($this)->post("{$this->url}/procurement/requests/{$pr->id}/review", ['decision' => 'reject', 'remarks' => 'Stock arriving from transfer'])->assertSessionHasNoErrors();
    expect(PurchaseOrder::count())->toBe(0)->and($pr->fresh()->status)->toBe(PurchaseRequest::REJECTED);
    Event::assertDispatched(PurchaseRequestRejected::class);
    Event::assertNotDispatched(PurchaseOrderCreated::class);
});

test('a draft without a known price cannot be sent, and quantity changes need a reason', function () {
    $this->product->update(['supplier_id' => null]);
    approvedRequest($this, 50);
    $po = PurchaseOrder::sole();
    expect($po->supplier_id)->toBeNull()->and((float) $po->unit_cost)->toBe(0.0)->and($po->notes)->toContain('Agreed price to be entered');

    asStaff($this)->post("{$this->url}/procurement/purchase-orders/{$po->id}/send")->assertSessionHasErrors('supplier_id');
    $draft = ['supplier_id' => $this->supplier->id, 'unit_cost' => 9];
    asStaff($this)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", $draft + ['quantity' => 40])->assertSessionHasErrors('quantity_adjustment_reason');
    asStaff($this)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", $draft + ['quantity' => 60, 'quantity_adjustment_reason' => 'MOQ'])->assertSessionHasErrors('quantity');
    asStaff($this)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", $draft + ['quantity' => 40, 'quantity_adjustment_reason' => 'Budget cap'])->assertSessionHasNoErrors();
    expect($po->fresh()->quantity_adjustment_reason)->toBe('Budget cap');
    asStaff($this)->post("{$this->url}/procurement/purchase-orders/{$po->id}/send")->assertSessionHasNoErrors();
    asStaff($this)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", $draft + ['quantity' => 40, 'quantity_adjustment_reason' => 'x'])->assertSessionHasErrors();
});

test('a fully rejected delivery adds nothing to stock, even after its invoice is verified', function () {
    approvedRequest($this, 10);
    $po = PurchaseOrder::sole();
    asStaff($this)->patch("{$this->url}/procurement/purchase-orders/{$po->id}/draft", ['supplier_id' => $this->supplier->id, 'quantity' => 10, 'unit_cost' => 9]);
    asStaff($this)->post("{$this->url}/procurement/purchase-orders/{$po->id}/send");
    asStaff($this)->post("{$this->url}/deliveries/purchase-orders/{$po->id}/receive", ['delivery_date' => '2026-10-15', 'quantity' => 10]);
    $d = Delivery::sole();
    asStaff($this)->post("{$this->url}/deliveries/{$d->id}/inspection", ['accepted_quantity' => 0, 'rejected_quantity' => 10, 'defective_quantity' => 10, 'inspected_on' => '2026-10-15', 'rejection_reason' => 'Wrong model']);
    expect($d->fresh()->rejection_category)->toBe('defective');

    asStaff($this)->post("{$this->url}/procurement/invoices", ['purchase_order_id' => $po->id, 'delivery_id' => $d->id, 'invoice_number' => 'INV-9', 'invoice_date' => '2026-10-15', 'quantity' => 10, 'unit_cost' => 9]);
    $ci = CommercialInvoice::sole();
    asAdmin($this)->post("{$this->url}/procurement/invoices/{$ci->id}/verify", ['remarks' => 'Credit note to follow'])->assertSessionHasNoErrors();
    expect($this->product->fresh()->stock)->toBe(5)->and($d->fresh()->posted_at)->toBeNull()->and($po->fresh()->status)->toBe(PurchaseOrder::PARTIAL);
});
