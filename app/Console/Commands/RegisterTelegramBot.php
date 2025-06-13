<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RegisterTelegramBot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:register {--delete : Delete the webhook before setting it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register the Telegram bot webhook';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $token = config('services.telegram.bot_token');
        $webhookUrl = config('services.telegram.webhook_url');

        if (!$token) {
            $this->error('Telegram bot token not configured. Please set TELEGRAM_BOT_TOKEN in your .env file.');
            return 1;
        }

        if (!$webhookUrl) {
            $this->error('Telegram webhook URL not configured. Please set TELEGRAM_WEBHOOK_URL in your .env file.');
            return 1;
        }

        // Delete webhook if requested
        if ($this->option('delete')) {
            $this->info('Deleting existing webhook...');
            $deleteResponse = Http::get("https://api.telegram.org/bot{$token}/deleteWebhook");
            $deleteResult = $deleteResponse->json();
            
            if ($deleteResult['ok'] ?? false) {
                $this->info('Webhook deleted successfully.');
            } else {
                $this->error('Failed to delete webhook: ' . json_encode($deleteResult));
                return 1;
            }
        }

        // Set webhook
        $this->info('Setting webhook to: ' . $webhookUrl);
        $response = Http::post("https://api.telegram.org/bot{$token}/setWebhook", [
            'url' => $webhookUrl,
            'allowed_updates' => ['message', 'callback_query'],
        ]);

        $result = $response->json();

        if ($result['ok'] ?? false) {
            $this->info('Webhook set successfully!');
            
            // Get webhook info
            $infoResponse = Http::get("https://api.telegram.org/bot{$token}/getWebhookInfo");
            $infoResult = $infoResponse->json();
            
            if ($infoResult['ok'] ?? false) {
                $this->info('Webhook info:');
                $this->table(
                    ['Property', 'Value'],
                    collect($infoResult['result'])->map(function ($value, $key) {
                        return [$key, is_array($value) ? json_encode($value) : $value];
                    })->toArray()
                );
            }
            
            return 0;
        } else {
            $this->error('Failed to set webhook: ' . json_encode($result));
            return 1;
        }
    }
}
