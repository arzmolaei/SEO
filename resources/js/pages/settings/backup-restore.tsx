import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Download, RotateCcw, ShieldAlert, Upload } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RestorePreview = {
    digest: string;
    source_installation_id: string;
    source_created_at_utc: string;
    source_product_version: string;
    table_record_count: number;
    durable_file_count: number;
    bundle_bytes: number;
};

type RestoreState = {
    phase: string;
    recoveryRequired: boolean;
    preview: RestorePreview | null;
};

type Props = {
    restore: RestoreState;
};

type SharedProps = {
    locale: 'fa' | 'en';
};

type ApiFailure = {
    code?: string;
    message?: string;
};

const copy = {
    fa: {
        title: 'پشتیبان‌گیری و بازیابی',
        description:
            'از کل داده‌های ماندگار این نصب پشتیبان بگیرید یا یک فایل پشتیبان تأییدشده را بازیابی کنید.',
        warningTitle: 'فایل پشتیبان بسیار حساس است',
        warningDescription:
            'فایل دانلودشده شامل داده‌های حساس این نصب و APP_KEY است. آن را مانند یک secret نگه دارید و در فضای عمومی، ایمیل یا تیکت پشتیبانی بارگذاری نکنید.',
        status: 'وضعیت بازیابی',
        downloadTitle: 'دریافت پشتیبان کامل',
        downloadDescription:
            'پشتیبان کامل از داده‌های ماندگار و فایل‌های مجاز این نصب ساخته می‌شود. برای شروع، تأیید اخیر رمز عبور لازم است.',
        download: 'دانلود فایل پشتیبان',
        restoreTitle: 'بازیابی از فایل پشتیبان',
        restoreDescription:
            'فایل ابتدا به‌صورت خصوصی بارگذاری و کامل بررسی می‌شود. هیچ داده‌ای قبل از تأیید نهایی شما جایگزین نمی‌شود.',
        chooseFile: 'فایل .seobak',
        verify: 'بارگذاری و بررسی فایل',
        verifying: 'در حال بررسی…',
        previewTitle: 'فایل بررسی و تأیید شد',
        previewDescription:
            'پیش از بازیابی، مشخصات منبع را بررسی کنید. بازیابی داده‌های ماندگار مقصد را جایگزین می‌کند.',
        sourceVersion: 'نسخه منبع',
        createdAt: 'زمان ساخت',
        records: 'رکوردهای ماندگار',
        files: 'فایل‌های ماندگار',
        size: 'حجم فایل',
        digest: 'SHA-256 فایل تأییدشده',
        confirmLabel:
            'برای تأیید عملیات مخرب، SHA-256 بالا را دقیقاً در این کادر وارد کنید.',
        confirmPlaceholder: 'SHA-256 را دقیقاً وارد کنید',
        restore: 'شروع بازیابی قطعی',
        restoring: 'در حال بازیابی…',
        recoveryTitle: 'بازیابی نیمه‌کاره است و اقدام عادی مسدود شده',
        recoveryDescription:
            'سیستم یک شکست پس از شروع تغییرات مخرب ثبت کرده است. تا بازگردانی snapshot ایمنی، دانلود پشتیبان یا شروع بازیابی جدید مجاز نیست.',
        recover: 'بازگردانی وضعیت امن قبل از بازیابی',
        recovering: 'در حال بازگردانی…',
        genericError: 'عملیات انجام نشد. وضعیت سرور را دوباره بررسی کنید و تلاش مجدد انجام دهید.',
        fileRequired: 'یک فایل .seobak انتخاب کنید.',
        digestMismatch: 'SHA-256 واردشده دقیقاً با فایل تأییدشده برابر نیست.',
        phaseIdle: 'آماده',
        phaseUploaded: 'فایل بارگذاری شده',
        phaseVerified: 'فایل تأیید شده؛ منتظر تأیید نهایی',
        phaseConfirmed: 'تأیید نهایی ثبت شده',
        phaseDatabaseRestoring: 'در حال بازیابی پایگاه داده',
        phaseDatabaseCommitted: 'پایگاه داده بازیابی شده',
        phaseFilesRestoring: 'در حال بازیابی فایل‌ها',
        phaseKeyRotating: 'در حال اعمال کلید و تنظیمات مجاز',
        phaseFinalizing: 'در حال نهایی‌سازی',
        phaseComplete: 'بازیابی کامل شد',
        phaseRecovery: 'نیازمند بازگردانی ایمن',
        phaseUnknown: 'وضعیت سرور نامشخص',
    },
    en: {
        title: 'Backup & Restore',
        description:
            'Download a full backup of durable instance data or restore a previously verified backup file.',
        warningTitle: 'The backup file is highly sensitive',
        warningDescription:
            'The downloaded bundle contains sensitive instance data and the APP_KEY. Treat it as a secret and never upload it to public storage, email, or support tickets.',
        status: 'Restore status',
        downloadTitle: 'Download full backup',
        downloadDescription:
            'A complete backup of durable data and allowlisted files will be generated. Recent password confirmation is required.',
        download: 'Download backup file',
        restoreTitle: 'Restore from backup',
        restoreDescription:
            'The file is uploaded privately and fully verified first. No durable data is replaced until you explicitly confirm the verified digest.',
        chooseFile: '.seobak file',
        verify: 'Upload and verify file',
        verifying: 'Verifying…',
        previewTitle: 'Backup verified',
        previewDescription:
            'Review the source metadata before restoring. Restore replaces durable destination state.',
        sourceVersion: 'Source version',
        createdAt: 'Created at',
        records: 'Durable records',
        files: 'Durable files',
        size: 'Bundle size',
        digest: 'Verified SHA-256',
        confirmLabel:
            'To confirm this destructive operation, enter the exact SHA-256 shown above.',
        confirmPlaceholder: 'Enter the exact SHA-256',
        restore: 'Start destructive restore',
        restoring: 'Restoring…',
        recoveryTitle: 'Restore is interrupted and ordinary actions are blocked',
        recoveryDescription:
            'The server recorded a failure after destructive progress started. Restore the protected safety snapshot before starting another lifecycle operation.',
        recover: 'Recover pre-restore safe state',
        recovering: 'Recovering…',
        genericError: 'The operation failed. Refresh server state and try again.',
        fileRequired: 'Choose a .seobak file first.',
        digestMismatch: 'The entered SHA-256 does not exactly match the verified bundle.',
        phaseIdle: 'Ready',
        phaseUploaded: 'Bundle uploaded',
        phaseVerified: 'Bundle verified; awaiting confirmation',
        phaseConfirmed: 'Restore confirmed',
        phaseDatabaseRestoring: 'Restoring database',
        phaseDatabaseCommitted: 'Database restored',
        phaseFilesRestoring: 'Restoring files',
        phaseKeyRotating: 'Applying key and allowlisted settings',
        phaseFinalizing: 'Finalizing restore',
        phaseComplete: 'Restore complete',
        phaseRecovery: 'Recovery required',
        phaseUnknown: 'Unknown server state',
    },
} as const;

