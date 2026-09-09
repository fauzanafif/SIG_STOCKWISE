<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->withCount('users')
            ->with('permissions:id,slug')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'slug' => $role->slug,
                'name' => $role->name,
                'description' => $role->description,
                'users_count' => $role->users_count,
                'permissions' => $role->permissions->pluck('slug'),
            ]);

        return response()->json(['data' => $roles]);
    }
}
