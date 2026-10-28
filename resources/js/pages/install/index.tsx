import { Form, Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    CircleX,
    Database,
    HardDrive,
    KeyRound,
    LoaderCircle,
    RefreshCw,
    Save,
    ServerCog,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

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

type InstallerValues = {
    app_url?: string;
    database_driver?: string;
    database_host?: string;
    database_port?: number;
    database_name?: string;
    database_username?: string;
    has_database_password?: boolean;
};

type InstallerState = {
    phase: InstallerPhase;
    installed: boolean;
    values: InstallerValues;
    actions: string[];
};

type MigrationState = {
    pending_count: number | null;
    pending: string[];
    complete: boolean;
    available: boolean;
};

type OperationalState = 'verified' | 'warning' | 'fail';

type OperationsState = {
    scheduler: { state: OperationalState; observed_at: string | null };
    queue: { state: OperationalState; completed_at: string | null };
    instructions: { scheduler: string; worker: string };
    blocking: boolean;
};

const labels: Record<string, string> = {
    php: 'نسخه PHP',
    extensions: 'افزونه‌های پایه PHP',
    storage: 'دسترسی نوشتن',
    disk: 'فضای دیسک',
    app_key: 'کلید امن برنامه',
    assets: 'فایل‌های رابط کاربری',
    runtime_files: 'فایل‌های اصلی برنامه',
    upload_limit: 'سقف بارگذاری فایل PHP',
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

function PreflightCard({
    preflight,
    canRefresh,
}: {
    preflight: HealthReport;
    canRefresh: boolean;
}) {
    const [isRefreshing, setIsRefreshing] = useState(false);

    const refreshPreflight = () => {
        if (!canRefresh || isRefreshing) {
            return;
        }

        router.reload({
            only: ['installer', 'preflight'],
            onStart: () => setIsRefreshing(true),
            onFinish: () => setIsRefreshing(false),
        });
    };

    return (
        <section className="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="font-semibold">مرحله ۱ — بررسی میزبان</h2>
                    <p className="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">
                        فقط پیش‌نیازهای مستقل از دیتابیس اینجا بررسی می‌شوند.
                        اتصال دیتابیس، migration، صف و cron در مراحل خودشان
                        تأیید می‌شوند.
                    </p>
                </div>
                <span
                    className={`rounded-full border px-3 py-1 text-xs font-semibold ${
                        preflight.ready
                            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                            : 'border-destructive/30 bg-destructive/10 text-destructive'
                    }`}
                >
                    {preflight.ready ? 'میزبان آماده است' : 'نیازمند اصلاح'}
                </span>
            </div>

            <div className="mt-5 grid gap-3 md:grid-cols-2">
                {Object.entries(preflight.checks).map(([key, check]) => (
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
                ))}
            </div>

            <div className="mt-5 flex flex-col gap-3 rounded-xl border bg-muted/20 p-4 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-sm leading-6 text-muted-foreground">
                    {preflight.ready
                        ? 'پیش‌نیازهای میزبان تأیید شده‌اند و می‌توانید تنظیمات نصب را وارد کنید.'
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
    );
}

function EnvironmentCard({
    values,
    canSave,
}: {
    values: InstallerValues;
    canSave: boolean;
}) {
    const hasPassword = values.has_database_password === true;

    return (
        <section className="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
            <div className="flex items-start gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border bg-muted/30">
                    <KeyRound className="size-5" />
                </div>
                <div>
                    <h2 className="font-semibold">
                        مرحله ۲ — تنظیمات برنامه و دیتابیس
                    </h2>
                    <p className="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">
                        اطلاعات فقط روی همین سرور ذخیره می‌شوند. رمز دیتابیس هرگز
                        به journal نصب یا دوباره به مرورگر برگردانده نمی‌شود.
                    </p>
                </div>
            </div>

            <Form
                action="/install/environment"
                method="post"
                resetOnSuccess={['database_password']}
                disableWhileProcessing
                className="mt-6 grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <InputError message={errors.preflight} />
                        <InputError message={errors.environment} />

                        <div className="grid gap-2">
                            <Label htmlFor="app_url">آدرس اصلی برنامه</Label>
                            <Input
                                id="app_url"
                                name="app_url"
                                type="url"
                                dir="ltr"
                                required
                                defaultValue={values.app_url ?? ''}
                                placeholder="https://seo.example.com"
                                autoComplete="url"
                            />
                            <InputError message={errors.app_url} />
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="database_driver">
                                    نوع دیتابیس
                                </Label>
                                <select
                                    id="database_driver"
                                    name="database_driver"
                                    defaultValue={
                                        values.database_driver ?? 'mysql'
                                    }
                                    className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs ring-offset-background outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                >
                                    <option value="mysql">MySQL</option>
                                    <option value="mariadb">MariaDB</option>
                                </select>
                                <InputError message={errors.database_driver} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="database_host">
                                    میزبان دیتابیس
                                </Label>
                                <Input
                                    id="database_host"
                                    name="database_host"
                                    dir="ltr"
                                    required
                                    defaultValue={
                                        values.database_host ?? '127.0.0.1'
                                    }
                                    autoComplete="off"
                                />
                                <InputError message={errors.database_host} />
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="database_port">پورت</Label>
                                <Input
                                    id="database_port"
                                    name="database_port"
                                    type="number"
                                    inputMode="numeric"
                                    dir="ltr"
                                    required
                                    min={1}
                                    max={65535}
                                    defaultValue={values.database_port ?? 3306}
                                />
                                <InputError message={errors.database_port} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="database_name">
                                    نام دیتابیس
                                </Label>
                                <Input
                                    id="database_name"
                                    name="database_name"
                                    dir="ltr"
                                    required
                                    defaultValue={values.database_name ?? ''}
                                    autoComplete="off"
                                />
                                <InputError message={errors.database_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="database_username">
                                    نام کاربری دیتابیس
                                </Label>
                                <Input
                                    id="database_username"
                                    name="database_username"
                                    dir="ltr"
                                    required
                                    defaultValue={
                                        values.database_username ?? ''
                                    }
                                    autoComplete="username"
                                />
                                <InputError
                                    message={errors.database_username}
                                />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <Label htmlFor="database_password">
                                    رمز دیتابیس
                                </Label>
                                {hasPassword ? (
                                    <span className="text-xs text-emerald-700 dark:text-emerald-400">
                                        یک رمز ذخیره شده است
                                    </span>
                                ) : null}
                            </div>
                            <PasswordInput
                                id="database_password"
                                name="database_password"
                                dir="ltr"
                                required={!hasPassword}
                                autoComplete="new-password"
                                placeholder={
                                    hasPassword
                                        ? 'برای تغییر رمز، گزینه نگه‌داشتن را بردارید'
                                        : 'رمز دیتابیس'
                                }
                            />
                            <InputError message={errors.database_password} />
                        </div>

                        <input
                            type="hidden"
                            name="keep_database_password"
                            value="0"
                        />
                        {hasPassword ? (
                            <label className="flex items-start gap-3 rounded-xl border bg-muted/20 p-4 text-sm">
                                <input
                                    type="checkbox"
                                    name="keep_database_password"
                                    value="1"
                                    defaultChecked
                                    className="mt-1 size-4 rounded border-input"
                                />
                                <span>
                                    <span className="font-medium">
                                        رمز دیتابیس فعلی را نگه دار
                                    </span>
                                    <span className="mt-1 block leading-6 text-muted-foreground">
                                        رمز فعلی نمایش داده نمی‌شود. برای
                                        جایگزینی آن، این گزینه را بردارید و رمز
                                        جدید وارد کنید.
                                    </span>
                                </span>
                            </label>
                        ) : null}

                        <Button
                            type="submit"
                            disabled={!canSave || processing}
                            className="w-full sm:w-fit"
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <Save className="size-4" />
                            )}
                            ذخیره امن تنظیمات
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}

function DatabaseCard({ canVerify }: { canVerify: boolean }) {
    return (
        <section className="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
            <div className="flex items-start gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border bg-muted/30">
                    <Database className="size-5" />
                </div>
                <div>
                    <h2 className="font-semibold">
                        مرحله ۳ — بررسی اتصال دیتابیس
                    </h2>
                    <p className="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">
                        اتصال، احراز هویت و امکان خواندن/نوشتن محلی بررسی می‌شود.
                        پیام خطا هیچ رمز یا DSN حساسی را نمایش نمی‌دهد.
                    </p>
                </div>
            </div>

            <Form
                action="/install/database/verify"
                method="post"
                disableWhileProcessing
                className="mt-5"
            >
                {({ processing, errors }) => (
                    <div className="space-y-4">
                        <InputError message={errors.database} />
                        <Button
                            type="submit"
                            disabled={!canVerify || processing}
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <Database className="size-4" />
                            )}
                            تست اتصال دیتابیس
                        </Button>
                    </div>
                )}
            </Form>
        </section>
    );
}

function MigrationCard({
    migrations,
    canRun,
}: {
    migrations: MigrationState;
    canRun: boolean;
}) {
    const hasPending =
        migrations.pending_count === null || migrations.pending_count > 0;

    return (
        <section className="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
            <div className="flex items-start gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border bg-muted/30">
                    <Database className="size-5" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="font-semibold">
                                مرحله ۴ — ساختار دیتابیس
                            </h2>
                            <p className="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">
                                migrationها داخل خود Laravel اجرا می‌شوند؛ هیچ
                                shell یا SSH لازم نیست. هر migration موفق در
                                repository ثبت می‌شود، بنابراین قطع شدن درخواست
                                باعث اجرای دوباره‌ی بخش‌های تکمیل‌شده نمی‌شود.
                            </p>
                        </div>
                        <span
                            className={`rounded-full border px-3 py-1 text-xs font-semibold ${
                                migrations.complete
                                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                    : 'bg-muted/30 text-muted-foreground'
                            }`}
                        >
                            {migrations.pending_count === null
                                ? 'وضعیت نامشخص'
                                : migrations.complete
                                  ? 'ساختار آماده است'
                                  : `${migrations.pending_count.toLocaleString('fa-IR')} مورد باقی مانده`}
                        </span>
                    </div>

                    {!migrations.available ? (
                        <div className="mt-4 flex gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm leading-6 text-amber-800 dark:text-amber-300">
                            <AlertTriangle className="mt-0.5 size-5 shrink-0" />
                            وضعیت migrationها خوانده نشد. اجرای دوباره امن است و
                            فقط موارد ثبت‌نشده را اجرا می‌کند.
                        </div>
                    ) : null}

                    {migrations.pending.length > 0 ? (
                        <div className="mt-4 rounded-xl border bg-muted/20 p-4">
                            <p className="text-xs font-semibold text-muted-foreground">
                                موارد باقی‌مانده
                            </p>
                            <ul className="mt-3 grid gap-2" dir="ltr">
                                {migrations.pending.map((migration) => (
                                    <li
                                        key={migration}
                                        className="overflow-hidden rounded-md border bg-background px-3 py-2 font-mono text-xs text-ellipsis"
                                        title={migration}
                                    >
                                        {migration}
                                    </li>
                                ))}
                            </ul>
                            {migrations.pending_count !== null &&
                            migrations.pending_count >
                                migrations.pending.length ? (
                                <p
                                    className="mt-3 text-xs leading-5 text-muted-foreground"
                                    dir="rtl"
                                >
                                    برای سبک ماندن صفحه فقط ۲۰ مورد اول نمایش
                                    داده می‌شود؛ تعداد کل بالا ثبت شده است.
                                </p>
                            ) : null}
                        </div>
                    ) : null}

                    <Form
                        action="/install/migrations/run"
                        method="post"
                        disableWhileProcessing
                        className="mt-5"
                    >
                        {({ processing, errors }) => (
                            <div className="space-y-4">
                                <InputError message={errors.migrations} />
                                <Button
                                    type="submit"
                                    disabled={!canRun || processing}
                                >
                                    {processing ? (
                                        <Spinner />
                                    ) : migrations.complete ? (
                                        <CheckCircle2 className="size-4" />
                                    ) : (
                                        <Database className="size-4" />
                                    )}
                                    {migrations.complete
                                        ? 'تأیید تکمیل و ادامه'
                                        : hasPending
                                          ? 'اجرای migrations / تلاش مجدد'
                                          : 'ادامه'}
                                </Button>
                            </div>
                        )}
                    </Form>
                </div>
            </div>
        </section>
    );
}

function OwnerCard({ canCreate }: { canCreate: boolean }) {
    return (
        <section className="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
            <div className="flex items-start gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border bg-muted/30">
                    <KeyRound className="size-5" />
                </div>
                <div>
                    <h2 className="font-semibold">مرحله ۵ — مالک اولیه</h2>
                    <p className="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">
                        اولین مالک و فضای کاری اصلی فقط یک‌بار ساخته می‌شوند. این
                        حساب برای نصب محلی تأییدشده محسوب می‌شود تا راه‌اندازی به
                        ایمیل یا اینترنت خارجی وابسته نباشد.
                    </p>
                </div>
            </div>

            <Form
                action="/install/owner"
                method="post"
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="mt-6 grid gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <InputError message={errors.owner} />

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="owner_name">نام مالک</Label>
                                <Input
                                    id="owner_name"
                                    name="name"
                                    required
                                    maxLength={255}
                                    autoComplete="name"
                                    placeholder="نام و نام خانوادگی"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="owner_email">ایمیل مالک</Label>
                                <Input
                                    id="owner_email"
                                    name="email"
                                    type="email"
                                    dir="ltr"
                                    required
                                    maxLength={255}
                                    autoComplete="email"
                                    placeholder="owner@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="workspace_name">
                                نام فضای کاری
                            </Label>
                            <Input
                                id="workspace_name"
                                name="workspace_name"
                                required
                                maxLength={255}
                                defaultValue="فضای کاری اصلی"
                                autoComplete="organization"
                            />
                            <InputError message={errors.workspace_name} />
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="owner_password">رمز عبور</Label>
                                <PasswordInput
                                    id="owner_password"
                                    name="password"
                                    dir="ltr"
                                    required
                                    autoComplete="new-password"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="owner_password_confirmation">
                                    تکرار رمز عبور
                                </Label>
                                <PasswordInput
                                    id="owner_password_confirmation"
                                    name="password_confirmation"
                                    dir="ltr"
                                    required
                                    autoComplete="new-password"
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>
                        </div>

                        <p className="rounded-xl border bg-muted/20 p-4 text-xs leading-6 text-muted-foreground">
                            رمز باید حداقل ۱۲ نویسه و شامل حروف کوچک و بزرگ، عدد
                            و نماد باشد. این بررسی کاملاً محلی است و برای نصب به
                            سرویس خارجی متصل نمی‌شود.
                        </p>

                        <Button
                            type="submit"
                            disabled={!canCreate || processing}
                            className="w-full sm:w-fit"
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <KeyRound className="size-4" />
                            )}
                            ساخت مالک و فضای کاری
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}

