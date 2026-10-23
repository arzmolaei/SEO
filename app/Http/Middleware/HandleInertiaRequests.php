<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Support\LocaleContext;
use App\Support\ProjectWorkspaceContext;
use App\Support\SystemOwner;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Throwable;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $localeContext = LocaleContext::from(app()->getLocale());
        $routeProject = $request->route('project');
        $user = $request->user();
        $canManageSystemBackup = false;

        if ($user !== null) {
            try {
                $canManageSystemBackup = app(SystemOwner::class)->is($user);
            } catch (Throwable) {
                $canManageSystemBackup = false;
            }
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => $localeContext['locale'],
            'direction' => $localeContext['direction'],
            'auth' => [
                'user' => $user,
            ],
            'flash' => [
                'export_error' => $request->session()->get('export_error'),
            ],
            'projectContext' => $routeProject instanceof Project
                ? ProjectWorkspaceContext::for($routeProject)
                : null,
            'systemBackup' => [
                'canManage' => $canManageSystemBackup,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
