<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Update\PrepareSystemUpdate;
use App\Services\Update\SwitchSystemUpdateRelease;
use App\Services\Update\UpdateException;
use App\Services\Update\UpdateJournal;
use App\Services\Update\VerifyUpdatePackage;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Throwable;

final class SystemUpdateController extends Controller
{
    public function show(ProductVersion $productVersion, UpdateJournal $journal): JsonResponse
    {
        try {
            $state = $journal->state();
            $status = is_array($state) && is_string($state['phase'] ?? null)
                ? $state['phase']
                : 'ready';
        } catch (Throwable) {
            $status = 'recovery_required';
        }

        return response()->json([
            'current_product_version' => $productVersion->requireDistributable(),
            'status' => $status,
        ], 200, $this->privateHeaders());
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