function OperationsCard({
    operations,
    canDispatchProbe,
    canContinue,
}: {
    operations: OperationsState;
    canDispatchProbe: boolean;
    canContinue: boolean;
}) {
    const [acknowledged, setAcknowledged] = useState(false);
    const [copied, setCopied] = useState<'scheduler' | 'worker' | null>(null);
    const [isRefreshing, setIsRefreshing] = useState(false);
    // Scheduler/worker evidence arrives from separate host processes; let the
    // operator re-read server truth without resubmitting the probe.
    const refreshOperations = () => {
        if (isRefreshing) {
            return;
        }

        router.reload({
            only: ['installer', 'operations'],
            onStart: () => setIsRefreshing(true),
            onFinish: () => setIsRefreshing(false),
        });
    };
    const hasWarnings =
        operations.scheduler.state === 'warning' ||
        operations.queue.state === 'warning';

    const statusFor = (state: OperationalState): HealthStatus =>
        state === 'verified' ? 'pass' : state;
    const labelFor = (state: OperationalState) =>
        state === 'verified'
            ? 'تأیید شده'
            : state === 'warning'
              ? 'در انتظار مشاهده'
              : 'نیازمند اصلاح';

    const copyCommand = async (
        key: 'scheduler' | 'worker',
        command: string,
    ) => {
        try {
            await navigator.clipboard.writeText(command);
            setCopied(key);
            window.setTimeout(() => setCopied(null), 1500);
        } catch {
            setCopied(null);
        }
    };

    return (
        <section className="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border bg-muted/30">
                        <ServerCog className="size-5" />
                    </div>
                    <div>
                        <h2 className="font-semibold">
                            مرحله ۶ — اجرای پس‌زمینه
                        </h2>
                        <p className="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">
                            cron و صف فقط وقتی تأیید شده‌اند که شواهد واقعی اجرای
                            محلی دیده شده باشد. این بررسی هیچ اتصال خارجی ندارد.
                        </p>
                    </div>
                </div>
                <span
                    className={`rounded-full border px-3 py-1 text-xs font-semibold ${
                        operations.blocking
                            ? 'border-destructive/30 bg-destructive/10 text-destructive'
                            : hasWarnings
                              ? 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400'
                              : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                    }`}
                >
                    {operations.blocking
                        ? 'خطای مسدودکننده'
                        : hasWarnings
                          ? 'نیازمند تأیید اپراتور'
                          : 'عملیات تأیید شد'}
                </span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={isRefreshing}
                    onClick={refreshOperations}
                >
                    {isRefreshing ? (
                        <Spinner />
                    ) : (
                        <RefreshCw className="size-4" />
                    )}
                    بررسی دوباره وضعیت اجرا
                </Button>
            </div>

            <div className="mt-6 grid gap-3 md:grid-cols-2">
                <article className="flex gap-3 rounded-xl border bg-background p-4">
                    <StatusIcon
                        status={statusFor(operations.scheduler.state)}
                    />
                    <div>
                        <h3 className="text-sm font-semibold">
                            Laravel Scheduler
                        </h3>
                        <p className="mt-1 text-xs leading-6 text-muted-foreground">
                            {labelFor(operations.scheduler.state)}
                            {operations.scheduler.observed_at
                                ? ` — آخرین heartbeat: ${operations.scheduler.observed_at}`
                                : ' — هنوز heartbeat تازه‌ای دیده نشده است.'}
                        </p>
                    </div>
                </article>
                <article className="flex gap-3 rounded-xl border bg-background p-4">
                    <StatusIcon status={statusFor(operations.queue.state)} />
                    <div>
                        <h3 className="text-sm font-semibold">
                            Database Queue
                        </h3>
                        <p className="mt-1 text-xs leading-6 text-muted-foreground">
                            {labelFor(operations.queue.state)}
                            {operations.queue.completed_at
                                ? ` — آخرین probe: ${operations.queue.completed_at}`
                                : ' — هنوز probe تکمیل‌شده‌ای دیده نشده است.'}
                        </p>
                    </div>
                </article>
            </div>

            <div className="mt-6 space-y-4">
                {(
                    [
                        [
                            'scheduler',
                            'Cron زمان‌بند — هر دقیقه',
                            operations.instructions.scheduler,
                        ],
                        [
                            'worker',
                            'Worker دوره‌ای صف — هر دقیقه',
                            operations.instructions.worker,
                        ],
                    ] as const
                ).map(([key, title, command]) => (
                    <div
                        key={key}
                        className="rounded-xl border bg-muted/20 p-4"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-semibold">
                                    {title}
                                </h3>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    این دستور را در بخش Cron Jobs کنترل‌پنل هاست
                                    ثبت کنید.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => copyCommand(key, command)}
                                className="rounded-lg border bg-background px-3 py-2 text-xs font-semibold transition hover:bg-muted"
                            >
                                {copied === key ? 'کپی شد' : 'کپی دستور'}
                            </button>
                        </div>
                        <code
                            dir="ltr"
                            className="mt-3 block overflow-x-auto rounded-lg border bg-background p-3 text-left text-xs leading-6"
                        >
                            {command}
                        </code>
                    </div>
                ))}
            </div>

            <div className="mt-6 grid gap-4 lg:grid-cols-2">
                <Form
                    action="/install/operations/probe"
                    method="post"
                    disableWhileProcessing
                    className="rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <div className="space-y-3">
                            <div>
                                <h3 className="text-sm font-semibold">
                                    تست واقعی صف
                                </h3>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    یک job بدون داده کاربر در صف دیتابیس قرار
                                    می‌گیرد و فقط worker واقعی آن را تکمیل می‌کند.
                                </p>
                            </div>
                            <InputError message={errors.operations} />
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={!canDispatchProbe || processing}
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <RefreshCw className="size-4" />
                                )}
                                ارسال Queue Probe
                            </Button>
                        </div>
                    )}
                </Form>

                <Form
                    action="/install/operations/continue"
                    method="post"
                    disableWhileProcessing
                    className="rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <div className="space-y-3">
                            <input
                                type="hidden"
                                name="acknowledge_warnings"
                                value={acknowledged ? '1' : '0'}
                            />
                            <div>
                                <h3 className="text-sm font-semibold">
                                    تأیید وضعیت عملیات
                                </h3>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    خطای واقعی قابل چشم‌پوشی نیست؛ فقط نبود شواهد
                                    اجرای خارجی پس از تنظیم دستورها قابل تأیید
                                    است.
                                </p>
                            </div>
                            <InputError message={errors.operations} />

                            {hasWarnings && !operations.blocking ? (
                                <label className="flex items-start gap-3 rounded-lg bg-amber-500/10 p-3 text-xs leading-6">
                                    <input
                                        type="checkbox"
                                        checked={acknowledged}
                                        onChange={(event) =>
                                            setAcknowledged(
                                                event.target.checked,
                                            )
                                        }
                                        className="mt-1 size-4 rounded border-input"
                                    />
                                    <span>
                                        دستورهای cron و worker را روی هاست تنظیم
                                        کرده‌ام و می‌پذیرم که هنوز اجرای آن‌ها در
                                        همین نشست مشاهده نشده است.
                                    </span>
                                </label>
                            ) : null}

                            {operations.blocking ? (
                                <p className="rounded-lg bg-destructive/10 p-3 text-xs leading-6 text-destructive">
                                    ابتدا خطای مسدودکننده‌ی scheduler یا queue را
                                    اصلاح کنید؛ این وضعیت با تأیید دستی قابل عبور
                                    نیست.
                                </p>
                            ) : null}

                            <Button
                                type="submit"
                                disabled={
                                    !canContinue ||
                                    processing ||
                                    operations.blocking ||
                                    (hasWarnings && !acknowledged)
                                }
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <CheckCircle2 className="size-4" />
                                )}
                                ادامه به نهایی‌سازی
                            </Button>
                        </div>
                    )}
                </Form>
            </div>
        </section>
    );
}

