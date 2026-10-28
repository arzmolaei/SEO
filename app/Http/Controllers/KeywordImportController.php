<?php

namespace App\Http\Controllers;

use App\Domain\Keywords\StreamingCsvReader;
use App\Jobs\CommitKeywordImport;
use App\Jobs\ProcessKeywordImportFile;
use App\Models\ImportBatch;
use App\Models\Project;
use App\Models\User;
use App\Services\Keywords\KeywordImportFiles;
use App\Services\Keywords\StageKeywordImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class KeywordImportController extends Controller
{
    public function index(Request $request, Project $project, StreamingCsvReader $reader, KeywordImportFiles $files): Response
    {
        Gate::authorize('view', $project);
        $request->validate(['batch' => ['nullable', 'integer', 'min:1']]);
        $query = $project->batches()->whereNotNull('source_path');
        $batch = $request->filled('batch')
            ? $query->whereKey($request->integer('batch'))->firstOrFail()
            : $query->latest('id')->first();
        $active = null;
        if ($batch !== null) {
            $headers = [];
            $preview = [];
            $unavailable = false;
            if ($batch->status !== 'discarded') {
                try {
                    $path = $files->absolutePath($batch);
                    $headers = $reader->headers($path);
                    foreach ($reader->rows($path) as $row) {
                        $preview[] = array_map(
                            static fn (?string $value): ?string => $value === null ? null : mb_scrub($value, 'UTF-8'),
                            $row,
                        );
                        if (count($preview) >= (int) config('keywords.import.preview_rows', 5)) {
                            break;
                        }
                    }
                } catch (RuntimeException) {
                    $unavailable = true;
                }
            }
            $active = [
                ...$this->batchData($batch),
                'headers' => $headers,
                'preview' => $preview,
                'mapping' => $batch->mapping,
                'processed_rows' => $batch->processed_rows,
                'commit_requested_at' => $batch->commit_requested_at?->toIso8601String(),
                'committed_at' => $batch->committed_at?->toIso8601String(),
                'discarded_at' => $batch->discarded_at?->toIso8601String(),
                'started_at' => $batch->started_at?->toIso8601String(),
                'completed_at' => $batch->completed_at?->toIso8601String(),
                'failed_at' => $batch->failed_at?->toIso8601String(),
                'source_unavailable' => $unavailable,
                'invalidRows' => $batch->rows()->where('is_valid', false)->orderBy('row_number')
                    ->paginate(25, ['id', 'row_number', 'keyword_original', 'errors'])
                    ->withQueryString(),
            ];
        }

        return Inertia::render('projects/imports', [
            'activeBatch' => $active,
            'batches' => $project->batches()->whereNotNull('source_path')->latest('id')
                ->paginate(25, ['id', 'original_filename', 'status'], 'batches_page')->withQueryString(),
            'canImport' => Gate::allows('stageKeywordImports', $project),
            'maxBytes' => (int) config('keywords.import.max_bytes'),
        ]);
    }

    public function store(Request $request, Project $project, StreamingCsvReader $reader, KeywordImportFiles $files): RedirectResponse
    {
        Gate::authorize('stageKeywordImports', $project);
        $request->validate([
            'file' => ['nullable', 'file'],
            'text' => ['nullable', 'string'],
        ]);
        $upload = $request->file('file');
        $text = $request->string('text')->toString();
        $isFile = $upload instanceof UploadedFile;
        if (! $isFile && trim($text) === '') {
            throw ValidationException::withMessages(['file' => 'فایل CSV یا متن کلمات را وارد کنید.']);
        }
        if ($isFile && trim($text) !== '') {
            throw ValidationException::withMessages(['file' => 'فقط فایل یا متن را انتخاب کنید.']);
        }
        $field = $isFile ? 'file' : 'text';
        if (($isFile && $upload->getSize() > (int) config('keywords.import.max_bytes'))
            || (! $isFile && (strlen($text) > (int) config('keywords.import.max_paste_bytes')
                || substr_count(str_replace("\r\n", "\n", $text), "\n") + 1 > (int) config('keywords.import.max_paste_lines')))) {
            throw ValidationException::withMessages([$field => 'حجم ورودی بیش از حد مجاز است.']);
        }
        $temporary = null;
        $storedPath = null;
        try {
            File::ensureDirectoryExists($files->root(), 0700);
            $temporary = $files->root().'/.incoming-'.bin2hex(random_bytes(16));
            if ($isFile) {
                if (! copy($upload->getPathname(), $temporary)) {
                    throw new RuntimeException('Unable to store import.');
                }
            } else {
                $stream = fopen($temporary, 'xb');
                if ($stream === false) {
                    throw new RuntimeException('Unable to store import.');
                }
                try {
                    fputcsv($stream, ['Keyword'], ',', '"', '');
                    foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
                        if (trim($line) !== '') {
                            fputcsv($stream, [$line], ',', '"', '');
                        }
                    }
                } finally {
                    fclose($stream);
                }
            }
            chmod($temporary, 0600);
            $headers = $reader->headers($temporary);
            if ($headers === [] || ! mb_check_encoding($headers, 'UTF-8') || in_array('', $headers, true) || count(array_unique($headers)) !== count($headers)
                || ! $reader->rows($temporary)->valid()) {
                throw ValidationException::withMessages([$field => 'CSV باید ستون‌های یکتا و حداقل یک ردیف داده داشته باشد.']);
            }
            $batch = DB::transaction(function () use ($project, $upload, $isFile, $temporary, $files, &$storedPath): ImportBatch {
                $batch = $project->batches()->create([
                    'source' => $isFile ? 'csv' : 'paste',
                    'original_filename' => $isFile ? $upload->getClientOriginalName() : 'pasted-keywords.csv',
                    'mapping' => [],
                    'status' => 'awaiting_mapping',
                    'source_bytes' => filesize($temporary),
                ]);
                $relative = $files->relativePath($batch);
                $storedPath = $files->root().'/'.$relative;
                File::ensureDirectoryExists(dirname($storedPath), 0700);
                if (realpath(dirname($storedPath)) !== dirname($storedPath) || is_link(dirname($storedPath))) {
                    $storedPath = null;
                    throw new RuntimeException('Import storage is not available.');
                }
                if (! rename($temporary, $storedPath)) {
                    throw new RuntimeException('Unable to store import.');
                }
                $batch->update(['source_path' => $relative]);

                return $batch;
            });
        } catch (Throwable $exception) {
            if (is_string($storedPath) && is_file($storedPath)) {
                unlink($storedPath);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            throw ValidationException::withMessages([$field => 'ذخیره ورودی ممکن نشد؛ دوباره تلاش کنید.']);
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $this->batchRedirect($project, $batch);
    }

    public function mapping(Request $request, Project $project, ImportBatch $batch, StreamingCsvReader $reader, KeywordImportFiles $files): RedirectResponse
    {
        Gate::authorize('stageKeywordImports', $project);
        $request->validate(['keyword' => ['required', 'string'], 'volume' => ['nullable', 'string', 'different:keyword']]);
        try {
            $headers = $reader->headers($files->absolutePath($batch));
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['keyword' => 'فایل ورودی در دسترس نیست.']);
        }
        foreach (['keyword', 'volume'] as $field) {
            if ($request->filled($field) && ! in_array($request->input($field), $headers, true)) {
                throw ValidationException::withMessages([$field => 'ستون انتخابی در فایل وجود ندارد.']);
            }
        }
        DB::transaction(function () use ($batch, $request): void {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            abort_unless($locked->status === 'awaiting_mapping', 409);
            $locked->update([
                'mapping' => array_filter($request->only(['keyword', 'volume']), fn ($value) => is_string($value) && $value !== ''),
                'status' => 'queued',
            ]);
            // The default database queue insert commits atomically with this state.
            ProcessKeywordImportFile::dispatch($batch->id)->beforeCommit();
        });

        return $this->batchRedirect($project, $batch);
    }

    public function commit(Request $request, Project $project, ImportBatch $batch): RedirectResponse
    {
        Gate::authorize('stageKeywordImports', $project);
        DB::transaction(function () use ($batch, $request): void {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            abort_unless(in_array($locked->status, ['staged', 'committed'], true), 409);
            if ($locked->commit_requested_at !== null || $locked->status === 'committed') {
                return;
            }
            $locked->update(['commit_requested_at' => now(), 'commit_requested_by' => $request->user()?->id]);

            CommitKeywordImport::dispatch($batch->id)->beforeCommit();
        });

        return $this->batchRedirect($project, $batch);
    }

    public function discard(Request $request, Project $project, ImportBatch $batch, KeywordImportFiles $files): RedirectResponse
    {
        Gate::authorize('stageKeywordImports', $project);
        DB::transaction(function () use ($batch, $request, $files): void {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            abort_unless(in_array($locked->status, ['awaiting_mapping', 'staged', 'failed'], true)
                && $locked->commit_requested_at === null, 409);
            try {
                $files->delete($locked);
            } catch (RuntimeException) {
                throw ValidationException::withMessages(['file' => 'حذف فایل ورودی ممکن نشد؛ دوباره تلاش کنید.']);
            }
            $locked->rows()->delete();
            $locked->update(['status' => 'discarded', 'discarded_at' => now(), 'discarded_by' => $request->user()?->id]);
        });

        return $this->batchRedirect($project, $batch);
    }

    private function batchRedirect(Project $project, ImportBatch $batch): RedirectResponse
    {
        return redirect()->route('projects.keyword-imports.index', ['project' => $project, 'batch' => $batch->id]);
    }

    public function stage(Request $request, Project $project, StageKeywordImport $stageImport): JsonResponse
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
