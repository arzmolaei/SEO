import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    CircleX,
    HardDrive,
    LoaderCircle,
    ServerCog,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';

type HealthStatus = 'pass' | 'warning' | 'fail';

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

const labels: Record<string, string> = {
    php: 'نسخه PHP',
    extensions: 'افزونه‌های PHP',
    storage: 'دسترسی نوشتن',
    database: 'پایگاه داده',
    queue: 'صف پردازش',
    scheduler: 'زمان‌بندی',
    disk: 'فضای دیسک',
    app_key: 'کلید برنامه',
    assets: 'فایل‌های رابط کاربری',
};

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
    report,
}: {
    report: HealthReport;
    installed: boolean;
}) {
    const [isSubmitting, setIsSubmitting] = useState(false);

    const completeInstallation = () => {
        if (!report.ready || isSubmitting) {
            return;
        }

        router.post(
            '/install/complete',
            {},
            {
                preserveScroll: true,
                onStart: () => setIsSubmitting(true),
                onFinish: () => setIsSubmitting(false),
            },
        );
    };

    return (
        <>
            <Head title="نصب SEO OS" />
            <main className="min-h-screen bg-background px-4 py-8 text-foreground sm:px-6 lg:px-8">
                <div className="mx-auto w-full max-w-4xl space-y-6">
                    <header className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="grid gap-6 p-6 md:grid-cols-[1fr_auto] md:items-center md:p-8">
                            <div className="space-y-3">
                                <div className="inline-flex items-center gap-2 rounded-full border bg-muted/40 px-3 py-1 text-xs font-medium text-muted-foreground">
                                    <ServerCog className="size-4" />
                                    نصب Self-hosted
                                </div>
                                <div>
                                    <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">
                                        آماده‌سازی SEO OS
                                    </h1>
                                    <p className="mt-2 max-w-2xl text-sm leading-7 text-muted-foreground sm:text-base">
                                        این مرحله بدون نیاز به Node.js، Composer
                                        یا اجرای دستور shell روی هاست،
                                        پیش‌نیازهای اجرای برنامه را بررسی می‌کند.
                                    </p>
                                </div>
                            </div>
                            <div className="flex size-20 items-center justify-center rounded-2xl border bg-muted/30">
                                {report.ready ? (
                                    <ShieldCheck className="size-10 text-emerald-600" />
                                ) : (
                                    <HardDrive className="size-10 text-amber-600" />
                                )}
                            </div>
                        </div>
                    </header>

                    <section className="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="font-semibold">
                                    بررسی سلامت سرور
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    موارد ضروری باید سبز باشند. هشدارها مانع نصب
                                    نمی‌شوند، اما برای محیط production بهتر است
                                    برطرف شوند.
                                </p>
                            </div>
                            <span
                                className={`rounded-full border px-3 py-1 text-xs font-semibold ${
                                    report.ready
                                        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                        : 'border-destructive/30 bg-destructive/10 text-destructive'
                                }`}
                            >
                                {report.ready ? 'آماده نصب' : 'نیازمند اصلاح'}
                            </span>
                        </div>

                        <div className="mt-5 grid gap-3 md:grid-cols-2">
                            {Object.entries(report.checks).map(
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
                    </section>

                    <section className="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="font-semibold">قفل نصب</h2>
                                <p className="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">
                                    بعد از تکمیل، installer قفل می‌شود تا دوباره
                                    از وب قابل اجرا نباشد. برای نصب مجدد باید
                                    مدیر سرور عمداً فایل lock را حذف کند.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={completeInstallation}
                                disabled={!report.ready || isSubmitting}
                                className="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground shadow-sm transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-45"
                            >
                                {isSubmitting ? (
                                    <LoaderCircle className="size-4 animate-spin" />
                                ) : (
                                    <ShieldCheck className="size-4" />
                                )}
                                تکمیل نصب
                            </button>
                        </div>
                    </section>
                </div>
            </main>
        </>
    );
}