function FinalizeCard({ canFinalize }: { canFinalize: boolean }) {
    return (
        <section className="rounded-2xl border bg-card p-5 shadow-sm sm:p-6">
            <div className="flex items-start gap-3">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-xl border bg-muted/30">
                    <ShieldCheck className="size-5" />
                </div>
                <div>
                    <h2 className="font-semibold">مرحله ۷ — نهایی‌سازی امن</h2>
                    <p className="mt-1 max-w-2xl text-sm leading-6 text-muted-foreground">
                        پیش از پایان، پیش‌نیازهای میزبان، APP_KEY پایدار، اتصال
                        دیتابیس، migrationها، مالک و شواهد اجرای Queue/Scheduler
                        دوباره بررسی می‌شوند. قفل نصب فقط بعد از عبور همه‌ی این
                        بررسی‌ها نوشته می‌شود.
                    </p>
                </div>
            </div>

            <div className="mt-5 grid gap-3 md:grid-cols-3">
                <div className="rounded-xl border bg-muted/20 p-4">
                    <p className="text-xs font-semibold">Revalidation کامل</p>
                    <p className="mt-1 text-xs leading-6 text-muted-foreground">
                        به journal به‌تنهایی اعتماد نمی‌شود و وضعیت فعلی دوباره
                        سنجیده می‌شود.
                    </p>
                </div>
                <div className="rounded-xl border bg-muted/20 p-4">
                    <p className="text-xs font-semibold">Lock در آخر</p>
                    <p className="mt-1 text-xs leading-6 text-muted-foreground">
                        تا قبل از موفقیت همه‌ی بررسی‌های اجباری فایل نصب‌شده ایجاد
                        نمی‌شود و retry امن باقی می‌ماند.
                    </p>
                </div>
                <div className="rounded-xl border bg-muted/20 p-4">
                    <p className="text-xs font-semibold">ورود خودکار مالک</p>
                    <p className="mt-1 text-xs leading-6 text-muted-foreground">
                        پس از ثبت موفق lock، مالک با session پایدار وارد می‌شود و
                        مستقیم به داشبورد منتقل خواهد شد.
                    </p>
                </div>
            </div>

            <Form
                action="/install/finalize"
                method="post"
                disableWhileProcessing
                className="mt-5"
            >
                {({ processing, errors }) => (
                    <div className="space-y-4">
                        <InputError message={errors.finalize} />
                        <div className="flex flex-col gap-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm leading-6 text-emerald-800 dark:text-emerald-300">
                                با موفقیت این مرحله، Installer بسته می‌شود. اگر
                                هر revalidation شکست بخورد، lock نوشته نمی‌شود و
                                همین مرحله برای تلاش مجدد باز می‌ماند.
                            </p>
                            <Button
                                type="submit"
                                disabled={!canFinalize || processing}
                                className="shrink-0"
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <CheckCircle2 className="size-4" />
                                )}
                                نهایی‌سازی و ورود
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </section>
    );
}

