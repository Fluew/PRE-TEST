<?php

namespace App\Services\Suppliers;

use App\Models\Delivery;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Transaction-based Supplier Evaluation. Every score is calculated from records only:
 * - Delivery (40%): inbound deliveries against purchase orders with a promised date, and the
 *   accepted quantity recorded at inspection.
 * - Price (35%): verified, unexpired supplier quotes for the same product, unit, currency and tax basis.
 * - Quality (25%): accepted / inspected quantities from goods-receipt inspections.
 * A criterion without records is "insufficient data", never 0, and such a supplier gets no final
 * score or classification. Nobody can type a score in.
 */
class SupplierEvaluator
{
    /** @return array<int, array> keyed by supplier id */
    public function forTeam(int $teamId, ?CarbonInterface $now = null): array
    {
        $now = Carbon::instance($now ?? now());
        $periodStart = $now->copy()->subMonths((int) config('evaluation.supplier.period_months'))->startOfDay();

        $suppliers = Supplier::where('team_id', $teamId)->orderBy('name')->get(['id', 'name', 'status']);
        $deliveries = $this->deliveries($teamId, $periodStart, $now);
        $quoteGroups = $this->quoteGroups($teamId, $now);
        $quoteExclusions = $this->quoteExclusions($teamId, $now);

        $results = [];
        foreach ($suppliers as $s) {
            $mine = $deliveries->where('supplier_id', $s->id);
            $results[$s->id] = $this->evaluate($s, $mine, $quoteGroups, $quoteExclusions[$s->id] ?? ['expired' => 0, 'unverified' => 0], $periodStart, $now);
        }

        return $results;
    }

    public function forSupplier(Supplier $supplier, ?CarbonInterface $now = null): array
    {
        return $this->forTeam($supplier->team_id, $now)[$supplier->id];
    }

    public function classify(float $score): string
    {
        foreach (config('evaluation.supplier.classes') as $min => $label) {
            if ($score >= $min) {
                return $label;
            }
        }

        return 'Needs Improvement';
    }

    private function evaluate(Supplier $s, Collection $deliveries, array $quoteGroups, array $quoteExclusions, Carbon $from, Carbon $now): array
    {
        $weights = config('evaluation.supplier.weights');
        $criteria = [
            $this->deliveryCriterion($deliveries, $weights['delivery']),
            $this->priceCriterion($s->id, $quoteGroups, $quoteExclusions, $weights['price']),
            $this->qualityCriterion($deliveries, $weights['quality']),
        ];

        $complete = collect($criteria)->every(fn ($c) => $c['available']);
        $sum = round(collect($criteria)->where('available', true)->sum('weighted'), 2);

        return [
            'supplier' => ['id' => $s->id, 'name' => $s->name, 'status' => $s->status],
            'complete' => $complete,
            'score' => $complete ? $sum : null,
            'partial_score' => $complete ? null : $sum,
            'weight_available' => round(collect($criteria)->where('available', true)->sum('weight') * 100),
            'classification' => $complete ? $this->classify($sum) : null,
            'missing' => collect($criteria)->where('available', false)->pluck('label')->all(),
            'criteria' => $criteria,
            'final_formula' => $complete
                ? implode(' + ', array_map(fn ($c) => number_format($c['weighted'], 2), $criteria)).' = '.number_format($sum, 2)
                : null,
            'period' => ['from' => $from->toDateString(), 'to' => $now->toDateString()],
            'calculated_at' => $now->toIso8601String(),
        ];
    }

