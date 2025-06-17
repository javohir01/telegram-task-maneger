<?php

namespace App\Services;

use App\Models\TelegramGroup;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class TelegramBotService
{
    protected string $apiUrl;
    protected string $token;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token');
        $this->apiUrl = "https://api.telegram.org/bot{$this->token}";
    }

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


    public function sendInlineKeyboard($chatId, string $text, array $keyboard): ?array
    {
        return $this->sendMessage($chatId, $text, [
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard
            ])
        ]);
    }

    public function processUpdate(array $update): void
    {
        try {
            if (isset($update['message'])) {
                $this->processMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->processCallbackQuery($update['callback_query']);
            }
        } catch (\Exception $e) {
            Log::error('Error processing update', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'update' => $update
            ]);
        }
    }

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
            case 'task_create_simple':
                $this->startSimpleTaskCreation($chatId, $telegramUser);
                break;
            case 'task_create_advanced':
                $this->startAdvancedTaskCreation($chatId, $telegramUser);
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
            case 'help':
                $this->handleHelpCommand($chatId);
                break;
        }

        Http::post("{$this->apiUrl}/answerCallbackQuery", [
            'callback_query_id' => $callbackQuery['id'],
        ]);
    }

    protected function processCommand(string $text, $chatId, TelegramUser $user): void
    {
        $parts = explode(' ', $text);
        $command = strtolower($parts[0]);
        $args = array_slice($parts, 1);

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
                if (!empty($args)) {
                    $this->handleCreateCommand($chatId, $user, implode(' ', $args));
                } else {
                    $this->startTaskCreation($chatId, $user);
                }
                break;
            case '/edit':
                Log::info('Edit command received', [
                    'chat_id' => $chatId,
                    'user_id' => $user->id,
                    'args' => $args
                ]);
                if (count($args) < 2) {
                    $this->sendMessage($chatId, "Usage: /edit <task_id> <title> | <description> | <priority> | <due_date>");
                    return;
                }
                $taskId = array_shift($args);
                $updateText = implode(' ', $args);
                if (empty($updateText)) {
                  $this->editTask($chatId, $user, $taskId);
                } else {
                  $this->handleUpdateCommand($chatId, $user, $taskId . '|' . $updateText);
                }
                break;
            default:
                $this->sendMessage($chatId, "Unknown command. Use /help to get a list of available commands.");
                break;
        }
    }

    protected function handleCreateCommand($chatId, TelegramUser $user, string $text): void
    {
        // This handles advanced creation from command or advanced mode
        $parts = explode('|', $text);
        
        try {
            $task = $user->tasks()->create([
                'title' => trim($parts[0]),
                'description' => trim($parts[1] ?? ''),
                'priority' => trim(strtolower($parts[2] ?? 'medium')),
                'due_date' => isset($parts[3]) ? date('Y-m-d H:i:s', strtotime(trim($parts[3]))) : null,
                'status' => 'pending'
            ]);

            $this->sendMessage($chatId, "✅ Task \"{$task->title}\" created successfully!");
            $this->viewTask($chatId, $user, $task->id);
            
        } catch (\Exception $e) {
            Log::error('Error creating advanced task', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'text' => $text
            ]);
            $this->sendMessage($chatId, "❌ Error creating task. Please try again.");
        }
    }

    protected function startSimpleTaskCreation($chatId, TelegramUser $user): void
    {
        $message = "Enter task title:";
        // Save state that user is creating simple task
        Cache::put("task_creation_{$chatId}", 'simple', 3600);
        
        $this->sendMessage($chatId, $message);
    }

    protected function startAdvancedTaskCreation($chatId, TelegramUser $user): void
    {
        $message = "Please enter task details in the following format:\n\n";
        $message .= "<b>Title | Description | Priority | Due Date</b>\n\n";
        $message .= "Example:\n";
        $message .= "<code>Buy milk | Get 2 liters from store | high | 2023-06-20</code>\n\n";
        $message .= "Priority can be: low, medium, high, urgent\n";
        $message .= "Date format: YYYY-MM-DD";
        
        $this->sendMessage($chatId, $message);
    }

    protected function processMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $user = $message['from'];

        $telegramUser = $this->saveUser($user);

        // Check if user is in task creation mode
        $creationMode = Cache::get("task_creation_{$chatId}");
        
        if ($creationMode === 'simple' && !str_starts_with($text, '/')) {
            $this->handleSimpleTaskCreation($chatId, $telegramUser, $text);
            Cache::forget("task_creation_{$chatId}");
            return;
        }

        if (in_array($message['chat']['type'], ['group', 'supergroup'])) {
            $this->saveGroup($message['chat'], $telegramUser);
        }

        if (str_starts_with($text, '/')) {
            $this->processCommand($text, $chatId, $telegramUser);
        }
    }

    protected function handleSimpleTaskCreation($chatId, TelegramUser $user, string $title): void
    {
        try {
            $task = $user->tasks()->create([
                'title' => trim($title),
                'description' => '',
                'priority' => 'medium',
                'status' => 'pending',
                'due_date' => null
            ]);

            $this->sendMessage($chatId, "✅ Task \"{$title}\" created successfully!");
            $this->viewTask($chatId, $user, $task->id);
            
            // Show options after creation
            $keyboard = [
                [
                    ['text' => '✏️ Add Details', 'callback_data' => "task_edit:{$task->id}"],
                    ['text' => '📋 Task List', 'callback_data' => 'task_list']
                ]
            ];
            
            $this->sendInlineKeyboard($chatId, "What would you like to do next?", $keyboard);
            
        } catch (\Exception $e) {
            Log::error('Error creating simple task', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'title' => $title
            ]);
            $this->sendMessage($chatId, "❌ Error creating task. Please try again.");
        }
    }

    protected function handleUpdateCommand($chatId, TelegramUser $user, string $taskId): void
    {
        try {
            $parts = explode('|', $taskId);
            $actualTaskId = trim(array_shift($parts));

            $task = $user->tasks()->find($actualTaskId);
            if (!$task) {
                $this->sendMessage($chatId, "Task not found or you don't have access to it.");
                return;
            }

            if (empty($parts)) {
                $this->editTask($chatId, $user, $actualTaskId);
                return;
            }

            $updateData = [
                'title' => trim($parts[0] ?? $task->title),
                'description' => trim($parts[1] ?? $task->description),
                'priority' => trim(strtolower($parts[2] ?? $task->priority)),
                'due_date' => !empty($parts[3]) ? date('Y-m-d H:i:s', strtotime(trim($parts[3]))) : $task->due_date
            ];
            
            $task->update($updateData);

            $this->sendMessage($chatId, "✅ Task \"{$updateData['title']}\" updated successfully!");
            $this->viewTask($chatId, $user, $task->id);

        } catch (\Exception $e) {
            Log::error('Error updating task', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'task_id' => $taskId,
                'update_data' => $parts ?? []
            ]);
            $this->sendMessage($chatId, "❌ Error updating task. Please try again.");
        }
    }

    protected function handleStartCommand($chatId, TelegramUser $user): void
    {
        $name = $user->first_name ?: 'user';
        $message = "Hello, {$name}! 👋\n\n";
        $message .= "Welcome to Task Manager bot. This bot will help you manage your tasks.\n\n";
        $message .= "To get started, you can use the following commands:\n";
        $keyboard = [
            [
                ['text' => '📋 My Tasks', 'callback_data' => 'task_list'],
                ['text' => '➕ Create Task', 'callback_data' => 'task_create']
            ],
            [
                ['text' => '❓ Help', 'callback_data' => 'help']
            ]
        ];

        $this->sendInlineKeyboard($chatId, $message, $keyboard);
    }

    protected function handleHelpCommand($chatId): void
    {
        $message = "<b>Available commands:</b>\n\n";
        $message .= "/start - Start the bot and register user.\n";
        $message .= "/help - Show help information.\n";
        $message .= "/tasks - View your task list.\n";
        $message .= "/create - Create a new task.\n";

        $this->sendMessage($chatId, $message);
    }

    protected function sendTaskList($chatId, TelegramUser $user): void
    {
        $tasks = $user->tasks()->orderBy('created_at', 'desc')->get();

        if ($tasks->isEmpty()) {
            $message = "You don't have any tasks yet. Use /create command to create a new task.";
            $keyboard = [
                [
                    ['text' => '➕ Create Task', 'callback_data' => 'task_create']
                ]
            ];
            $this->sendInlineKeyboard($chatId, $message, $keyboard);
            return;
        }

        $message = "<b>Your tasks:</b>\n\n";
        
        foreach ($tasks as $index => $task) {
            $statusEmoji = $this->getStatusEmoji($task->status);
            $priorityEmoji = $this->getPriorityEmoji($task->priority);
            
            $message .= ($index + 1) . ". {$statusEmoji} {$priorityEmoji} <b>{$task->title}</b>\n";
            
            if ($task->due_date) {
                $message .= "📅 Due: " . $task->due_date->format('d.m.Y H:i') . "\n";
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
            ['text' => '➕ Create Task', 'callback_data' => 'task_create']
        ];

        $this->sendInlineKeyboard($chatId, $message, $keyboard);
    }

    protected function startTaskCreation($chatId, TelegramUser $user): void
    {
        $message = "Choose task creation mode:\n\n";
        $message .= "📝 Simple Mode - Create task with title only\n";
        $message .= "🔧 Advanced Mode - Create task with all details";
        
        $keyboard = [
            [
                ['text' => '📝 Simple Mode', 'callback_data' => 'task_create_simple'],
                ['text' => '🔧 Advanced Mode', 'callback_data' => 'task_create_advanced']
            ],
            [
                ['text' => '◀️ Back', 'callback_data' => 'task_list']
            ]
        ];

        $this->sendInlineKeyboard($chatId, $message, $keyboard);
    }

    protected function viewTask($chatId, TelegramUser $user, $taskId): void
    {
        $task = $user->tasks()->find($taskId);

        if (!$task) {
            $this->sendMessage($chatId, "Task not found or you don't have access to it.");
            return;
        }

        $statusEmoji = $this->getStatusEmoji($task->status);
        $priorityEmoji = $this->getPriorityEmoji($task->priority);
        
        $message = "<b>{$task->title}</b>\n\n";
        $message .= "Status: {$statusEmoji} " . ucfirst($task->status) . "\n";
        $message .= "Priority: {$priorityEmoji} " . ucfirst($task->priority) . "\n";
        
        if ($task->due_date) {
            $message .= "Due date: 📅 " . $task->due_date->format('d.m.Y H:i') . "\n";
        }
        
        if ($task->description) {
            $message .= "\nDescription:\n{$task->description}\n";
        }
        
        $files = $task->files;
        if ($files->count() > 0) {
            $message .= "\nAttached files: " . $files->count() . "\n";
        }

        $keyboard = [
            [
                ['text' => '✏️ Edit', 'callback_data' => "task_edit:{$task->id}"],
                ['text' => '🗑️ Delete', 'callback_data' => "task_delete:{$task->id}"]
            ],
            [
                ['text' => '✅ Complete', 'callback_data' => "task_status:{$task->id}:completed"],
                ['text' => '⏳ In Progress', 'callback_data' => "task_status:{$task->id}:in_progress"]
            ],
            [
                ['text' => '◀️ Back to List', 'callback_data' => "task_list"]
            ]
        ];

        $this->sendInlineKeyboard($chatId, $message, $keyboard);
    }

    protected function editTask($chatId, TelegramUser $user, $taskId): void
    {
        $message = "To edit a task, send a message in the format:\n\n";
        $message .= "<code>/edit {$taskId} Task title | Task description | Priority | Due date</code>\n\n";
        $message .= "Example:\n";
        $message .= "<code>/edit {$taskId} Buy milk | Buy 2 liters of milk from store | high | 2023-06-15</code>";

        $this->sendMessage($chatId, $message);
    }

    protected function deleteTask($chatId, TelegramUser $user, $taskId): void
    {
        $task = $user->tasks()->find($taskId);

        if (!$task) {
            $this->sendMessage($chatId, "Task not found or you don't have access to it.");
            return;
        }

        $task->delete();
        $this->sendMessage($chatId, "✅ Task \"{$task->title}\" was successfully deleted.");
        
        $this->sendTaskList($chatId, $user);
    }

    protected function updateTaskStatus($chatId, TelegramUser $user, $taskId, $status): void
    {
        $task = $user->tasks()->find($taskId);

        if (!$task) {
            $this->sendMessage($chatId, "Task not found or you don't have access to it.");
            return;
        }

        $task->status = $status;
        $task->save();

        $statusText = match($status) {
            'pending' => 'pending',
            'in_progress' => 'in progress',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => $status
        };

        $this->sendMessage($chatId, "✅ Task \"{$task->title}\" status changed to \"{$statusText}\".");
        
        $this->viewTask($chatId, $user, $taskId);
    }

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
