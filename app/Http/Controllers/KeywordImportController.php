<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\Project;
use App\Models\User;
use App\Services\Keywords\StageKeywordImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class KeywordImportController extends Controller
{
    public function store(Request $request, Project $project, StageKeywordImport $stageImport): JsonResponse
    {
        Gate::authorize('stageKeywordImports', $project);

        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $validated = $request->validate([
            'source' => ['required', 'string', 'max:100'],
            'original_filename' => ['required', 'string', 'max:255'],
            'mapping' => ['required', 'array'],
            'mapping.*' => ['string', 'max:255'],
            'rows' => ['required', 'array'],
            'rows.*' => ['array'],
        ]);

        /** @var array<string, string> $mapping */
        $mapping = $validated['mapping'];
        /** @var list<array<string, mixed>> $validatedRows */
        $validatedRows = array_values($validated['rows']);
        $rows = $this->rawRows($request, $validatedRows);

        $batch = $stageImport->handle(
            actor: $actor,
            project: $project,
            source: $validated['source'],
            originalFilename: $validated['original_filename'],
            mapping: $mapping,
            rows: $rows,
        );

        return response()->json([
            'data' => $this->batchData($batch),
        ], 201);
    }

    /**
     * Laravel's input normalization intentionally trims request strings. The
     * original JSON body is retained separately so provenance rows can stay
     * byte-for-byte faithful to the parsed source values supplied by callers.
     *
     * @param  list<array<string, mixed>>  $fallbackRows
     * @return list<array<string, mixed>>
     */
    private function rawRows(Request $request, array $fallbackRows): array
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! isset($payload['rows']) || ! is_array($payload['rows'])) {
            return $fallbackRows;
        }

        foreach ($payload['rows'] as $row) {
            if (! is_array($row)) {
                return $fallbackRows;
            }
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($payload['rows']);

        return $rows;
    }

    /** @return array{id: int, project_id: int, source: string, original_filename: string, status: string, total_rows: int, valid_rows: int, invalid_rows: int, warning_count: int, error_count: int} */
    private function batchData(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'project_id' => $batch->project_id,
            'source' => $batch->source,
            'original_filename' => $batch->original_filename,
            'status' => $batch->status,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'invalid_rows' => $batch->invalid_rows,
            'warning_count' => $batch->warning_count,
            'error_count' => $batch->error_count,
        ];
    }
}
