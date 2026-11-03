<?php

namespace App\Modules\Assistant\Support;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * DAVI, the assistant, is a separate service (docs/platform-intelligence/davi/server.py) that only this API calls.
 * It reads and acts through this API with a short-lived token of the signed-in user, bound to their current company:
 * DAVI never has a right the user does not have, every action it runs is the user's (and audited as such), and the
 * user's own session is never handed over.
 */
final class DaviClient
{
    public const TOKEN_NAME = 'davi';

    private const TOKEN_HOURS = 2;

    public static function configured(): bool
    {
        return filled(config('services.davi.url')) && filled(config('services.davi.key'));
    }

    /** @param  array<string, mixed>  $body */
    public function post(User $user, ?int $companyId, string $path, array $body = [], ?UploadedFile $file = null): array
    {
        abort_unless(self::configured(), 503, __('davi.not_configured'));
        $response = $this->send($this->token($user, $companyId), $path, $body, $file);
        if ($response->status() === 401) {
            // The token was revoked or has expired early: once more with a new one.
            Cache::forget($this->cacheKey($user, $companyId));
            $response = $this->send($this->token($user, $companyId), $path, $body, $file);
        }
        if ($response->status() === 403) {
            throw new HttpException(403, __('davi.not_for_this_account'));
        }
        if ($response->failed()) {
            throw new HttpException(502, __('davi.unavailable'));
        }

        return (array) $response->json();
    }

    private function send(string $token, string $path, array $body, ?UploadedFile $file): Response
    {
        // A turn that reaches the model can take longer than PHP's own limit (30 s, real time on Windows): the request
        // would die there with a 500 while DAVI is still answering. It may last as long as the client waits for it.
        set_time_limit(250);
        $request = Http::withHeaders(['X-Davi-Key' => (string) config('services.davi.key'), 'X-Davi-Token' => $token])
            ->acceptJson()->timeout(240)->connectTimeout(3);
        try {
            return $file === null
                ? $request->post(rtrim((string) config('services.davi.url'), '/').$path, $body)
                : $request->attach('file', (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName(), ['Content-Type' => $file->getMimeType() ?? 'application/pdf'])
                    ->post(rtrim((string) config('services.davi.url'), '/').$path);
        } catch (ConnectionException) {
            throw new HttpException(503, __('davi.unavailable'));
        }
    }

    /** The user's DAVI token for this company, kept (encrypted) while it has more than a quarter of an hour left. */
    private function token(User $user, ?int $companyId): string
    {
        $key = $this->cacheKey($user, $companyId);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return Crypt::decryptString($cached);
        }
        $user->tokens()->where('name', self::TOKEN_NAME)->where('expires_at', '<', now())->delete();
        $issued = $user->createToken(self::TOKEN_NAME, ['*'], now()->addHours(self::TOKEN_HOURS));
        $issued->accessToken->forceFill(['company_id' => $companyId])->save();
        Cache::put($key, Crypt::encryptString($issued->plainTextToken), now()->addHours(self::TOKEN_HOURS)->subMinutes(15));

        return $issued->plainTextToken;
    }

    private function cacheKey(User $user, ?int $companyId): string
    {
        return "davi.token.{$user->id}.".($companyId ?? 'none');
    }
}
