<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureOperatorRole Middleware
 *
 * Memastikan user yang sudah login via Sanctum memiliki role yang diizinkan.
 * Dipasang setelah middleware 'auth:sanctum' di routes/api/v1/admin.php.
 *
 * Contoh pemakaian di route:
 *   ->middleware(['auth:sanctum', 'role:admin'])         // hanya admin
 *   ->middleware(['auth:sanctum', 'role:admin,operator']) // admin atau operator
 */
class EnsureOperatorRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Seharusnya tidak terjadi jika auth:sanctum dipasang duluan,
        // tapi guard tetap diperlukan
        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Jika tidak ada role yang di-passing ke middleware, izinkan semua operator & admin
        $allowedRoles = empty($roles) ? ['admin', 'operator'] : $roles;

        if (! in_array($user->role, $allowedRoles, true)) {
            return response()->json([
                'message' => 'Forbidden. Insufficient role.',
                'required' => $allowedRoles,
                'current'  => $user->role,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}