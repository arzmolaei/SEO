import { usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/use-locale';
import type { TranslationKey } from '@/i18n';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';

export default function AuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    const { component } = usePage();
    const { t } = useLocale();

    // Static page copy is localized here; pages that switch copy at runtime
    // (two-factor challenge) pass already-translated layout props.
    const copyKeys: Partial<Record<string, [TranslationKey, TranslationKey]>> =
        {
            'auth/login': ['auth.loginTitle', 'auth.loginDescription'],
            'auth/register': ['auth.registerTitle', 'auth.registerDescription'],
            'auth/confirm-password': [
                'auth.confirmTitle',
                'auth.confirmDescription',
            ],
            'auth/forgot-password': [
                'auth.forgotTitle',
                'auth.forgotDescription',
            ],
            'auth/reset-password': ['auth.resetTitle', 'auth.resetDescription'],
            'auth/verify-email': ['auth.verifyTitle', 'auth.verifyDescription'],
        };
    const keys = copyKeys[component];
    const localizedCopy = keys
        ? { title: t(keys[0]), description: t(keys[1]) }
        : { title, description };

    return (
        <AuthLayoutTemplate
            title={localizedCopy.title}
            description={localizedCopy.description}
        >
            {children}
        </AuthLayoutTemplate>
    );
}
