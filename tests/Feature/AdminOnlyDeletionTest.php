<?php

use App\Enums\QuotationStatus;
use App\Enums\TeamRole;
use App\Http\Middleware\EnsureAdminForDeletion;
use App\Models\AuditLog;
use App\Models\CommercialInvoice;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Each case: [module role, model class, factory closure(team) => record, path closure(record) => url suffix]
 */
dataset('deletable modules', [
    'inventory product' => [
        'staff_inventory',
        Product::class,
        fn (Team $t) => Product::create(['team_id' => $t->id, 'name' => 'Relay', 'sku' => 'REL-1', 'category' => 'Relays', 'price' => 10, 'stock' => 5, 'is_active' => true]),
        fn ($r) => "inventory/products/{$r->id}",
    ],
    'supplier' => [
        'staff_suppliers',
        Supplier::class,
        fn (Team $t) => Supplier::create(['team_id' => $t->id, 'name' => 'Acme Supply', 'email' => 'a@example.com', 'status' => 'Active']),
        fn ($r) => "suppliers/{$r->id}",
    ],
    'quotation' => [
        'staff_quotations',
        Quotation::class,
        fn (Team $t) => Quotation::create([
            'team_id' => $t->id, 'quotation_number' => 'Q-DEL-1', 'client_name' => 'Client Co',
            'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
            'status' => QuotationStatus::Pending,
        ]),
        fn ($r) => "quotations/{$r->id}",
    ],
    'purchase request' => [
        'staff_procurement',
        PurchaseRequest::class,
        fn (Team $t) => PurchaseRequest::create([
            'team_id' => $t->id, 'request_number' => 'PR-DEL-1', 'quantity' => 5,
            'estimated_cost' => 100, 'status' => 'Pending Approval', 'requested_at' => now(),
        ]),
        fn ($r) => "procurement/{$r->id}",
    ],
    'commercial invoice' => [
        'staff_procurement',
        CommercialInvoice::class,
        fn (Team $t) => CommercialInvoice::create([
            'team_id' => $t->id, 'invoice_date' => '2026-10-25', 'invoice_number' => 'CI-DEL-1',
            'quantity' => 10, 'description' => 'Test CI', 'unit_cost' => 100, 'total_cost' => 1000,
        ]),
        fn ($r) => "procurement/commercial-invoices/{$r->id}",
    ],
    'purchase order' => [
        'staff_procurement',
        PurchaseOrder::class,
        fn (Team $t) => PurchaseOrder::create([
            'team_id' => $t->id, 'po_date' => '2026-10-25', 'po_number' => 'PO-DEL-1',
            'quantity' => 10, 'description' => 'Test PO', 'unit_cost' => 100, 'total_cost' => 1000,
        ]),
        fn ($r) => "procurement/purchase-orders/{$r->id}",
    ],
    'delivery' => [
        'staff_deliveries',
        Delivery::class,
        fn (Team $t) => Delivery::create([
            'team_id' => $t->id, 'delivery_date' => '2026-10-25', 'delivery_number' => 'DEL-DEL-1',
            'purchase_order_number' => '4000000001', 'quantity' => 1, 'unit' => 'PCS',
            'description' => 'Test delivery', 'status' => 'Delivered',
        ]),
        fn ($r) => "deliveries/{$r->id}",
    ],
    'sale' => [
        'staff_sales', Sale::class,
        fn (Team $t) => Sale::create([
            'team_id' => $t->id, 'invoice_number' => 'INV-DEL-1', 'client_name' => 'Someone',
            'sale_date' => now(), 'subtotal' => 100, 'discount' => 0, 'tax_amount' => 0,
            'grand_total' => 100, 'status' => 'cancelled', // only cancelled orders can be archived
        ]),
        fn ($r) => "sales/{$r->id}",
    ],
]);

function deletionActor(string $role, Team $team): array
{
    $user = User::factory()->create([
        'role' => $role,
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);

    return [$user, createTrustedDeviceFor($user)['cookies']];
}

it('rejects staff DELETE requests with 403 and leaves the record untouched', function (string $role, string $model, Closure $make, Closure $path) {
    $team = Team::factory()->create(['slug' => 'del-team']);
    [$staff, $cookies] = deletionActor($role, $team);
    $record = $make($team);

    $this->actingAs($staff)->withCookies($cookies)
        ->delete("/{$team->slug}/".$path($record))
        ->assertForbidden();

    expect($model::withTrashed()->find($record->id))->not->toBeNull()
        ->and($model::find($record->id))->not->toBeNull();
    expect(AuditLog::where('event', 'auth.unauthorized_delete')->exists())->toBeTrue();
})->with('deletable modules');

it('returns a JSON 403 from the middleware for non-admins and 401 for guests', function () {
    $middleware = new EnsureAdminForDeletion;
    $next = fn ($r) => response('ok');

    $staffRequest = Request::create('/x/suppliers/1', 'DELETE', server: ['HTTP_ACCEPT' => 'application/json']);
    $staffRequest->setUserResolver(fn () => User::factory()->make(['role' => 'staff_suppliers']));
    $forbidden = $middleware->handle($staffRequest, $next);
    expect($forbidden->getStatusCode())->toBe(403)
        ->and(json_decode($forbidden->getContent(), true)['message'])->toBe('Only administrators can delete records.');

    $guestRequest = Request::create('/x/suppliers/1', 'DELETE', server: ['HTTP_ACCEPT' => 'application/json']);
    $guestRequest->setUserResolver(fn () => null);
    expect($middleware->handle($guestRequest, $next)->getStatusCode())->toBe(401);

    $adminRequest = Request::create('/x/suppliers/1', 'DELETE');
    $adminRequest->setUserResolver(fn () => User::factory()->make(['role' => 'admin']));
    expect($middleware->handle($adminRequest, $next)->getContent())->toBe('ok');
});

it('still lets administrators delete records', function (string $role, string $model, Closure $make, Closure $path) {
    $team = Team::factory()->create(['slug' => 'del-team']);
    [$admin, $cookies] = deletionActor('admin', $team);
    $record = $make($team);

    $this->actingAs($admin)->withCookies($cookies)
        ->delete("/{$team->slug}/".$path($record))
        ->assertSessionHasNoErrors()
        ->assertStatus(302);

    // Soft-deleted/archived records leave the default scope but remain recoverable.
    $fresh = $model::withTrashed()->find($record->id);
    expect($fresh)->not->toBeNull();
})->with('deletable modules');

it('keeps non-delete staff operations working (staff can still view their module)', function () {
    $team = Team::factory()->create(['slug' => 'del-team']);
    [$staff, $cookies] = deletionActor('staff_suppliers', $team);

    $this->actingAs($staff)->withCookies($cookies)
        ->get("/{$team->slug}/suppliers")
        ->assertOk();
});

it('blocks staff from the admin-only user deletion and recycle bin bulk delete routes', function () {
    $team = Team::factory()->create(['slug' => 'del-team']);
    [$staff, $cookies] = deletionActor('staff_inventory', $team);
    $victim = User::factory()->create(['role' => 'staff_sales']);
    $team->members()->attach($victim, ['role' => TeamRole::Member->value]);

    $this->actingAs($staff)->withCookies($cookies)
        ->delete("/{$team->slug}/users/{$victim->id}")
        ->assertForbidden(); // staff get 403 on admin-only areas

    $this->actingAs($staff)->withCookies($cookies)
        ->post("/{$team->slug}/recycle-bin/bulk-force-delete", ['items' => []])
        ->assertForbidden();

    expect(User::find($victim->id))->not->toBeNull();
});
