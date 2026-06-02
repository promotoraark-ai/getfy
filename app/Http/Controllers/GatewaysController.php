<?php

namespace App\Http\Controllers;

use App\Gateways\GatewayRegistry;
use App\Gateways\CajuPay\CajuPayDriver;
use App\Models\GatewayCredential;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

class GatewaysController extends Controller
{
    public function index(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $gateways = $this->buildGatewaysList($tenantId);
        $gatewayOrder = $this->getGatewayOrder($tenantId);

        return response()->json([
            'gateways' => $gateways,
            'gateway_order' => $gatewayOrder,
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (!$gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        $tenantId = auth()->user()->tenant_id;
        $credential = GatewayCredential::forTenant($tenantId)->where('gateway_slug', $slug)->first();
        if ($credential !== null) {
            $credential = $credential->fresh();
        }

        $credentialKeys = collect($gateway['credential_keys'] ?? []);
        $credentialValues = [];
        $certificateConfigured = false;
        $certificateFilename = null;
        $decrypted = [];
        if ($credential !== null && $credential->getRawOriginal('credentials') !== null && $credential->getRawOriginal('credentials') !== '') {
            $decrypted = $credential->getDecryptedCredentials();
            if ($decrypted === [] && (string) $credential->getRawOriginal('credentials') !== '') {
                \Log::warning('GatewaysController::show decryption returned empty', [
                    'slug' => $slug,
                    'tenant_id' => $tenantId,
                ]);
            }
            $certificateConfigured = !empty($decrypted['certificate_path'] ?? '');
            $certificateFilename = $decrypted['certificate_filename'] ?? null;
        }
        foreach ($credentialKeys as $keyDef) {
            $keyDef = is_array($keyDef) ? $keyDef : (array) $keyDef;
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '') {
                continue;
            }
            if ($type === 'file') {
                continue;
            }
            $raw = $decrypted[$key] ?? null;
            if ($type === 'boolean') {
                $credentialValues[$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
            } elseif (in_array($key, ['secret_key', 'webhook_secret', 'webhook_signing_secret'], true) && $raw !== null && (string) $raw !== '') {
                // Nunca devolve o segredo ao browser; salvar em branco preserva o valor (update).
                $credentialValues[$key] = '';
            } else {
                $credentialValues[$key] = $raw !== null && $raw !== '' ? (string) $raw : '';
            }
        }

        $webhookUrl = null;
        $webhookUrlSecondary = null;
        if ($slug === 'pushinpay') {
            $webhookRoute = $gateway['webhook_route'] ?? 'webhooks.' . $slug;
            $webhookUrl = Route::has($webhookRoute) ? route($webhookRoute) : null;
        } elseif ($slug === 'cajupay' && Route::has('webhooks.cajupay')) {
            $webhookUrl = route('webhooks.cajupay');
            $webhookUrlSecondary = Route::has('webhooks.cajupay.checkout-alias')
                ? route('webhooks.cajupay.checkout-alias')
                : null;
        }

        $usesOauth = ! empty($gateway['oauth']);
        $oauthRoutePrefix = 'gateways.'.$slug.'.oauth.';
        $oauthStartUrl = $usesOauth && Route::has($oauthRoutePrefix.'start')
            ? route($oauthRoutePrefix.'start')
            : null;
        $oauthDisconnectUrl = $usesOauth && Route::has($oauthRoutePrefix.'disconnect')
            ? route($oauthRoutePrefix.'disconnect')
            : null;
        $oauthCallbackUrl = $usesOauth && Route::has($oauthRoutePrefix.'callback')
            ? route($oauthRoutePrefix.'callback', [], true)
            : null;
        $oauthConnected = $usesOauth
            && ($credential?->is_connected ?? false)
            && trim((string) ($decrypted['access_token'] ?? '')) !== '';

        $oauthClientConfigured = false;

        $payload = [
            'slug' => $gateway['slug'],
            'name' => $gateway['name'],
            'image' => $gateway['image'] ?? null,
            'methods' => $gateway['methods'] ?? [],
            'scope' => $gateway['scope'] ?? 'national',
            'signup_url' => $gateway['signup_url'] ?? null,
            'credential_keys' => $gateway['credential_keys'] ?? [],
            'certificate_key' => $gateway['certificate_key'] ?? null,
            'is_configured' => $credential !== null,
            'is_connected' => $credential?->is_connected ?? false,
            'credential_values' => $credentialValues,
            'certificate_configured' => $certificateConfigured,
            'certificate_filename' => $certificateFilename && is_string($certificateFilename) ? $certificateFilename : null,
            'webhook_url' => $webhookUrl,
            'webhook_url_secondary' => $webhookUrlSecondary,
            'webhook_signing_secret_set' => $slug === 'cajupay'
                && ! empty(trim((string) ($decrypted['webhook_signing_secret'] ?? ''))),
            'spacepag_keys_configured' => $slug === 'spacepag' && (
                trim((string) ($decrypted['secret_key'] ?? '')) !== ''
                || trim((string) ($decrypted['public_key'] ?? '')) !== ''
            ),
            'spacepag_secret_key_set' => $slug === 'spacepag'
                && ! empty(trim((string) ($decrypted['secret_key'] ?? ''))),
            'webhook_secret_set' => $slug === 'spacepag'
                && ! empty(trim((string) ($decrypted['webhook_secret'] ?? ''))),
            'uses_oauth' => $usesOauth,
            'oauth_client_configured' => $oauthClientConfigured,
            'oauth_start_url' => $oauthStartUrl,
            'oauth_disconnect_url' => $oauthDisconnectUrl,
            'oauth_callback_url' => $oauthCallbackUrl,
            'oauth_connected' => $oauthConnected,
        ];

        return response()->json($payload)->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (!$gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        $credentialKeys = collect($gateway['credential_keys'] ?? []);
        $certificateKey = $gateway['certificate_key'] ?? null;

        $rules = [];
        foreach ($credentialKeys as $keyDef) {
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '') {
                continue;
            }
            if ($type === 'file') {
                $rules[$key] = ['nullable', 'file', 'max:512'];
                continue;
            }
            if ($type === 'boolean') {
                $rules[$key] = ['nullable', 'boolean'];
                continue;
            }
            // Tokens (ex.: Mercado Pago Access Token) podem ultrapassar 500 caracteres
            $rules[$key] = ['nullable', 'string', 'max:2000'];
        }

        $validated = $request->validate($rules);

        $tenantId = auth()->user()->tenant_id;
        $credential = GatewayCredential::forTenant($tenantId)->firstOrNew(
            ['gateway_slug' => $slug],
            ['tenant_id' => $tenantId]
        );

        $existingCredentials = $credential->exists ? $credential->getDecryptedCredentials() : [];

        $credentials = [];
        foreach ($credentialKeys as $keyDef) {
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '' || $key === $certificateKey) {
                continue;
            }
            $v = array_key_exists($key, $validated) ? $validated[$key] : $request->input($key);
            if ($type === 'boolean') {
                $credentials[$key] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                continue;
            }
            $trimmed = is_string($v) ? trim($v) : '';
            if (in_array($key, ['secret_key', 'webhook_secret', 'webhook_signing_secret'], true) && $trimmed === '' && ! empty($existingCredentials[$key])) {
                $credentials[$key] = $existingCredentials[$key];
                continue;
            }
            $credentials[$key] = $trimmed;
        }

        if ($slug === 'spacepag') {
            foreach (['public_key', 'secret_key', 'webhook_secret'] as $preserveField) {
                if (trim((string) ($credentials[$preserveField] ?? '')) === '' && ! empty($existingCredentials[$preserveField])) {
                    $credentials[$preserveField] = $existingCredentials[$preserveField];
                }
            }
            unset($credentials['base_url'], $credentials['api_key']);
            if (trim((string) ($credentials['public_key'] ?? '')) === '' && trim((string) ($credentials['secret_key'] ?? '')) === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Informe ao menos a chave pública (pk_) ou a chave privada (sk_) da Spacepag.',
                ], 422);
            }
        }

        // Preserve existing certificate_path when nenhum novo arquivo foi enviado
        if (! empty($existingCredentials['certificate_path'])) {
            $credentials['certificate_path'] = $existingCredentials['certificate_path'];
        }

        if (! empty($gateway['oauth'])) {
            foreach (['access_token', 'refresh_token', 'token_expires_at'] as $oauthKey) {
                if (! isset($credentials[$oauthKey]) || (string) ($credentials[$oauthKey] ?? '') === '') {
                    if (array_key_exists($oauthKey, $existingCredentials)) {
                        $credentials[$oauthKey] = $existingCredentials[$oauthKey];
                    }
                }
            }
        }

        if (! empty($existingCredentials['webhook_endpoint_id']) && empty($credentials['webhook_endpoint_id'] ?? null)) {
            $credentials['webhook_endpoint_id'] = $existingCredentials['webhook_endpoint_id'];
        }

        if ($certificateKey && $request->hasFile($certificateKey)) {
            $file = $request->file($certificateKey);
            if ($file->isValid() && strtolower($file->getClientOriginalExtension()) === 'p12') {
                $path = $file->storeAs(
                    'gateway_certs/' . ($tenantId ?? 'global'),
                    $slug . '.p12',
                    'local'
                );
                $absolutePath = Storage::path($path);
                $credentials['certificate_path'] = $absolutePath;
                $credentials['certificate_filename'] = $file->getClientOriginalName();
            }
        }

        $driver = GatewayRegistry::driver($slug);
        $isConnected = false;
        if ($driver && ! empty($credentials)) {
            try {
                if ($slug === 'spacepag' && $driver instanceof \App\Gateways\Spacepag\SpacepagDriver) {
                    $authMode = $driver->detectAuthMode($credentials);
                    if ($authMode !== null) {
                        $credentials['auth_mode'] = $authMode;
                        $isConnected = true;
                    }
                } else {
                    $isConnected = $driver->testConnection($credentials);
                }
            } catch (\Throwable) {
                $isConnected = false;
            }
        }

        $webhookWarning = null;
        if ($slug === 'cajupay' && $isConnected && $driver instanceof CajuPayDriver) {
            $credentials = $this->ensureCajuPayWebhookRegistered($driver, $credentials, $webhookWarning);
        }

        $credential->is_connected = $isConnected;
        $credential->setEncryptedCredentials($credentials);
        $credential->save();

        return response()->json([
            'success' => true,
            'is_connected' => $isConnected,
            'message' => $isConnected ? 'Credenciais salvas e conexão verificada.' : 'Credenciais salvas.',
            'webhook_warning' => $webhookWarning,
        ]);
    }

