<?php

namespace App\Services\Inventory;

use App\Models\InventoryAnalysisRun;
use App\Models\InventorySnapshot;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Runs the STOCKWISE engine across the whole catalog: computes the dataset-level
 * parameters (lead-time threshold, median deficit), analyses every active item and
 * refreshes `inventory_snapshots` + records an `inventory_analysis_runs` row.
 *
 * docs/calculation-engine.md §3, assumptions A2/A3.
 */
class InventoryAnalyzer
{
    public function __construct(private readonly StockwiseEngine $engine) {}

    /** @param  int|null  $triggeredBy  user id */
    public function run(?int $triggeredBy = null): InventoryAnalysisRun
    {
        $fallback = (int) config('stockwise.engine.lead_time_threshold_fallback', 14);

        $invAgg = DB::table('inventory')
            ->selectRaw('item_id, SUM(actual_qty) a, SUM(reserved_qty) r, MAX(stock_known) k')
            ->groupBy('item_id');

        /** @var Collection<int, object> $rows */
        $rows = Item::query()
            ->where('items.is_active', true)
            ->leftJoin('item_safety_stocks as ss', function ($join) {
                $join->on('ss.item_id', '=', 'items.id')->where('ss.is_effective', true);
            })
            ->leftJoin('units', 'units.id', '=', 'items.unit_id')
            ->leftJoinSub($invAgg, 'inv', 'inv.item_id', '=', 'items.id')
            ->select([
                'items.id',
                'items.lead_time_days',
                'units.code as uom',
                DB::raw('COALESCE(ss.safety_stock, 0) as safety_stock'),
                DB::raw('COALESCE(inv.a, 0) as actual'),
                DB::raw('COALESCE(inv.r, 0) as reserved'),
                DB::raw('COALESCE(inv.k, 0) as stock_known'),
            ])
            ->get()
            ->map(function ($row) {
                $row->actual = (float) $row->actual;
                $row->reserved = (float) $row->reserved;
                $row->available = $row->actual - $row->reserved;
                $row->stock_known = (bool) $row->stock_known;
                $row->safety_stock = (float) $row->safety_stock;
                $row->lead_time = (int) ($row->lead_time_days ?? 0);

                return $row;
            });

        // Dataset parameter: lead-time threshold (75th percentile of positive lead times).
        $leadTimes = $rows->pluck('lead_time')->filter(fn ($lt) => $lt > 0)->values()->all();
        $threshold = count($leadTimes) >= 4
            ? $this->percentile($leadTimes, 75)
            : (float) $fallback;

        // Preliminary pass: status + deficit (independent of median).
        $deficitsTidakAman = [];
        foreach ($rows as $row) {
            $selisih = $row->available - $row->safety_stock;
            $row->status = ($this->isZero($row->available) && $this->isZero($row->safety_stock))
                ? 'BEP'
                : ($selisih >= -1e-6 ? 'AMAN' : 'TIDAK_AMAN');
            $row->deficit = max($row->safety_stock - $row->available, 0.0);
            if ($row->status === 'TIDAK_AMAN') {
                $deficitsTidakAman[] = $row->deficit;
            }
        }

        $medianDeficit = $deficitsTidakAman === [] ? 0.0 : $this->median($deficitsTidakAman);

        $run = InventoryAnalysisRun::create([
            'scope' => 'global',
            'lead_time_threshold' => round($threshold, 2),
            'median_deficit' => round($medianDeficit, 2),
            'item_count' => $rows->count(),
            'tidak_aman_count' => count($deficitsTidakAman),
            'triggered_by' => $triggeredBy,
            'computed_at' => now(),
        ]);

        $now = now();
        $snapshotRows = $rows->map(function ($row) use ($medianDeficit, $threshold, $run, $now) {
            $a = $this->engine->analyze(
                sisaStok: $row->available,
                safetyStock: $row->safety_stock,
                leadTimeDays: $row->lead_time,
                medianDeficitTidakAman: $medianDeficit,
                leadTimeThreshold: $threshold,
                uom: $row->uom,
            );

            return [
                'item_id' => $row->id,
                'warehouse_id' => null,
                'analysis_run_id' => $run->id,
                'actual' => $row->actual,
                'reserved' => $row->reserved,
                'available' => $row->available,
                'stock_known' => $row->stock_known,
                'safety_stock' => $a->safetyStock,
                'lead_time_days' => $row->lead_time ?: null,
                'selisih' => $a->selisih,
                'status' => $a->status,
                'deficit' => $a->deficit,
                'priority_score' => round($a->priorityScore, 2),
                'priority_level' => $a->priorityLevel,
                'recommendation' => $a->recommendation,
                'recommended_qty' => $a->recommendedQty,
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        InventorySnapshot::query()->whereNull('warehouse_id')->delete();
        foreach (array_chunk($snapshotRows, 500) as $chunk) {
            InventorySnapshot::insert($chunk);
        }

        return $run;
    }

    private function isZero(float $v): bool
    {
        return abs($v) < 1e-6;
    }

    /**
     * Linear-interpolation percentile (numpy 'linear' / Excel PERCENTILE.INC).
     *
     * @param  list<int|float>  $values
     */
    public function percentile(array $values, float $p): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        if ($n === 1) {
            return (float) $values[0];
        }

        $rank = ($p / 100) * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        $frac = $rank - $low;

        return $values[$low] + $frac * ($values[$high] - $values[$low]);
    }

    /** @param  list<int|float>  $values */
    public function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
