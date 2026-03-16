<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalReportingToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $headerNameValue = config('reporting.api.token_header', 'X-Reporting-Token');
        $expectedTokenValue = config('reporting.api.token', '');
        $headerName = is_string($headerNameValue) ? $headerNameValue : 'X-Reporting-Token';
        $expectedToken = is_string($expectedTokenValue) ? $expectedTokenValue : '';
        $receivedToken = $request->header($headerName);

        if ($expectedToken === '' || ! is_string($receivedToken) || ! hash_equals($expectedToken, $receivedToken)) {
            return new JsonResponse([
                'message' => 'Unauthorized internal reporting request.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