    public function test(Request $request, string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (!$gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        $credentialKeys = collect($gateway['credential_keys'] ?? []);
        $certificateKey = $gateway['certificate_key'] ?? null;

        $tenantId = auth()->user()->tenant_id;
        $credential = GatewayCredential::forTenant($tenantId)->where('gateway_slug', $slug)->first();
        $existingCredentials = $credential ? $credential->getDecryptedCredentials() : [];

        $rules = [];
        foreach ($credentialKeys as $keyDef) {
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '' || $key === $certificateKey) {
                continue;
            }
            if ($type === 'boolean') {
                $rules[$key] = ['nullable', 'boolean'];
                continue;
            }
            if ($slug === 'spacepag' && in_array($key, ['public_key', 'secret_key'], true)) {
                $rules[$key] = ['nullable', 'string', 'max:2000'];

                continue;
            }
            $optional = ! empty($keyDef['optional']);
            $rules[$key] = $optional ? ['nullable', 'string', 'max:2000'] : ['required', 'string', 'max:2000'];
        }
        $validated = $request->validate($rules);
        $credentials = $existingCredentials;
        foreach ($credentialKeys as $keyDef) {
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '' || $key === $certificateKey) {
                continue;
            }
            $v = $validated[$key] ?? null;
            if ($type === 'boolean') {
                $credentials[$key] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                continue;
            }
            if (is_string($v)) {
                $credentials[$key] = trim($v);
            }
        }

