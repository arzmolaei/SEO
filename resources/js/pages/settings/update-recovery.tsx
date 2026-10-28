import { Head, router, usePage } from '@inertiajs/react';
import { ShieldAlert, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useServerPoll } from '@/hooks/use-server-poll';
import { updateNeedsPolling } from '@/lib/status-polling';

type Preview = {
    package_digest_sha256: string;
    source_product_version: string;
    target_product_version: string;
    channel: string;
    minimum_php_version: string;
    required_php_extensions: string[];
    migration_count: number;
};
type UpdateState = {
    current_product_version: string;
    max_upload_bytes: number | null;
    status: string;
    preview: Preview | null;
    canContinue: boolean;
    canRecover: boolean;
    canCancel: boolean;
};
type Result = {
    message?: string;
    requires_reload?: boolean;
    requires_login?: boolean;
};
const phaseLabels: Record<string, [string, string]> = {
    ready: ['آماده دریافت بسته', 'Ready for a package'],
    uploaded: ['بسته بارگذاری شده', 'Package uploaded'],
    verified: [
        'بسته بررسی شده؛ منتظر تأیید',
        'Verified; awaiting confirmation',
    ],
    preparing: ['آماده‌سازی عملیات', 'Preparing update'],
    quiescing: [
        'در انتظار پایان کارهای در حال اجرا',
        'Waiting for active work to finish',
    ],
    snapshot_ready: ['پشتیبان ایمنی آماده است', 'Safety snapshot ready'],
    staged: ['نسخه جدید آماده فعال‌سازی است', 'Target release staged'],
    switch_pending: ['در حال تغییر نسخه فعال', 'Release switch pending'],
    target_active: [
        'نسخه جدید راه‌اندازی شده؛ ادامه را بزنید',
        'Target booted; continue update',
    ],
    migrating: ['اجرای مهاجرت‌های نسخه جدید', 'Applying target migrations'],
    validating: ['بررسی سلامت و ثبت نسخه', 'Validating and finalizing'],
    rollback_required: ['نیازمند بازگردانی نسخه قبلی', 'Rollback required'],
    migration_rollback: [
        'بازگردانی ساختار پایگاه داده',
        'Reversing update migrations',
    ],
    source_switch_pending: [
        'بازگشت به نسخه قبلی',
        'Switching to source release',
    ],
    snapshot_restore_required: [
        'نسخه قبلی فعال است؛ بازیابی داده را ادامه دهید',
        'Source active; restore safety snapshot',
    ],
    recovery_required: [
        'بازیابی خودکار قابل اثبات نیست',
        'Automatic recovery cannot be proven',
    ],
    complete: ['عملیات تکمیل شده', 'Update complete'],
};
const copy = {
    fa: {
        title: 'به‌روزرسانی و بازیابی نسخه',
        description:
            'بسته نسخه جدید را بررسی کنید و وضعیت واقعی به‌روزرسانی یا بازیابی را دنبال کنید.',
        trusted: 'فقط بسته انتشار مورد اعتماد را نصب کنید',
        warning:
            'این بسته کد اجرایی دارد. SHA-256 سلامت فایل را بررسی می‌کند و تأیید هویت ناشر یا امضای دیجیتال نیست. پیش از تغییر نسخه، پشتیبان ایمنی ساخته می‌شود.',
        version: 'نسخه جاری',
        status: 'وضعیت سرور',
        file: 'بسته .seoupdate',
        verify: 'بارگذاری و بررسی بسته',
        confirm: 'شروع به‌روزرسانی',
        target: 'نسخه مقصد',
        channel: 'کانال انتشار',
        php: 'حداقل PHP',
        migrations: 'تعداد مهاجرت‌ها',
        digest: 'برای تأیید، SHA-256 بسته را دقیقاً وارد کنید',
        experimental:
            'این بسته پایدار نیست؛ نسخه‌های آزمایشی را فقط پس از بررسی سازگاری و داشتن پشتیبان مستقل نصب کنید.',
        continue: 'ادامه به‌روزرسانی',
        recover: 'ادامه بازگردانی ایمن',
        cancel: 'لغو به‌روزرسانی',
        cancelHint:
            'نسخه فعال هنوز تغییر نکرده است. با لغو، حالت نگهداری برداشته می‌شود و می‌توانید بعداً دوباره تلاش کنید.',
        autoRefresh: 'وضعیت هر چند ثانیه به‌طور خودکار از سرور خوانده می‌شود.',
        pollFailed:
            'دریافت وضعیت از سرور ناموفق بود؛ به‌طور خودکار دوباره تلاش می‌شود.',
        manual: 'ادامه خودکار مسدود است. فایل‌های ایمنی را حفظ کنید و با مدیر فنی برای بررسی بسته و شواهد بازیابی هماهنگ کنید.',
        busy: 'در حال انجام عملیات…',
        error: 'عملیات کامل نشد؛ وضعیت فعلی سرور در همین صفحه نمایش داده می‌شود.',
        mismatch: 'SHA-256 واردشده با بسته بررسی‌شده برابر نیست.',
        choose: 'ابتدا فایل بسته را انتخاب کنید.',
        tooLarge: (size: string, limit: string) =>
            `حجم بسته (${size}) از سقف آپلود این هاست (${limit}) بیشتر است. در تنظیمات PHP هاست، upload_max_filesize و post_max_size را دست‌کم به اندازه بسته افزایش دهید.`,
        completed: 'نسخه جدید ثبت شد و بررسی سلامت را گذراند.',
        back: 'بازگشت به برنامه',
    },
    en: {
        title: 'Update & Recovery',
        description:
            'Verify a release package and follow actual update or recovery phases.',
        trusted: 'Install trusted release packages only',
        warning:
            'Packages contain executable code. SHA-256 verifies file integrity, not publisher identity or a digital signature. A safety snapshot is required before switching releases.',
        version: 'Current version',
        status: 'Server status',
        file: '.seoupdate package',
        verify: 'Upload and verify',
        confirm: 'Start update',
        target: 'Target version',
        channel: 'Release channel',
        php: 'Minimum PHP',
        migrations: 'Migrations',
        digest: 'Type the exact verified package SHA-256 to confirm',
        experimental:
            'This is a non-stable release. Check compatibility and retain an independent backup before installing.',
        continue: 'Continue update',
        recover: 'Continue safe rollback',
        cancel: 'Cancel update',
        cancelHint:
            'The active release has not changed. Cancelling lifts maintenance so you can retry later.',
        autoRefresh:
            'Status is re-read from the server automatically every few seconds.',
        pollFailed:
            'Could not read status from the server; retrying automatically.',
        manual: 'Automatic continuation is blocked. Retain the safety artifacts and ask your system operator to inspect the recovery evidence.',
        busy: 'Working…',
        error: 'The operation did not finish. The current server state is shown on this page.',
        mismatch: 'The digest does not match the verified package.',
        choose: 'Choose a release package first.',
        tooLarge: (size: string, limit: string) =>
            `The package (${size}) exceeds this host's upload limit (${limit}). Raise PHP upload_max_filesize and post_max_size to at least the package size.`,
        completed:
            'The new version passed health validation and was finalized.',
        back: 'Return to application',
    },
};

