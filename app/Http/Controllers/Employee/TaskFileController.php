<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Concerns\ManagesFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\File\StoreFileRequest;
use App\Models\Task;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A task's attachments on the Employee surface.
 *
 * The same two endpoints as the Admin surface's, resolving the task the same way and calling
 * the same service. There is no second rule here: an employee reaches the tasks they are
 * ASSIGNED to — Task::visibleTo() — and may attach to the ones they may edit, which is
 * TaskPolicy::update and therefore excludes an archived task for them exactly as it does for
 * an Admin. A Manager lives on this surface too and is refused or allowed by the same check.
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
