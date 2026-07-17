<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $ctx = app(\App\Support\Contracts\TenantResolver::class);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'tenant' => $request->user() ? [
                'companies' => $ctx->options(),
                'activeId' => $ctx->activeId(),
            ] : null,
            // Open (not-done) task count for the nav badge — auto-scoped to the
            // active company by the Task model's global scope. Lazy closure so it
            // only runs when a page actually needs shared props.
            'pendingTasks' => fn () => $request->user()
                ? \App\Models\Task::query()->where('done', false)->count()
                : 0,
        ];
    }
}
