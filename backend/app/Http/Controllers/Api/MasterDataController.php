<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MasterDataController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        $query = Category::query()->orderBy('path');
        $request->whenFilled('level', fn ($v) => $query->where('level', (int) $v));
        $request->whenFilled('parent_id', fn ($v) => $query->where('parent_id', $v));

        return response()->json(['data' => $query->get(['id', 'parent_id', 'name', 'level', 'path', 'is_active'])]);
    }

    public function categoryTree(): JsonResponse
    {
        $all = Category::query()->orderBy('name')->get(['id', 'parent_id', 'name', 'level', 'path']);

        $build = function ($parentId) use (&$build, $all) {
            return $all->where('parent_id', $parentId)->values()->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'level' => $c->level,
                'path' => $c->path,
                'children' => $build($c->id),
            ]);
        };

        return response()->json(['data' => $build(null)]);
    }

    public function units(): JsonResponse
    {
        return response()->json([
            'data' => Unit::orderBy('code')->get(['id', 'code', 'name', 'is_active']),
        ]);
    }

    public function warehouses(Request $request): JsonResponse
    {
        $query = Warehouse::query()->with('site:id,code,name')->orderBy('code');
        $request->whenFilled('site_id', fn ($v) => $query->where('site_id', $v));

        return response()->json(['data' => $query->get()]);
    }

    public function warehouseLocations(Request $request): JsonResponse
    {
        $query = WarehouseLocation::query()->orderBy('code');
        $request->whenFilled('warehouse_id', fn ($v) => $query->where('warehouse_id', $v));

        return response()->json([
            'data' => $query->get(['id', 'warehouse_id', 'code', 'description', 'is_active']),
        ]);
    }
}
