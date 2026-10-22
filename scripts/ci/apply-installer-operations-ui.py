from pathlib import Path

path = Path('resources/js/pages/install/index.tsx')
source = path.read_text()

type_anchor = """type MigrationState = {
    pending_count: number | null;
    pending: string[];
    complete: boolean;
    available: boolean;
};
"""
type_replacement = type_anchor + """
type OperationalState = 'verified' | 'warning' | 'fail';

type OperationsState = {
    scheduler: { state: OperationalState; observed_at: string | null };
    queue: { state: OperationalState; completed_at: string | null };
    instructions: { scheduler: string; worker: string };
    blocking: boolean;
};
"""
if type_anchor not in source:
    raise SystemExit('operations type anchor missing')
source = source.replace(type_anchor, type_replacement, 1)

card = r'''
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
                        <h3 className="text-sm font-semibold">Database Queue</h3>
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
                        ['scheduler', 'Cron زمان‌بند — هر دقیقه', operations.instructions.scheduler],
                        ['worker', 'Worker دوره‌ای صف — هر دقیقه', operations.instructions.worker],
                    ] as const
                ).map(([key, title, command]) => (
                    <div key={key} className="rounded-xl border bg-muted/20 p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-semibold">{title}</h3>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    این دستور را در بخش Cron Jobs کنترل‌پنل هاست ثبت کنید.
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
                                <h3 className="text-sm font-semibold">تست واقعی صف</h3>
                                <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                    یک job بدون داده کاربر در صف دیتابیس قرار می‌گیرد و فقط worker واقعی آن را تکمیل می‌کند.
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
                                    خطای واقعی قابل چشم‌پوشی نیست؛ فقط نبود شواهد اجرای خارجی پس از تنظیم دستورها قابل تأیید است.
                                </p>
                            </div>
                            <InputError message={errors.operations} />

                            {hasWarnings && !operations.blocking ? (
                                <label className="flex items-start gap-3 rounded-lg bg-amber-500/10 p-3 text-xs leading-6">
                                    <input
                                        type="checkbox"
                                        checked={acknowledged}
                                        onChange={(event) =>
                                            setAcknowledged(event.target.checked)
                                        }
                                        className="mt-1 size-4 rounded border-input"
                                    />
                                    <span>
                                        دستورهای cron و worker را روی هاست تنظیم کرده‌ام و می‌پذیرم که هنوز اجرای آن‌ها در همین نشست مشاهده نشده است.
                                    </span>
                                </label>
                            ) : null}

                            {operations.blocking ? (
                                <p className="rounded-lg bg-destructive/10 p-3 text-xs leading-6 text-destructive">
                                    ابتدا خطای مسدودکننده‌ی scheduler یا queue را اصلاح کنید؛ این وضعیت با تأیید دستی قابل عبور نیست.
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
'''

export_anchor = '\nexport default function Installer({'
if export_anchor not in source:
    raise SystemExit('operations card export anchor missing')
source = source.replace(export_anchor, '\n' + card + export_anchor, 1)

props_anchor = """export default function Installer({
    installer,
    preflight,
    migrations,
}: {
    installer: InstallerState;
    preflight: HealthReport;
    migrations: MigrationState | null;
}) {
"""
props_replacement = """export default function Installer({
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
"""
if props_anchor not in source:
    raise SystemExit('operations props anchor missing')
source = source.replace(props_anchor, props_replacement, 1)

action_anchor = "    const canCreateOwner = installer.actions.includes('create_owner');\n"
if action_anchor not in source:
    raise SystemExit('operations action anchor missing')
source = source.replace(
    action_anchor,
    action_anchor
    + "    const canDispatchQueueProbe = installer.actions.includes('dispatch_queue_probe');\n"
    + "    const canContinueOperations = installer.actions.includes('continue_operations');\n",
    1,
)

placeholder = """                    {['operations', 'finalize'].includes(installer.phase) ? (
                        <section className=\"rounded-2xl border border-dashed bg-card/50 p-5 sm:p-6\">
                            <h2 className=\"font-semibold\">
                                مرحله فعلی با موفقیت باز شده است
                            </h2>
                            <p className=\"mt-1 text-sm leading-6 text-muted-foreground\">
                                این مرحله فقط بعد از تکمیل قرارداد backend خودش
                                فعال می‌شود؛ تا آن زمان هیچ دکمه‌ی عبور ساختگی
                                نمایش داده نمی‌شود.
                            </p>
                        </section>
                    ) : null}
"""
replacement = """                    {installer.phase === 'operations' && operations ? (
                        <OperationsCard
                            operations={operations}
                            canDispatchProbe={canDispatchQueueProbe}
                            canContinue={canContinueOperations}
                        />
                    ) : null}

                    {installer.phase === 'finalize' ? (
                        <section className=\"rounded-2xl border border-dashed bg-card/50 p-5 sm:p-6\">
                            <h2 className=\"font-semibold\">مرحله نهایی‌سازی</h2>
                            <p className=\"mt-1 text-sm leading-6 text-muted-foreground\">
                                قفل نهایی فقط بعد از revalidation کامل Task 7 فعال می‌شود؛ تا آن زمان هیچ دکمه‌ی پایان ساختگی نمایش داده نمی‌شود.
                            </p>
                        </section>
                    ) : null}
"""
if placeholder not in source:
    raise SystemExit('operations placeholder anchor missing')
source = source.replace(placeholder, replacement, 1)

path.write_text(source)
