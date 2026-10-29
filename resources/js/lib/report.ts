import type { Locale } from '@/i18n';

// Kept in sync with ProjectReportController::ACTIVITY_LABELS (Persian).
const labels: Record<Locale, Record<string, string>> = {
    fa: {
        'keywords.intent.assigned': 'تعیین نیت کلمات',
        'keywords.cluster.created': 'ساخت خوشه',
        'keywords.cluster.planning.updated': 'برنامه‌ریزی خوشه',
        'keywords.cluster.ready': 'آماده شدن خوشه',
        'keywords.mapping.set': 'اختصاص صفحه به خوشه',
        'keywords.bulk_status.updated': 'تغییر وضعیت کلمات',
        'review.intent.resolved': 'بررسی نیت',
        'review.cluster.resolved': 'بررسی خوشه',
        'review.mapping_conflict.resolved': 'رفع تداخل صفحه',
        'page.on_page_updated': 'به‌روزرسانی سئوی صفحه',
        'page.live_values_adopted': 'برداشتن متن از سایت',
        'content_brief.created': 'ساخت بریف محتوا',
        'content_brief.updated': 'ویرایش بریف محتوا',
        'content_brief.deleted': 'حذف بریف محتوا',
        'task.created': 'ساخت کار',
        'task.updated': 'ویرایش کار',
        'task.deleted': 'حذف کار',
        'internal_link.decided': 'تصمیم درباره لینک داخلی',
        'search_console.imported': 'ورود داده سرچ کنسول',
        'search_console.deleted': 'حذف داده سرچ کنسول',
        'export.seo_plan': 'خروجی برنامه سئو',
    },
    en: {
        'keywords.intent.assigned': 'Keyword intent set',
        'keywords.cluster.created': 'Cluster created',
        'keywords.cluster.planning.updated': 'Cluster planned',
        'keywords.cluster.ready': 'Cluster marked ready',
        'keywords.mapping.set': 'Page mapped to cluster',
        'keywords.bulk_status.updated': 'Keyword status changed',
        'review.intent.resolved': 'Intent reviewed',
        'review.cluster.resolved': 'Cluster reviewed',
        'review.mapping_conflict.resolved': 'Page conflict resolved',
        'page.on_page_updated': 'Page SEO updated',
        'page.live_values_adopted': 'Site text copied to page',
        'content_brief.created': 'Content brief created',
        'content_brief.updated': 'Content brief edited',
        'content_brief.deleted': 'Content brief deleted',
        'task.created': 'Task created',
        'task.updated': 'Task updated',
        'task.deleted': 'Task deleted',
        'internal_link.decided': 'Internal link decision',
        'search_console.imported': 'Search Console data imported',
        'search_console.deleted': 'Search Console data deleted',
        'export.seo_plan': 'SEO plan exported',
    },
};

export function activityLabel(event: string, locale: Locale): string {
    return labels[locale][event] ?? event;
}
