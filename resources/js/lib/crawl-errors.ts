import type { Locale } from '@/i18n';

// Stable crawler codes (CrawlRefusal reasons and processor outcomes) explained
// for operators; the raw code stays visible next to the explanation.
const labels: Record<string, [fa: string, en: string]> = {
    blocked_by_robots: [
        'robots.txt سایت اجازه خزش این نشانی را نمی‌دهد.',
        'The site robots.txt disallows this URL.',
    ],
    excluded_by_settings: [
        'با پیشوندهای شامل/مستثنای این خزش کنار گذاشته شد.',
        'Excluded by this crawl’s include/exclude prefixes.',
    ],
    out_of_scope: [
        'خارج از دامنه‌های مجاز این خزش است و درخواستی ارسال نشد.',
        'Outside this crawl’s allowed hosts; no request was sent.',
    ],
    cancelled: [
        'خزش پیش از رسیدن به این نشانی لغو شد.',
        'The crawl was cancelled before this URL.',
    ],
    private_network: [
        'نشانی به شبکه خصوصی یا داخلی اشاره می‌کند و برای امنیت مسدود شد. برای سایت‌های داخلی، مدیر سرور می‌تواند CRAWLER_ALLOW_PRIVATE_NETWORKS را فعال کند.',
        'The host resolves to a private or internal network and was blocked for safety. For intranet sites the operator can enable CRAWLER_ALLOW_PRIVATE_NETWORKS.',
    ],
    proxy_not_supported: [
        'سرور از پروکسی خروجی استفاده می‌کند؛ خزنده برای حفظ بررسی‌های امنیتی از پروکسی عبور نمی‌کند. دامنه سایت را در no_proxy سرور قرار دهید.',
        'The server uses an outbound proxy; the crawler does not route through it so its safety checks stay effective. Add the site to the server no_proxy list.',
    ],
    dns_failed: ['نام دامنه پیدا نشد.', 'The host name could not be resolved.'],
    connect_failed: [
        'اتصال به سایت برقرار نشد.',
        'Could not connect to the site.',
    ],
    timeout: [
        'پاسخ سایت در زمان مجاز نرسید.',
        'The site did not respond in time.',
    ],
    tls_failed: [
        'گواهی امنیتی (HTTPS) سایت تأیید نشد.',
        'The site’s HTTPS certificate could not be verified.',
    ],
    body_too_large: [
        'حجم صفحه از سقف مجاز بیشتر بود.',
        'The page exceeded the size limit.',
    ],
    invalid_url: ['نشانی معتبر نیست.', 'The URL is not valid.'],
    redirect_invalid: [
        'مقصد تغییر مسیر معتبر نیست.',
        'The redirect target is not valid.',
    ],
    redirect_loop: ['تغییر مسیر در حلقه افتاد.', 'The redirects form a loop.'],
    too_many_redirects: [
        'تعداد تغییر مسیرها بیش از حد مجاز است.',
        'Too many redirects.',
    ],
    redirect_out_of_scope: [
        'به سایت دیگری تغییر مسیر داد؛ مقصد ثبت شد ولی خزش نشد.',
        'Redirects to another site; the target was recorded but not crawled.',
    ],
    transport_unavailable: [
        'افزونه curl در PHP سرور فعال نیست.',
        'The PHP curl extension is not available on the server.',
    ],
};

export function crawlErrorLabel(code: string, locale: Locale): string | null {
    const index = locale === 'fa' ? 0 : 1;
    const known = labels[code];
    if (known) return known[index];
    const http = /^http_(\d{3})$/.exec(code);
    if (http)
        return locale === 'fa'
            ? `سایت پاسخ ${http[1]} داد؛ دوباره تلاش می‌شود.`
            : `The site answered ${http[1]}; it will be retried.`;

    return null;
}
