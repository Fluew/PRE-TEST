<?php

use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\TeamRole;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->team = Team::factory()->create();
});

function paymentActor(string $role, Team $team): array
{
    $user = User::factory()->create([
        'role' => $role,
        'current_team_id' => $team->id,
        'password_changed_at' => now(),
    ]);
    $team->members()->attach($user, ['role' => $role === 'admin' ? TeamRole::Owner->value : TeamRole::Member->value]);
    $deviceData = createTrustedDeviceFor($user);

    return [$user, $deviceData['cookies']];
}

it('flags sale with proof of payment for staff verification and tracks balance', function () {
    [$user, $cookies] = paymentActor('admin', $this->team);

    $product = Product::create([
        'team_id' => $this->team->id,
        'name' => 'Schneider Circuit Breaker 30A',
        'sku' => 'SCB-30A-TEST',
        'category' => 'Breakers',
        'price' => 500.00,
        'cost_price' => 350.00,
        'stock' => 20,
        'status' => 'In Stock',
        'is_active' => true,
    ]);

    $proofFile = UploadedFile::fake()->image('gcash_receipt.png', 400, 400);

    $response = $this->actingAs($user)->withCookies($cookies)->post("/{$this->team->slug}/sales", [
        'customer' => 'Mega Builders Inc',
        'payment_method' => 'GCash',
        'payment_reference' => 'GCASH-99887766',
        'amount_paid' => 1000.00,
        'payment_date' => now()->toDateString(),
        'proof_of_payment' => $proofFile,
        'items' => [
            ['product_id' => $product->id, 'qty' => 2],
        ],
    ]);

    $response->assertSessionHasNoErrors();

    // Attached proof means the payment waits for verification: nothing counts as paid yet.
    $payment = Payment::where('submitted_reference', 'GCASH-99887766')->sole();
    $sale = $payment->sale;
    expect($payment->status)->toBe(PaymentRecordStatus::Submitted);
    expect($sale->payment_status)->toBe(PaymentStatus::PaymentSubmitted);
    expect($sale->status)->toBe(SalesOrderStatus::Pending);
    expect((float) $sale->amount_paid)->toBe(0.00);
    expect((float) $sale->outstanding_balance)->toBe(1000.00);
    Storage::disk('local')->assertExists($payment->proof_path);
});

it('allows authorized staff to verify customer payment', function () {
    [$user, $cookies] = paymentActor('admin', $this->team);
    $product = Product::create(['team_id' => $this->team->id, 'name' => 'Panel Board', 'sku' => 'PB-1', 'price' => 1000, 'stock' => 5, 'is_active' => true]);

    $this->actingAs($user)->withCookies($cookies)->post("/{$this->team->slug}/sales", [
        'customer' => 'Titan Construction', 'payment_method' => 'Bank Transfer', 'payment_reference' => 'BDO-55443322',
        'amount_paid' => 2000, 'payment_date' => now()->toDateString(),
        'proof_of_payment' => UploadedFile::fake()->image('deposit.png'),
        'items' => [['product_id' => $product->id, 'qty' => 2]],
    ])->assertSessionHasNoErrors();
    $payment = Payment::sole();

    $this->actingAs($user)->withCookies($cookies)->post("/{$this->team->slug}/sales/{$payment->sale_id}/payments/{$payment->id}/verify", [
        'reference' => 'BDO-55443322', 'amount' => 2000, 'payment_date' => now()->toDateString(),
        'remarks' => 'Confirmed matching deposit on BDO online portal.',
    ])->assertSessionHasNoErrors();

    $sale = Sale::find($payment->sale_id);
    expect($sale->payment_status)->toBe(PaymentStatus::Paid);
    expect($sale->status)->toBe(SalesOrderStatus::Completed); // counter sale: handed over when paid
    expect($sale->verified_by_id)->toBe($user->id);
    expect($sale->verified_at)->not->toBeNull();
    expect($payment->fresh()->receipt)->not->toBeNull();
});
