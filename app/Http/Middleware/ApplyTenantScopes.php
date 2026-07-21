<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyTenantScopes
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request):Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        $user = $request->user();

        if ($user instanceof User && $tenant !== null) {
            setPermissionsTeamId($tenant->getKey());
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
        }

        return $next($request);
    }
}
