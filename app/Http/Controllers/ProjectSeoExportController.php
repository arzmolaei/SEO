<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Exports\ExportProjectSeoPlan;
use App\Services\Exports\ProjectSeoExportException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectSeoExportController extends Controller
{
    public function __construct(private ProjectPolicy $policy) {}

    /** @throws AuthorizationException */
    public function __invoke(Request $request, Project $project, ExportProjectSeoPlan $export): StreamedResponse|RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->policy->export($user, $project)) {
            throw new AuthorizationException;
        }

        try {
            $result = $export->handle($project);
        } catch (ProjectSeoExportException $exception) {
            Log::warning('Project SEO export generation failed.', [
                'code' => $exception->codeName(),
                'project_id' => $project->id,
                'user_id' => $user->id,
            ]);

            return to_route('projects.keywords.index', $project)
                ->with('export_error', $exception->userMessage());
        }

        return response()->streamDownload(
            function () use ($result): void {
                try {
                    fpassthru($result->stream());
                } finally {
                    $result->close();
                }
            },
            $result->filename(),
            [
                'Content-Type' => $result->mimeType(),
                'Cache-Control' => 'private, no-store',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'X-SEO-Export-Schema' => $result->schemaVersion(),
                'X-SEO-Export-Row-Count' => (string) $result->rowCount(),
                'X-SEO-Export-SHA256' => $result->sha256(),
                'X-SEO-Export-Generated-At' => $result->generatedAt()->toIso8601String(),
            ],
        );
    }
}
