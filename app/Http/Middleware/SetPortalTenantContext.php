<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPortalTenantContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organizationParameter = $request->route('organization');
        $organization = $organizationParameter instanceof Organization
            ? $organizationParameter
            : Organization::query()
                ->where((new Organization)->getRouteKeyName(), $organizationParameter)
                ->first();
        $user = $request->user();

        abort_unless($organization instanceof Organization, 404);
        abort_unless($user instanceof User && $user->canAccessTenant($organization), 403);

        $request->route()?->setParameter('organization', $organization);

        $previousPermissionsTeamId = getPermissionsTeamId();
        setPermissionsTeamId($organization->getKey());
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        try {
            return $next($request);
        } finally {
            setPermissionsTeamId($previousPermissionsTeamId);
        }
    }
}
