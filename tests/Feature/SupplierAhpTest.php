<?php

use App\Enums\TeamRole;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\Team;
use App\Models\User;
use App\Services\Forecast\SmaForecaster;
use App\Services\Inventory\ProductPrioritizer;
use App\Services\Suppliers\SupplierEvaluator;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * AHP Product Prioritization, SMA forecasting and transaction-based Supplier Evaluation:
 * calculated from records only, missing data reported rather than invented.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->team = Team::factory()->create(['slug' => 'eval-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
});

afterEach(fn () => Carbon::setTestNow());

function evalProduct(Team $team, string $sku, int $stock, string $created = '2025-01-01'): Product
{
    $p = Product::create(['team_id' => $team->id, 'sku' => $sku, 'name' => "Product {$sku}", 'price' => 10, 'cost_price' => 5, 'stock' => $stock, 'is_active' => true, 'unit' => 'pcs']);
    $p->forceFill(['created_at' => $created])->saveQuietly();

    return $p;
}

function sold(Team $team, Product $p, string $date, int $qty): void
{
    StockMovement::create(['team_id' => $team->id, 'product_id' => $p->id, 'type' => 'sale', 'quantity' => -$qty, 'previous_stock' => 0, 'new_stock' => 0, 'movement_date' => $date, 'reference_number' => "SALE-{$p->sku}-{$date}"]);
}

function evalSupplier(Team $team, string $name): Supplier
{
    return Supplier::create(['team_id' => $team->id, 'name' => $name, 'status' => 'Active']);
}

