<?php

use App\Enums\TeamRole;
use App\Models\CommercialInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('procurement index loads commercial invoices and purchase orders', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    CommercialInvoice::create([
        'team_id' => $team->id,
        'invoice_date' => '2026-10-25',
        'invoice_number' => 'CI-035-0722',
        'quantity' => 50,
        'description' => 'Heavy Duty Electrical Conduit 20mm',
        'unit_cost' => 120.00,
        'total_cost' => 6000.00,
        'status' => 'Received',
    ]);

    PurchaseOrder::create([
        'team_id' => $team->id,
        'po_date' => '2026-10-25',
        'po_number' => '4000005472',
        'quantity' => 100,
        'item_sku' => '068SSCR2',
        'description' => '0.68mm Stainless Steel Conduit Rod 2m',
        'unit_of_measure' => 'PCS',
        'unit_cost' => 450.00,
        'total_cost' => 45000.00,
        'status' => 'Issued',
    ]);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get("/{$team->slug}/procurement");

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('Procurement/Index')
        ->has('commercialInvoices.data', 1)
        ->where('commercialInvoices.data.0.invoice_number', 'CI-035-0722')
        ->has('purchaseOrders.data', 1)
        ->where('purchaseOrders.data.0.po_number', '4000005472')
        ->has('metrics')
        ->where('metrics.totalCommercialInvoices', 1)
        ->where('metrics.totalPurchaseOrders', 1)
    );
});

test('an invoice cannot be recorded without its purchase order and delivery', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    // Invoices are now recorded only against their purchase order and inbound delivery.
    $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/procurement/commercial-invoices", [
            'invoice_date' => '2026-10-25',
            'invoice_number' => 'CI-035-0722',
            'quantity' => 20,
            'unit_cost' => 95.50,
        ])
        ->assertSessionHasErrors(['purchase_order_id', 'delivery_id']);
    $this->assertDatabaseCount('commercial_invoices', 0);
});

test('a purchase order cannot be created without an approved purchase request', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    // Purchase orders are created only from an approved purchase request now.
    $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/procurement/purchase-orders", [
            'po_date' => '2026-10-25',
            'po_number' => '4000005472',
            'quantity' => 50,
            'description' => '0.68mm Stainless Steel Conduit Rod 2m',
            'unit_cost' => 450.00,
        ])
        ->assertNotFound();
    $this->assertDatabaseCount('purchase_orders', 0);
});

test('can import commercial invoices csv with required headers', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $csvContent = implode("\n", [
        'DATE,COMMERCIAL INVOICE,QUANTITY,Description,UNIT COST',
        '10-25-2026,CI-035-0722,50,"Heavy Duty Electrical Conduit 20mm",120.00',
        '10-25-2026,CI-036-0722,15,"Industrial Circuit Breaker 3-Phase 100A",1450.00',
    ]);

    $file = UploadedFile::fake()->createWithContent('commercial_invoices.csv', $csvContent);

    // Validate preview
    $previewResponse = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/procurement/commercial-invoices/import-csv", [
            'file' => $file,
            'execute' => false,
        ], [
            'Accept' => 'application/json',
        ]);

    $previewResponse->assertSuccessful();
    $previewData = $previewResponse->json();

    expect($previewData['success'])->toBeTrue()
        ->and($previewData['summary']['valid_count'])->toBe(2)
        ->and($previewData['valid_rows'][0]['invoice_number'])->toBe('CI-035-0722')
        ->and($previewData['valid_rows'][0]['quantity'])->toBe(50)
        ->and($previewData['valid_rows'][0]['unit_cost'])->toEqual(120.0)
        ->and($previewData['valid_rows'][0]['total_cost'])->toEqual(6000.0);

    // Execute import
    $executeResponse = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/procurement/commercial-invoices/import-csv", [
            'file' => $file,
            'execute' => true,
        ], [
            'Accept' => 'application/json',
        ]);

    $executeResponse->assertSuccessful();
    $this->assertDatabaseHas('commercial_invoices', [
        'team_id' => $team->id,
        'invoice_number' => 'CI-035-0722',
        'quantity' => 50,
        'unit_cost' => 120.00,
        'total_cost' => 6000.00,
    ]);
    $this->assertDatabaseHas('commercial_invoices', [
        'team_id' => $team->id,
        'invoice_number' => 'CI-036-0722',
        'quantity' => 15,
        'unit_cost' => 1450.00,
        'total_cost' => 21750.00,
    ]);
});

