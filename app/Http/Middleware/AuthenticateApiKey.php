<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Minimal service-to-service authentication: a static bearer token per actor
 * (API_KEYS="registrar:token1,examcell:token2"). The resolved actor name is
 * stored on the request and stamped on every write for auditability.
 *
 * Deliberately simple - see README "Intentionally incomplete" for the
 * production replacement (OIDC + role-based policies).
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $keys = self::parseKeys((string) config('exams.api_keys'));

        if ($keys === []) {
            $request->attributes->set('actor', 'anonymous');

            return $next($request);
        }

        $token = $request->bearerToken() ?? $request->header('X-Api-Key');

        if (is_string($token) && $token !== '') {
            foreach ($keys as $actor => $expected) {
                if (hash_equals($expected, $token)) {
                    $request->attributes->set('actor', $actor);

                    return $next($request);
                }
            }
        }

        return response()->json([
            'error' => ['code' => 'unauthenticated', 'message' => 'A valid API key is required.'],
        ], 401);
    }

    /** @return array<string, string> actor => token */
    private static function parseKeys(string $raw): array
    {
        $keys = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $pair) {
            [$actor, $token] = array_pad(explode(':', $pair, 2), 2, '');
            if ($actor !== '' && $token !== '') {
                $keys[$actor] = $token;
            }
        }

        return $keys;
    }
}
