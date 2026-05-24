<?php

$base = __DIR__.DIRECTORY_SEPARATOR.'src';

foreach ([
    '/Models/InboundWebhookEndpoint.php',
    '/Http/InboundWebhookController.php',
    '/Http/WebhookEntradaApiController.php',
    '/Services/InboundWebhookFulfillmentService.php',
] as $file) {
    $path = $base.$file;
    if (is_file($path)) {
        require_once $path;
    }
}

return function (): void {
    //
};
