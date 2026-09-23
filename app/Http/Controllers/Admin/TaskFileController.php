<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ManagesFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\File\StoreFileRequest;
use App\Models\Task;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A task's attachments on the Admin surface.
 *
 * The task is resolved through Task::visibleTo() exactly as TaskController resolves it, so a
 * task this requester may not see answers 404 before the question of files arises. Who may
 * attach is TaskPolicy::update — the same ability editing the task takes — checked in
 * FileService against the record, never against the surface the request arrived on.
 */
class TaskFileController extends Controller
{
    use ManagesFiles;

    public function __construct(private readonly FileService $files) {}

    public function index(Request $request, Task $task): JsonResponse
    {
        return $this->fileIndex($request, $this->visibleTask($request, $task));
    }

    public function store(StoreFileRequest $request, Task $task): RedirectResponse
    {
        return $this->fileStore($request, $this->visibleTask($request, $task), 'File attached.');
    }

    private function visibleTask(Request $request, Task $task): Task
    {
        return Task::query()
            ->visibleTo($request->user())
            ->whereKey($task->getKey())
            ->firstOrFail();
    }
}
