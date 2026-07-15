<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Tasks + projects. Auto-scoped to the active company by the model global scope. */
class TaskController extends Controller
{
    private const PROJECT_STATUSES = ['Proposed', 'Approved', 'In progress', 'On hold', 'Blocked', 'Done'];

    public function index(): JsonResponse
    {
        $tasks = Task::query()->with('assignee:id,name,last')
            ->orderByDesc('week')->orderBy('ord')->orderByDesc('pri')->get()
            ->map(fn ($t) => $this->row($t));

        return response()->json([
            'tasks' => $tasks,
            'statuses' => self::PROJECT_STATUSES,
        ]);
    }

    public function show(Task $task): JsonResponse
    {
        $task->load('assignee:id,name,last');
        return response()->json($this->row($task) + [
            'details' => $task->details, 'impact' => $task->impact, 'needs' => $task->needs,
            'challenges' => $task->challenges, 'workarounds' => $task->workarounds,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $this->validated($request, true);
        $task = Task::create($data + ['ord' => (int) (Task::query()->max('ord') + 1)]);
        return response()->json(['id' => $task->id], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $this->validated($request, false);
        // stamp completion when done toggles on
        if (array_key_exists('done', $data)) {
            $data['completed_at'] = $data['done'] ? ($task->completed_at ?? now()) : null;
            if ($data['done']) $data['pct'] = 100;
        }
        if (($data['status'] ?? null) === 'Done') $data['pct'] = 100;
        $task->update($data);
        return response()->json(['ok' => true]);
    }

    public function destroy(Task $task): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $task->delete();
        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $rules = [
            'title' => ($creating ? 'required' : 'sometimes') . '|string|max:255',
            'week' => 'sometimes|date',
            'assigned_to' => 'sometimes|nullable|integer|exists:users,id',
            'done' => 'sometimes|boolean',
            'pct' => 'sometimes|integer|min:0|max:100',
            'pri' => 'sometimes|integer|min:0|max:3',
            'is_project' => 'sometimes|boolean',
            'status' => 'sometimes|nullable|string|max:40',
            'details' => 'sometimes|nullable|string',
            'impact' => 'sometimes|nullable|string',
            'needs' => 'sometimes|nullable|string',
            'challenges' => 'sometimes|nullable|string',
            'workarounds' => 'sometimes|nullable|string',
        ];
        $data = $request->validate($rules);
        if ($creating && empty($data['week'])) {
            $data['week'] = now()->startOfWeek()->toDateString();
        }
        return $data;
    }

    private function row(Task $t): array
    {
        return [
            'id' => $t->id, 'title' => $t->title, 'week' => $t->week?->toDateString(),
            'done' => $t->done, 'pct' => $t->pct, 'pri' => $t->pri, 'is_project' => $t->is_project,
            'status' => $t->status, 'ord' => $t->ord,
            'assigned_to' => $t->assigned_to,
            'assignee' => $t->assignee ? trim("{$t->assignee->name} {$t->assignee->last}") : null,
        ];
    }
}
