<?php

use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TelegramUserController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
Route::post('/telegram/set-webhook', [TelegramWebhookController::class, 'setWebhook']);
Route::get('/telegram/webhook-info', [TelegramWebhookController::class, 'getWebhookInfo']);
Route::post('/telegram/delete-webhook', [TelegramWebhookController::class, 'deleteWebhook']);

Route::apiResource('users', TelegramUserController::class);

Route::apiResource('tasks', TaskController::class);
Route::delete('tasks/{task}/files/{file}', [TaskController::class, 'removeFile']);
