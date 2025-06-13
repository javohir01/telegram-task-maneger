<?php

use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TelegramUserController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Telegram webhook routes
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
Route::post('/telegram/set-webhook', [TelegramWebhookController::class, 'setWebhook']);
Route::get('/telegram/webhook-info', [TelegramWebhookController::class, 'getWebhookInfo']);
Route::post('/telegram/delete-webhook', [TelegramWebhookController::class, 'deleteWebhook']);

// User routes
Route::apiResource('users', TelegramUserController::class);

// Task routes
Route::apiResource('tasks', TaskController::class);
Route::delete('tasks/{task}/files/{file}', [TaskController::class, 'removeFile']);