        foreach (['public_key', 'secret_key', 'webhook_secret', 'webhook_signing_secret', 'webhook_endpoint_id', 'auth_mode'] as $preserveKey) {
            if (
                (! isset($credentials[$preserveKey]) || $credentials[$preserveKey] === '' || $credentials[$preserveKey] === null)
                && ! empty($existingCredentials[$preserveKey])
            ) {
                $credentials[$preserveKey] = $existingCredentials[$preserveKey];
            }
        }

        if ($slug === 'spacepag') {
            foreach (['public_key', 'secret_key', 'webhook_secret', 'auth_mode'] as $preserveField) {
                if (trim((string) ($credentials[$preserveField] ?? '')) === '' && ! empty($existingCredentials[$preserveField])) {
                    $credentials[$preserveField] = $existingCredentials[$preserveField];
                }
            }
            if (trim((string) ($credentials['public_key'] ?? '')) === '' && trim((string) ($credentials['secret_key'] ?? '')) === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Informe ao menos a chave pública (pk_) ou a chave privada (sk_) da Spacepag.',
                ], 422);
            }
        }

        if ($certificateKey && empty($credentials['certificate_path'])) {
            return response()->json([
                'success' => false,
                'message' => 'Envie e salve o certificado P12 antes de testar a conexão.',
            ], 422);
        }

        $driver = GatewayRegistry::driver($slug);
        if (!$driver) {
            return response()->json(['success' => false, 'message' => 'Driver do gateway não disponível.'], 422);
        }

        try {
            $ok = false;
            if ($slug === 'spacepag' && $driver instanceof \App\Gateways\Spacepag\SpacepagDriver) {
                $authMode = $driver->detectAuthMode($credentials);
                if ($authMode !== null) {
                    $credentials['auth_mode'] = $authMode;
                    $ok = true;
                    if ($credential) {
                        $credential->setEncryptedCredentials($credentials);
                        $credential->is_connected = true;
                        $credential->save();
                    }
                }
            } else {
                $ok = $driver->testConnection($credentials);
            }

            $webhookWarning = null;
            if ($ok && $slug === 'cajupay' && $driver instanceof CajuPayDriver) {
                $credentials = $this->ensureCajuPayWebhookRegistered($driver, $credentials, $webhookWarning);
                if ($credential) {
                    $credential->setEncryptedCredentials($credentials);
                    $credential->save();
                }
            }

            $failMessage = $slug === 'spacepag'
                ? 'Falha na autenticação. Confira pk_ e sk_ no painel Spacepag (copie sem espaços) e salve de novo.'
                : 'Falha na autenticação. Verifique as credenciais.';

            return response()->json([
                'success' => $ok,
                'message' => $ok ? 'Conexão realizada com sucesso.' : $failMessage,
                'webhook_warning' => $webhookWarning,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Erro ao testar conexão.',
            ], 422);
        }
    }

    /**
     * Auto-registra (ou rotaciona) o endpoint de webhook na API CajuPay e devolve
     * o array de credentials atualizado com webhook_endpoint_id e webhook_signing_secret.
     * Falhas só geram warning para a UI — não impedem o save.
     *
     * @param  array<string, mixed>  $credentials
     * @param  string|null  $warning  out param
     * @return array<string, mixed>
     */
    private function ensureCajuPayWebhookRegistered(CajuPayDriver $driver, array $credentials, ?string &$warning): array
    {
        $warning = null;
        try {
            $url = route('webhooks.cajupay');
        } catch (\Throwable) {
            $warning = 'Webhook CajuPay: rota webhooks.cajupay indisponível.';
            return $credentials;
        }

        try {
            $existing = $driver->listWebhookEndpoints($credentials);
        } catch (\Throwable $e) {
            $existing = [];
            Log::debug('GatewaysController: list webhooks CajuPay falhou', ['error' => $e->getMessage()]);
        }

        $foundId = null;
        foreach ($existing as $endpoint) {
            if (! is_array($endpoint)) {
                continue;
            }
            if (($endpoint['url'] ?? null) === $url) {
                $foundId = is_string($endpoint['id'] ?? null) ? $endpoint['id'] : null;
                break;
            }
        }

        // Se já existe e já temos signing_secret salvo, mantém — para evitar invalidar
        // uma assinatura existente sem necessidade.
        if ($foundId !== null && ! empty($credentials['webhook_endpoint_id']) && ! empty($credentials['webhook_signing_secret'])) {
            return $credentials;
        }

        try {
            $reg = $driver->registerWebhookEndpoint($credentials, $url, $foundId);
        } catch (\Throwable $e) {
            $warning = 'Webhook ainda não registrado: ' . $e->getMessage();
            Log::warning('GatewaysController: registro de webhook CajuPay falhou', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);
            return $credentials;
        }

        $credentials['webhook_endpoint_id'] = $reg['endpoint_id'];
        if (! empty($reg['signing_secret'])) {
            $credentials['webhook_signing_secret'] = $reg['signing_secret'];
        }

        return $credentials;
    }

    public function updateCertificate(Request $request, string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (!$gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        $certificateKey = $gateway['certificate_key'] ?? null;
        if (!$certificateKey) {
            return response()->json([
                'success' => false,
                'message' => 'Este gateway não utiliza certificado.',
            ], 422);
        }

        $rules = [
            $certificateKey => ['required', 'file', 'max:4096'],
        ];
        $request->validate($rules);

        $tenantId = auth()->user()->tenant_id;
        $credential = GatewayCredential::forTenant($tenantId)->firstOrNew(
            ['gateway_slug' => $slug],
            ['tenant_id' => $tenantId]
        );

        $credentials = $credential->exists ? $credential->getDecryptedCredentials() : [];

        if (! $request->hasFile($certificateKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum arquivo recebido. Envie o certificado .p12 novamente.',
            ], 422);
        }

        /** @var \Illuminate\Http\UploadedFile|null $file */
        $file = $request->file($certificateKey);
        if ($file && $file->isValid() && strtolower($file->getClientOriginalExtension()) === 'p12') {
            $path = $file->storeAs(
                'gateway_certs/' . ($tenantId ?? 'global'),
                $slug . '.p12',
                'local'
            );
            $absolutePath = Storage::path($path);
            $credentials['certificate_path'] = $absolutePath;
            $credentials['certificate_filename'] = $file->getClientOriginalName();
            \Log::info('GatewaysController::updateCertificate saved certificate', [
                'slug' => $slug,
                'tenant_id' => $tenantId,
                'path' => $absolutePath,
                'keys' => array_keys($credentials),
            ]);
        } elseif ($file && $file->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'O certificado deve ser um arquivo .p12.',
            ], 422);
        }

        $driver = GatewayRegistry::driver($slug);
        $isConnected = $credential->is_connected ?? false;
        if ($driver && !empty($credentials)) {
            try {
                $isConnected = $driver->testConnection($credentials);
            } catch (\Throwable) {
                $isConnected = false;
            }
        }

        $credential->is_connected = $isConnected;
        $credential->setEncryptedCredentials($credentials);
        $credential->save();

        return response()->json([
            'success' => true,
            'is_connected' => $isConnected,
            'message' => $isConnected ? 'Certificado salvo e conexão verificada.' : 'Certificado salvo.',
        ]);
    }

    public function updateOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gateway_order' => ['required', 'array'],
            'gateway_order.pix' => ['nullable', 'array'],
            'gateway_order.pix.*' => ['string', 'max:64'],
            'gateway_order.card' => ['nullable', 'array'],
            'gateway_order.card.*' => ['string', 'max:64'],
            'gateway_order.boleto' => ['nullable', 'array'],
            'gateway_order.boleto.*' => ['string', 'max:64'],
            'gateway_order.pix_auto' => ['nullable', 'array'],
            'gateway_order.pix_auto.*' => ['string', 'max:64'],
            'gateway_order.apple_pay' => ['nullable', 'array'],
            'gateway_order.apple_pay.*' => ['string', 'max:64'],
            'gateway_order.google_pay' => ['nullable', 'array'],
            'gateway_order.google_pay.*' => ['string', 'max:64'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        Setting::set('gateway_order', $validated['gateway_order'], $tenantId);

        return response()->json(['success' => true, 'message' => 'Ordem de redundância atualizada.']);
    }

    /**
     * Build gateways list with is_configured and is_connected for tenant.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildGatewaysList(?int $tenantId): array
    {
        $all = GatewayRegistry::all();
        $credentialBySlug = GatewayCredential::forTenant($tenantId)
            ->get()
            ->keyBy('gateway_slug');

        return array_map(function ($g) use ($credentialBySlug) {
            $cred = $credentialBySlug->get($g['slug'] ?? '');
            $image = $g['image'] ?? null;
            return [
                'slug' => $g['slug'],
                'name' => $g['name'],
                'image' => GatewayRegistry::resolveImageUrl(is_string($image) ? $image : null),
                'methods' => $g['methods'] ?? [],
                'scope' => $g['scope'] ?? 'national',
                'signup_url' => $g['signup_url'] ?? null,
                'is_configured' => $cred !== null,
                'is_connected' => $cred?->is_connected ?? false,
            ];
        }, $all);
    }

    /**
     * @return array{pix: array<string>, card: array<string>, boleto: array<string>, pix_auto: array<string>, apple_pay: array<string>, google_pay: array<string>}
     */
    private function getGatewayOrder(?int $tenantId): array
    {
        $raw = Setting::get('gateway_order', null, $tenantId);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }
        $default = config('gateways.default_order', [
            'pix' => [],
            'card' => [],
            'boleto' => [],
            'pix_auto' => [],
            'apple_pay' => [],
            'google_pay' => [],
        ]);
        if (!is_array($raw)) {
            return $default;
        }
        return [
            'pix' => $raw['pix'] ?? $default['pix'] ?? [],
            'card' => $raw['card'] ?? $default['card'] ?? [],
            'boleto' => $raw['boleto'] ?? $default['boleto'] ?? [],
            'pix_auto' => $raw['pix_auto'] ?? $default['pix_auto'] ?? [],
            'apple_pay' => $raw['apple_pay'] ?? $default['apple_pay'] ?? [],
            'google_pay' => $raw['google_pay'] ?? $default['google_pay'] ?? [],
        ];
    }

    public function fees(string $slug): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $methods = ['pix', 'card', 'boleto'];
        $fees = [];
        foreach ($methods as $method) {
            $row = \App\Models\GatewayFeeSetting::forTenant($tenantId)
                ->where('gateway_slug', $slug)
                ->where('method', $method)
                ->first();
            $defaults = config('commissions.default_gateway_fees.'.$method, ['percent' => 0, 'fixed_cents' => 0]);
            $fees[$method] = [
                'percent' => $row ? (float) $row->percent : (float) ($defaults['percent'] ?? 0),
                'fixed_cents' => $row ? (int) $row->fixed_cents : (int) ($defaults['fixed_cents'] ?? 0),
            ];
        }

        return response()->json(['fees' => $fees]);
    }

    public function updateFees(Request $request, string $slug): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $validated = $request->validate([
            'fees' => ['required', 'array'],
            'fees.pix' => ['nullable', 'array'],
            'fees.pix.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fees.pix.fixed_cents' => ['nullable', 'integer', 'min:0'],
            'fees.card' => ['nullable', 'array'],
            'fees.card.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fees.card.fixed_cents' => ['nullable', 'integer', 'min:0'],
            'fees.boleto' => ['nullable', 'array'],
            'fees.boleto.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fees.boleto.fixed_cents' => ['nullable', 'integer', 'min:0'],
        ]);

        foreach (['pix', 'card', 'boleto'] as $method) {
            $cfg = $validated['fees'][$method] ?? null;
            if (! is_array($cfg)) {
                continue;
            }
            \App\Models\GatewayFeeSetting::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'gateway_slug' => $slug,
                    'method' => $method,
                ],
                [
                    'percent' => $cfg['percent'] ?? 0,
                    'fixed_cents' => $cfg['fixed_cents'] ?? 0,
                ]
            );
        }

        return response()->json(['success' => true]);
    }
}
