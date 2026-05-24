<?php

require_once __DIR__.'/src/WhiteLabelSetting.php';
require_once __DIR__.'/src/ApplyWhiteLabelConfig.php';
require_once __DIR__.'/src/WhiteLabelController.php';

return function ($app, $events): void {
    $app->booted(function () use ($app) {
        $router = $app->make(\Illuminate\Routing\Router::class);
        $router->prependMiddlewareToGroup('web', \Plugins\WhiteLabel\ApplyWhiteLabelConfig::class);
    });
};
