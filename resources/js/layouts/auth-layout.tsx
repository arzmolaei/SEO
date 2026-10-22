import { usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/use-locale';
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

    const localizedCopy =
        component === 'auth/login'
            ? {
                  title: t('auth.loginTitle'),
                  description: t('auth.loginDescription'),
              }
            : component === 'auth/register'
              ? {
                    title: t('auth.registerTitle'),
                    description: t('auth.registerDescription'),
                }
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
