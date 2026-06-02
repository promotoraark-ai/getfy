<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\PanelPushSubscription;
use App\Models\User;
use Tests\TestCase;

class PanelPushSubscribeRenewedTest extends TestCase
{
    public function test_push_subscribe_accepts_renewed_flag_and_resets_fail_counters(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $endpoint = 'https://example.com/push/renewed-endpoint';

        PanelPushSubscription::create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'endpoint' => $endpoint,
            'keys' => ['auth' => 'AAA+/=', 'p256dh' => 'BBB+/='],
            'push_fail_count' => 3,
            'last_push_failed_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user)->postJson('/painel/push-subscribe', [
            'endpoint' => $endpoint,
            'keys' => ['auth' => 'CCC+/=', 'p256dh' => 'DDD+/='],
            'renewed' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('renewed', true);

        $sub = PanelPushSubscription::where('endpoint', $endpoint)->first();
        $this->assertNotNull($sub);
        $this->assertSame(0, $sub->push_fail_count);
        $this->assertNull($sub->last_push_failed_at);
    }
}
