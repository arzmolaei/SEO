<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Projects\ProjectReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectReportController extends Controller
{
    /** Plain Persian labels for the CSV, in report order. */
    private const CSV_LABELS = [
        'path.done' => 'مراحل انجام‌شده مسیر سئو',
        'path.total' => 'همه مراحل مسیر سئو',
        'keywords.keywords' => 'کلمات کلیدی',
        'keywords.clusters' => 'خوشه‌ها',
        'keywords.mapped' => 'خوشه‌های دارای صفحه',
        'keywords.ready' => 'خوشه‌های آماده',
        'pages.existing' => 'صفحه‌های موجود',
        'pages.planned' => 'صفحه‌های برنامه‌ریزی‌شده',
        'pages.on_page.todo' => 'صفحه‌های شروع‌نشده',
        'pages.on_page.in_progress' => 'صفحه‌های در حال کار',
        'pages.on_page.done' => 'صفحه‌های تمام‌شده',
        'pages.last_check.checked' => 'صفحه‌های بررسی‌شده در آخرین بررسی سایت',
        'pages.last_check.errors' => 'صفحه‌های دارای خطا در آخرین بررسی',
        'content.draft' => 'بریف‌های پیش‌نویس',
        'content.review' => 'بریف‌های در بازبینی',
        'content.published' => 'محتوای منتشرشده',
        'content.published_in_period' => 'منتشرشده در این دوره',
        'content.due_in_period' => 'موعد در این دوره (منتشرنشده)',
        'tasks.open' => 'کارهای باز',
        'tasks.overdue' => 'کارهای عقب‌افتاده',
        'tasks.done_in_period' => 'کارهای انجام‌شده در این دوره',
        'tasks.created_in_period' => 'کارهای ساخته‌شده در این دوره',
        'links.added_in_period' => 'لینک‌های داخلی افزوده در این دوره',
        'links.added_total' => 'همه لینک‌های داخلی افزوده',
        'performance.totals.clicks' => 'کلیک (آخرین فایل سرچ کنسول)',
        'performance.totals.impressions' => 'نمایش (آخرین فایل سرچ کنسول)',
        'performance.totals.ctr' => 'نرخ کلیک',
        'performance.totals.position' => 'میانگین جایگاه',
    ];

    /** Plain Persian names for the recorded kinds of work. */
    public const ACTIVITY_LABELS = [
        'keywords.intent.assigned' => 'تعیین نیت کلمات',
        'keywords.cluster.created' => 'ساخت خوشه',
        'keywords.cluster.planning.updated' => 'برنامه‌ریزی خوشه',
        'keywords.cluster.ready' => 'آماده شدن خوشه',
        'keywords.mapping.set' => 'اختصاص صفحه به خوشه',
        'keywords.bulk_status.updated' => 'تغییر وضعیت کلمات',
        'review.intent.resolved' => 'بررسی نیت',
        'review.cluster.resolved' => 'بررسی خوشه',
        'review.mapping_conflict.resolved' => 'رفع تداخل صفحه',
        'page.on_page_updated' => 'به‌روزرسانی سئوی صفحه',
        'page.live_values_adopted' => 'برداشتن متن از سایت',
        'content_brief.created' => 'ساخت بریف محتوا',
        'content_brief.updated' => 'ویرایش بریف محتوا',
        'content_brief.deleted' => 'حذف بریف محتوا',
        'task.created' => 'ساخت کار',
        'task.updated' => 'ویرایش کار',
        'task.deleted' => 'حذف کار',
        'internal_link.decided' => 'تصمیم درباره لینک داخلی',
        'search_console.imported' => 'ورود داده سرچ کنسول',
        'search_console.deleted' => 'حذف داده سرچ کنسول',
        'export.seo_plan' => 'خروجی برنامه سئو',
    ];

    public function index(Request $request, Project $project, ProjectReport $report): Response
    {
        Gate::authorize('view', $project);
        [$from, $to] = $this->period($request);

        return Inertia::render('projects/report', [
            'report' => $report->for($project, $from, $to),
        ]);
    }

    public function csv(Request $request, Project $project, ProjectReport $report): StreamedResponse
    {
        Gate::authorize('view', $project);
        [$from, $to] = $this->period($request);
        $data = $report->for($project, $from, $to);
        $filename = 'seo-report-'.$project->id.'-'.$from->toDateString().'-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['شاخص', 'کلید', 'مقدار'], ',', '"', '');
            fputcsv($out, ['دوره', 'period', $data['period']['from'].' — '.$data['period']['to']], ',', '"', '');
            foreach (self::CSV_LABELS as $key => $label) {
                $value = data_get($data, $key === 'path.done' ? 'path.progress.done' : ($key === 'path.total' ? 'path.progress.total' : $key));
                if ($value !== null) {
                    fputcsv($out, [$label, $key, is_scalar($value) ? (string) $value : ''], ',', '"', '');
                }
            }
            foreach ($data['activity'] as $row) {
                $label = self::ACTIVITY_LABELS[$row['event']] ?? $row['event'];
                fputcsv($out, ['کار انجام‌شده: '.$label, 'activity.'.$row['event'], (string) $row['count']], ',', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = isset($data['to']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['to'])->startOfDay() : CarbonImmutable::today();
        $from = isset($data['from']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['from'])->startOfDay() : $to->subDays(29);

        // Bounded to two years so one report stays one quick page.
        return [$from->lessThan($to->subYears(2)) ? $to->subYears(2) : $from, $to];
    }
}
