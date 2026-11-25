<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('sanctum')->check()) {
            Auth::setUser(Auth::guard('sanctum')->user());
            return $next($request);
        }

        if ($bearer = $request->bearerToken()) {
            $tokenModel = PersonalAccessToken::findToken($bearer);
            if ($tokenModel && $tokenModel->tokenable) {
                $expiresAt = $tokenModel->expires_at ?? null;
                if ($expiresAt && now()->greaterThan($expiresAt)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Sesi login telah kedaluwarsa. Silakan login kembali.',
                    ], 401);
                }

                Auth::setUser($tokenModel->tokenable);
                $request->setUserResolver(fn () => $tokenModel->tokenable);
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Akses ditolak. Silakan login terlebih dahulu.',
        ], 401);
    }
}