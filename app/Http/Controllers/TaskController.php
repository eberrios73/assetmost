<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Weekly rolling task sheet + projects. Auto-scoped to the active company by
 * the model global scope. Tasks and projects are the same records; `kind`
 * (project | milestone | task) moves a record between the views, and parent_id
 * nests: subprojects under projects, milestones under projects, tasks under
 * milestones or tasks (subtasks).
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

        $tasks = Task::query()->with(['assignee:id,name,last', 'links'])
            ->orderBy('ord')->orderByDesc('created_at')->get()
            ->map(fn ($t) => $this->row($t));

        return response()->json([
            'tasks' => $tasks,
            'statuses' => self::PROJECT_STATUSES,
            'states' => Task::STATES,
            'currentWeek' => $this->monday(),
        ]);
    }

    public function show(Task $task): JsonResponse
    {
        $task->load(['assignee:id,name,last', 'links']);
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

    /** Keep pct / done / state / completed_at consistent — % is the source of truth. */
    private function syncProgress(array $data, ?Task $task): array
    {
        $has = fn ($k) => array_key_exists($k, $data);
        $cur = fn ($k, $d = null) => $task?->{$k} ?? $d;

        // "Done" project status implies 100%.
        if (($data['status'] ?? null) === 'Done') $data['pct'] = 100;
        // A done checkbox sets/clears pct; so does the state hitting/leaving 'done'.
        if ($has('done') && ! $has('pct')) $data['pct'] = $data['done'] ? 100 : 0;
        if ($has('state') && ! $has('pct') && ! $has('done')) {
            if ($data['state'] === 'done') $data['pct'] = 100;
            elseif ($cur('done')) $data['pct'] = 0;   // reopening from done
        }

        // Not $has(): that closure captured $data before the lines above mutated it,
        // so a pct set in this function would be invisible here.
        if (array_key_exists('pct', $data)) {
            $done = $data['pct'] >= 100;
            $data['done'] = $done;
            $wasCompleted = $cur('completed_at');
            $data['completed_at'] = $done ? ($wasCompleted ?? Carbon::now()) : null;
            // done and state never disagree; leaving done lands on the state the
            // caller asked for, or back to plain todo.
            if ($done) $data['state'] = 'done';
            elseif (($data['state'] ?? $cur('state')) === 'done') $data['state'] = $data['state'] ?? 'todo';
            if (! $done && ($data['state'] ?? null) === 'done') $data['state'] = 'todo';
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
            'kind' => 'sometimes|in:'.implode(',', Task::KINDS),
            'state' => 'sometimes|in:'.implode(',', Task::STATES),
            'labels' => 'sometimes|nullable|string|max:255',
            // Nesting: subproject/milestone under a project, subtask under a task.
            'parent_id' => 'sometimes|nullable|integer|exists:tasks,id',
            // One predecessor; chains compose. not_in blocks self-dependency.
            'depends_on_id' => 'sometimes|nullable|integer|exists:tasks,id|not_in:'.($request->route('task')?->id ?? 0),
            // The planned window is draggable; origin (creation) never is.
            'planned_start' => 'sometimes|nullable|date',
            'due_date' => 'sometimes|nullable|date',
            'ord' => 'sometimes|integer',
            'completed_at' => 'sometimes|nullable|date',
            'status' => 'sometimes|nullable|string|max:40',
            'details' => 'sometimes|nullable|string',
            'impact' => 'sometimes|nullable|string',
            'needs' => 'sometimes|nullable|string',
            'challenges' => 'sometimes|nullable|string',
            'workarounds' => 'sometimes|nullable|string',
            'notes' => 'sometimes|nullable|string',
        ];
        $data = $request->validate($rules);
        if ($creating && empty($data['week'])) $data['week'] = $this->monday();

        // A milestone hangs off a project — anywhere else it's just a task.
        if (($data['kind'] ?? null) === Task::MILESTONE) {
            $parent = Task::find($data['parent_id'] ?? $request->route('task')?->parent_id);
            abort_unless($parent?->isProject(), 422, 'A milestone must belong to a project.');
        }
        return $data;
    }

    /** The task's log: flat, stamped, append-only. Fetched when the drawer opens. */
    public function comments(Task $task): JsonResponse
    {
        return response()->json($task->comments()->with('author:id,name,last')->get()
            ->map(fn ($c) => $this->commentRow($c)));
    }

    public function storeComment(Request $request, Task $task): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $c = $task->comments()->create(['user_id' => auth()->id(), 'body' => $data['body']]);
        return response()->json($this->commentRow($c->load('author:id,name,last')), 201);
    }

    /** Typo cleanup, not history editing: only your own line, and admins. */
    public function destroyComment(Task $task, \App\Models\TaskComment $comment): JsonResponse
    {
        abort_unless($comment->task_id === $task->id, 404);
        abort_unless($comment->user_id === auth()->id() || auth()->user()->isAdmin(), 403);
        $comment->delete();
        return response()->json(['ok' => true]);
    }

    private function commentRow(\App\Models\TaskComment $c): array
    {
        return [
            'id' => $c->id, 'body' => $c->body,
            'author' => trim(($c->author->name ?? '').' '.($c->author->last ?? '')) ?: '—',
            'mine' => $c->user_id === auth()->id(),
            'at' => $c->created_at->toDateTimeString(),
        ];
    }

    /** Attach a pasted URL (a PR, a ticket, a doc). */
    public function storeLink(Request $request, Task $task): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        $data = $request->validate(['url' => 'required|url|max:500', 'label' => 'nullable|string|max:120']);
        // A GitHub/GitLab PR URL labels itself.
        $data['label'] ??= preg_match('~(github|gitlab)\.com/([^/]+/[^/]+)/(?:pull|merge_requests)/(\d+)~', $data['url'], $m)
            ? "{$m[2]} #{$m[3]}" : null;
        $link = $task->links()->create($data);
        return response()->json(['id' => $link->id, 'url' => $link->url, 'label' => $link->label], 201);
    }

    public function destroyLink(Task $task, \App\Models\TaskLink $link): JsonResponse
    {
        abort_if(auth()->user()?->role === 'User', 403);
        abort_unless($link->task_id === $task->id, 404);
        $link->delete();
        return response()->json(['ok' => true]);
    }

    private function row(Task $t): array
    {
        return [
            'id' => $t->id, 'title' => $t->title,
            'week' => $t->week?->toDateString(), 'origin' => $t->origin?->toDateString(),
            'done' => $t->done, 'pct' => $t->pct, 'pri' => $t->pri,
            'kind' => $t->kind, 'state' => $t->state, 'labels' => $t->labels,
            'is_project' => $t->isProject(),   // derived; the UI groups on it
            'links' => $t->relationLoaded('links')
                ? $t->links->map(fn ($l) => ['id' => $l->id, 'url' => $l->url, 'label' => $l->label])->values()
                : [],
            'parent_id' => $t->parent_id, 'depends_on_id' => $t->depends_on_id,
            'planned_start' => $t->planned_start?->toDateString(), 'due_date' => $t->due_date?->toDateString(),
            'status' => $t->status, 'ord' => $t->ord,
            'completed_at' => $t->completed_at?->toDateString(),
            'assigned_to' => $t->assigned_to,
            'assignee' => $t->assignee ? trim("{$t->assignee->name} {$t->assignee->last}") : null,
            // long text (used by the task/project detail panels)
            'notes' => $t->notes,
            'details' => $t->details, 'impact' => $t->impact, 'needs' => $t->needs,
            'challenges' => $t->challenges, 'workarounds' => $t->workarounds,
        ];
    }
}
