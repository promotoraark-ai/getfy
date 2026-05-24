<?php

use Illuminate\Support\Facades\Route;
use Plugins\WhiteLabel\WhiteLabelController;

Route::get('/settings/data', [WhiteLabelController::class, 'data'])->name('white-label.settings.data');
Route::put('/settings', [WhiteLabelController::class, 'update'])->name('white-label.settings.update');
Route::post('/settings/upload', [WhiteLabelController::class, 'upload'])->name('white-label.settings.upload');
Route::post('/settings/clear-field', [WhiteLabelController::class, 'clearField'])->name('white-label.settings.clear');
Route::post('/settings/sync-global', [WhiteLabelController::class, 'syncGlobal'])->name('white-label.settings.sync-global');
