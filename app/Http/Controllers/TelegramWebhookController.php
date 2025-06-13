<?php

namespace App\Http\Controllers;

use App\Services\TelegramBotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    protected $telegramService;

    public function __construct(TelegramBotService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    /**
     * Handle the incoming Telegram webhook request.
     */
    public function handle(Request $request)
    {
        $update = $request->all();
        
        Log::info('Telegram webhook received', [
            'update_id' => $update['update_id'] ?? null,
            'has_message' => isset($update['message']),
            'has_callback_query' => isset($update['callback_query']),
        ]);

        try {
            $this->telegramService->processUpdate($update);
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('Error processing Telegram update', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'update' => $update,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Error processing update: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set the Telegram webhook URL.
     */
    public function setWebhook(Request $request)
    {
        $token = config('services.telegram.bot_token');
        $webhookUrl = config('services.telegram.webhook_url');
        
        if (!$token || !$webhookUrl) {
            return response()->json([
                'status' => 'error',
                'message' => 'Telegram bot token or webhook URL not configured'
            ], 500);
        }

        $url = "https://api.telegram.org/bot{$token}/setWebhook";
        $response = \Http::post($url, [
            'url' => $webhookUrl,
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        $result = $response->json();

        if ($result['ok'] ?? false) {
            return response()->json([
                'status' => 'success',
                'message' => 'Webhook set successfully',
                'data' => $result
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to set webhook',
                'data' => $result
            ], 500);
        }
    }

    /**
     * Get information about the current webhook.
     */
    public function getWebhookInfo()
    {
        $token = config('services.telegram.bot_token');
        
        if (!$token) {
            return response()->json([
                'status' => 'error',
                'message' => 'Telegram bot token not configured'
            ], 500);
        }

        $url = "https://api.telegram.org/bot{$token}/getWebhookInfo";
        $response = \Http::get($url);

        return response()->json([
            'status' => 'success',
            'data' => $response->json()
        ]);
    }

    /**
     * Delete the current webhook.
     */
    public function deleteWebhook()
    {
        $token = config('services.telegram.bot_token');
        
        if (!$token) {
            return response()->json([
                'status' => 'error',
                'message' => 'Telegram bot token not configured'
            ], 500);
        }

        $url = "https://api.telegram.org/bot{$token}/deleteWebhook";
        $response = \Http::get($url);

        return response()->json([
            'status' => 'success',
            'data' => $response->json()
        ]);
    }
}
