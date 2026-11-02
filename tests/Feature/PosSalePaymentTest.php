<?php

use App\Enums\PaymentRecordStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\TeamRole;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('pos sale requires customer, bank transfer or gcash payment method, reference, amount, and date', function () {
    $team = Team::factory()->create(['slug' => 'test-pos-team']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $product = Product::create([
        'team_id' => $team->id,
        'sku' => 'SKU-POS-001',
        'name' => 'High Output Alternator',
        'price' => 5000.00,
        'stock' => 10,
        'is_active' => true,
    ]);

    // Missing customer and payment details
    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/sales", [
            'items' => [
                ['product_id' => $product->id, 'qty' => 1],
            ],
        ]);

    $response->assertSessionHasErrors(['customer', 'payment_method', 'payment_reference', 'amount_paid', 'payment_date']);
});

test('pos sale rejects invalid payment method like Cash or Check', function () {
    $team = Team::factory()->create(['slug' => 'test-pos-team-2']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $product = Product::create([
        'team_id' => $team->id,
        'sku' => 'SKU-POS-002',
        'name' => 'Starter Motor',
        'price' => 3500.00,
        'stock' => 5,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/sales", [
            'customer' => 'Apex Logistics Corp',
            'payment_method' => 'Cash',
            'payment_reference' => 'REF-12345',
            'amount_paid' => 3500.00,
            'payment_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'qty' => 1],
            ],
        ]);

    $response->assertSessionHasErrors(['payment_method']);
});

test('pos records sale with bank transfer details and deducts inventory', function () {
    $team = Team::factory()->create(['slug' => 'test-pos-team-3']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $product = Product::create([
        'team_id' => $team->id,
        'sku' => 'SKU-POS-003',
        'name' => 'Heavy Duty Battery 12V',
        'price' => 4500.00,
        'stock' => 8,
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/sales", [
            'customer' => 'Metro Fleets Inc',
            'payment_method' => 'Bank Transfer',
            'payment_reference' => 'BDO-TRX-987654321',
            'amount_paid' => 9000.00,
            'payment_date' => now()->toDateString(),
            'notes' => 'Direct BDO deposit verified',
            'items' => [
                ['product_id' => $product->id, 'qty' => 2],
            ],
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $this->assertDatabaseHas('sales', [
        'team_id' => $team->id,
        'client_name' => 'Metro Fleets Inc',
        'payment_method' => 'Bank Transfer',
        'payment_reference' => 'BDO-TRX-987654321',
        'amount_paid' => 9000.00,
        'grand_total' => 9000.00,
        'status' => 'completed',
        'payment_status' => 'paid',
    ]);

    expect($product->fresh()->stock)->toBe(6);
});

test('pos records sale with gcash details and optional proof of payment file', function () {
    $team = Team::factory()->create(['slug' => 'test-pos-team-4']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $product = Product::create([
        'team_id' => $team->id,
        'sku' => 'SKU-POS-004',
        'name' => 'LED Light Bar 40 Inch',
        'price' => 2800.00,
        'stock' => 15,
        'is_active' => true,
    ]);

    Storage::fake('local');
    Storage::fake('public');
    $proofFile = UploadedFile::fake()->image('gcash_receipt.png', 600, 800);

    $response = $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/sales", [
            'customer' => 'Juan Dela Cruz Transport',
            'payment_method' => 'GCash',
            'payment_reference' => 'GCASH-REF-1002348912',
            'amount_paid' => 2800.00,
            'payment_date' => now()->toDateString(),
            'proof_of_payment' => $proofFile,
            'items' => [
                ['product_id' => $product->id, 'qty' => 1],
            ],
        ]);

    $response->assertSessionHasNoErrors();

    $sale = Sale::where('team_id', $team->id)->where('client_name', 'Juan Dela Cruz Transport')->first();
    expect($sale)->not->toBeNull();
    expect($sale->payment_method)->toBe('GCash');
    expect($sale->payment_reference)->toBe('GCASH-REF-1002348912');
    $payment = $sale->payments()->sole();
    expect($payment->proof_path)->not->toBeNull()
        // Proof alone verifies nothing: the payment waits for staff.
        ->and($payment->status)->toBe(PaymentRecordStatus::Submitted)
        ->and($sale->status)->toBe(SalesOrderStatus::Pending);

    // Stored privately, not on the public disk.
    Storage::disk('local')->assertExists($payment->proof_path);
    Storage::disk('public')->assertMissing($payment->proof_path);
    $url = "/{$team->slug}/sales/{$sale->id}/payments/{$payment->id}/proof";

    // The owner's team can view it through the authenticated route.
    $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->get($url)
        ->assertOk();

    // Guests can't.
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
});

test('pos rejects a payment smaller than the total and leaves stock untouched', function () {
    $team = Team::factory()->create(['slug' => 'test-pos-team-5']);
    $user = User::factory()->create([
        'role' => 'admin',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $deviceData = createTrustedDeviceFor($user);

    $product = Product::create([
        'team_id' => $team->id,
        'sku' => 'SKU-POS-005',
        'name' => 'Circuit Breaker 60A',
        'price' => 50000.00,
        'stock' => 3,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->withCookies($deviceData['cookies'])
        ->post("/{$team->slug}/sales", [
            'customer' => 'Underpaying Customer',
            'payment_method' => 'GCash',
            'payment_reference' => 'GCASH-LOW-1',
            'amount_paid' => 1.00,
            'payment_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'qty' => 1]],
        ])
        ->assertSessionHasErrors('amount_paid');

    expect(Sale::where('team_id', $team->id)->count())->toBe(0);
    expect($product->fresh()->stock)->toBe(3);
});

test('sales staff cannot void a sale; only admins can delete', function () {
    $team = Team::factory()->create(['slug' => 'test-pos-team-6']);
    $staff = User::factory()->create([
        'role' => 'staff_sales',
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($staff, ['role' => TeamRole::Member->value]);
    $deviceData = createTrustedDeviceFor($staff);

    $sale = Sale::create([
        'team_id' => $team->id,
        'invoice_number' => 'INV-TEST-1',
        'client_name' => 'Someone',
        'sale_date' => now(),
        'subtotal' => 100,
        'discount' => 0,
        'tax_amount' => 0,
        'grand_total' => 100,
        'status' => 'completed',
    ]);

    $this->actingAs($staff)
        ->withCookies($deviceData['cookies'])
        ->delete("/{$team->slug}/sales/{$sale->id}")
        ->assertForbidden();

    expect(Sale::find($sale->id))->not->toBeNull();
});
