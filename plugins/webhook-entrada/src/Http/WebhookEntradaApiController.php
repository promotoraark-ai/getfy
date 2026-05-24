<?php

namespace Plugins\WebhookEntrada\Http;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Plugins\WebhookEntrada\Models\InboundWebhookEndpoint;

class WebhookEntradaApiController
{
    public function products(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $items = Product::query()
            ->forTenant($tenantId)
            ->where('type', Product::TYPE_AREA_MEMBROS)
            ->with([
                'offers:id,name,product_id',
                'subscriptionPlans:id,name,product_id',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'offers' => $p->offers->map(fn ($o) => ['id' => $o->id, 'name' => $o->name])->values()->all(),
                'subscription_plans' => $p->subscriptionPlans->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
            ]);

        return response()->json(['data' => $items]);
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $rows = InboundWebhookEndpoint::query()
            ->forTenant($tenantId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (InboundWebhookEndpoint $e) => $this->serializeEndpoint($e));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'product_id' => ['required', 'string', 'max:64'],
            'product_offer_id' => ['nullable', 'integer', 'min:1'],
            'subscription_plan_id' => ['nullable', 'integer', 'min:1'],
            'field_map' => ['nullable', 'array'],
            'signing_secret' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ]);

        $product = Product::query()
            ->forTenant($tenantId)
            ->where('id', $validated['product_id'])
            ->where('type', Product::TYPE_AREA_MEMBROS)
            ->first();

        if (! $product) {
            return response()->json(['message' => 'Produto inválido.'], 422);
        }

        $offerId = $validated['product_offer_id'] ?? null;
        $planId = $validated['subscription_plan_id'] ?? null;
        if ($offerId && $planId) {
            return response()->json(['message' => 'Escolha oferta ou plano, não ambos.'], 422);
        }

        if ($offerId) {
            $ok = $product->offers()->where('id', $offerId)->exists();
            if (! $ok) {
                return response()->json(['message' => 'Oferta não pertence ao produto.'], 422);
            }
        }
        if ($planId) {
            $ok = $product->subscriptionPlans()->where('id', $planId)->exists();
            if (! $ok) {
                return response()->json(['message' => 'Plano não pertence ao produto.'], 422);
            }
        }

        $fieldMapRaw = $validated['field_map'] ?? null;
        $fieldErrors = $this->fieldMapValidationErrors(is_array($fieldMapRaw) ? $fieldMapRaw : null);
        if ($fieldErrors !== []) {
            return response()->json(['message' => $fieldErrors[0]], 422);
        }
        $fieldMap = InboundWebhookEndpoint::sanitizeFieldMapForStorage(is_array($fieldMapRaw) ? $fieldMapRaw : null);

        $endpoint = InboundWebhookEndpoint::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'is_active' => $validated['is_active'] ?? true,
            'url_token' => InboundWebhookEndpoint::generateUrlToken(),
            'product_id' => $product->id,
            'product_offer_id' => $offerId,
            'subscription_plan_id' => $planId,
            'field_map' => $fieldMap,
            'signing_secret' => isset($validated['signing_secret']) && trim((string) $validated['signing_secret']) !== ''
                ? trim((string) $validated['signing_secret'])
                : null,
        ]);

        return response()->json($this->serializeEndpoint($endpoint->fresh()), 201);
    }

    public function update(Request $request, int $endpoint): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        /** @var InboundWebhookEndpoint|null $model */
        $model = InboundWebhookEndpoint::query()->forTenant($tenantId)->whereKey($endpoint)->first();
        if (! $model) {
            return response()->json(['message' => 'Não encontrado.'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'product_id' => ['sometimes', 'string', 'max:64'],
            'product_offer_id' => ['nullable', 'integer', 'min:1'],
            'subscription_plan_id' => ['nullable', 'integer', 'min:1'],
            'field_map' => ['nullable', 'array'],
            'signing_secret' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['product_id'])) {
            $product = Product::query()
                ->forTenant($tenantId)
                ->where('id', $validated['product_id'])
                ->where('type', Product::TYPE_AREA_MEMBROS)
                ->first();
            if (! $product) {
                return response()->json(['message' => 'Produto inválido.'], 422);
            }
            $model->product_id = $product->id;
        }

        $productCur = Product::query()->forTenant($tenantId)->where('id', $model->product_id)->first();
        if (! $productCur) {
            return response()->json(['message' => 'Produto inválido.'], 422);
        }

        if (array_key_exists('name', $validated)) {
            $model->name = $validated['name'];
        }
        if (array_key_exists('product_offer_id', $validated)) {
            $model->product_offer_id = $validated['product_offer_id'];
        }
        if (array_key_exists('subscription_plan_id', $validated)) {
            $model->subscription_plan_id = $validated['subscription_plan_id'];
        }
        if (array_key_exists('is_active', $validated)) {
            $model->is_active = (bool) $validated['is_active'];
        }

        $offerId = $model->product_offer_id ? (int) $model->product_offer_id : null;
        $planId = $model->subscription_plan_id ? (int) $model->subscription_plan_id : null;
        if ($offerId && $planId) {
            return response()->json(['message' => 'Escolha oferta ou plano, não ambos.'], 422);
        }
        if ($offerId && ! $productCur->offers()->where('id', $offerId)->exists()) {
            return response()->json(['message' => 'Oferta não pertence ao produto.'], 422);
        }
        if ($planId && ! $productCur->subscriptionPlans()->where('id', $planId)->exists()) {
            return response()->json(['message' => 'Plano não pertence ao produto.'], 422);
        }

        if (isset($validated['field_map'])) {
            $fm = $validated['field_map'];
            if (is_array($fm)) {
                $fieldErrors = $this->fieldMapValidationErrors($fm);
                if ($fieldErrors !== []) {
                    return response()->json(['message' => $fieldErrors[0]], 422);
                }
                $model->field_map = InboundWebhookEndpoint::sanitizeFieldMapForStorage($fm);
            } else {
                $model->field_map = null;
            }
        }

        if (array_key_exists('signing_secret', $validated)) {
            $s = $validated['signing_secret'];
            $model->signing_secret = (is_string($s) && trim($s) !== '') ? trim($s) : null;
        }

        $model->save();

        return response()->json($this->serializeEndpoint($model->fresh()));
    }

    public function destroy(Request $request, int $endpoint): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        /** @var InboundWebhookEndpoint|null $model */
        $model = InboundWebhookEndpoint::query()->forTenant($tenantId)->whereKey($endpoint)->first();
        if (! $model) {
            return response()->json(['message' => 'Não encontrado.'], 404);
        }
        $model->delete();

        return response()->json(['success' => true]);
    }

    public function regenerateToken(Request $request, int $endpoint): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        /** @var InboundWebhookEndpoint|null $model */
        $model = InboundWebhookEndpoint::query()->forTenant($tenantId)->whereKey($endpoint)->first();
        if (! $model) {
            return response()->json(['message' => 'Não encontrado.'], 404);
        }
        $model->url_token = InboundWebhookEndpoint::generateUrlToken();
        $model->save();

        return response()->json($this->serializeEndpoint($model->fresh()));
    }

    /**
     * @param  array<string, mixed>|null  $fieldMap
     * @return array<int, string>
     */
    private function fieldMapValidationErrors(?array $fieldMap): array
    {
        if ($fieldMap === null || $fieldMap === []) {
            return [];
        }

        $errors = [];
        foreach ($fieldMap as $k => $v) {
            if (! is_string($k) || trim($k) === '') {
                $errors[] = 'field_map: cada chave deve ser texto.';

                return $errors;
            }
            $k = trim($k);
            if ($k === InboundWebhookEndpoint::META_STRICT) {
                if (! is_bool($v) && ! is_int($v) && ! is_string($v)) {
                    $errors[] = 'field_map._strict deve ser booleano (true/false).';
                }

                continue;
            }
            if (! in_array($k, InboundWebhookEndpoint::FIELD_KEYS, true)) {
                continue;
            }
            if (is_string($v)) {
                if (strlen(trim($v)) > 255) {
                    $errors[] = "field_map.{$k}: cada caminho tem no máximo 255 caracteres.";
                }

                continue;
            }
            if (is_array($v)) {
                if (count($v) > 40) {
                    $errors[] = "field_map.{$k}: no máximo 40 caminhos em lista.";
                }
                foreach ($v as $item) {
                    if (! is_string($item) || trim($item) === '' || strlen(trim($item)) > 255) {
                        $errors[] = "field_map.{$k}: cada item da lista deve ser texto não vazio (dot notation, máx. 255 caracteres).";
                        break;
                    }
                }

                continue;
            }

            $errors[] = "field_map.{$k}: use um caminho (texto) ou lista ordenada de caminhos.";
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEndpoint(InboundWebhookEndpoint $e): array
    {
        $url = URL::route('webhook-entrada.inbound.post', ['token' => $e->url_token], true);

        return [
            'id' => $e->id,
            'name' => $e->name,
            'is_active' => (bool) $e->is_active,
            'url' => $url,
            'url_token_masked' => '••••'.substr($e->url_token, -8),
            'product_id' => $e->product_id,
            'product_offer_id' => $e->product_offer_id,
            'subscription_plan_id' => $e->subscription_plan_id,
            'field_map' => $e->field_map ?? (object) [],
            'signing_secret_set' => is_string($e->signing_secret) && trim($e->signing_secret) !== '',
        ];
    }
}