    private function deliveryCriterion(Collection $deliveries, float $weight): array
    {
        $split = config('evaluation.supplier.delivery_split');
        $timed = $deliveries->filter(fn ($d) => $d->promised_delivery_date !== null);
        $onTime = $timed->filter(fn ($d) => $d->delivery_date->lte($d->promised_delivery_date));

        // Quantity fulfillment per purchase order, from inspected deliveries (accepted units, capped at the ordered quantity).
        $byPo = $deliveries->filter(fn ($d) => $d->purchase_order_id && $d->accepted_quantity !== null)->groupBy('purchase_order_id');
        $ordered = 0;
        $accepted = 0;
        $poRows = [];
        foreach ($byPo as $poId => $rows) {
            $poQty = (int) $rows->first()->po_quantity;
            $acc = min($poQty, (int) $rows->sum('accepted_quantity'));
            $ordered += $poQty;
            $accepted += $acc;
            $poRows[] = ['po_number' => $rows->first()->po_number, 'ordered' => $poQty, 'accepted' => $acc];
        }

        $base = [
            'key' => 'delivery', 'label' => 'Inbound Delivery Performance', 'weight' => $weight,
            'source' => 'Inbound deliveries linked to purchase orders with a promised delivery date, and inspection results',
            'inputs' => [
                'completed_deliveries' => $timed->count(),
                'on_time_deliveries' => $onTime->count(),
                'ordered_quantity' => $ordered,
                'accepted_quantity' => $accepted,
                'excluded_without_promised_date' => $deliveries->count() - $timed->count(),
                'records' => $timed->map(fn ($d) => [
                    'delivery_number' => $d->delivery_number,
                    'po_number' => $d->po_number,
                    'promised' => $d->promised_delivery_date->toDateString(),
                    'delivered' => $d->delivery_date->toDateString(),
                    'on_time' => $d->delivery_date->lte($d->promised_delivery_date),
                ])->values()->all(),
                'purchase_orders' => $poRows,
            ],
        ];

        $reasons = [];
        if ($timed->isEmpty()) {
            $reasons[] = 'no completed delivery is linked to a purchase order with a promised delivery date';
        }
        if ($ordered === 0) {
            $reasons[] = 'no delivery against a purchase order has an inspection with accepted quantities';
        }
        if ($reasons) {
            return $base + ['available' => false, 'score' => null, 'weighted' => null, 'reason' => 'Insufficient data: '.implode('; ', $reasons).'.'];
        }

        $onTimeRate = round($onTime->count() / $timed->count() * 100, 2);
        $fulfillRate = round($accepted / $ordered * 100, 2);
        $score = round($onTimeRate * $split['on_time'] + $fulfillRate * $split['fulfillment'], 2);
        $base['inputs']['on_time_rate'] = $onTimeRate;
        $base['inputs']['fulfillment_rate'] = $fulfillRate;

        return $base + $this->scored($score, $weight,
            '(On-Time Rate × 0.60) + (Quantity Fulfillment Rate × 0.40)',
            "On-time: ({$onTime->count()} / {$timed->count()}) × 100 = ".number_format($onTimeRate, 2)
            ."; Fulfillment: ({$accepted} / {$ordered}) × 100 = ".number_format($fulfillRate, 2)
            .'; ('.number_format($onTimeRate, 2).' × 0.60) + ('.number_format($fulfillRate, 2).' × 0.40) = '.number_format($score, 2));
    }

    private function priceCriterion(int $supplierId, array $groups, array $exclusions, float $weight): array
    {
        $comparisons = [];
        foreach ($groups as $g) {
            $mine = collect($g['quotes'])->firstWhere('supplier_id', $supplierId);
            if (! $mine || count($g['quotes']) < 2) {
                continue;
            }
            $lowest = min(array_column($g['quotes'], 'unit_price'));
            $comparisons[] = [
                'product' => $g['product'],
                'basis' => $g['basis'],
                'own_price' => $mine['unit_price'],
                'own_reference' => $mine['reference'],
                'own_quoted_on' => $mine['quoted_on'],
                'lowest_price' => $lowest,
                'score' => round($lowest / $mine['unit_price'] * 100, 2),
                'quotes' => $g['quotes'],
            ];
        }

        $base = [
            'key' => 'price', 'label' => 'Price Competitiveness', 'weight' => $weight,
            'source' => 'Verified, unexpired supplier quotes for the same product, unit, currency and tax basis',
            'inputs' => ['comparisons' => $comparisons, 'excluded_expired' => $exclusions['expired'], 'excluded_unverified' => $exclusions['unverified']],
        ];

        if (! $comparisons) {
            return $base + ['available' => false, 'score' => null, 'weighted' => null,
                'reason' => 'Insufficient data: no verified, unexpired quote from this supplier can be compared with another supplier\'s quote for the same product and terms.'];
        }

        $score = round(array_sum(array_column($comparisons, 'score')) / count($comparisons), 2);
        $parts = array_map(fn ($c) => '('.number_format($c['lowest_price'], 2).' / '.number_format($c['own_price'], 2).') × 100 = '.number_format($c['score'], 2), $comparisons);

        return $base + $this->scored($score, $weight,
            'Average over comparable products of (Lowest Verified Price / Supplier\'s Verified Price) × 100',
            implode('; ', $parts).(count($comparisons) > 1 ? '; average = '.number_format($score, 2) : ''));
    }

    private function qualityCriterion(Collection $deliveries, float $weight): array
    {
        $inspected = $deliveries->filter(fn ($d) => $d->accepted_quantity !== null && $d->rejected_quantity !== null);
        $acc = (int) $inspected->sum('accepted_quantity');
        $rej = (int) $inspected->sum('rejected_quantity');
        $total = $acc + $rej;

        $base = [
            'key' => 'quality', 'label' => 'Product Quality', 'weight' => $weight,
            'source' => 'Goods-receipt inspection records',
            'inputs' => [
                'inspections' => $inspected->count(),
                'accepted' => $acc,
                'rejected' => $rej,
                'records' => $inspected->map(fn ($d) => [
                    'delivery_number' => $d->delivery_number,
                    'inspected_on' => $d->inspected_on?->toDateString(),
                    'result' => $d->inspection_result,
                    'accepted' => (int) $d->accepted_quantity,
                    'rejected' => (int) $d->rejected_quantity,
                    'reason' => $d->rejection_reason,
                ])->values()->all(),
            ],
        ];

        if ($total === 0) {
            return $base + ['available' => false, 'score' => null, 'weighted' => null, 'reason' => 'Insufficient data: no goods-receipt inspection has been recorded for this supplier.'];
        }

        $score = round($acc / $total * 100, 2);

        return $base + $this->scored($score, $weight,
            '(Total Accepted Quantity / Total Inspected Quantity) × 100',
            "({$acc} / {$total}) × 100 = ".number_format($score, 2));
    }

