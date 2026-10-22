import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/use-locale';

export default function Onboarding() {
    const { t } = useLocale();

    return (
        <div className="flex min-h-[60vh] items-center justify-center p-6">
            <div className="w-full max-w-xl rounded-2xl border bg-card p-8 shadow-sm">
                <Head title={t('onboarding.title')} />
                <div className="space-y-3 text-center">
                    <p className="text-sm font-medium text-muted-foreground">
                        SEO OS
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('onboarding.title')}
                    </h1>
                    <p className="text-sm leading-6 text-muted-foreground">
                        {t('onboarding.description')}
                    </p>
                    <div className="rounded-xl bg-muted p-4 text-sm text-muted-foreground">
                        {t('onboarding.pending')}
                    </div>
                </div>
            </div>
        </div>
    );
}
