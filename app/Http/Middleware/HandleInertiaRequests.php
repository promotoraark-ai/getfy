<?php

namespace App\Http\Middleware;

use App\Models\MemberNotification;
use App\Models\MemberPushSubscription;
use App\Models\PanelNotification;
use App\Plugins\PluginRegistry;
use App\Services\SalesAchievementsService;
use App\Services\StorageService;
use App\Services\TeamAccessService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id;

        $appSettings = $user ? [
            'app_name' => config('getfy.app_name'),
            'theme_primary' => config('getfy.theme_primary'),
            'app_logo' => config('getfy.app_logo'),
            'app_logo_dark' => config('getfy.app_logo_dark'),
            'app_logo_icon' => config('getfy.app_logo_icon'),
            'app_logo_icon_dark' => config('getfy.app_logo_icon_dark'),
        ] : null;

        $publicBranding = $this->buildPublicBranding();

        $pageTitle = $this->pageTitleForRoute($request->route()?->getName());

        $pluginNavItems = [];
        $plugins = [];
        $achievementsProgress = null;
        $pushEnabled = false;
        $vapidPublic = null;
        $settingsPluginTabs = [];
        if ($user && $user->canAccessPanel()) {
            $settingsPluginTabs = PluginRegistry::getSettingsTabs();
            $pluginNavItems = PluginRegistry::getMenuItems();
            $vapidPublic = config('getfy.pwa.vapid_public');
            $pushEnabled = ! empty($vapidPublic) && ! empty(config('getfy.pwa.vapid_private'));
            $installed = PluginRegistry::installed();
            $plugins = array_map(fn ($p) => [
                'slug' => $p['slug'],
                'name' => $p['name'],
                'version' => $p['version'],
                'is_enabled' => $p['is_enabled'],
            ], $installed);
            $achievementsProgress = app(SalesAchievementsService::class)->getProgressForTenant($user->tenant_id);
        }

        $notificationsUnreadCount = 0;
        if ($user && $user->canAccessPanel()) {
            $notificationsUnreadCount = PanelNotification::forUser($user->id)->unread()->count();
        }

        $path = $request->path();
        $isMemberArea = str_starts_with($path, 'm/') || $request->attributes->get('member_area_slug');
        $isCheckout = str_starts_with($path, 'c/') || str_starts_with($path, 'checkout') || str_starts_with($path, 'api-checkout');
        $skipPanelPwa = $isMemberArea || $isCheckout;

        $memberNotificationsUnreadCount = 0;
        $memberPushSubscribed = false;
        if ($user && $isMemberArea) {
            $product = $request->route('product') ?? $request->attributes->get('member_area_product');
            if ($product) {
                $memberNotificationsUnreadCount = MemberNotification::forUser($user->id)
                    ->forProduct($product->id)
                    ->unread()
                    ->count();
                $memberPushSubscribed = MemberPushSubscription::where('user_id', $user->id)
                    ->where('product_id', $product->id)
                    ->exists();
            }
        }

        $shared = [
            ...parent::share($request),
            'csrf_token' => $request->session()->token(),
            'app_url' => rtrim(config('app.url'), '/'),
            'pageTitle' => $pageTitle,
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role' => $user->role,
                    'avatar_url' => $user->avatar ? app(StorageService::class)->url($user->avatar) : null,
                ] : null,
                'permissions' => ($user && $user->canAccessPanel())
                    ? app(TeamAccessService::class)->permissionsFor($user)
                    : [],
                'allowed_product_ids' => ($user && $user->canAccessPanel())
                    ? app(TeamAccessService::class)->allowedProductIdsFor($user)
                    : [],
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'status' => $request->session()->get('status'),
                'zip_unavailable' => $request->session()->get('zip_unavailable'),
                'newly_unlocked_achievements' => $request->session()->get('newly_unlocked_achievements'),
            ],
            'platform' => null,
            'cloud_mode' => (bool) config('getfy.cloud_mode', false),
            'cloud_billing_renew_window_days' => (int) config('getfy.cloud.billing_renew_window_days', 7),
            'appSettings' => $appSettings,
            'public_branding' => $publicBranding,
            'settings_plugin_tabs' => $settingsPluginTabs,
            'pluginNavItems' => $pluginNavItems,
            'plugins' => $plugins,
            'achievementsProgress' => $achievementsProgress,
            'push_enabled' => $pushEnabled,
            'vapid_public' => $pushEnabled ? $vapidPublic : null,
            'notifications_unread_count' => $notificationsUnreadCount,
            'member_notifications_unread_count' => $memberNotificationsUnreadCount,
            'member_push_subscribed' => $memberPushSubscribed,
        ];

        if (! $skipPanelPwa) {
            $shared['pwa_manifest_url'] = url('/manifest.json');
            $shared['pwa_sw_url'] = url('/painel-sw.js');
        }

        return $shared;
    }

    private function pageTitleForRoute(?string $name): ?string
    {
        $titles = [
            'dashboard' => 'Dashboard',
            'vendas.index' => 'Vendas',
            'produtos.index' => 'Produtos',
            'produtos.create' => 'Novo produto',
            'produtos.edit' => 'Editar produto',
            'cupons.index' => 'Cupons',
            'assinaturas.index' => 'Assinaturas',
            'alunos.index' => 'Alunos',
            'relatorios.index' => 'Relatórios',
            'settings.index' => 'Configurações',
            'profile.index' => 'Meu perfil',
            'integrations.index' => 'Integrações',
            'plugins.index' => 'Plugins',
            'checkout.builder' => 'Editar checkout',
            'usuarios.index' => 'Usuários',
            'usuarios.create' => 'Novo infoprodutor',
            'email-marketing.index' => 'E-mail Marketing',
            'email-marketing.create' => 'Nova campanha',
            'email-marketing.edit' => 'Editar campanha',
            'api-applications.index' => 'API de Pagamentos',
            'api-applications.create' => 'Nova aplicação API',
            'api-applications.edit' => 'Editar aplicação API',
            'conquistas.index' => 'Conquistas',
        ];

        return $name ? ($titles[$name] ?? null) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPublicBranding(): array
    {
        $themePrimary = (string) config('getfy.theme_primary', '#00cc00');
        $pwaTheme = config('getfy.pwa_theme_color');
        $pwaTheme = ($pwaTheme !== null && $pwaTheme !== '') ? (string) $pwaTheme : $themePrimary;
        $favicon = config('getfy.favicon_url');
        $favicon = ($favicon !== null && $favicon !== '') ? (string) $favicon : 'https://cdn.getfy.cloud/collapsed-logo.png';
        $loginHero = config('getfy.login_hero_image');
        $loginHero = ($loginHero !== null && $loginHero !== '') ? (string) $loginHero : 'https://cdn.getfy.cloud/login.webp';

        return [
            'app_name' => (string) config('getfy.app_name', 'Getfy'),
            'theme_primary' => $themePrimary,
            'pwa_theme_color' => $pwaTheme,
            'app_logo' => (string) config('getfy.app_logo'),
            'app_logo_dark' => (string) config('getfy.app_logo_dark'),
            'app_logo_icon' => (string) config('getfy.app_logo_icon'),
            'app_logo_icon_dark' => (string) config('getfy.app_logo_icon_dark'),
            'login_hero_image' => $loginHero,
            'favicon_url' => $favicon,
            'pwa_icon_192' => config('getfy.pwa_icon_192'),
            'pwa_icon_512' => config('getfy.pwa_icon_512'),
        ];
    }
}