function formatBytes(bytes: number): string {
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function UpdateRecovery({ update }: { update: UpdateState }) {
    const { locale } = usePage<{ locale: 'fa' | 'en' }>().props;
    const t = copy[locale];
    const input = useRef<HTMLInputElement>(null);
    const inFlight = useRef(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [digest, setDigest] = useState('');
    const [completed, setCompleted] = useState(false);
    const preview = update.preview;
    const label =
        phaseLabels[update.status]?.[locale === 'fa' ? 0 : 1] ?? update.status;
    const canUpload = update.status === 'ready';
    // Re-read server truth while a phase can advance on its own. The release
    // switch itself still ends in a full page load (requires_reload, or the
    // Inertia asset-version change), so the new release's code boots.
    const polling = updateNeedsPolling(update.status, busy);
    const poll = useServerPoll(['update'], polling);

    async function run(
        action: 'verify' | 'confirm' | 'continue' | 'recover' | 'cancel',
    ) {
        if (inFlight.current) return;
        const body = new FormData();
        if (action === 'verify') {
            const file = input.current?.files?.[0];
            if (!file) {
                setError(t.choose);
                return;
            }
            if (
                update.max_upload_bytes !== null &&
                file.size > update.max_upload_bytes
            ) {
                setError(
                    t.tooLarge(
                        formatBytes(file.size),
                        formatBytes(update.max_upload_bytes),
                    ),
                );
                return;
            }
            body.append('update', file);
        }
        if (action === 'confirm') {
            if (!preview || digest !== preview.package_digest_sha256) {
                setError(t.mismatch);
                return;
            }
            body.append('package_digest_sha256', digest);
        }
        inFlight.current = true;
        setBusy(true);
        setError('');
        try {
            const headers = new Headers({
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            });
            const cookie = document.cookie
                .split('; ')
                .find((value) => value.startsWith('XSRF-TOKEN='));
            if (cookie)
                headers.set(
                    'X-XSRF-TOKEN',
                    decodeURIComponent(cookie.slice(11)),
                );
            const response = await fetch(`/settings/update/${action}`, {
                method: 'POST',
                body,
                headers,
                credentials: 'same-origin',
            });
            if (response.redirected) {
                window.location.assign(response.url);
                return;
            }
            if (response.status === 401) {
                window.location.assign('/login');
                return;
            }
            if (response.status === 423) {
                window.location.assign('/settings/update/authorize');
                return;
            }
            const result = (await response.json()) as Result;
            if (!response.ok) throw new Error(result.message || t.error);
            if (result.requires_login) {
                window.location.assign('/login');
                return;
            }
            if (result.requires_reload) {
                window.location.assign('/settings/update');
                return;
            }
            if (action === 'continue') setCompleted(true);
            setDigest('');
            router.reload({ only: ['update'] });
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : t.error);
            router.reload({ only: ['update'] });
        } finally {
            inFlight.current = false;
            setBusy(false);
        }
    }

    return (
        <>
            <Head title={t.title} />
            <div
                className="space-y-6"
                dir={locale === 'fa' ? 'rtl' : 'ltr'}
                aria-busy={busy}
            >
                <Heading
                    variant="small"
                    title={t.title}
                    description={t.description}
                />
                <Alert>
                    <ShieldAlert aria-hidden="true" />
                    <AlertTitle>{t.trusted}</AlertTitle>
                    <AlertDescription>{t.warning}</AlertDescription>
                </Alert>
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t.version}:{' '}
                            <bdi>{update.current_product_version}</bdi>
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p role="status" aria-live="polite">
                            {t.status}: {label}
                        </p>
                        {busy && <p role="status">{t.busy}</p>}
                        {polling && (
                            <p
                                className="text-sm text-muted-foreground"
                                data-test="update-auto-refresh"
                            >
                                {t.autoRefresh}
                            </p>
                        )}
                        {polling && poll.failed && (
                            <p role="alert">{t.pollFailed}</p>
                        )}
                        {update.canContinue && (
                            <Button
                                disabled={busy}
                                onClick={() => void run('continue')}
                            >
                                {t.continue}
                            </Button>
                        )}
                        {update.canCancel && (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    {t.cancelHint}
                                </p>
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => void run('cancel')}
                                >
                                    {t.cancel}
                                </Button>
                            </>
                        )}
                        {update.canRecover && (
                            <Button
                                disabled={busy}
                                onClick={() => void run('recover')}
                            >
                                {t.recover}
                            </Button>
                        )}
                    </CardContent>
                </Card>
                {error && (
                    <Alert variant="destructive">
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}
                {completed && (
                    <Alert>
                        <AlertDescription>{t.completed}</AlertDescription>
                    </Alert>
                )}
                {update.status === 'recovery_required' &&
                    !update.canRecover && (
                        <Alert variant="destructive">
                            <AlertDescription>{t.manual}</AlertDescription>
                        </Alert>
                    )}
                {canUpload && (
                    <Card>
                        <CardContent className="space-y-4 pt-6">
                            <Label htmlFor="update-package">{t.file}</Label>
                            <Input
                                ref={input}
                                id="update-package"
                                type="file"
                                accept=".seoupdate"
                                disabled={busy}
                            />
                            <Button
                                disabled={busy}
                                onClick={() => void run('verify')}
                            >
                                <Upload aria-hidden="true" />
                                {t.verify}
                            </Button>
                        </CardContent>
                    </Card>
                )}
                {preview && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t.target}:{' '}
                                <bdi>{preview.target_product_version}</bdi>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <dl className="grid grid-cols-2 gap-3 text-sm">
                                <dt>{t.channel}</dt>
                                <dd dir="ltr">{preview.channel}</dd>
                                <dt>{t.php}</dt>
                                <dd dir="ltr">
                                    {preview.minimum_php_version} ·{' '}
                                    {preview.required_php_extensions.join(', ')}
                                </dd>
                                <dt>{t.migrations}</dt>
                                <dd>{preview.migration_count}</dd>
                            </dl>
                            {preview.channel !== 'stable' && (
                                <Alert variant="destructive">
                                    <AlertDescription>
                                        {t.experimental}
                                    </AlertDescription>
                                </Alert>
                            )}
                            <code
                                className="block rounded bg-muted p-3 text-xs break-all"
                                dir="ltr"
                            >
                                {preview.package_digest_sha256}
                            </code>
                            <Label htmlFor="update-digest">{t.digest}</Label>
                            <Input
                                id="update-digest"
                                dir="ltr"
                                autoComplete="off"
                                spellCheck={false}
                                value={digest}
                                onChange={(event) =>
                                    setDigest(event.target.value)
                                }
                                disabled={busy}
                            />
                            <Button
                                disabled={
                                    busy ||
                                    update.status !== 'verified' ||
                                    digest !== preview.package_digest_sha256
                                }
                                onClick={() => void run('confirm')}
                            >
                                {t.confirm}
                            </Button>
                        </CardContent>
                    </Card>
                )}
                <Button variant="link" asChild>
                    <a href="/dashboard">{t.back}</a>
                </Button>
            </div>
        </>
    );
}

UpdateRecovery.layout = {
    breadcrumbs: [
        { title: 'به‌روزرسانی و بازیابی نسخه', href: '/settings/update' },
    ],
};
