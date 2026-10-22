import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    CircleX,
    HardDrive,
    LoaderCircle,
    RefreshCw,
    ServerCog,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';

type HealthStatus = 'pass' | 'warning' | 'fail';
type InstallerPhase =
    | 'preflight'
    | 'environment'
    | 'database'
    | 'migrations'
    | 'owner'
    | 'operations'
    | 'finalize'
    | 'installed';

type HealthCheck = {
    status: HealthStatus;
    required: boolean;
    value: unknown;
    message: string;
};

type HealthReport = {
    ready: boolean;
    checks: Record<string, HealthCheck>;
};

type InstallerState = {
    phase: InstallerPhase;
    installed: boolean;
    values: Record<string, unknown>;
    actions: string[];
};

const labels: Record<string, string> = {
    php: 'نسخه PHP',
    extensions: 'افزونه‌های پایه PHP',
    storage: 'دسترسی نوشتن',
    disk: 'فضای دیسک',
    app_key: 'کلید امن برنامه',
    assets: 'فایل‌های رابط کاربری',
    runtime_files: 'فایل‌های اصلی برنامه',
};

const phases: Array<{ key: InstallerPhase; label: string }> = [
    { key: 'preflight', label: 'سرور' },
    { key: 'environment', label: 'تنظیمات' },
    { key: 'database', label: 'دیتابیس' },
    { key: 'migrations', label: 'ساختار داده' },
    { key: 'owner', label: 'مالک' },
    { key: 'operations', label: 'اجرای پس‌زمینه' },
    { key: 'finalize', label: 'نهایی‌سازی' },
];

function StatusIcon({ status }: { status: HealthStatus }) {
    if (status === 'pass') {
        return <CheckCircle2 className="size-5 shrink-0 text-emerald-600" />;
    }

    if (status === 'warning') {
        return <AlertTriangle className="size-5 shrink-0 text-amber-600" />;
    }

    return <CircleX className="size-5 shrink-0 text-destructive" />;
}

