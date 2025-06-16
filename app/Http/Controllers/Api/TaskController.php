<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TelegramUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'telegram_id' => 'required|integer',
            'status' => 'nullable|string|in:pending,in_progress,completed,cancelled',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'search' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $telegramId = $request->input('telegram_id');
        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $query = $user->tasks();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $tasks = $query->with('files')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $tasks
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'telegram_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:pending,in_progress,completed,cancelled',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'due_date' => 'nullable|date',
            'files' => 'nullable|array',
            'files.*' => 'file|max:10240', // 10MB max per file
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $telegramId = $request->input('telegram_id');
        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $task = new Task([
            'telegram_user_id' => $user->id,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'status' => $request->input('status', 'pending'),
            'priority' => $request->input('priority', 'medium'),
            'due_date' => $request->input('due_date'),
        ]);

        $task->save();

        // Handle file uploads
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store('task_files/' . $task->id, 'public');
                
                $task->files()->create([
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Task created successfully',
            'data' => $task->load('files')
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'telegram_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $telegramId = $request->input('telegram_id');
        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $task = $user->tasks()->with('files')->find($id);

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found or you do not have access to it'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $task
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'telegram_id' => 'required|integer',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:pending,in_progress,completed,cancelled',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'due_date' => 'nullable|date',
            'files' => 'nullable|array',
            'files.*' => 'file|max:10240', // 10MB max per file
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $telegramId = $request->input('telegram_id');
        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $task = $user->tasks()->find($id);

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found or you do not have access to it'
            ], 404);
        }

        // Update task fields
        if ($request->has('title')) {
            $task->title = $request->input('title');
        }
        
        if ($request->has('description')) {
            $task->description = $request->input('description');
        }
        
        if ($request->has('status')) {
            $task->status = $request->input('status');
        }
        
        if ($request->has('priority')) {
            $task->priority = $request->input('priority');
        }
        
        if ($request->has('due_date')) {
            $task->due_date = $request->input('due_date');
        }

        $task->save();

        // Handle file uploads
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store('task_files/' . $task->id, 'public');
                
                $task->files()->create([
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Task updated successfully',
            'data' => $task->load('files')
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'telegram_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $telegramId = $request->input('telegram_id');
        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $task = $user->tasks()->find($id);

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found or you do not have access to it'
            ], 404);
        }

        // Delete associated files from storage
        foreach ($task->files as $file) {
            Storage::disk('public')->delete($file->file_path);
        }

        $task->delete();

        return response()->json([
            'success' => true,
            'message' => 'Task deleted successfully'
        ]);
    }

    /**
     * Remove a file from a task.
     */
    public function removeFile(Request $request, string $taskId, string $fileId)
    {
        $validator = Validator::make($request->all(), [
            'telegram_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $telegramId = $request->input('telegram_id');
        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $task = $user->tasks()->find($taskId);

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found or you do not have access to it'
            ], 404);
        }

        $file = $task->files()->find($fileId);

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => 'File not found'
            ], 404);
        }

        // Delete file from storage
        Storage::disk('public')->delete($file->file_path);
        $file->delete();

        return response()->json([
            'success' => true,
            'message' => 'File removed successfully'
        ]);
    }
}
