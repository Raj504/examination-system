<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Implements the `Idempotency-Key` request header for unsafe methods.
 *
 *  - First request with a key claims it atomically (INSERT IGNORE on the
 *    primary key) and executes.
 *  - A retry with the same key and the same payload receives the stored
 *    response verbatim (`Idempotent-Replayed: true`) without re-executing.
 *  - The same key with a different payload is rejected (422).
 *  - A retry while the original is still executing gets 409 + Retry-After.
 *
 * 5xx and transient 409/423/429 responses are NOT stored, so the client can
 * retry them with the same key. Keys are scoped per actor and expire.
 */
class EnsureIdempotency
{
    private const METHODS = ['POST', 'PUT', 'PATCH'];

    private const NOT_STORED = [409, 423, 429];

    public function handle(Request $request, Closure $next): Response
    {
        $clientKey = $request->header('Idempotency-Key');

        if (! in_array($request->method(), self::METHODS, true) || $clientKey === null) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9_\-:.]{8,128}$/', $clientKey)) {
            return $this->error(400, 'invalid_idempotency_key', 'Idempotency-Key must be 8-128 characters of [A-Za-z0-9_-:.].');
        }

        $actor = (string) $request->attributes->get('actor', 'anonymous');
        $key = hash('sha256', $actor.'|'.$clientKey);
        $fingerprint = $this->fingerprint($request);

        if (! $this->claim($key, $request, $fingerprint)) {
            $stored = DB::table('idempotency_keys')->where('key', $key)->first();

            if ($stored === null) {
                return $this->error(409, 'idempotency_request_in_progress', 'Retry shortly.', 1);
            }

            if (! hash_equals($stored->request_hash, $fingerprint)) {
                return $this->error(422, 'idempotency_key_reused', 'This Idempotency-Key was already used with a different request.');
            }

            if ($stored->status !== 'completed') {
                return $this->error(409, 'idempotency_request_in_progress', 'The original request is still being processed.', 1);
            }

            return response($stored->response_body, $stored->response_code)
                ->header('Content-Type', 'application/json')
                ->header('Idempotent-Replayed', 'true');
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            DB::table('idempotency_keys')->where('key', $key)->delete();

            throw $e;
        }

        $status = $response->getStatusCode();

        if ($status >= 500 || in_array($status, self::NOT_STORED, true)) {
            DB::table('idempotency_keys')->where('key', $key)->delete();
        } else {
            DB::table('idempotency_keys')->where('key', $key)->update([
                'status' => 'completed',
                'response_code' => $status,
                'response_body' => $response->getContent(),
                'updated_at' => now(),
            ]);
        }

        return $response;
    }

    private function claim(string $key, Request $request, string $fingerprint): bool
    {
        // Expired keys, and claims orphaned by a crashed process, are reclaimable.
        DB::table('idempotency_keys')
            ->where('key', $key)
            ->where(fn ($q) => $q->where('expires_at', '<', now())
                ->orWhere(fn ($q) => $q->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(5))))
            ->delete();

        return DB::table('idempotency_keys')->insertOrIgnore([
            'key' => $key,
            'method' => $request->method(),
            'path' => mb_substr($request->path(), 0, 255),
            'request_hash' => $fingerprint,
            'status' => 'processing',
            'expires_at' => now()->addHours((int) config('exams.idempotency.ttl_hours')),
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    private function fingerprint(Request $request): string
    {
        $files = [];
        foreach ($request->allFiles() as $name => $file) {
            foreach ((array) $file as $i => $f) {
                if ($f instanceof UploadedFile) {
                    $files["{$name}.{$i}"] = hash_file('sha256', $f->getRealPath());
                }
            }
        }
        ksort($files);

        $input = $request->except(array_keys($request->allFiles()));
        ksort($input);

        return hash('sha256', implode('|', [
            $request->method(),
            $request->path(),
            json_encode($input),
            json_encode($files),
        ]));
    }

    private function error(int $status, string $code, string $message, ?int $retryAfter = null): Response
    {
        $response = response()->json(['error' => ['code' => $code, 'message' => $message]], $status);

        return $retryAfter ? $response->header('Retry-After', (string) $retryAfter) : $response;
    }
}