function xsrfToken(): string | null {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));

    if (!cookie) {
        return null;
    }

    return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
}

async function readFailure(response: Response): Promise<ApiFailure> {
    const contentType = response.headers.get('content-type') ?? '';

    if (!contentType.includes('application/json')) {
        return {};
    }

    try {
        return (await response.json()) as ApiFailure;
    } catch {
        return {};
    }
}

function formatBytes(value: number, locale: 'fa' | 'en'): string {
    return new Intl.NumberFormat(locale === 'fa' ? 'fa-IR' : 'en-US', {
        style: 'unit',
        unit: 'byte',
        unitDisplay: 'short',
        notation: value >= 1_000_000 ? 'compact' : 'standard',
    }).format(value);
}

export default function BackupRestore({ restore }: Props) {
    const { locale } = usePage<SharedProps>().props;
    const t = copy[locale];
    const fileInput = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [confirmationDigest, setConfirmationDigest] = useState('');
    const [busy, setBusy] = useState<'verify' | 'restore' | 'recover' | null>(null);
    const [error, setError] = useState<string | null>(null);

    const phaseLabel = useMemo(() => {
        switch (restore.phase) {
            case 'idle':
                return t.phaseIdle;
            case 'uploaded':
                return t.phaseUploaded;
            case 'verified':
                return t.phaseVerified;
            case 'confirmed':
                return t.phaseConfirmed;
            case 'database_restoring':
                return t.phaseDatabaseRestoring;
            case 'database_committed':
                return t.phaseDatabaseCommitted;
            case 'files_restoring':
                return t.phaseFilesRestoring;
            case 'key_rotating':
                return t.phaseKeyRotating;
            case 'finalizing':
                return t.phaseFinalizing;
            case 'complete':
                return t.phaseComplete;
            case 'recovery_required':
                return t.phaseRecovery;
            default:
                return t.phaseUnknown;
        }
    }, [restore.phase, t]);

    const refreshRestoreState = () => {
        router.reload({
            only: ['restore'],
            preserveScroll: true,
            preserveState: true,
        });
    };

    const request = async (url: string, init: RequestInit) => {
        const token = xsrfToken();
        const headers = new Headers(init.headers);
        headers.set('Accept', 'application/json');
        headers.set('X-Requested-With', 'XMLHttpRequest');
        if (token) {
            headers.set('X-XSRF-TOKEN', token);
        }

        const response = await fetch(url, {
            ...init,
            headers,
            credentials: 'same-origin',
        });

        if (response.redirected) {
            window.location.assign(response.url);
            return null;
        }

        if (response.status === 401) {
            window.location.assign('/login');
            return null;
        }

        if (response.status === 423) {
            window.location.assign('/confirm-password');
            return null;
        }

        if (!response.ok) {
            const failure = await readFailure(response);
            throw new Error(failure.message || t.genericError);
        }

        return response;
    };

    const verify = async () => {
        if (!file) {
            setError(t.fileRequired);
            fileInput.current?.focus();
            return;
        }

        setBusy('verify');
        setError(null);

        try {
            const data = new FormData();
            data.append('backup', file);
            const response = await request('/settings/backup/restore/verify', {
                method: 'POST',
                body: data,
            });
            if (response) {
                setConfirmationDigest('');
                refreshRestoreState();
            }
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : t.genericError);
            refreshRestoreState();
        } finally {
            setBusy(null);
        }
    };

    const confirmRestore = async () => {
        const digest = restore.preview?.digest;
        if (!digest || confirmationDigest !== digest) {
            setError(t.digestMismatch);
            return;
        }

        setBusy('restore');
        setError(null);

        try {
            const response = await request('/settings/backup/restore/confirm', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    content_digest_sha256: confirmationDigest,
                }),
            });
            if (response) {
                window.location.assign('/login');
            }
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : t.genericError);
            refreshRestoreState();
        } finally {
            setBusy(null);
        }
    };

    const recover = async () => {
        setBusy('recover');
        setError(null);

        try {
            const response = await request('/settings/backup/restore/recover', {
                method: 'POST',
            });
            if (response) {
                window.location.assign('/login');
            }
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : t.genericError);
            refreshRestoreState();
        } finally {
            setBusy(null);
        }
    };

    return (
        <>
            <Head title={t.title} />

            <div className="space-y-6" dir={locale === 'fa' ? 'rtl' : 'ltr'}>
                <Heading variant="small" title={t.title} description={t.description} />

                <Alert variant="destructive">
                    <ShieldAlert />
                    <AlertTitle>{t.warningTitle}</AlertTitle>
                    <AlertDescription>{t.warningDescription}</AlertDescription>
                </Alert>

                <div className="rounded-lg border px-4 py-3 text-sm">
                    <div className="font-medium">{t.status}</div>
                    <div className="text-muted-foreground mt-1" data-test="restore-phase">
                        {phaseLabel}
                    </div>
                </div>

                {error && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                {restore.recoveryRequired ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>{t.recoveryTitle}</CardTitle>
                            <CardDescription>{t.recoveryDescription}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button
                                type="button"
                                onClick={recover}
                                disabled={busy !== null}
                                data-test="recover-restore"
                            >
                                <RotateCcw />
                                {busy === 'recover' ? t.recovering : t.recover}
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {restore.phase === 'idle' && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>{t.downloadTitle}</CardTitle>
                                    <CardDescription>{t.downloadDescription}</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Button asChild>
                                        <a href="/settings/backup/download" data-test="download-backup">
                                            <Download />
                                            {t.download}
                                        </a>
                                    </Button>
                                </CardContent>
                            </Card>
                        )}

                        {restore.phase === 'idle' && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>{t.restoreTitle}</CardTitle>
                                    <CardDescription>{t.restoreDescription}</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="restore-backup">{t.chooseFile}</Label>
                                        <Input
                                            ref={fileInput}
                                            id="restore-backup"
                                            type="file"
                                            accept=".seobak,application/octet-stream"
                                            onChange={(event) => {
                                                setFile(event.target.files?.[0] ?? null);
                                                setError(null);
                                            }}
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        onClick={verify}
                                        disabled={busy !== null || !file}
                                        data-test="verify-backup"
                                    >
                                        <Upload />
                                        {busy === 'verify' ? t.verifying : t.verify}
                                    </Button>
                                </CardContent>
                            </Card>
                        )}

                        {restore.phase === 'verified' && restore.preview && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>{t.previewTitle}</CardTitle>
                                    <CardDescription>{t.previewDescription}</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-5">
                                    <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                        <div>
                                            <dt className="text-muted-foreground">{t.sourceVersion}</dt>
                                            <dd className="font-medium">{restore.preview.source_product_version}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">{t.createdAt}</dt>
                                            <dd className="font-medium">{restore.preview.source_created_at_utc}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">{t.records}</dt>
                                            <dd className="font-medium">{restore.preview.table_record_count}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">{t.files}</dt>
                                            <dd className="font-medium">{restore.preview.durable_file_count}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">{t.size}</dt>
                                            <dd className="font-medium">{formatBytes(restore.preview.bundle_bytes, locale)}</dd>
                                        </div>
                                    </dl>

                                    <div className="space-y-2">
                                        <div className="text-muted-foreground text-sm">{t.digest}</div>
                                        <code className="bg-muted block overflow-x-auto rounded-md p-3 text-xs" dir="ltr">
                                            {restore.preview.digest}
                                        </code>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="restore-digest">{t.confirmLabel}</Label>
                                        <Input
                                            id="restore-digest"
                                            dir="ltr"
                                            autoComplete="off"
                                            spellCheck={false}
                                            value={confirmationDigest}
                                            onChange={(event) => {
                                                setConfirmationDigest(event.target.value.trim());
                                                setError(null);
                                            }}
                                            placeholder={t.confirmPlaceholder}
                                        />
                                    </div>

                                    <Button
                                        type="button"
                                        variant="destructive"
                                        onClick={confirmRestore}
                                        disabled={
                                            busy !== null ||
                                            confirmationDigest !== restore.preview.digest
                                        }
                                        data-test="confirm-restore"
                                    >
                                        <AlertTriangle />
                                        {busy === 'restore' ? t.restoring : t.restore}
                                    </Button>
                                </CardContent>
                            </Card>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

BackupRestore.layout = {
    breadcrumbs: [
        {
            title: 'پشتیبان‌گیری و بازیابی',
            href: '/settings/backup',
        },
    ],
};
