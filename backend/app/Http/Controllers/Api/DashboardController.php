<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BorrowTransaction;
use App\Models\GoodsIssue;
use App\Models\Inventory;
use App\Models\InventoryAnalysisRun;
use App\Models\LendTransaction;
use App\Models\MaintenanceOrder;
use App\Models\MaterialRequest;
use App\Models\PurchaseProposal;
use App\Models\PurchaseOrder;
use App\Models\Receiving;
use App\Models\StockMovement;
use App\Models\StockOpname;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Ringkasan per-peran untuk halaman Dashboard (brief §AE, §W).
 * Satu endpoint; blok yang dikembalikan menyesuaikan permission user.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $cards = [];
        $lists = [];
        $charts = [];

        // ---- Inventory / analisa (semua peran gudang + eksekutif) ----
        if ($user->hasPermission('inventory.view') || $user->hasPermission('inventory.view_analysis')) {
            $run = InventoryAnalysisRun::latest('computed_at')->first();
            $belowSafety = Inventory::query()
                ->join('items', 'items.id', '=', 'inventory.item_id')
                ->leftJoin('item_safety_stocks', function ($j) {
                    $j->on('item_safety_stocks.item_id', '=', 'items.id')->where('item_safety_stocks.is_effective', true);
                })
                ->whereRaw('(inventory.actual_qty - inventory.reserved_qty) < COALESCE(item_safety_stocks.safety_stock, 0)')
                ->where('inventory.stock_known', true)
                ->distinct('inventory.item_id')
                ->count('inventory.item_id');

            $cards[] = ['key' => 'items_tracked', 'label' => 'Item dianalisa', 'value' => $run?->item_count ?? 0, 'tone' => 'default'];
            $cards[] = ['key' => 'below_safety', 'label' => 'Di bawah Safety Stock', 'value' => $belowSafety, 'tone' => $belowSafety > 0 ? 'danger' : 'success'];
            if ($run) {
                $tidakAman = (int) $run->tidak_aman_count;
                $cards[] = ['key' => 'tidak_aman', 'label' => 'Status TIDAK AMAN', 'value' => $tidakAman, 'tone' => 'danger'];
                $cards[] = ['key' => 'aman', 'label' => 'Status AMAN', 'value' => max((int) $run->item_count - $tidakAman, 0), 'tone' => 'success'];
            }
        }

        // ---- Request ----
        if ($user->hasPermission('request.view') || $user->hasPermission('request.view_own')) {
            $scope = fn ($q) => $user->hasPermission('request.view') ? $q : $q->where('requester_id', $user->id);
            $byStatus = $scope(MaterialRequest::query())
                ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

            $cards[] = ['key' => 'req_open', 'label' => 'Request berjalan', 'tone' => 'default',
                'value' => (int) $byStatus->except(['COMPLETED', 'CANCELLED', 'REJECTED'])->sum()];
            $cards[] = ['key' => 'req_review', 'label' => 'Menunggu review', 'tone' => 'warning',
                'value' => (int) ($byStatus['SUBMITTED'] ?? 0) + (int) ($byStatus['UNDER_REVIEW'] ?? 0)];
            $charts['request_status'] = $byStatus->map(fn ($c, $s) => ['name' => $s, 'value' => (int) $c])->values();

            $lists['recent_requests'] = $scope(MaterialRequest::query())->latest()->limit(6)
                ->get(['id', 'number', 'status', 'created_at'])
                ->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'date' => $r->created_at?->toDateString()]);
        }

        // ---- Bukti Keluar Barang / pickup ----
        if ($user->hasPermission('goods_issue.view') || $user->hasPermission('goods_issue.view_own')) {
            $q = $user->hasPermission('goods_issue.view') ? GoodsIssue::query() : GoodsIssue::query()->where('requester_id', $user->id);
            $cards[] = ['key' => 'goods_issue_ready', 'label' => 'Siap diambil', 'tone' => 'default',
                'value' => (clone $q)->where('status', 'READY_TO_PICKUP')->count()];
            $cards[] = ['key' => 'goods_issue_prep', 'label' => 'Sedang disiapkan', 'tone' => 'warning',
                'value' => (clone $q)->where('status', 'PREPARING')->count()];
        }

        // ---- Opname ----
        if ($user->hasPermission('opname.view')) {
            $cards[] = ['key' => 'opname_active', 'label' => 'Opname aktif', 'tone' => 'default',
                'value' => StockOpname::whereIn('status', ['SCHEDULED', 'IN_PROGRESS', 'SUBMITTED'])->count()];
        }

        // ---- Purchasing ----
        if ($user->hasPermission('purchase_proposal.view') || $user->hasPermission('po.view')) {
            $cards[] = ['key' => 'ppb_open', 'label' => 'Usulan pembelian menunggu', 'tone' => 'warning',
                'value' => PurchaseProposal::whereIn('status', ['SUBMITTED', 'REVIEW'])->count()];
            $cards[] = ['key' => 'po_open', 'label' => 'PO berjalan', 'tone' => 'default',
                'value' => PurchaseOrder::whereIn('status', ['APPROVED', 'SENT', 'PARTIAL_RECEIVED'])->count()];
            $cards[] = ['key' => 'ri_checking', 'label' => 'Penerimaan diperiksa', 'tone' => 'warning',
                'value' => Receiving::where('status', 'CHECKING')->count()];
        }

        // ---- Tracking ----
        if ($user->hasPermission('lend.view')) {
            $cards[] = ['key' => 'lend_open', 'label' => 'Barang dipinjamkan', 'tone' => 'default',
                'value' => LendTransaction::whereIn('status', ['ON_LOAN', 'PARTIAL_RETURN', 'OVERDUE'])->count()];
            $overdue = LendTransaction::where('status', 'OVERDUE')->count();
            if ($overdue > 0) {
                $cards[] = ['key' => 'lend_overdue', 'label' => 'Pinjaman lewat tempo', 'tone' => 'danger', 'value' => $overdue];
            }
        }
        if ($user->hasPermission('borrow.view')) {
            $cards[] = ['key' => 'borrow_open', 'label' => 'Pinjaman luar aktif', 'tone' => 'default',
                'value' => BorrowTransaction::whereIn('status', ['BORROWED', 'PARTIAL'])->count()];
        }
        if ($user->hasPermission('maintenance.view')) {
            $cards[] = ['key' => 'spk_open', 'label' => 'SPK berjalan', 'tone' => 'default',
                'value' => MaintenanceOrder::whereIn('status', ['OPEN', 'ON_GOING'])->count()];
        }

        // ---- Executive: pergerakan stok 14 hari ----
        if ($user->hasPermission('dashboard.executive') || $user->hasPermission('report.stock_movement')) {
            $since = Carbon::today()->subDays(13);
            $rows = StockMovement::query()
                ->where('created_at', '>=', $since)
                ->selectRaw('DATE(created_at) d, direction, count(*) c')
                ->groupBy('d', 'direction')->get();
            $charts['stock_movement_14d'] = collect(range(0, 13))->map(function ($i) use ($since, $rows) {
                $d = $since->copy()->addDays($i)->toDateString();

                return [
                    'name' => $d,
                    'in' => (int) $rows->where('d', $d)->where('direction', 1)->sum('c'),
                    'out' => (int) $rows->where('d', $d)->where('direction', -1)->sum('c'),
                ];
            });
        }

        return response()->json([
            'data' => [
                'role' => $user->roles->pluck('slug'),
                'generated_at' => now()->toIso8601String(),
                'cards' => $cards,
                'charts' => $charts,
                'lists' => $lists,
            ],
        ]);
    }
}