test('can import purchase orders csv with required headers', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $csvContent = implode("\n", [
        'Date,Purchase Order No.,Quantity,ITEM/SKU Number,Description,U/M,Unit Cost',
        '10-25-2026,4000005472,100,068SSCR2,"0.68mm Stainless Steel Conduit Rod 2m",PCS,450.00',
        '10-25-2026,4000005473,40,BRK-40A,"Circuit Breaker 2P 40A Rail Mounted",BOX,320.00',
    ]);

    $file = UploadedFile::fake()->createWithContent('purchase_orders.csv', $csvContent);

    // Validate preview
    $previewResponse = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/procurement/purchase-orders/import-csv", [
            'file' => $file,
            'execute' => false,
        ], [
            'Accept' => 'application/json',
        ]);

    $previewResponse->assertSuccessful();
    $previewData = $previewResponse->json();

    expect($previewData['success'])->toBeTrue()
        ->and($previewData['summary']['valid_count'])->toBe(2)
        ->and($previewData['valid_rows'][0]['po_number'])->toBe('4000005472')
        ->and($previewData['valid_rows'][0]['quantity'])->toBe(100)
        ->and($previewData['valid_rows'][0]['item_sku'])->toBe('068SSCR2')
        ->and($previewData['valid_rows'][0]['unit_of_measure'])->toBe('PCS')
        ->and($previewData['valid_rows'][0]['unit_cost'])->toEqual(450.0)
        ->and($previewData['valid_rows'][0]['total_cost'])->toEqual(45000.0);

    // Execute import
    $executeResponse = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/procurement/purchase-orders/import-csv", [
            'file' => $file,
            'execute' => true,
        ], [
            'Accept' => 'application/json',
        ]);

    $executeResponse->assertSuccessful();
    $this->assertDatabaseHas('purchase_orders', [
        'team_id' => $team->id,
        'po_number' => '4000005472',
        'quantity' => 100,
        'item_sku' => '068SSCR2',
        'unit_of_measure' => 'PCS',
        'unit_cost' => 450.00,
        'total_cost' => 45000.00,
    ]);
});

test('can delete commercial invoice and purchase order', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $ci = CommercialInvoice::create([
        'team_id' => $team->id,
        'invoice_date' => '2026-10-25',
        'invoice_number' => 'CI-035-0722',
        'quantity' => 10,
        'description' => 'Test CI',
        'unit_cost' => 100.00,
        'total_cost' => 1000.00,
    ]);

    $po = PurchaseOrder::create([
        'team_id' => $team->id,
        'po_date' => '2026-10-25',
        'po_number' => '4000005472',
        'quantity' => 10,
        'description' => 'Test PO',
        'unit_cost' => 100.00,
        'total_cost' => 1000.00,
    ]);

    $ciDelete = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->delete("/{$team->slug}/procurement/commercial-invoices/{$ci->id}");

    $ciDelete->assertSessionHasNoErrors();
    $this->assertSoftDeleted('commercial_invoices', ['id' => $ci->id]);

    $poDelete = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->delete("/{$team->slug}/procurement/purchase-orders/{$po->id}");

    $poDelete->assertSessionHasNoErrors();
    $this->assertSoftDeleted('purchase_orders', ['id' => $po->id]);
});

test('can filter commercial invoices, purchase orders, and purchase requests', function () {
    $team = Team::factory()->create(['slug' => 'test-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $supplierA = Supplier::create([
        'team_id' => $team->id,
        'name' => 'Supplier Alpha',
        'status' => 'Active',
    ]);
    $supplierB = Supplier::create([
        'team_id' => $team->id,
        'name' => 'Supplier Beta',
        'status' => 'Active',
    ]);

    CommercialInvoice::create([
        'team_id' => $team->id,
        'invoice_date' => '2026-10-25',
        'invoice_number' => 'CI-001-1026',
        'quantity' => 10,
        'description' => 'Item 1',
        'unit_cost' => 100,
        'total_cost' => 1000,
        'status' => 'Received',
        'supplier_id' => $supplierA->id,
    ]);

    CommercialInvoice::create([
        'team_id' => $team->id,
        'invoice_date' => '2026-10-25',
        'invoice_number' => 'CI-002-1026',
        'quantity' => 5,
        'description' => 'Item 2',
        'unit_cost' => 50,
        'total_cost' => 250,
        'status' => 'Pending',
        'supplier_id' => $supplierB->id,
    ]);

    PurchaseOrder::create([
        'team_id' => $team->id,
        'po_date' => '2026-10-25',
        'po_number' => '4000001001',
        'quantity' => 20,
        'description' => 'Cable Box',
        'unit_of_measure' => 'BOX',
        'unit_cost' => 500,
        'total_cost' => 10000,
        'status' => 'Issued',
        'supplier_id' => $supplierA->id,
    ]);

    PurchaseOrder::create([
        'team_id' => $team->id,
        'po_date' => '2026-10-25',
        'po_number' => '4000001002',
        'quantity' => 100,
        'description' => 'Screws',
        'unit_of_measure' => 'PCS',
        'unit_cost' => 2,
        'total_cost' => 200,
        'status' => 'Received',
        'supplier_id' => $supplierB->id,
    ]);

    // Test filter CI by status=Received
    $responseCi = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get("/{$team->slug}/procurement?tab=commercial_invoices&status=Received");

    $responseCi->assertSuccessful();
    $responseCi->assertInertia(fn ($page) => $page
        ->where('commercialInvoices.total', 1)
        ->where('commercialInvoices.data.0.invoice_number', 'CI-001-1026')
    );

    // Test filter PO by unit_of_measure=BOX
    $responsePo = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get("/{$team->slug}/procurement?tab=purchase_orders&unit_of_measure=BOX");

    $responsePo->assertSuccessful();
    $responsePo->assertInertia(fn ($page) => $page
        ->where('purchaseOrders.total', 1)
        ->where('purchaseOrders.data.0.po_number', '4000001001')
    );

    // Test filter by supplier_id
    $responseSupplier = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get("/{$team->slug}/procurement?supplier_id={$supplierB->id}");

    $responseSupplier->assertSuccessful();
    $responseSupplier->assertInertia(fn ($page) => $page
        ->where('commercialInvoices.total', 1)
        ->where('commercialInvoices.data.0.invoice_number', 'CI-002-1026')
        ->where('purchaseOrders.total', 1)
        ->where('purchaseOrders.data.0.po_number', '4000001002')
    );
});
