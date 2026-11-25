<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        $role = strtolower(trim((string) ($user->role ?? '')));

        if (!$user || !in_array($role, ['admin', 'administrator'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Endpoint ini khusus Administrator.',
            ], 403);
        }

        return $next($request);
    }
}