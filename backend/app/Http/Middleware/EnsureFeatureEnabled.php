<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: `feature:material_request` — blocks a whole route group
 * behind a config/stockwise.php 'features' flag. Deliberately env-only (no
 * admin/Settings toggle) — "belum diaktifkan, hanya developer yang bisa
 * aktifkan" per user decision.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! config("stockwise.features.{$feature}_enabled")) {
            abort(403, 'Fitur ini belum diaktifkan. Hubungi developer untuk mengaktifkan.');
        }

        return $next($request);
    }
}
