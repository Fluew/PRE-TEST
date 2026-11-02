<?php

namespace App\Services\Sales;

use App\Enums\PaymentStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentNumbers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Walk-in sales at the counter (the existing POS): the customer pays in full and takes the goods
 * right away, so stock is taken out when the sale is recorded.
 *
 * The cashier who takes the payment checks it at the counter, so a payment without attached
 * proof is verified by them on the spot (recorded as their verification). A payment with
 * proof attached waits for verification like any other payment; the order is confirmed and
 * completed when it is verified.
 */
class CounterSaleService
{
    public function __construct(private PaymentService $payments, private SalesOrderService $orders) {}

    /**
     * @param  array{customer: string, payment_method: string, payment_reference: string, amount_paid: float|string, payment_date: string, notes?: ?string, items: array<int, array{product_id: int, qty: int}>}  $data
     */
    public function record(int $teamId, array $data, ?UploadedFile $proof, User $user): Sale
    {
        return DB::transaction(function () use ($teamId, $data, $proof, $user) {
            $lines = [];
            $subtotal = 0.0;
            $products = Product::where('team_id', $teamId)->whereIn('id', collect($data['items'])->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($data['items'] as $item) {
                $product = $products[$item['product_id']] ?? throw new WorkflowException('A product in the cart no longer exists.', 'items');
                $qty = (int) $item['qty'];
                $available = $product->availableStock();
                if ($available < $qty) {
                    throw new WorkflowException("Insufficient stock for {$product->name}. Available: {$available}, requested: {$qty}.", 'items');
                }
                $line = round((float) $product->price * $qty, 2);
                $subtotal += $line;
                $lines[] = [$product, $qty, $line];
            }
            $subtotal = round($subtotal, 2);

            if (round((float) $data['amount_paid'], 2) < $subtotal) {
                throw new WorkflowException('The amount paid cannot be less than the total amount.', 'amount_paid');
            }

            $sale = Sale::create([
                'team_id' => $teamId,
                'order_number' => DocumentNumbers::next($teamId, DocumentNumbers::SALES_ORDER),
                'channel' => Sale::CHANNEL_COUNTER,
                'fulfillment_type' => 'pickup',
                'client_name' => $data['customer'],
                'sale_date' => now(),
                'subtotal' => $subtotal,
                'discount' => 0,
                'tax_amount' => 0,
                'grand_total' => $subtotal,
                'status' => SalesOrderStatus::Pending,
                'payment_status' => PaymentStatus::Unpaid,
                'payment_terms' => Sale::TERMS_FULL,
                'payment_method' => $data['payment_method'],
                'amount_paid' => 0,
                'outstanding_balance' => $subtotal,
                'notes' => $data['notes'] ?? null,
                'processed_by_id' => $user->id,
            ]);

            foreach ($lines as [$product, $qty, $lineTotal]) {
                $saleItem = $sale->items()->create([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'product_name' => $product->name,
                    'quantity' => $qty,
                    'unit_price' => $product->price,
                    'subtotal' => $lineTotal,
                    'deducted_quantity' => $qty, // taken by the customer at the counter
                ]);

                $previous = (int) $product->stock;
                $product->forceFill(['stock' => $previous - $qty])->save();
                StockMovement::create([
                    'team_id' => $teamId,
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'sale_id' => $sale->id,
                    'movement_key' => "counter:{$sale->id}:item:{$saleItem->id}",
                    'type' => 'sale',
                    'quantity' => -$qty,
                    'previous_stock' => $previous,
                    'new_stock' => $previous - $qty,
                    'reference_number' => $sale->order_number,
                    'reason' => "Counter sale to {$data['customer']}",
                    'movement_date' => now(),
                ]);
                $product->refresh()->updateCalculatedStatus();
            }

            AuditLogger::record('sales_order.created', "Counter sale {$sale->order_number} to {$data['customer']} (\$".number_format($subtotal, 2).').', $sale, [], $user);

            // Only the order total counts as received; change given back is not part of the sale.
            $payment = $this->payments->record($sale, [
                'method' => $data['payment_method'],
                'amount' => $subtotal,
                'reference' => $data['payment_reference'],
                'payment_date' => $data['payment_date'],
                'remarks' => (round((float) $data['amount_paid'], 2) > $subtotal ? 'Tendered $'.number_format((float) $data['amount_paid'], 2).'. ' : '').'Counter sale.',
            ], $proof, $user, notify: false);

            if (! $proof) {
                $this->payments->verify($payment, [
                    'reference' => $data['payment_reference'],
                    'amount' => $subtotal,
                    'payment_date' => $data['payment_date'],
                    'remarks' => 'Checked at the counter by the cashier.',
                ], $user, notify: false);
            }

            return $sale->fresh();
        });
    }
}