export default function Installer({
    installer,
    preflight,
    migrations,
    operations,
}: {
    installer: InstallerState;
    preflight: HealthReport;
    migrations: MigrationState | null;
    operations: OperationsState | null;
}) {
    const canRefresh = installer.actions.includes('refresh_preflight');
    const canSaveEnvironment = installer.actions.includes('save_environment');
    const canVerifyDatabase = installer.actions.includes('verify_database');
    const canRunMigrations = installer.actions.includes('run_migrations');
    const canCreateOwner = installer.actions.includes('create_owner');
    const canDispatchQueueProbe = installer.actions.includes(
        'dispatch_queue_probe',
    );
    const canContinueOperations = installer.actions.includes(
        'continue_operations',
    );
    const canFinalize = installer.actions.includes('finalize_installation');
    const activePhase = phases.findIndex(
        (phase) => phase.key === installer.phase,
    );
    const showEnvironment = ['environment', 'database'].includes(
        installer.phase,
    );

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

                    <PreflightCard
                        preflight={preflight}
                        canRefresh={canRefresh}
                    />

                    {showEnvironment ? (
                        <EnvironmentCard
                            values={installer.values}
                            canSave={canSaveEnvironment}
                        />
                    ) : null}

                    {installer.phase === 'database' ? (
                        <DatabaseCard canVerify={canVerifyDatabase} />
                    ) : null}

                    {installer.phase === 'migrations' && migrations ? (
                        <MigrationCard
                            migrations={migrations}
                            canRun={canRunMigrations}
                        />
                    ) : null}

                    {installer.phase === 'owner' ? (
                        <OwnerCard canCreate={canCreateOwner} />
                    ) : null}

                    {installer.phase === 'operations' && operations ? (
                        <OperationsCard
                            operations={operations}
                            canDispatchProbe={canDispatchQueueProbe}
                            canContinue={canContinueOperations}
                        />
                    ) : null}

                    {installer.phase === 'finalize' ? (
                        <FinalizeCard canFinalize={canFinalize} />
                    ) : null}
                </div>
            </main>
        </>
    );
}
