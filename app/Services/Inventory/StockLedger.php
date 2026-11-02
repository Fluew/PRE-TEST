<?php

namespace App\Services\Inventory;

use App\Exceptions\WorkflowException;
use App\Models\DeliveryRelease;
use App\Models\DeliveryReleaseItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Stock for Sales Orders:
 *  - quotations and unconfirmed orders never touch stock;
 *  - confirming an order reserves what is available (available = stock − others' reservations);
 *  - stock is taken out only for what physically leaves, per release, exactly once
 *    (stock_movements.movement_key is unique);
 *  - returned goods are put back and held for the same order.
 *
 * Product rows are locked (in id order) for every change, so two orders can never reserve or
 * take the same units. Call these inside a transaction.
 */
class StockLedger
{
    /**
     * Reserve what the order still needs, as far as stock allows.
     *
     * @return array<int, array{product: string, needed: int, reserved: int}> lines that could not be fully reserved
     */
    public function reserve(Sale $sale, ?User $user = null): array
    {
        $shortages = [];
        $reserved = [];

        foreach ($this->lockedLines($sale) as [$line, $product]) {
            $needed = max(0, $line->quantity - $line->cancelled_quantity - $line->deducted_quantity - $line->reserved_quantity);
            if ($needed === 0) {
                continue;
            }
            // Everything already reserved counts, this order's other lines included.
            $take = max(0, min($needed, $product->availableStock()));
            if ($take > 0) {
                $line->increment('reserved_quantity', $take);
                $reserved[] = "{$product->sku} × {$take}";
            }
            if ($take < $needed) {
                $shortages[] = ['product' => $line->product_name, 'needed' => $needed, 'reserved' => $take];
            }
        }

        if ($reserved || $shortages) {
            AuditLogger::record('inventory.reserved', "Stock reserved for {$sale->order_number}: ".($reserved ? implode(', ', $reserved) : 'nothing available').'.'
                .($shortages ? ' Awaiting stock: '.collect($shortages)->map(fn ($s) => "{$s['product']} (".($s['needed'] - $s['reserved']).')')->implode(', ').'.' : ''),
                $sale, ['shortages' => $shortages], $user);
        }

        return $shortages;
    }

    /**
     * Free reserved units, for the whole order or given quantities per line.
     *
     * @param  array<int, int>|null  $quantities  sale_item_id => quantity
     */
    public function releaseReservations(Sale $sale, ?array $quantities = null, ?User $user = null, string $why = 'order cancelled'): void
    {
        $freed = [];
        foreach ($this->lockedLines($sale) as [$line, $product]) {
            $qty = $quantities === null ? $line->reserved_quantity : min($line->reserved_quantity, (int) ($quantities[$line->id] ?? 0));
            if ($qty > 0) {
                $line->decrement('reserved_quantity', $qty);
                $freed[] = "{$product->sku} × {$qty}";
            }
        }

        if ($freed) {
            AuditLogger::record('inventory.reservation_released', "Reservation released for {$sale->order_number} ({$why}): ".implode(', ', $freed).'.', $sale, [], $user);
        }
    }

    /** Take the release's goods out of stock. Lines already deducted (counter sales) are skipped. */
    public function deductForRelease(DeliveryRelease $release, ?User $user = null): void
    {
        $release->loadMissing('items');
        $sale = $release->sale;
        $taken = [];

        foreach ($this->lockedLines($sale, $release->items->pluck('sale_item_id')->all()) as [$line, $product]) {
            foreach ($release->items->where('sale_item_id', $line->id) as $item) {
                $key = "release:{$release->id}:item:{$item->id}:out";
                if ($item->deducted_quantity > 0 || StockMovement::where('movement_key', $key)->exists()) {
                    continue; // already taken out for this release
                }

                // Units of this line not yet taken out of stock (counter sales were taken out when sold).
                $toDeduct = min($item->quantity, max(0, $line->quantity - $line->cancelled_quantity - $line->deducted_quantity));
                if ($toDeduct === 0) {
                    continue;
                }

                $usable = (int) $product->stock - $product->reservedQuantity($sale->id);
                if ($toDeduct > $usable) {
                    throw new WorkflowException("Not enough stock for {$product->name}: {$usable} available for this order, {$toDeduct} needed. Reduce the quantity or wait for stock.", 'items');
                }

                $this->move($product, -$toDeduct, 'sale_release', $key, $sale, $release, $user,
                    "Released for {$sale->order_number}", "{$release->release_number}: {$toDeduct} unit(s) left the warehouse.");
                $line->forceFill([
                    'deducted_quantity' => $line->deducted_quantity + $toDeduct,
                    'reserved_quantity' => max(0, $line->reserved_quantity - $toDeduct),
                ])->save();
                $item->update(['deducted_quantity' => $toDeduct]);
                $taken[] = "{$product->sku} × {$toDeduct}";
            }
        }

        if ($taken) {
            AuditLogger::record('inventory.deducted', "Stock taken out for {$release->release_number} ({$sale->order_number}): ".implode(', ', $taken).'.', $sale, ['release_id' => $release->id], $user);
        }
    }

    /**
     * Put returned (undelivered) goods back in stock and hold them for the same order.
     * Only units that were taken out for this release are put back.
     */
    public function restock(DeliveryReleaseItem $item, int $quantity, string $reason, ?User $user = null): void
    {
        $release = $item->release;
        $sale = $release->sale;
        [$line, $product] = $this->lockedLines($sale, [$item->sale_item_id])[0] ?? [null, null];
        if (! $line || ! $product || $quantity <= 0) {
            return;
        }

        $back = min($quantity, $item->deducted_quantity);
        if ($back <= 0) {
            return; // nothing of this line was taken out for this release (e.g. a counter sale)
        }

        $key = "release:{$release->id}:item:{$item->id}:return";
        if (StockMovement::where('movement_key', $key)->exists()) {
            return;
        }

        $this->move($product, $back, 'return', $key, $sale, $release, $user, $reason, "{$release->release_number}: {$back} unit(s) back in the warehouse.");
        $line->forceFill([
            'deducted_quantity' => max(0, $line->deducted_quantity - $back),
            'reserved_quantity' => $line->reserved_quantity + $back,
        ])->save();
        $item->update(['deducted_quantity' => $item->deducted_quantity - $back]);

        AuditLogger::record('inventory.returned', "{$back} × {$product->sku} returned to stock from {$release->release_number} and held for {$sale->order_number}.", $sale, ['release_id' => $release->id], $user);
    }

    /** A cancelled order: put back units taken out of stock that the customer never received. */
    public function returnUndelivered(Sale $sale, string $reason, ?User $user = null): void
    {
        foreach ($this->lockedLines($sale) as [$line, $product]) {
            $back = $line->deducted_quantity - $line->delivered_quantity;
            $key = "cancel:{$sale->id}:item:{$line->id}";
            if ($back <= 0 || StockMovement::where('movement_key', $key)->exists()) {
                continue;
            }
            $this->move($product, $back, 'return', $key, $sale, null, $user, "Order {$sale->order_number} cancelled", $reason);
            $line->forceFill(['deducted_quantity' => $line->delivered_quantity])->save();
            AuditLogger::record('inventory.returned', "{$back} × {$product->sku} put back in stock: order {$sale->order_number} cancelled.", $sale, [], $user);
        }
    }

    private function move(Product $product, int $quantity, string $type, string $key, Sale $sale, ?DeliveryRelease $release, ?User $user, string $reason, string $remarks): void
    {
        $previous = (int) $product->stock;
        $product->forceFill(['stock' => $previous + $quantity])->save();

        StockMovement::create([
            'team_id' => $sale->team_id,
            'product_id' => $product->id,
            'user_id' => $user?->id,
            'sale_id' => $sale->id,
            'delivery_release_id' => $release?->id,
            'movement_key' => $key,
            'type' => $type,
            'quantity' => $quantity,
            'previous_stock' => $previous,
            'new_stock' => $previous + $quantity,
            'reference_number' => $release?->release_number ?? $sale->order_number,
            'reason' => $reason,
            'remarks' => $remarks,
            'movement_date' => now(),
        ]);

        $product->refresh()->updateCalculatedStatus();
    }

    /**
     * The order's lines with their products, both locked, products in id order.
     *
     * @param  list<int>|null  $lineIds
     * @return list<array{0: SaleItem, 1: Product}>
     */
    private function lockedLines(Sale $sale, ?array $lineIds = null): array
    {
        $lines = SaleItem::where('sale_id', $sale->id)
            ->whereNotNull('product_id')
            ->when($lineIds !== null, fn ($q) => $q->whereIn('id', $lineIds))
            ->orderBy('product_id')->orderBy('id')
            ->lockForUpdate()
            ->get();

        $products = Product::whereIn('id', $lines->pluck('product_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return $lines->filter(fn ($l) => $products->has($l->product_id))->map(fn ($l) => [$l, $products[$l->product_id]])->values()->all();
    }
}
