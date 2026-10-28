<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Update\CancelPendingUpdate;
use App\Services\Update\ContinueSystemUpdate;
use App\Services\Update\PrepareSystemUpdate;
use App\Services\Update\RecoverInterruptedUpdate;
use App\Services\Update\SwitchSystemUpdateRelease;
use App\Services\Update\UpdateException;
use App\Services\Update\UpdateJournal;
use App\Services\Update\VerifyUpdatePackage;
use App\Support\Lifecycle\ProductVersion;
use App\Support\Lifecycle\RuntimeReleaseAdmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SystemUpdateController extends Controller
{
    public function show(Request $request, ProductVersion $productVersion, UpdateJournal $journal): Response
    {
        try {
            $state = $journal->state();
            $status = is_string($state['phase'] ?? null) ? $state['phase'] : 'ready';
        } catch (Throwable) {
            $state = null;
            $status = 'recovery_required';
        }
        $bootstrapRecovery = $status === 'recovery_required'
            && ($state['recovery_code'] ?? null) === 'update_target_bootstrap_failed'
            && ($state['recovery_reverted_to_source'] ?? null) === true
            && ($state['migration_started'] ?? null) === false;
        $data = [
            'current_product_version' => $productVersion->requireDistributable(),
            'status' => $status,
            'preview' => $this->safePreview($state['preview'] ?? null),
            'canContinue' => in_array($status, ['target_active', 'migrating', 'validating', 'complete'], true),
            'canRecover' => $bootstrapRecovery || in_array($status, ['rollback_required', 'migration_rollback', 'source_switch_pending', 'snapshot_restore_required'], true),
            // Before the release switch nothing destructive happened; the owner may abandon it.
            'canCancel' => in_array($status, ['uploaded', 'verified', 'preparing', 'quiescing', 'snapshot_ready', 'staged'], true),
        ];
        if ($request->expectsJson()) {
            return response()->json($data, 200, $this->privateHeaders());
        }
        $response = Inertia::render('settings/update-recovery', ['update' => $data])->toResponse($request);
        $response->headers->add($this->privateHeaders());

        return $response;
    }

    public function authorize(): RedirectResponse
    {
        return redirect()->route('settings.update');
    }

    public function verify(Request $request, VerifyUpdatePackage $verifier): JsonResponse
    {
        $maxKilobytes = max(1, (int) ceil(((int) config('update.package.max_package_bytes', 2 * 1024 * 1024 * 1024)) / 1024));
        $validated = $request->validate([
            'update' => ['required', 'file', 'max:'.$maxKilobytes],
        ]);

        $upload = $validated['update'] ?? null;
        if (! $upload instanceof UploadedFile) {
            return response()->json([
                'code' => 'update_invalid_package',
                'message' => 'بسته به‌روزرسانی معتبر نیست.',
            ], 422, $this->privateHeaders());
        }

        try {
            $preview = $verifier->handle($upload);

            return response()->json([
                'preview' => $preview->toArray(),
            ], 200, $this->privateHeaders());
        } catch (UpdateException $exception) {
            $status = $exception->codeName() === 'update_lifecycle_conflict' ? 409 : 422;

            return response()->json([
                'code' => $exception->codeName(),
                'message' => $exception->userMessage(),
            ], $status, $this->privateHeaders());
        } catch (Throwable) {
            return response()->json([
                'code' => 'update_invalid_package',
                'message' => 'بسته به‌روزرسانی معتبر نیست.',
            ], 422, $this->privateHeaders());
        }
    }

    public function confirm(
        Request $request,
        UpdateJournal $journal,
        PrepareSystemUpdate $prepare,
        SwitchSystemUpdateRelease $switchRelease,
    ): JsonResponse {
        $validated = $request->validate([
            'package_digest_sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $digest = $validated['package_digest_sha256'];
        try {
            if (($journal->state()['phase'] ?? null) === 'verified') {
                $prepare->handle($digest);
            }
            $result = $switchRelease->handle($digest);

            // The target's autoloader/kernel must start only in the next HTTP request.
            return response()->json([
                ...$result,
                'requires_reload' => true,
                'continue_url' => route('settings.update'),
            ], 200, $this->privateHeaders());
        } catch (UpdateException $exception) {
            return response()->json([
                'code' => $exception->codeName(),
                'message' => $exception->userMessage(),
            ], 409, $this->privateHeaders());
        } catch (Throwable) {
            return response()->json([
                'code' => 'update_operation_failed',
                'message' => 'ادامه به‌روزرسانی متوقف شد؛ وضعیت عملیات را بررسی کنید.',
            ], 409, $this->privateHeaders());
        }
    }

    public function continueUpdate(
        Request $request,
        ContinueSystemUpdate $continue,
        RuntimeReleaseAdmission $admission,
    ): JsonResponse {
        return $this->performLifecycle($request, $admission, fn (): array => $continue->handle());
    }

    public function cancel(CancelPendingUpdate $cancel): JsonResponse
    {
        try {
            return response()->json([
                ...$cancel->handle(),
                'requires_reload' => true,
                'continue_url' => route('settings.update'),
            ], 200, $this->privateHeaders());
        } catch (UpdateException $exception) {
            return response()->json(['code' => $exception->codeName(), 'message' => $exception->userMessage()], 409, $this->privateHeaders());
        } catch (Throwable) {
            return response()->json(['code' => 'update_operation_failed', 'message' => 'لغو به‌روزرسانی کامل نشد؛ وضعیت سرور را دوباره بررسی کنید.'], 409, $this->privateHeaders());
        }
    }

    public function recover(
        Request $request,
        RecoverInterruptedUpdate $recover,
        RuntimeReleaseAdmission $admission,
    ): JsonResponse {
        return $this->performLifecycle($request, $admission, fn (): array => $recover->handle());
    }

    /** @param callable(): array<string, mixed> $operation */
    private function performLifecycle(Request $request, RuntimeReleaseAdmission $admission, callable $operation): JsonResponse
    {
        try {
            $admission->assertCurrent();
        } catch (Throwable) {
            return response()->json([
                'code' => 'update_invalid_state',
                'message' => 'درخواست باید از نسخه فعال برنامه دوباره ارسال شود.',
            ], 409, $this->privateHeaders());
        }

        try {
            $result = $operation();
            $loginRequired = ($result['status'] ?? null) === 'rolled_back';
            if ($loginRequired) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                ...$result,
                'requires_reload' => ($result['status'] ?? null) === 'snapshot_restore_required',
                'requires_login' => $loginRequired,
                'continue_url' => route('settings.update'),
            ], 200, $this->privateHeaders());
        } catch (UpdateException $exception) {
            return response()->json(['code' => $exception->codeName(), 'message' => $exception->userMessage()], 409, $this->privateHeaders());
        } catch (Throwable) {
            return response()->json(['code' => 'update_operation_failed', 'message' => 'عملیات کامل نشد؛ وضعیت سرور را بررسی و دوباره تلاش کنید.'], 409, $this->privateHeaders());
        }
    }

    /** @return array<string, mixed>|null */
    private function safePreview(mixed $preview): ?array
    {
        if (! is_array($preview)) {
            return null;
        }
        $result = [];
        foreach (['package_digest_sha256', 'source_product_version', 'target_product_version', 'channel', 'minimum_php_version'] as $key) {
            if (! is_string($preview[$key] ?? null)) {
                return null;
            }
            $result[$key] = $preview[$key];
        }
        $extensions = $preview['required_php_extensions'] ?? null;
        if (! is_array($extensions) || ! array_is_list($extensions) || ! is_int($preview['migration_count'] ?? null)) {
            return null;
        }
        foreach ($extensions as $extension) {
            if (! is_string($extension)) {
                return null;
            }
        }

        return [...$result, 'required_php_extensions' => $extensions, 'migration_count' => $preview['migration_count']];
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'no-cache, private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