    private function scored(float $score, float $weight, string $formula, string $substitution): array
    {
        $weighted = round($score * $weight, 2);

        return [
            'available' => true,
            'score' => $score,
            'weighted' => $weighted,
            'formula' => $formula,
            'substitution' => $substitution,
            'weighted_formula' => number_format($score, 2).' × '.number_format($weight, 2).' = '.number_format($weighted, 2),
            'reason' => null,
        ];
    }

    /**
     * Inbound deliveries in the period (not cancelled or archived), with their purchase order.
     * A delivery number recorded twice for the same purchase order counts once.
     */
    private function deliveries(int $teamId, Carbon $from, Carbon $to): Collection
    {
        return Delivery::query()
            ->leftJoin('purchase_orders', function ($j) {
                $j->on('purchase_orders.id', '=', 'deliveries.purchase_order_id')->whereNull('purchase_orders.deleted_at');
            })
            ->where('deliveries.team_id', $teamId)
            ->whereBetween('deliveries.delivery_date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->whereNull('deliveries.status')->orWhere('deliveries.status', '!=', 'Cancelled'))
            ->orderBy('deliveries.id')
            ->get([
                'deliveries.*',
                'purchase_orders.po_number',
                'purchase_orders.quantity as po_quantity',
                'purchase_orders.promised_delivery_date',
                'purchase_orders.supplier_id as po_supplier_id',
            ])
            ->each(function ($d) {
                $d->supplier_id = $d->supplier_id ?: $d->po_supplier_id;
                $d->promised_delivery_date = $d->promised_delivery_date ? Carbon::parse($d->promised_delivery_date) : null;
                if (! $d->purchase_order_id) {
                    $d->promised_delivery_date = null;
                }
            })
            ->unique(fn ($d) => ($d->purchase_order_id ?: 'none').'|'.mb_strtolower(trim($d->delivery_number)))
            ->values();
    }

    /** Latest valid verified quote per supplier, grouped by product + unit + currency + tax basis. */
    private function quoteGroups(int $teamId, Carbon $now): array
    {
        $quotes = SupplierQuote::query()
            ->with(['product:id,name,sku', 'supplier:id,name'])
            ->whereHas('supplier')
            ->where('team_id', $teamId)
            ->whereNotNull('verified_at')
            ->whereDate('quoted_on', '<=', $now)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $now))
            ->orderByDesc('quoted_on')->orderByDesc('id')
            ->get();

        $groups = [];
        foreach ($quotes as $q) {
            $key = implode('|', [$q->product_id, mb_strtolower($q->unit), $q->currency, (int) $q->tax_inclusive]);
            $groups[$key] ??= [
                'product' => ['id' => $q->product_id, 'name' => $q->product?->name, 'sku' => $q->product?->sku],
                'basis' => "per {$q->unit}, {$q->currency}, ".($q->tax_inclusive ? 'tax included' : 'tax excluded'),
                'quotes' => [],
            ];
            if (collect($groups[$key]['quotes'])->contains('supplier_id', $q->supplier_id)) {
                continue;
            }
            $groups[$key]['quotes'][] = [
                'supplier_id' => $q->supplier_id,
                'supplier' => $q->supplier?->name,
                'unit_price' => (float) $q->unit_price,
                'reference' => $q->reference,
                'quoted_on' => $q->quoted_on->toDateString(),
                'valid_until' => $q->valid_until?->toDateString(),
            ];
        }

        return array_values($groups);
    }

    /** @return array<int, array{expired: int, unverified: int}> */
    private function quoteExclusions(int $teamId, Carbon $now): array
    {
        $out = [];
        foreach (SupplierQuote::where('team_id', $teamId)->get(['supplier_id', 'verified_at', 'valid_until']) as $q) {
            $out[$q->supplier_id] ??= ['expired' => 0, 'unverified' => 0];
            if (! $q->verified_at) {
                $out[$q->supplier_id]['unverified']++;
            } elseif ($q->valid_until && $q->valid_until->lt($now->copy()->startOfDay())) {
                $out[$q->supplier_id]['expired']++;
            }
        }

        return $out;
    }
}