export default function Installer({
    installer,
    preflight,
}: {
    installer: InstallerState;
    preflight: HealthReport;
}) {
    const [isRefreshing, setIsRefreshing] = useState(false);
    const canRefresh = installer.actions.includes('refresh_preflight');
    const activePhase = phases.findIndex(
        (phase) => phase.key === installer.phase,
    );

    const refreshPreflight = () => {
        if (!canRefresh || isRefreshing) {
            return;
        }

        router.reload({
            only: ['installer', 'preflight'],
            preserveScroll: true,
            onStart: () => setIsRefreshing(true),
            onFinish: () => setIsRefreshing(false),
        });
    };

    return (
        <>
            <Head title="نصب SEO OS" />
            <main className="min-h-screen bg-background px-4 py-8 text-foreground sm:px-6 lg:px-8">
                <div className="mx-auto w-full max-w-5xl space-y-6">
                    <header className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="grid gap-6 p-6 md:grid-cols-[1fr_auto] md:items-center md:p-8">
                            <div className="space-y-3">
                                <div className="inline-flex items-center gap-2 rounded-full border bg-muted/40 px-3 py-1 text-xs font-medium text-muted-foreground">
                                    <ServerCog className="size-4" />
                                    نصب Self-hosted
                                </div>
                                <div>
                                    <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                                        راه‌اندازی امن SEO OS
                                    </h1>
                                    <p className="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground sm:text-base">
                                        نصب از داخل مرورگر انجام می‌شود و روی
                                        هاست مقصد به Composer، Node.js، npm یا
                                        دسترسی SSH نیاز ندارد.
                                    </p>
                                </div>
                            </div>
                            <div className="flex size-20 items-center justify-center rounded-2xl border bg-muted/30">
                                {preflight.ready ? (
                                    <ShieldCheck className="size-10 text-emerald-600" />
                                ) : (
                                    <HardDrive className="size-10 text-amber-600" />
                                )}
                            </div>
                        </div>
                    </header>

                    <nav
                        aria-label="مراحل نصب"
                        className="overflow-x-auto rounded-2xl border bg-card p-3 shadow-sm"
                    >
                        <ol className="flex min-w-max items-center gap-2">
                            {phases.map((phase, index) => {
                                const isActive = phase.key === installer.phase;
                                const isPast = activePhase > index;

                                return (
                                    <li
                                        key={phase.key}
                                        className={`rounded-xl border px-3 py-2 text-xs font-medium ${
                                            isActive
                                                ? 'border-primary bg-primary/10 text-primary'
                                                : isPast
                                                  ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                                  : 'bg-muted/30 text-muted-foreground'
                                        }`}
                                    >
                                        <span className="me-1.5 tabular-nums">
                                            {index + 1}
                                        </span>
                                        {phase.label}
                                    </li>
                                );
                            })}
                        </ol>
                    </nav>

                    <section className="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="font-semibold">
                                    مرحله ۱ — بررسی میزبان
                                </h2>
                                <p className="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">
                                    فقط پیش‌نیازهایی بررسی می‌شوند که قبل از وارد
                                    کردن اطلاعات دیتابیس قابل تشخیص‌اند. دیتابیس،
                                    صف و cron در مراحل بعدی بررسی می‌شوند.
                                </p>
                            </div>
                            <span
                                className={`rounded-full border px-3 py-1 text-xs font-semibold ${
                                    preflight.ready
                                        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                        : 'border-destructive/30 bg-destructive/10 text-destructive'
                                }`}
                            >
                                {preflight.ready
                                    ? 'میزبان آماده است'
                                    : 'نیازمند اصلاح'}
                            </span>
                        </div>

                        <div className="mt-5 grid gap-3 md:grid-cols-2">
                            {Object.entries(preflight.checks).map(
                                ([key, check]) => (
                                    <article
                                        key={key}
                                        className="flex gap-3 rounded-xl border bg-background p-4"
                                    >
                                        <StatusIcon status={check.status} />
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className="text-sm font-semibold">
                                                    {labels[key] ?? key}
                                                </h3>
                                                {check.required ? (
                                                    <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                                                        ضروری
                                                    </span>
                                                ) : null}
                                            </div>
                                            <p className="mt-1 text-xs leading-6 text-muted-foreground">
                                                {check.message}
                                            </p>
                                        </div>
                                    </article>
                                ),
                            )}
                        </div>

                        <div className="mt-5 flex flex-col gap-3 rounded-xl border bg-muted/20 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm leading-6 text-muted-foreground">
                                {preflight.ready
                                    ? 'پیش‌نیازهای میزبان تأیید شده‌اند. تنظیمات محیط و دیتابیس در مرحله بعد ثبت می‌شوند.'
                                    : 'موارد قرمز را در کنترل‌پنل هاست اصلاح کنید و دوباره بررسی را اجرا کنید.'}
                            </p>
                            <button
                                type="button"
                                onClick={refreshPreflight}
                                disabled={!canRefresh || isRefreshing}
                                className="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-lg border bg-background px-4 text-sm font-semibold shadow-sm transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-45"
                            >
                                {isRefreshing ? (
                                    <LoaderCircle className="size-4 animate-spin" />
                                ) : (
                                    <RefreshCw className="size-4" />
                                )}
                                بررسی دوباره
                            </button>
                        </div>
                    </section>

                    <section className="rounded-2xl border border-dashed bg-card/50 p-5 sm:p-6">
                        <h2 className="font-semibold">
                            مراحل بعدی محافظت شده‌اند
                        </h2>
                        <p className="mt-1 text-sm leading-6 text-muted-foreground">
                            تا زمانی که هر قابلیت backend به‌صورت کامل و قابل
                            بازیابی آماده نشده باشد، دکمه‌ای برای عبور ساختگی از
                            مرحله یا ساخت فایل قفل نصب نمایش داده نمی‌شود.
                        </p>
                    </section>
                </div>
            </main>
        </>
    );
}
