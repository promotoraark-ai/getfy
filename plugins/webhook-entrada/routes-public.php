<?php

use Illuminate\Support\Facades\Route;
use Plugins\WebhookEntrada\Http\InboundWebhookController;

require_once __DIR__.'/src/Http/InboundWebhookController.php';
require_once __DIR__.'/src/Services/InboundWebhookFulfillmentService.php';
require_once __DIR__.'/src/Models/InboundWebhookEndpoint.php';

Route::post('/{token}', [InboundWebhookController::class, 'handle'])
    ->where('token', '[a-fA-F0-9]{64}')
    ->name('webhook-entrada.inbound.post');
