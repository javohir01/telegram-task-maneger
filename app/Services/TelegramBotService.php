<?php

namespace App\Services;

use App\Models\TelegramGroup;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    protected string $apiUrl;
    protected string $token;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token');
        $this->apiUrl = "https://api.telegram.org/bot{$this->token}";
    }

    /**
     * Send a message to a chat.
     *
     * @param int|string $chatId
     * @param string $text
     * @param array $options
     * @return array|null
     */
    public function sendMessage($chatId, string $text, array $options = []): ?array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $options);

        try {
            $response = Http::post("{$this->apiUrl}/sendMessage", $params);
            return $response->json();
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram message', [
                'error' => $e->getMessage(),
                'chat_id' => $chatId,
                'text' => $text,
            ]);
            return null;
        }
    }

    /**
     * Send a message with inline keyboard.
     *
     * @param int|string $chatId
     * @param string $text
     * @param array $keyboard
     * @return array|null
     */
    public function sendInlineKeyboard($chatId, string $text, array $keyboard): ?array
    {
        return $this->sendMessage($chatId, $text, [
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ])
        ]);
    }

    /**
     * Process an update from Telegram.
     *
     * @param array $update
     * @return void
     */
    public function processUpdate(array $update): void
    {
        if (isset($update['message'])) {
            $this->processMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->processCallbackQuery($update['callback_query']);
        }
    }

    /**
     * Process a message from Telegram.
     *
     * @param array $message
     * @return void
     */
    protected function processMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $user = $message['from'];

        // Save or update user
        $telegramUser = $this->saveUser($user);

        // Check if this is a group chat
        if (in_array($message['chat']['type'], ['group', 'supergroup'])) {
            $this->saveGroup($message['chat'], $telegramUser);
        }

        // Process commands
        if (str_starts_with($text, '/')) {
            $this->processCommand($text, $chatId, $telegramUser);
        }
    }

    /**
     * Process a callback query from Telegram.
     *
     * @param array $callbackQuery
     * @return void
     */
    protected function processCallbackQuery(array $callbackQuery): void
    {
        $data = $callbackQuery['data'];
        $chatId = $callbackQuery['message']['chat']['id'];
        $user = $callbackQuery['from'];
        $messageId = $callbackQuery['message']['message_id'];

        // Save or update user
        $telegramUser = $this->saveUser($user);

        // Process callback data
        $parts = explode(':', $data);
        $action = $parts[0] ?? '';

        switch ($action) {
            case 'task_list':
                $this->sendTaskList($chatId, $telegramUser);
                break;
            case 'task_create':
                $this->startTaskCreation($chatId, $telegramUser);
                break;
            case 'task_view':
                $taskId = $parts[1] ?? null;
                if ($taskId) {
                    $this->viewTask($chatId, $telegramUser, $taskId);
                }
                break;
            case 'task_edit':
                $taskId = $parts[1] ?? null;
                if ($taskId) {
                    $this->editTask($chatId, $telegramUser, $taskId);
                }
                break;
            case 'task_delete':
                $taskId = $parts[1] ?? null;
                if ($taskId) {
                    $this->deleteTask($chatId, $telegramUser, $taskId);
                }
                break;
            case 'task_status':
                $taskId = $parts[1] ?? null;
                $status = $parts[2] ?? null;
                if ($taskId && $status) {
                    $this->updateTaskStatus($chatId, $telegramUser, $taskId, $status);
                }
                break;
        }

        // Answer callback query to remove loading state
        Http::post("{$this->apiUrl}/answerCallbackQuery", [
            'callback_query_id' => $callbackQuery['id'],
        ]);
    }

    /**
     * Process a command from Telegram.
     *
     * @param string $text
     * @param int|string $chatId
     * @param TelegramUser $user
     * @return void
     */
    protected function processCommand(string $text, $chatId, TelegramUser $user): void
    {
        $command = strtolower(explode(' ', $text)[0]);

        switch ($command) {
            case '/start':
                $this->handleStartCommand($chatId, $user);
                break;
            case '/help':
                $this->handleHelpCommand($chatId);
                break;
            case '/tasks':
                $this->sendTaskList($chatId, $user);
                break;
            case '/create':
                $this->startTaskCreation($chatId, $user);
                break;
            default:
                $this->sendMessage($chatId, "Невідома команда. Використайте /help для отримання списку доступних команд.");
                break;
        }
    }

    /**
     * Handle the /start command.
     *
     * @param int|string $chatId
     * @param TelegramUser $user
     * @return void
     */
    protected function handleStartCommand($chatId, TelegramUser $user): void
    {
        $name = $user->first_name ?: 'користувач';
        $message = "Привіт, {$name}! 👋\n\n";
        $message .= "Ласкаво просимо до Task Manager бота. Цей бот допоможе вам керувати вашими завданнями.\n\n";
        $message .= "Використайте /help для отримання списку доступних команд.";

        $keyboard = [
            [
                ['text' => '📋 Мої завдання', 'callback_data' => 'task_list'],
                ['text' => '➕ Створити завдання', 'callback_data' => 'task_create']
            ],
            [
                ['text' => '❓ Допомога', 'callback_data' => 'help']
            ]
        ];

        $this->sendInlineKeyboard($chatId, $message, $keyboard);
    }

    /**
     * Handle the /help command.
     *
     * @param int|string $chatId
     * @return void
     */
    protected function handleHelpCommand($chatId): void
    {
        $message = "<b>Доступні команди:</b>\n\n";
        $message .= "/start - Запуск бота та реєстрація користувача.\n";
        $message .= "/help - Вивід довідки по командам бота.\n";
        $message .= "/tasks - Переглянути список ваших завдань.\n";
        $message .= "/create - Створити нове завдання.\n";

        $this->sendMessage($chatId, $message);
    }

    /**
     * Send the task list to the user.
     *
     * @param int|string $chatId
     * @param TelegramUser $user
     * @return void
     */
    protected function sendTaskList($chatId, TelegramUser $user): void
    {
        $tasks = $user->tasks()->orderBy('created_at', 'desc')->get();

        if ($tasks->isEmpty()) {
            $message = "У вас поки немає завдань. Використайте команду /create щоб створити нове завдання.";
            $keyboard = [
                [
                    ['text' => '➕ Створити завдання', 'callback_data' => 'task_create']
                ]
            ];
            $this->sendInlineKeyboard($chatId, $message, $keyboard);
            return;
        }

        $message = "<b>Ваші завдання:</b>\n\n";
        
        foreach ($tasks as $index => $task) {
            $statusEmoji = $this->getStatusEmoji($task->status);
            $priorityEmoji = $this->getPriorityEmoji($task->priority);
            
            $message .= "{$index + 1}. {$statusEmoji} {$priorityEmoji} <b>{$task->title}</b>\n";
            
            if ($task->due_date) {
                $message .= "📅 До: " . $task->due_date->format('d.m.Y H:i') . "\n";
            }
            
            $message .= "\n";
        }

        $keyboard = [];
        foreach ($tasks as $task) {
            $keyboard[] = [
                ['text' => "👁️ {$task->title}", 'callback_data' => "task_view:{$task->id}"]
            ];
        }
        
        $keyboard[] = [
            ['text' => '➕ Створити завдання', 'callback_data' => 'task_create']
        ];

        $this->sendInlineKeyboard($chatId, $message, $keyboard);
    }

    /**
     * Start the task creation process.
     *
     * @param int|string $chatId
     * @param TelegramUser $user
     * @return void
     */
    protected function startTaskCreation($chatId, TelegramUser $user): void
    {
        // This would typically involve a conversation flow
        // For simplicity, we'll just send instructions
        $message = "Щоб створити нове завдання, відправте повідомлення у форматі:\n\n";
        $message .= "<code>/create Назва завдання | Опис завдання | Пріоритет | Дата виконання</code>\n\n";
        $message .= "Наприклад:\n";
        $message .= "<code>/create Купити молоко | Купити 2 літри молока в магазині | high | 2023-06-15</code>\n\n";
        $message .= "Пріоритет може бути: low, medium, high, urgent\n";
        $message .= "Дата у форматі YYYY-MM-DD";

        $this->sendMessage($chatId, $message);
    }

    /**
     * View a task.
     *
     * @param int|string $chatId
     * @param TelegramUser $user
     * @param int $taskId
     * @return void
     */
    protected function viewTask($chatId, TelegramUser $user, $taskId): void
    {
        $task = $user->tasks()->find($taskId);

        if (!$task) {
            $this->sendMessage($chatId, "Завдання не знайдено або у вас немає доступу до нього.");
            return;
        }

        $statusEmoji = $this->getStatusEmoji($task->status);
        $priorityEmoji = $this->getPriorityEmoji($task->priority);
        
        $message = "<b>{$task->title}</b>\n\n";
        $message .= "Статус: {$statusEmoji} " . ucfirst($task->status) . "\n";
        $message .= "Пріоритет: {$priorityEmoji} " . ucfirst($task->priority) . "\n";
        
        if ($task->due_date) {
            $message .= "Термін виконання: 📅 " . $task->due_date->format('d.m.Y H:i') . "\n";
        }
        
        if ($task->description) {
            $message .= "\nОпис:\n{$task->description}\n";
        }
        
        $files = $task->files;
        if ($files->count() > 0) {
            $message .= "\nПрикріплені файли: " . $files->count() . "\n";
        }

        $keyboard = [
            [
                ['text' => '✏️ Редагувати', 'callback_data' => "task_edit:{$task->id}"],
                ['text' => '🗑️ Видалити', 'callback_data' => "task_delete:{$task->id}"]
            ],
            [
                ['text' => '✅ Виконано', 'callback_data' => "task_status:{$task->id}:completed"],
                ['text' => '⏳ В процесі', 'callback_data' => "task_status:{$task->id}:in_progress"]
            ],
            [
                ['text' => '◀️ Назад до списку', 'callback_data' => "task_list"]
            ]
        ];

        $this->sendInlineKeyboard($chatId, $message, $keyboard);
    }

    /**
     * Edit a task.
     *
     * @param int|string $chatId
     * @param TelegramUser $user
     * @param int $taskId
     * @return void
     */
    protected function editTask($chatId, TelegramUser $user, $taskId): void
    {
        // This would typically involve a conversation flow
        // For simplicity, we'll just send instructions
        $message = "Щоб редагувати завдання, відправте повідомлення у форматі:\n\n";
        $message .= "<code>/edit {$taskId} Назва завдання | Опис завдання | Пріоритет | Дата виконання</code>\n\n";
        $message .= "Наприклад:\n";
        $message .= "<code>/edit {$taskId} Купити молоко | Купити 2 літри молока в магазині | high | 2023-06-15</code>";

        $this->sendMessage($chatId, $message);
    }

    /**
     * Delete a task.
     *
     * @param int|string $chatId
     * @param TelegramUser $user
     * @param int $taskId
     * @return void
     */
    protected function deleteTask($chatId, TelegramUser $user, $taskId): void
    {
        $task = $user->tasks()->find($taskId);

        if (!$task) {
            $this->sendMessage($chatId, "Завдання не знайдено або у вас немає доступу до нього.");
            return;
        }

        $task->delete();
        $this->sendMessage($chatId, "✅ Завдання \"{$task->title}\" було успішно видалено.");
        
        // Show the task list again
        $this->sendTaskList($chatId, $user);
    }

    /**
     * Update the status of a task.
     *
     * @param int|string $chatId
     * @param TelegramUser $user
     * @param int $taskId
     * @param string $status
     * @return void
     */
    protected function updateTaskStatus($chatId, TelegramUser $user, $taskId, $status): void
    {
        $task = $user->tasks()->find($taskId);

        if (!$task) {
            $this->sendMessage($chatId, "Завдання не знайдено або у вас немає доступу до нього.");
            return;
        }

        $task->status = $status;
        $task->save();

        $statusText = match($status) {
            'pending' => 'очікує',
            'in_progress' => 'в процесі',
            'completed' => 'виконано',
            'cancelled' => 'скасовано',
            default => $status
        };

        $this->sendMessage($chatId, "✅ Статус завдання \"{$task->title}\" змінено на \"{$statusText}\".");
        
        // Show the task again
        $this->viewTask($chatId, $user, $taskId);
    }

    /**
     * Save or update a Telegram user.
     *
     * @param array $userData
     * @return TelegramUser
     */
    protected function saveUser(array $userData): TelegramUser
    {
        return TelegramUser::updateOrCreate(
            ['telegram_id' => $userData['id']],
            [
                'username' => $userData['username'] ?? null,
                'first_name' => $userData['first_name'] ?? null,
                'last_name' => $userData['last_name'] ?? null,
                'is_bot' => $userData['is_bot'] ?? false,
                'language_code' => $userData['language_code'] ?? null,
            ]
        );
    }

    /**
     * Save or update a Telegram group and add the user as a member.
     *
     * @param array $chatData
     * @param TelegramUser $user
     * @return TelegramGroup
     */
    protected function saveGroup(array $chatData, TelegramUser $user): TelegramGroup
    {
        $group = TelegramGroup::updateOrCreate(
            ['telegram_chat_id' => $chatData['id']],
            [
                'title' => $chatData['title'] ?? 'Group',
                'type' => $chatData['type'],
                'is_active' => true,
            ]
        );

        // Add user to group if not already a member
        if (!$group->members()->where('telegram_user_id', $user->id)->exists()) {
            $group->members()->attach($user->id, ['is_admin' => false]);
        }

        return $group;
    }

    /**
     * Get emoji for task status.
     *
     * @param string $status
     * @return string
     */
    protected function getStatusEmoji(string $status): string
    {
        return match($status) {
            'pending' => '⏳',
            'in_progress' => '🔄',
            'completed' => '✅',
            'cancelled' => '❌',
            default => '❓'
        };
    }

    /**
     * Get emoji for task priority.
     *
     * @param string $priority
     * @return string
     */
    protected function getPriorityEmoji(string $priority): string
    {
        return match($priority) {
            'low' => '🟢',
            'medium' => '🟡',
            'high' => '🟠',
            'urgent' => '🔴',
            default => '⚪'
        };
    }
}
