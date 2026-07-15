<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Weekly rolling task sheet + projects. Auto-scoped to the active company by
 * the model global scope. Tasks and projects are the same records; the
 * is_project flag moves a record between the Tasks and Projects views.
 */
class TaskController extends Controller
{
    private const PROJECT_STATUSES = ['Proposed', 'Approved', 'In progress', 'On hold', 'Blocked', 'Done'];

    private function monday(): string
    {
        return Carbon::now()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    public function index(): JsonResponse
    {
        // Weekly rollover: unfinished tasks from earlier weeks roll into the
        // current week. `origin` is left untouched so we can flag carry-overs.
        Task::query()->where('done', false)->whereDate('week', '<', $this->monday())
            ->update(['week' => $this->monday()]);

        $tasks = Task::query()->with('assignee:id,name,last')
            ->orderBy('ord')->orderByDesc('created_at')->get()
            ->map(fn ($t) => $this->row($t));

        return response()->json([
            'tasks' => $tasks,
            'statuses' => self::PROJECT_STATUSES,
            'currentWeek' => $this->monday(),
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
        $data['origin'] = $data['week'];                 // first week it appeared
        $data = $this->syncProgress($data, null);
        $task = Task::create($data + ['ord' => (int) (Task::query()->max('ord') + 1)]);
        return response()->json(['id' => $task->id], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $this->validated($request, false);
        $task->update($this->syncProgress($data, $task));
        return response()->json(['ok' => true]);
    }

    public function destroy(Task $task): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $task->delete();
        return response()->json(['ok' => true]);
    }

    /** Keep pct / done / completed_at consistent — % is the source of truth. */
    private function syncProgress(array $data, ?Task $task): array
    {
        $has = fn ($k) => array_key_exists($k, $data);
        $cur = fn ($k, $d = null) => $task?->{$k} ?? $d;

        // "Done" project status implies 100%.
        if (($data['status'] ?? null) === 'Done') $data['pct'] = 100;
        // A done checkbox sets/clears pct.
        if ($has('done') && ! $has('pct')) $data['pct'] = $data['done'] ? 100 : 0;

        if ($has('pct')) {
            $done = $data['pct'] >= 100;
            $data['done'] = $done;
            $wasCompleted = $cur('completed_at');
            $data['completed_at'] = $done ? ($wasCompleted ?? Carbon::now()) : null;
        }
        return $data;
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
            'ord' => 'sometimes|integer',
            'status' => 'sometimes|nullable|string|max:40',
            'details' => 'sometimes|nullable|string',
            'impact' => 'sometimes|nullable|string',
            'needs' => 'sometimes|nullable|string',
            'challenges' => 'sometimes|nullable|string',
            'workarounds' => 'sometimes|nullable|string',
        ];
        $data = $request->validate($rules);
        if ($creating && empty($data['week'])) $data['week'] = $this->monday();
        return $data;
    }

    private function row(Task $t): array
    {
        return [
            'id' => $t->id, 'title' => $t->title,
            'week' => $t->week?->toDateString(), 'origin' => $t->origin?->toDateString(),
            'done' => $t->done, 'pct' => $t->pct, 'pri' => $t->pri, 'is_project' => $t->is_project,
            'status' => $t->status, 'ord' => $t->ord,
            'completed_at' => $t->completed_at?->toDateString(),
            'assigned_to' => $t->assigned_to,
            'assignee' => $t->assignee ? trim("{$t->assignee->name} {$t->assignee->last}") : null,
        ];
    }
}
