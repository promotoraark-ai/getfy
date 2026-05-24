<?php

use Illuminate\Support\Facades\Route;
use Plugins\WebhookEntrada\Http\WebhookEntradaApiController;

require_once __DIR__.'/src/Http/WebhookEntradaApiController.php';

Route::middleware('team.permission:integracoes.view')->group(function () {
    Route::get('/api/products', [WebhookEntradaApiController::class, 'products'])->name('webhook-entrada.api.products');
    Route::get('/api/endpoints', [WebhookEntradaApiController::class, 'index'])->name('webhook-entrada.api.endpoints.index');
    Route::post('/api/endpoints', [WebhookEntradaApiController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('webhook-entrada.api.endpoints.store');
    Route::put('/api/endpoints/{endpoint}', [WebhookEntradaApiController::class, 'update'])
        ->whereNumber('endpoint')
        ->middleware('throttle:60,1')
        ->name('webhook-entrada.api.endpoints.update');
    Route::delete('/api/endpoints/{endpoint}', [WebhookEntradaApiController::class, 'destroy'])
        ->whereNumber('endpoint')
        ->middleware('throttle:30,1')
        ->name('webhook-entrada.api.endpoints.destroy');
    Route::post('/api/endpoints/{endpoint}/regenerate-token', [WebhookEntradaApiController::class, 'regenerateToken'])
        ->whereNumber('endpoint')
        ->middleware('throttle:20,1')
        ->name('webhook-entrada.api.endpoints.regenerate-token');
});