test('SMA forecast is the plain average of the previous months, with no trend factor or invented history', function () {
    $p = evalProduct($this->team, 'SMA-1', 50);
    sold($this->team, $p, '2026-07-10', 10);
    sold($this->team, $p, '2026-08-10', 20);
    sold($this->team, $p, '2026-09-10', 30);

    $series = app(SmaForecaster::class)->series($this->team->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-10-15'), 3, 'All', $p->id);
    expect($series['historical'])->toBe([10, 20, 30, 0])
        ->and($series['forecast'])->toBe([null, null, null, 20])
        ->and($series['sourceRecordCount'])->toBe(3);

    $empty = evalProduct($this->team, 'SMA-EMPTY', 5);
    $none = app(SmaForecaster::class)->series($this->team->id, Carbon::parse('2026-01-01'), Carbon::parse('2026-10-15'), 3, 'All', $empty->id);
    expect($none['hasData'])->toBeFalse()
        ->and(array_sum($none['historical']))->toBe(0);
});

test('AHP product priority uses the fixed weights and real records', function () {
    $a = evalProduct($this->team, 'A', 20);
    $b = evalProduct($this->team, 'B', 80);
    foreach (['2026-07-05', '2026-08-05', '2026-09-05'] as $d) {
        sold($this->team, $a, $d, 30);
        sold($this->team, $b, $d, 10);
    }
    $s1 = evalSupplier($this->team, 'S1');
    $s2 = evalSupplier($this->team, 'S2');
    ProductSupplier::create(['team_id' => $this->team->id, 'product_id' => $a->id, 'supplier_id' => $s1->id, 'is_available' => true, 'verified_at' => now()]);
    ProductSupplier::create(['team_id' => $this->team->id, 'product_id' => $a->id, 'supplier_id' => $s2->id, 'is_available' => false, 'verified_at' => now()]);
    // Unverified: must not count.
    ProductSupplier::create(['team_id' => $this->team->id, 'product_id' => $b->id, 'supplier_id' => $s1->id, 'is_available' => true]);

    $r = app(ProductPrioritizer::class)->forTeam($this->team->id);
    $scores = fn ($id) => collect($r[$id]['criteria'])->mapWithKeys(fn ($c) => [$c['key'] => $c['score']])->all();

    // A: demand 30/30, stock (1 − 20/80), movement 90/90, availability (1 − 1/2).
    expect($scores($a->id))->toEqual(['demand' => 100.0, 'stock' => 75.0, 'movement' => 100.0, 'supplier_availability' => 50.0])
        ->and($r[$a->id]['score'])->toEqual(87.5)
        ->and($r[$a->id]['classification'])->toBe('Very High Priority')
        ->and($r[$a->id]['final_formula'])->toBe('40.00 + 22.50 + 20.00 + 5.00 = 87.50');

    // B has no verified supplier record: incomplete, never classified, availability not treated as 0.
    expect($r[$b->id]['complete'])->toBeFalse()
        ->and($r[$b->id]['score'])->toBeNull()
        ->and($r[$b->id]['classification'])->toBeNull()
        ->and($scores($b->id)['supplier_availability'])->toBeNull()
        ->and($scores($b->id)['demand'])->toEqual(33.33)
        ->and($r[$b->id]['partial_score'])->toEqual(round(33.33 * 0.4, 2) + 0.0 + round(33.33 * 0.2, 2));
});

test('a product without sales records has no demand or movement score instead of zero', function () {
    $p = evalProduct($this->team, 'NEW', 10);
    $r = app(ProductPrioritizer::class)->forTeam($this->team->id)[$p->id];
    $demand = collect($r['criteria'])->firstWhere('key', 'demand');

    expect($demand['available'])->toBeFalse()
        ->and($demand['score'])->toBeNull()
        ->and($demand['reason'])->toContain('No sales')
        ->and($r['classification'])->toBeNull();
});

test('supplier evaluation is calculated from deliveries, inspections and verified quotes', function () {
    $p = evalProduct($this->team, 'P', 0);
    $s1 = evalSupplier($this->team, 'Alpha');
    $s2 = evalSupplier($this->team, 'Beta');
    $po = PurchaseOrder::create(['team_id' => $this->team->id, 'po_date' => '2026-09-01', 'promised_delivery_date' => '2026-09-10', 'po_number' => 'PO-1', 'quantity' => 100, 'product_id' => $p->id, 'description' => 'P', 'unit_cost' => 10, 'total_cost' => 1000, 'supplier_id' => $s1->id]);
    $base = ['team_id' => $this->team->id, 'purchase_order_id' => $po->id, 'purchase_order_number' => 'PO-1', 'unit' => 'pcs', 'description' => 'P', 'supplier_id' => $s1->id, 'status' => 'Delivered', 'inspection_result' => 'partially_accepted', 'inspected_on' => '2026-09-16'];
    Delivery::create($base + ['delivery_date' => '2026-09-08', 'delivery_number' => 'D-1', 'quantity' => 60, 'accepted_quantity' => 57, 'rejected_quantity' => 3, 'rejection_reason' => 'Dented']);
    Delivery::create($base + ['delivery_date' => '2026-09-15', 'delivery_number' => 'D-2', 'quantity' => 40, 'accepted_quantity' => 40, 'rejected_quantity' => 0]);
    // The same delivery recorded twice must not inflate the score.
    Delivery::create($base + ['delivery_date' => '2026-09-15', 'delivery_number' => 'D-2', 'quantity' => 40, 'accepted_quantity' => 40, 'rejected_quantity' => 0]);

    $q = ['team_id' => $this->team->id, 'product_id' => $p->id, 'currency' => 'USD', 'unit' => 'pcs', 'tax_inclusive' => false, 'quoted_on' => '2026-09-01', 'valid_until' => '2026-12-31'];
    SupplierQuote::create($q + ['supplier_id' => $s1->id, 'unit_price' => 1100, 'verified_at' => now()]);
    SupplierQuote::create($q + ['supplier_id' => $s2->id, 'unit_price' => 1000, 'verified_at' => now()]);
    // Excluded: expired, and unverified.
    SupplierQuote::create(array_merge($q, ['supplier_id' => $s1->id, 'unit_price' => 500, 'quoted_on' => '2026-01-01', 'valid_until' => '2026-02-01', 'verified_at' => now()]));
    SupplierQuote::create(array_merge($q, ['supplier_id' => $s1->id, 'unit_price' => 400, 'quoted_on' => '2026-09-02']));

    $all = app(SupplierEvaluator::class)->forTeam($this->team->id);
    $e = $all[$s1->id];
    $c = collect($e['criteria'])->keyBy('key');

    // On-time 1/2 = 50, fulfillment 97/100 = 97 → 50×0.6 + 97×0.4 = 68.80.
    expect($c['delivery']['inputs']['completed_deliveries'])->toBe(2)
        ->and($c['delivery']['score'])->toEqual(68.8)
        ->and($c['price']['score'])->toEqual(90.91)
        ->and($c['price']['inputs']['excluded_expired'])->toBe(1)
        ->and($c['price']['inputs']['excluded_unverified'])->toBe(1)
        ->and($c['quality']['score'])->toEqual(97.0)
        // 27.52 + 31.82 + 24.25
        ->and($e['score'])->toEqual(83.59)
        ->and($e['classification'])->toBe('Very Good');

    // Beta only has a quote: no delivery or inspection records, so it is not classified.
    expect($all[$s2->id]['complete'])->toBeFalse()
        ->and($all[$s2->id]['classification'])->toBeNull()
        ->and(collect($all[$s2->id]['criteria'])->firstWhere('key', 'price')['score'])->toEqual(100.0)
        ->and(collect($all[$s2->id]['criteria'])->firstWhere('key', 'quality')['score'])->toBeNull();
});

test('the manual supplier scoring endpoint no longer exists', function () {
    $device = createTrustedDeviceFor($this->admin);
    $this->actingAs($this->admin)->withCookies($device['cookies'])
        ->post("/{$this->team->slug}/suppliers/ahp", ['criteria_weights' => ['price' => 1, 'quality' => 1, 'delivery' => 1]])
        ->assertStatus(405);
});

test('inspection derives the result from quantities and must account for every unit received', function () {
    $device = createTrustedDeviceFor($this->admin);
    $s = evalSupplier($this->team, 'Gamma');
    $d = Delivery::create(['team_id' => $this->team->id, 'delivery_date' => '2026-10-10', 'delivery_number' => 'D-9', 'quantity' => 10, 'unit' => 'pcs', 'description' => 'X', 'supplier_id' => $s->id, 'status' => 'Delivered']);
    $url = "/{$this->team->slug}/deliveries/{$d->id}/inspection";
    $as = fn () => $this->actingAs($this->admin)->withCookies($device['cookies']);

    $as()->post($url, ['accepted_quantity' => 8, 'rejected_quantity' => 1, 'inspected_on' => '2026-10-11', 'rejection_category' => 'damaged', 'rejection_reason' => 'Cracked'])->assertSessionHasErrors('accepted_quantity');
    $as()->post($url, ['accepted_quantity' => 8, 'rejected_quantity' => 2, 'inspected_on' => '2026-10-11'])->assertSessionHasErrors('rejection_reason');
    $as()->post($url, ['accepted_quantity' => 8, 'rejected_quantity' => 2, 'inspected_on' => '2026-10-11', 'rejection_category' => 'damaged', 'rejection_reason' => 'Cracked casing'])->assertSessionHasNoErrors();

    $d->refresh();
    expect($d->inspection_result)->toBe('partially_accepted')
        ->and($d->accepted_quantity)->toBe(8)
        ->and($d->inspected_by_id)->toBe($this->admin->id);
});

test('quotes need an administrator to verify them', function () {
    $staff = User::factory()->create(['role' => 'staff_suppliers', 'current_team_id' => $this->team->id, 'password_changed_at' => now()]);
    $this->team->members()->attach($staff, ['role' => TeamRole::Member->value]);
    $device = createTrustedDeviceFor($staff);
    $p = evalProduct($this->team, 'Q', 1);
    $s = evalSupplier($this->team, 'Delta');

    $this->actingAs($staff)->withCookies($device['cookies'])
        ->post("/{$this->team->slug}/suppliers/{$s->id}/quotes", ['product_id' => $p->id, 'unit_price' => 12.5, 'currency' => 'USD', 'unit' => 'PCS', 'tax_inclusive' => false, 'quoted_on' => '2026-10-01'])
        ->assertSessionHasNoErrors();
    $quote = SupplierQuote::firstOrFail();
    expect($quote->unit)->toBe('pcs')->and($quote->verified_at)->toBeNull();

    $this->actingAs($staff)->withCookies($device['cookies'])->post("/{$this->team->slug}/supplier-quotes/{$quote->id}/verify")->assertForbidden();

    $adminDevice = createTrustedDeviceFor($this->admin);
    $this->actingAs($this->admin)->withCookies($adminDevice['cookies'])->post("/{$this->team->slug}/supplier-quotes/{$quote->id}/verify")->assertSessionHasNoErrors();
    expect($quote->fresh()->verified_by_id)->toBe($this->admin->id);
});

test('inventory and supplier pages receive the backend evaluations', function () {
    $device = createTrustedDeviceFor($this->admin);
    evalProduct($this->team, 'PG', 3);
    evalSupplier($this->team, 'Epsilon');

    $this->actingAs($this->admin)->withCookies($device['cookies'])->get("/{$this->team->slug}/inventory")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('priorities', 1)->where('priorities.0.complete', false));

    $this->actingAs($this->admin)->withCookies($device['cookies'])->get("/{$this->team->slug}/suppliers")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('suppliers.data', 1)->where('suppliers.data.0.evaluation.complete', false)->where('weights.delivery', 0.4));
});

test('reorder recommendation comes from the reorder level and the SMA forecast', function () {
    $a = evalProduct($this->team, 'RA', 20);
    $a->forceFill(['reorder_level' => 5, 'reorder_quantity' => 15])->saveQuietly();
    foreach (['2026-07-05', '2026-08-05', '2026-09-05'] as $d) {
        sold($this->team, $a, $d, 30);
    }
    $b = evalProduct($this->team, 'RB', 100);
    $b->forceFill(['reorder_level' => 5, 'reorder_quantity' => 15])->saveQuietly();

    $r = app(ProductPrioritizer::class)->forTeam($this->team->id);

    // A: available 20 < forecast 30, shortfall 10, so max(15, 10) = 15.
    expect($r[$a->id]['reorder']['needed'])->toBeTrue()
        ->and($r[$a->id]['reorder']['shortfall'])->toBe(10)
        ->and($r[$a->id]['reorder']['suggested_quantity'])->toBe(15);
    // B: no sales history and stock above the reorder level: no reorder, no invented forecast.
    expect($r[$b->id]['reorder']['needed'])->toBeFalse()
        ->and($r[$b->id]['reorder']['forecast'])->toBeNull();
});
