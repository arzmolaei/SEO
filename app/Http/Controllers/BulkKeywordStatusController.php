<?php

namespace App\Http\Controllers;

use App\Enums\KeywordStatus;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\BulkUpdateKeywordStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class BulkKeywordStatusController extends Controller
{
    public function __construct(
        private ProjectPolicy $policy,
        private BulkUpdateKeywordStatus $bulkUpdateKeywordStatus,
    ) {}

    /** @throws AuthorizationException */
    public function __invoke(Request $request, Project $project): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->policy->manageKeywords($user, $project)) {
            throw new AuthorizationException;
        }

        $validator = Validator::make($request->all(), [
            'keyword_ids' => ['required', 'array', 'min:1', 'max:500'],
            'keyword_ids.*' => ['required', 'integer', 'distinct'],
            'status' => ['required', Rule::enum(KeywordStatus::class)],
        ]);

        if ($validator->fails()) {
            abort(422, $validator->errors()->first());
        }

        $validated = $validator->validated();
        $keywordIds = array_map('intval', $validated['keyword_ids']);
        $status = KeywordStatus::from((string) $validated['status']);

        try {
            $this->bulkUpdateKeywordStatus->handle($user, $project, $keywordIds, $status);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return redirect()->route('projects.keywords.index', $project);
    }
}
