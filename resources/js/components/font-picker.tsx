import { Check } from 'lucide-react';
import type { AppFont } from '@/hooks/use-font-preference';
import { useFontPreference } from '@/hooks/use-font-preference';
import { cn } from '@/lib/utils';

const fonts: Array<{
    value: AppFont;
    label: string;
    sample: string;
}> = [
    {
        value: 'iransansx',
        label: 'IRANSansX FaNum',
        sample: 'تجربه سریع و خوانای مدیریت سئو ۱۴۰۵',
    },
    {
        value: 'yekan-bakh',
        label: 'Yekan Bakh FaNum',
        sample: 'تجربه سریع و خوانای مدیریت سئو ۱۴۰۵',
    },
    {
        value: 'vazirmatn',
        label: 'Vazirmatn',
        sample: 'تجربه سریع و خوانای مدیریت سئو ۱۴۰۵',
    },
];

export function FontPicker() {
    const { font, updateFont } = useFontPreference();

    return (
        <div className="grid gap-3 sm:grid-cols-3">
            {fonts.map((option) => {
                const active = font === option.value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        onClick={() => updateFont(option.value)}
                        data-font-preview={option.value}
                        aria-pressed={active}
                        className={cn(
                            'relative rounded-xl border bg-card p-4 text-start transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            active && 'border-primary ring-1 ring-primary',
                        )}
                    >
                        <div className="flex items-start justify-between gap-3">
                            <span className="text-sm font-semibold">
                                {option.label}
                            </span>
                            {active ? (
                                <span className="flex size-5 items-center justify-center rounded-full bg-primary text-primary-foreground">
                                    <Check className="size-3.5" />
                                </span>
                            ) : null}
                        </div>
                        <p className="mt-4 text-base leading-7">
                            {option.sample}
                        </p>
                    </button>
                );
            })}
        </div>
    );
}
