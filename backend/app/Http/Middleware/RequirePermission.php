<?php

namespace App\Http\Middleware;

use App\Services\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jogosultság-ellenőrzés: a szerepkör jogait minden kérésnél az adatbázisból olvassa,
 * így egy szerepkör-módosítás azonnal érvényesül, a token cseréje nélkül is.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        foreach ($permissions as $permission) {
            if ($user && $user->hasPermission($permission)) {
                return $next($request);
            }
        }

        ActivityLogger::log(
            'auth.permission_denied',
            sprintf('Jogosulatlan hozzáférési kísérlet: %s /%s (szükséges jog: %s)', $request->method(), $request->path(), implode(' vagy ', $permissions)),
            null,
            ['required' => $permissions, 'role' => $user?->role]
        );

        return response()->json(['message' => 'Nincs jogosultságod ehhez a művelethez.'], 403);
    }
}
