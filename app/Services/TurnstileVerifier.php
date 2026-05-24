<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileVerifier
{
    public function isConfigured(): bool
    {
        $secret = trim((string) config('checkout_security.captcha.secret_key', ''));

        return $secret !== '';
    }

    /**
     * @return array{ok: bool, error_codes?: array<int, string>}
     */
    public function verify(Request $request, ?string $token = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => true];
        }

        $token = $token ?? (string) $request->input('cf-turnstile-response', '');
        if (trim($token) === '') {
            return ['ok' => false, 'error_codes' => ['missing-input-response']];
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => config('checkout_security.captcha.secret_key'),
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Turnstile: request failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error_codes' => ['internal-error']];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error_codes' => ['http-error']];
        }

        $body = $response->json();
        if (! is_array($body)) {
            return ['ok' => false, 'error_codes' => ['invalid-json']];
        }

        return [
            'ok' => (bool) ($body['success'] ?? false),
            'error_codes' => is_array($body['error-codes'] ?? null) ? $body['error-codes'] : [],
        ];
    }
}
