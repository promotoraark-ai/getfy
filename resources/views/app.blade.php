@php
    $path = request()->path();
    $isMemberArea = str_starts_with($path, 'm/') || request()->attributes->get('member_area_slug');
    $isCheckout = str_starts_with($path, 'c/') || str_starts_with($path, 'checkout') || str_starts_with($path, 'api-checkout');
    $skipPanelPwa = $isMemberArea || $isCheckout;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        (function(){try{var s=localStorage.getItem('theme');var t=s||'dark';document.documentElement.classList.toggle('dark',t==='dark');}catch(_){}})();
    </script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if(!empty($openGraph) && is_array($openGraph))
        <title>{{ e($openGraph['title'] ?? config('getfy.app_name', config('app.name', 'Getfy'))) }}</title>
        @if(!empty($openGraph['description']))
            <meta name="description" content="{{ e($openGraph['description']) }}">
        @endif
        <meta property="og:type" content="{{ e($openGraph['type'] ?? 'website') }}">
        <meta property="og:site_name" content="{{ e($openGraph['site_name'] ?? config('getfy.app_name', config('app.name', 'Getfy'))) }}">
        <meta property="og:title" content="{{ e($openGraph['title'] ?? '') }}">
        @if(!empty($openGraph['description']))
            <meta property="og:description" content="{{ e($openGraph['description']) }}">
        @endif
        @if(!empty($openGraph['url']))
            <meta property="og:url" content="{{ e($openGraph['url']) }}">
            <link rel="canonical" href="{{ e($openGraph['url']) }}">
        @endif
        @if(!empty($openGraph['favicon']))
            <link rel="icon" href="{{ e($openGraph['favicon']) }}" type="image/png" sizes="32x32">
            <link rel="shortcut icon" href="{{ e($openGraph['favicon']) }}" type="image/png">
        @endif
        @if(!empty($openGraph['image']))
            <meta property="og:image" content="{{ e($openGraph['image']) }}">
            <meta property="og:image:secure_url" content="{{ e($openGraph['image']) }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ e($openGraph['title'] ?? '') }}">
            @if(!empty($openGraph['description']))
                <meta name="twitter:description" content="{{ e($openGraph['description']) }}">
            @endif
            <meta name="twitter:image" content="{{ e($openGraph['image']) }}">
        @endif
    @else
        <title>{{ config('getfy.app_name', config('app.name', 'Getfy')) }}</title>
    @endif
    @unless($skipPanelPwa)
    @php
        $wlFavicon = \App\Support\BrandFavicon::publicUrl();
        $wlThemeColor = config('getfy.pwa_theme_color');
        $wlThemeColor = ($wlThemeColor !== null && $wlThemeColor !== '') ? $wlThemeColor : config('getfy.theme_primary', '#0ea5e9');
        $pwaIconPath = config('getfy.pwa_icon') ?: config('getfy.pwa_icon_192');
        $wlAppleIcon = (is_string($pwaIconPath) && $pwaIconPath !== '' && is_file(public_path(ltrim($pwaIconPath, '/'))))
            ? url('/'.ltrim($pwaIconPath, '/'))
            : null;
    @endphp
    <link rel="icon" href="{{ $wlFavicon }}" type="image/png" sizes="32x32">
    <link rel="shortcut icon" href="{{ $wlFavicon }}" type="image/png">
    <link rel="manifest" href="{{ url('/manifest.json') }}">
    <meta name="theme-color" content="{{ $wlThemeColor }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    @if($wlAppleIcon)
    <link rel="apple-touch-icon" href="{{ $wlAppleIcon }}">
    @endif
    <script>
        (function(){var e=null;window.addEventListener('beforeinstallprompt',function(t){t.preventDefault();e=t;window.__pwaInstallPrompt=e;},{capture:true});Object.defineProperty(window,'__pwaInstallPrompt',{get:function(){return e;},set:function(t){e=t;}});})();
    </script>
    @endunless
    @inertiaHead
    @php
        $viteDevHot = public_path('hot');
        $vueBridgeBuilt = public_path('build/getfy-plugin-vue.mjs');
        $vueBridgeImport = (! file_exists($viteDevHot) && file_exists($vueBridgeBuilt))
            ? asset('build/getfy-plugin-vue.mjs')
            : \Illuminate\Support\Facades\Vite::asset('resources/js/plugins/getfyPluginVueBridge.js');
    @endphp
    <script type="importmap">
        {"scopes":{"/plugins/":{"vue":@json($vueBridgeImport)}}}
    </script>
    @php
        $assetContext = \App\Plugins\PluginAssetQueue::contextForRequest(request());
        \App\Plugins\PluginAssetQueue::fireHeadHooks($assetContext);
        $themeTokensHead = \App\Plugins\ThemeEngine::tokensForRequest(request());
        $pluginContextStyles = \App\Plugins\PluginAssetQueue::stylesFor($assetContext);
        $pluginContextScripts = \App\Plugins\PluginAssetQueue::scriptsFor($assetContext);
    @endphp
    @if (!empty($themeTokensHead))
    <style>:root { @foreach ($themeTokensHead as $cssVar => $cssValue) {{ $cssVar }}: {{ $cssValue }}; @endforeach }</style>
    @endif
    @foreach ($pluginContextStyles as $asset)
    <link rel="stylesheet" href="{{ $asset['url'] }}" data-plugin-style="{{ $asset['handle'] }}">
    @endforeach
    @foreach ($pluginContextScripts as $asset)
    <script src="{{ $asset['url'] }}" @if(!empty($asset['defer'])) defer @endif data-plugin-script="{{ $asset['handle'] }}"></script>
    @endforeach
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased">
    @if($isMemberArea)
    <style>
        #app:empty { min-height: 100vh; background: #18181b; }
        #app:empty::before {
            content: '';
            position: fixed;
            inset: 0;
            margin: auto;
            width: 2.5rem;
            height: 2.5rem;
            border: 3px solid rgba(255,255,255,0.15);
            border-top-color: #0ea5e9;
            border-radius: 50%;
            animation: ma-boot-spin 0.75s linear infinite;
        }
        @keyframes ma-boot-spin { to { transform: rotate(360deg); } }
    </style>
    @endif
    @php
        $page = $page ?? [];
        $page['props'] = array_merge(
            [
                'auth' => ['user' => null],
                'flash' => ['success' => null, 'error' => null],
                'platform' => null,
            ],
            $page['props'] ?? []
        );
    @endphp
    <div id="app" data-page="{{ json_encode($page) }}"></div>
</body>
</html>
