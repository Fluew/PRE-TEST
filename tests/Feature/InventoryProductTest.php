<?php

use App\Enums\TeamRole;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('can update an inventory product without type error', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $product = Product::create([
        'team_id' => $team->id,
        'name' => 'Original Circuit Breaker 63A',
        'sku' => 'CB-63A-001',
        'category' => 'Circuit Breakers',
        'unit' => 'pcs',
        'stock' => 50,
        'min_stock' => 10,
        'reorder_level' => 10,
        'reorder_quantity' => 20,
        'price' => 750.00,
        'cost_price' => 500.00,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->put("/{$team->slug}/inventory/products/{$product->id}", [
            'name' => 'Updated Circuit Breaker 63A 3P',
            'sku' => 'CB-63A-001',
            'category' => 'Circuit Breakers',
            'unit' => 'pcs',
            'price' => 850.00,
            'cost_price' => 550.00,
            'reorder_level' => 15,
            'reorder_quantity' => 30,
            'description' => 'Updated technical specs and industrial enclosure.',
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'name' => 'Updated Circuit Breaker 63A 3P',
        'price' => 850.00,
        'cost_price' => 550.00,
        'reorder_level' => 15,
    ]);
});

test('can delete or archive an inventory product without type error', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $product = Product::create([
        'team_id' => $team->id,
        'name' => 'Obsolete Switch',
        'sku' => 'OBS-SW-01',
        'category' => 'Switches',
        'price' => 100.00,
        'stock' => 0,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->delete("/{$team->slug}/inventory/products/{$product->id}");

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $this->assertSoftDeleted('products', [
        'id' => $product->id,
    ]);
});

test('can update a supplier without type error', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $supplier = Supplier::create([
        'team_id' => $team->id,
        'name' => 'Original Supplier Corp',
        'contact_person' => 'Jane Doe',
        'phone' => '09123456789',
        'email' => 'supplier@example.com',
        'status' => 'Active',
    ]);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->put("/{$team->slug}/suppliers/{$supplier->id}", [
            'name' => 'Updated Supplier Corp',
            'contact_person' => 'Jane Doe',
            'phone' => '09123456789',
            'email' => 'supplier@example.com',
            'status' => 'Active',
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $this->assertDatabaseHas('suppliers', [
        'id' => $supplier->id,
        'name' => 'Updated Supplier Corp',
    ]);
});

test('inventory index provides the backend ahp prioritization', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $supplier = Supplier::create([
        'team_id' => $team->id,
        'name' => 'Schneider Electric Philippines',
        'contact_person' => 'Juan Dela Cruz',
        'phone' => '09123456789',
        'email' => 'juan@schneider.ph',
        'status' => 'Active',
    ]);

    Product::create([
        'team_id' => $team->id,
        'name' => 'Molded Case Circuit Breaker 100A',
        'sku' => 'MCCB-100A',
        'category' => 'Circuit Breakers',
        'unit' => 'pcs',
        'stock' => 5,
        'min_stock' => 10,
        'reorder_level' => 20,
        'reorder_quantity' => 50,
        'price' => 3500.00,
        'cost_price' => 2200.00,
        'supplier_id' => $supplier->id,
        'status' => 'Low Stock',
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get("/{$team->slug}/inventory");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Inventory/Index')
        ->has('priorities', 1)
        ->where('priorities.0.product.sku', 'MCCB-100A')
        // No sales history and no verified supplier availability: reported as incomplete, not scored as 0.
        ->where('priorities.0.complete', false)
        ->where('priorities.0.classification', null)
        ->where('priorities.0.criteria.0.key', 'demand')
        ->where('priorities.0.criteria.0.weight', 0.4)
        ->where('priorities.0.criteria.0.available', false)
    );
});
