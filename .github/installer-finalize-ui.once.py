from pathlib import Path

path = Path('resources/js/pages/install/index.tsx')
source = path.read_text()

export_anchor = '\nexport default function Installer({'
if export_anchor not in source:
    raise SystemExit('export anchor missing')

card = r'''
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
                                با موفقیت این مرحله، Installer بسته می‌شود. اگر هر
                                revalidation شکست بخورد، lock نوشته نمی‌شود و
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
'''
source = source.replace(export_anchor, '\n' + card + export_anchor, 1)

action_anchor = "    const canContinueOperations = installer.actions.includes(\n        'continue_operations',\n    );\n"
if action_anchor not in source:
    raise SystemExit('action anchor missing')
source = source.replace(
    action_anchor,
    action_anchor + "    const canFinalize = installer.actions.includes('finalize_installation');\n",
    1,
)

placeholder = '''                    {installer.phase === 'finalize' ? (
                        <section className="rounded-2xl border border-dashed bg-card/50 p-5 sm:p-6">
                            <h2 className="font-semibold">مرحله نهایی‌سازی</h2>
                            <p className="mt-1 text-sm leading-6 text-muted-foreground">
                                قفل نهایی فقط بعد از revalidation کامل Task 7
                                فعال می‌شود؛ تا آن زمان هیچ دکمه‌ی پایان ساختگی
                                نمایش داده نمی‌شود.
                            </p>
                        </section>
                    ) : null}'''
replacement = '''                    {installer.phase === 'finalize' ? (
                        <FinalizeCard canFinalize={canFinalize} />
                    ) : null}'''
if placeholder not in source:
    raise SystemExit('finalize placeholder missing')
source = source.replace(placeholder, replacement, 1)

path.write_text(source)
