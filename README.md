# Telegram Task Manager

A Laravel 12 application that integrates with Telegram to manage tasks.

## Features

- Telegram bot integration with /start and /help commands
- Task management via Telegram bot
- REST API for task management
- File attachments for tasks
- Group chat support
- Task filtering and search

## Requirements

- PHP 8.3+
- PostgreSQL
- Composer
- Docker (optional)

## Installation

### Using Docker

1. Clone the repository:
   \`\`\`bash
   git clone https://github.com/yourusername/telegram-task-manager.git
   cd telegram-task-manager
   \`\`\`

2. Copy the environment file:
   \`\`\`bash
   cp .env.example .env
   \`\`\`

3. Update the environment variables in the `.env` file:
   \`\`\`
   DB_CONNECTION=pgsql
   DB_HOST=db
   DB_PORT=5432
   DB_DATABASE=task_manager
   DB_USERNAME=postgres
   DB_PASSWORD=your_password

   TELEGRAM_BOT_TOKEN=your_telegram_bot_token
   TELEGRAM_WEBHOOK_URL=https://your-domain.com/api/telegram/webhook
   \`\`\`

4. Start the Docker containers:
   \`\`\`bash
   docker-compose up -d
   \`\`\`

5. Install dependencies:
   \`\`\`bash
   docker-compose exec app composer install
   \`\`\`

6. Generate application key:
   \`\`\`bash
   docker-compose exec app php artisan key:generate
   \`\`\`

7. Run migrations:
   \`\`\`bash
   docker-compose exec app php artisan migrate
   \`\`\`

8. Register the Telegram bot webhook:
   \`\`\`bash
   docker-compose exec app php artisan telegram:register
   \`\`\`

### Without Docker

1. Clone the repository:
   \`\`\`bash
   git clone https://github.com/yourusername/telegram-task-manager.git
   cd telegram-task-manager
   \`\`\`

2. Copy the environment file:
   \`\`\`bash
   cp .env.example .env
   \`\`\`

3. Update the environment variables in the `.env` file.

4. Install dependencies:
   \`\`\`bash
   composer install
   \`\`\`

5. Generate application key:
   \`\`\`bash
   php artisan key:generate
   \`\`\`

6. Run migrations:
   \`\`\`bash
   php artisan migrate
   \`\`\`

7. Register the Telegram bot webhook:
   \`\`\`bash
   php artisan telegram:register
   \`\`\`

## API Documentation

### Authentication

All API endpoints require a `telegram_id` parameter to identify the user.

### Endpoints

#### Users

- `GET /api/users` - Get all users
- `POST /api/users` - Create a new user
- `GET /api/users/{telegram_id}` - Get a user by Telegram ID
- `PUT /api/users/{telegram_id}` - Update a user
- `DELETE /api/users/{telegram_id}` - Delete a user

#### Tasks

- `GET /api/tasks` - Get all tasks for a user
  - Query parameters:
    - `telegram_id` (required) - Telegram ID of the user
    - `status` (optional) - Filter by status (pending, in_progress, completed, cancelled)
    - `priority` (optional) - Filter by priority (low, medium, high, urgent)
    - `search` (optional) - Search in title and description
- `POST /api/tasks` - Create a new task
  - Form parameters:
    - `telegram_id` (required) - Telegram ID of the user
    - `title` (required) - Task title
    - `description` (optional) - Task description
    - `status` (optional) - Task status
    - `priority` (optional) - Task priority
    - `due_date` (optional) - Task due date
    - `files[]` (optional) - Task files
- `GET /api/tasks/{id}` - Get a task by ID
  - Query parameters:
    - `telegram_id` (required) - Telegram ID of the user
- `PUT /api/tasks/{id}` - Update a task
  - Form parameters:
    - `telegram_id` (required) - Telegram ID of the user
    - `title` (optional) - Task title
    - `description` (optional) - Task description
    - `status` (optional) - Task status
    - `priority` (optional) - Task priority
    - `due_date` (optional) - Task due date
    - `files[]` (optional) - Task files
- `DELETE /api/tasks/{id}` - Delete a task
  - Query parameters:
    - `telegram_id` (required) - Telegram ID of the user
- `DELETE /api/tasks/{task_id}/files/{file_id}` - Remove a file from a task
  - Query parameters:
    - `telegram_id` (required) - Telegram ID of the user

## Telegram Bot Commands

- `/start` - Start the bot and register the user
- `/help` - Show help information
- `/tasks` - Show the list of tasks
- `/create` - Create a new task

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
