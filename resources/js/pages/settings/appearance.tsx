import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import { FontPicker } from '@/components/font-picker';
import Heading from '@/components/heading';
import { useLocale } from '@/hooks/use-locale';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    const { locale } = useLocale();
    const isPersian = locale === 'fa';
    const title = isPersian ? 'تنظیمات ظاهر' : 'Appearance settings';

    return (
        <>
            <Head title={title} />

            <h1 className="sr-only">{title}</h1>

            <div className="space-y-8">
                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title={title}
                        description={
                            isPersian
                                ? 'ظاهر و تم رابط کاربری را برای حساب خود تنظیم کنید.'
                                : 'Update the appearance settings for your account.'
                        }
                    />
                    <AppearanceTabs />
                </section>

                <section className="space-y-4 border-t pt-6">
                    <Heading
                        variant="small"
                        title={
                            isPersian ? 'فونت رابط کاربری' : 'Interface font'
                        }
                        description={
                            isPersian
                                ? 'ایران‌سنس پیش‌فرض است؛ یکان‌بخ و وزیرمتن هم برای کل رابط قابل انتخاب‌اند.'
                                : 'IRANSansX is the default; Yekan Bakh and Vazirmatn can also be used across the interface.'
                        }
                    />
                    <FontPicker />
                </section>
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance settings',
            href: editAppearance(),
        },
    ],
};
