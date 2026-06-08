<?php
    $path = request()->path();
    $isMemberArea = str_starts_with($path, 'm/') || request()->attributes->get('member_area_slug');
    $isCheckout = str_starts_with($path, 'c/') || str_starts_with($path, 'checkout') || str_starts_with($path, 'api-checkout');
    $skipPanelPwa = $isMemberArea || $isCheckout;
?>
<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        (function(){try{var s=localStorage.getItem('theme');var t=s||'dark';document.documentElement.classList.toggle('dark',t==='dark');}catch(_){}})();
    </script>
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <?php if(!empty($openGraph) && is_array($openGraph)): ?>
        <title><?php echo e(e($openGraph['title'] ?? config('getfy.app_name', config('app.name', 'Getfy')))); ?></title>
        <?php if(!empty($openGraph['description'])): ?>
            <meta name="description" content="<?php echo e(e($openGraph['description'])); ?>">
        <?php endif; ?>
        <meta property="og:type" content="<?php echo e(e($openGraph['type'] ?? 'website')); ?>">
        <meta property="og:site_name" content="<?php echo e(e($openGraph['site_name'] ?? config('getfy.app_name', config('app.name', 'Getfy')))); ?>">
        <meta property="og:title" content="<?php echo e(e($openGraph['title'] ?? '')); ?>">
        <?php if(!empty($openGraph['description'])): ?>
            <meta property="og:description" content="<?php echo e(e($openGraph['description'])); ?>">
        <?php endif; ?>
        <?php if(!empty($openGraph['url'])): ?>
            <meta property="og:url" content="<?php echo e(e($openGraph['url'])); ?>">
            <link rel="canonical" href="<?php echo e(e($openGraph['url'])); ?>">
        <?php endif; ?>
        <?php if(!empty($openGraph['favicon'])): ?>
            <link rel="icon" href="<?php echo e(e($openGraph['favicon'])); ?>" type="image/png" sizes="32x32">
            <link rel="shortcut icon" href="<?php echo e(e($openGraph['favicon'])); ?>" type="image/png">
        <?php endif; ?>
        <?php if(!empty($openGraph['image'])): ?>
            <meta property="og:image" content="<?php echo e(e($openGraph['image'])); ?>">
            <meta property="og:image:secure_url" content="<?php echo e(e($openGraph['image'])); ?>">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="<?php echo e(e($openGraph['title'] ?? '')); ?>">
            <?php if(!empty($openGraph['description'])): ?>
                <meta name="twitter:description" content="<?php echo e(e($openGraph['description'])); ?>">
            <?php endif; ?>
            <meta name="twitter:image" content="<?php echo e(e($openGraph['image'])); ?>">
        <?php endif; ?>
    <?php else: ?>
        <title><?php echo e(config('getfy.app_name', config('app.name', 'Getfy'))); ?></title>
    <?php endif; ?>
    <?php if (! ($skipPanelPwa)): ?>
    <?php
        $wlFavicon = \App\Support\BrandFavicon::publicUrl();
        $wlThemeColor = config('getfy.pwa_theme_color');
        $wlThemeColor = ($wlThemeColor !== null && $wlThemeColor !== '') ? $wlThemeColor : config('getfy.theme_primary', '#0ea5e9');
        $pwaIconPath = config('getfy.pwa_icon') ?: config('getfy.pwa_icon_192');
        $wlAppleIcon = (is_string($pwaIconPath) && $pwaIconPath !== '' && is_file(public_path(ltrim($pwaIconPath, '/'))))
            ? url('/'.ltrim($pwaIconPath, '/'))
            : null;
    ?>
    <link rel="icon" href="<?php echo e($wlFavicon); ?>" type="image/png" sizes="32x32">
    <link rel="shortcut icon" href="<?php echo e($wlFavicon); ?>" type="image/png">
    <link rel="manifest" href="<?php echo e(url('/manifest.json')); ?>">
    <meta name="theme-color" content="<?php echo e($wlThemeColor); ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <?php if($wlAppleIcon): ?>
    <link rel="apple-touch-icon" href="<?php echo e($wlAppleIcon); ?>">
    <?php endif; ?>
    <script>
        (function(){var e=null;window.addEventListener('beforeinstallprompt',function(t){t.preventDefault();e=t;window.__pwaInstallPrompt=e;},{capture:true});Object.defineProperty(window,'__pwaInstallPrompt',{get:function(){return e;},set:function(t){e=t;}});})();
    </script>
    <?php endif; ?>
    <?php if (!isset($__inertiaSsrDispatched)) { $__inertiaSsrDispatched = true; $__inertiaSsrResponse = app(\Inertia\Ssr\Gateway::class)->dispatch($page); }  if ($__inertiaSsrResponse) { echo $__inertiaSsrResponse->head; } ?>
    <?php
        $viteDevHot = public_path('hot');
        $vueBridgeBuilt = public_path('build/getfy-plugin-vue.mjs');
        $vueBridgeImport = (! file_exists($viteDevHot) && file_exists($vueBridgeBuilt))
            ? asset('build/getfy-plugin-vue.mjs')
            : \Illuminate\Support\Facades\Vite::asset('resources/js/plugins/getfyPluginVueBridge.js');
    ?>
    <script type="importmap">
        {"scopes":{"/plugins/":{"vue":<?php echo json_encode($vueBridgeImport, 15, 512) ?>}}}
    </script>
    <?php
        $assetContext = \App\Plugins\PluginAssetQueue::contextForRequest(request());
        \App\Plugins\PluginAssetQueue::fireHeadHooks($assetContext);
        $themeTokensHead = \App\Plugins\ThemeEngine::tokensForRequest(request());
        $pluginContextStyles = \App\Plugins\PluginAssetQueue::stylesFor($assetContext);
        $pluginContextScripts = \App\Plugins\PluginAssetQueue::scriptsFor($assetContext);
    ?>
    <?php if(!empty($themeTokensHead)): ?>
    <style>:root { <?php $__currentLoopData = $themeTokensHead; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $cssVar => $cssValue): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?> <?php echo e($cssVar); ?>: <?php echo e($cssValue); ?>; <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?> }</style>
    <?php endif; ?>
    <?php $__currentLoopData = $pluginContextStyles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $asset): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <link rel="stylesheet" href="<?php echo e($asset['url']); ?>" data-plugin-style="<?php echo e($asset['handle']); ?>">
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <?php $__currentLoopData = $pluginContextScripts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $asset): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <script src="<?php echo e($asset['url']); ?>" <?php if(!empty($asset['defer'])): ?> defer <?php endif; ?> data-plugin-script="<?php echo e($asset['handle']); ?>"></script>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
</head>
<body class="antialiased">
    <?php if($isMemberArea): ?>
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
    <?php endif; ?>
    <?php
        $page = $page ?? [];
        $page['props'] = array_merge(
            [
                'auth' => ['user' => null],
                'flash' => ['success' => null, 'error' => null],
                'platform' => null,
            ],
            $page['props'] ?? []
        );
    ?>
    <div id="app" data-page="<?php echo e(json_encode($page)); ?>"></div>
</body>
</html>
<?php /**PATH C:\laragon\www\getfy-opensource\resources\views/app.blade.php ENDPATH**/ ?>