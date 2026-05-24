<?php

namespace App\Http\Controllers;

use App\Models\ApiApplication;
use App\Models\Product;
use App\Models\UtmifyIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UtmifyController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'api_key' => ['required', 'string', 'max:512'],
            'is_active' => ['boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['string', 'exists:products,id'],
            'api_application_ids' => ['nullable', 'array'],
            'api_application_ids.*' => ['integer', 'exists:api_applications,id'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        $this->ensureProductIdsBelongToTenant($tenantId, $validated['product_ids'] ?? []);
        $this->ensureApiApplicationIdsBelongToTenant($tenantId, $validated['api_application_ids'] ?? []);

        $integration = UtmifyIntegration::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'api_key' => $validated['api_key'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (! empty($validated['product_ids'])) {
            $integration->products()->sync($validated['product_ids']);
        }
        if (! empty($validated['api_application_ids'])) {
            $integration->apiApplications()->sync($validated['api_application_ids']);
        }

        return response()->json([
            'integration' => $this->integrationToArray($integration),
        ], 201);
    }

    public function update(Request $request, UtmifyIntegration $utmify): JsonResponse
    {
        $this->authorizeIntegration($utmify);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:512'],
            'is_active' => ['boolean'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['string', 'exists:products,id'],
            'api_application_ids' => ['nullable', 'array'],
            'api_application_ids.*' => ['integer', 'exists:api_applications,id'],
        ]);

        $this->ensureProductIdsBelongToTenant($utmify->tenant_id, $validated['product_ids'] ?? []);
        $this->ensureApiApplicationIdsBelongToTenant($utmify->tenant_id, $validated['api_application_ids'] ?? []);

        $utmify->update([
            'name' => $validated['name'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if ($request->has('api_key')) {
            $utmify->api_key = $validated['api_key'] !== '' ? $validated['api_key'] : null;
            $utmify->save();
        }

        if (array_key_exists('product_ids', $validated)) {
            $utmify->products()->sync($validated['product_ids'] ?? []);
        }
        if (array_key_exists('api_application_ids', $validated)) {
            $utmify->apiApplications()->sync($validated['api_application_ids'] ?? []);
        }

        $utmify->load('products:id,name', 'apiApplications:id,name');

        return response()->json([
            'integration' => $this->integrationToArray($utmify),
        ]);
    }

    public function destroy(UtmifyIntegration $utmify): JsonResponse
    {
        $this->authorizeIntegration($utmify);
        $utmify->products()->detach();
        $utmify->apiApplications()->detach();
        $utmify->delete();

        return response()->json(null, 204);
    }

    private function authorizeIntegration(UtmifyIntegration $integration): void
    {
        $tenantId = auth()->user()->tenant_id;
        if ($integration->tenant_id !== $tenantId) {
            abort(404);
        }
    }

    /**
     * @param  array<int, string>  $productIds
     */
    private function ensureProductIdsBelongToTenant(?int $tenantId, array $productIds): void
    {
        if (empty($productIds)) {
            return;
        }
        $count = Product::forTenant($tenantId)->whereIn('id', $productIds)->count();
        if ($count !== count($productIds)) {
            abort(422, 'Um ou mais produtos não pertencem ao seu tenant.');
        }
    }

    private function integrationToArray(UtmifyIntegration $i): array
    {
        $i->load('products:id,name', 'apiApplications:id,name');

        return [
            'id' => $i->id,
            'name' => $i->name,
            'is_active' => $i->is_active,
            'configured' => $i->api_key !== null && $i->api_key !== '',
            'product_ids' => $i->products->pluck('id')->values()->all(),
            'products' => $i->products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            'api_application_ids' => $i->apiApplications->pluck('id')->values()->all(),
            'api_applications' => $i->apiApplications->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values()->all(),
        ];
    }

    /**
     * @param  array<int, int>  $apiApplicationIds
     */
    private function ensureApiApplicationIdsBelongToTenant(?int $tenantId, array $apiApplicationIds): void
    {
        if (empty($apiApplicationIds)) {
            return;
        }
        $count = ApiApplication::forTenant($tenantId)->whereIn('id', $apiApplicationIds)->count();
        if ($count !== count($apiApplicationIds)) {
            abort(422, 'Uma ou mais aplicações da API não pertencem ao seu tenant.');
        }
    }
}
