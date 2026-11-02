<?php

use App\Enums\EmailStatus;
use App\Enums\QuotationStatus;
use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\NotificationLog;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Team;
use App\Models\User;
use App\Services\Delivery\FulfillmentService;
use App\Services\Notifications\CustomerNotifier;
use App\Services\Quotations\QuotationWorkflow;
use App\Services\ResendService;
use App\Services\Sales\PaymentService;
use App\Services\Sales\SalesOrderService;
use Illuminate\Support\Facades\Storage;

/**
 * Customer emails, one per business event: quotation sent (Send Quotation, not approval), order
 * confirmed (with the sales invoice), out for delivery. ResendService is mocked, so nothing
 * leaves the machine; we record what would be sent.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->sent = collect();
    $this->sendOk = true;
    $this->mock(ResendService::class)
        ->shouldReceive('sendCustomerEmail')
        ->andReturnUsing(function ($to, $subject, $html, $kind, $attachments = []) {
            $this->sent->push(compact('to', 'subject', 'html', 'kind', 'attachments'));

            return $this->sendOk
                ? ['success' => true, 'id' => 'test-id', 'error' => null]
                : ['success' => false, 'id' => null, 'error' => 'Resend API error (403): not allowed'];
        });

    $this->team = Team::factory()->create(['slug' => 'mail-team']);
    $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'password_changed_at' => now(), 'current_team_id' => $this->team->id]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Owner->value]);
});

function mailQuotation(Team $team, array $overrides = []): Quotation
{
    $product = Product::create(['team_id' => $team->id, 'sku' => 'BRK-60A', 'name' => 'Circuit Breaker 60A', 'price' => 1000, 'stock' => 10, 'is_active' => true]);
    $quotation = Quotation::create($overrides + [
        'team_id' => $team->id,
        'quotation_number' => 'QT-2026-0001',
        'client_name' => 'Apex Builders',
        'contact_person' => 'Ana Reyes',
        'email' => 'ana@apex.example',
        'address' => '12 Rizal St, Manila',
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'subtotal' => 2000, 'discount' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'grand_total' => 2000,
        'status' => QuotationStatus::ForApproval,
    ]);
    $quotation->items()->create([
        'product_id' => $product->id, 'sku' => 'BRK-60A', 'product_name' => 'Circuit Breaker 60A', 'quantity' => 2, 'unit_price' => 1000, 'discount' => 0, 'subtotal' => 2000,
    ]);

    return $quotation;
}

// --- Quotation -----------------------------------------------------------------------------

it('emails the quotation PDF to the customer as soon as an administrator approves it', function () {
    $quotation = mailQuotation($this->team);

    $this->actingAs($this->admin)->withCookies(createTrustedDeviceFor($this->admin)['cookies'])
        ->post("/mail-team/quotations/{$quotation->id}/approve")
        ->assertSessionHasNoErrors();

    expect($this->sent)->toHaveCount(1);
    $mail = $this->sent->first();
    expect($mail['to'])->toBe('ana@apex.example')
        ->and($mail['subject'])->toContain('QT-2026-0001')
        ->and($mail['html'])->toContain('Circuit Breaker 60A')->toContain('$2,000.00')->toContain('Ana Reyes')
        ->and($mail['attachments'][0]['filename'])->toBe('Quotation-QT-2026-0001.pdf')
        ->and($mail['attachments'][0]['content'])->toStartWith('%PDF');
    expect(AuditLog::where('event', 'email.quotation_sent')->exists())->toBeTrue();
});

it('falls back to the linked client email when the quotation has none', function () {
    $client = Client::create(['team_id' => $this->team->id, 'company_name' => 'Apex Builders', 'email' => 'buyer@apex.example']);
    $quotation = mailQuotation($this->team, ['email' => null, 'client_id' => $client->id, 'status' => QuotationStatus::ForApproval, 'approved_at' => now()]);

    app(QuotationWorkflow::class)->send($quotation, $this->admin);

    expect($this->sent->first()['to'])->toBe('buyer@apex.example');
});

it('records a failed send without undoing the sending step', function () {
    $this->sendOk = false;
    $quotation = mailQuotation($this->team, ['status' => QuotationStatus::ForApproval, 'approved_at' => now()]);

    app(QuotationWorkflow::class)->send($quotation, $this->admin);

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Sent)
        ->and($quotation->fresh()->email_status)->toBe(EmailStatus::Failed);
    $log = AuditLog::where('event', 'email.quotation_sent')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->description)->toContain('failed')
        ->and(NotificationLog::sole()->error)->toContain('403');
});

it('escapes customer-entered text in the email', function () {
    $quotation = mailQuotation($this->team, ['contact_person' => '<script>alert(1)</script>', 'status' => QuotationStatus::ForApproval, 'approved_at' => now()]);
    app(QuotationWorkflow::class)->send($quotation, $this->admin);

    expect($this->sent->first()['html'])->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;');
});

// --- Order confirmed (with the invoice) and delivery notices --------------------------------

function mailConfirmedOrder(Team $team, User $admin, ?string $email = 'ana@apex.example'): Sale
{
    $quotation = mailQuotation($team, ['status' => QuotationStatus::Accepted, 'email' => $email]);
    [$sale] = app(SalesOrderService::class)->createFromQuotation($quotation, $admin);
    $payments = app(PaymentService::class);
    $payment = $payments->record($sale, ['method' => 'GCash', 'amount' => 2000, 'reference' => 'GC-123', 'payment_date' => now()->toDateString()], null, $admin);
    $payments->verify($payment, ['reference' => 'GC-123', 'amount' => 2000, 'payment_date' => now()->toDateString()], $admin);

    return $sale->fresh();
}

it('emails the confirmation with the sales invoice attached once the payment is verified', function () {
    $sale = mailConfirmedOrder($this->team, $this->admin);

    $confirmation = $this->sent->first(fn ($m) => str_contains($m['subject'], 'Order Confirmed'));
    expect($confirmation)->not->toBeNull()
        ->and($confirmation['to'])->toBe('ana@apex.example')
        ->and($confirmation['html'])->toContain($sale->invoice_number)
        ->and($confirmation['attachments'][0]['filename'])->toBe("Invoice-{$sale->invoice_number}.pdf");
    // Sales Order created, payment received (with receipt), order confirmed: no duplicates.
    expect($this->sent->pluck('subject')->all())->toHaveCount(3)
        ->and($this->sent->filter(fn ($m) => str_contains($m['subject'], 'Order Confirmed')))->toHaveCount(1);
});

it('skips customer emails when the order has no email address, and logs why', function () {
    mailConfirmedOrder($this->team, $this->admin, email: null);

    expect($this->sent)->toHaveCount(0)
        ->and(NotificationLog::where('status', 'skipped')->count())->toBeGreaterThan(0);
});

it('tells the customer when the order goes out for delivery, once per dispatch', function () {
    $sale = mailConfirmedOrder($this->team, $this->admin);
    $this->sent = collect();
    $service = app(FulfillmentService::class);

    $release = $service->prepareRelease($sale->fulfillment, [$sale->items->first()->id => 2], $this->admin);
    $service->markReady($release, $this->admin);
    $service->dispatch($release, $this->admin);

    $inTransit = $this->sent->filter(fn ($m) => str_contains($m['subject'], 'on the way'));
    expect($inTransit)->toHaveCount(1)
        ->and($inTransit->first()['html'])->toContain($release->release_number)->toContain('12 Rizal St, Manila');

    $service->recordReceipt($release, [], 'Ana Reyes', null, null, $this->admin);
    expect($this->sent->filter(fn ($m) => str_contains($m['subject'], 'on the way')))->toHaveCount(1)
        ->and($this->sent->pluck('subject')->filter(fn ($s) => str_contains($s, 'was delivered')))->toHaveCount(1)
        // Everything delivered and paid: the order completed itself.
        ->and($this->sent->last()['subject'])->toContain('Order Completed');
});

it('confirms a recorded acceptance to the customer and sends the Sales Order', function () {
    $quotation = mailQuotation($this->team, ['status' => QuotationStatus::Sent]);

    app(QuotationWorkflow::class)->recordDecision($quotation, true, 'phone', null, $this->admin);

    expect($this->sent->pluck('subject')->all())->toHaveCount(2)
        ->and($this->sent->first()['subject'])->toContain('Accepted')
        ->and($this->sent->last()['subject'])->toContain('Your Sales Order Has Been Created');
});

it('tells the customer when an order is cancelled, once', function () {
    $sale = mailConfirmedOrder($this->team, $this->admin);
    $this->sent = collect();

    app(SalesOrderService::class)->cancel($sale, 'Customer changed plans', $this->admin);

    expect($this->sent)->toHaveCount(1) // the order email only; no separate delivery email
        ->and($this->sent->first()['subject'])->toContain('Order Cancelled');
});

it('retries a failed email once it is fixed, without sending a duplicate', function () {
    $this->sendOk = false;
    $quotation = mailQuotation($this->team, ['status' => QuotationStatus::ForApproval, 'approved_at' => now()]);
    app(QuotationWorkflow::class)->send($quotation, $this->admin);
    $log = NotificationLog::sole();
    expect($log->status)->toBe(EmailStatus::Failed);

    $this->sendOk = true;
    app(CustomerNotifier::class)->retry($log);
    app(CustomerNotifier::class)->retry($log->fresh()); // already sent: nothing happens

    expect($log->fresh()->status)->toBe(EmailStatus::Sent)
        ->and($quotation->fresh()->email_status)->toBe(EmailStatus::Sent)
        ->and(NotificationLog::count())->toBe(1)
        ->and($this->sent->where('to', 'ana@apex.example'))->toHaveCount(2); // one failed try, one success
});
