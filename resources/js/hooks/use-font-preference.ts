import { useSyncExternalStore } from 'react';

export type AppFont = 'iransansx' | 'yekan-bakh' | 'vazirmatn';

export type UseFontPreferenceReturn = {
    readonly font: AppFont;
    readonly updateFont: (font: AppFont) => void;
};

export const DEFAULT_APP_FONT: AppFont = 'iransansx';

const listeners = new Set<() => void>();
let currentFont: AppFont = DEFAULT_APP_FONT;

const isAppFont = (value: string | null): value is AppFont => {
    return (
        value === 'iransansx' || value === 'yekan-bakh' || value === 'vazirmatn'
    );
};

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const getStoredFont = (): AppFont => {
    if (typeof window === 'undefined') {
        return DEFAULT_APP_FONT;
    }

    const stored = localStorage.getItem('app-font');

    return isAppFont(stored) ? stored : DEFAULT_APP_FONT;
};

const applyFont = (font: AppFont): void => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.dataset.font = font;
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

export function initializeFontPreference(): void {
    if (typeof window === 'undefined') {
        return;
    }

    currentFont = getStoredFont();
    localStorage.setItem('app-font', currentFont);
    setCookie('app-font', currentFont);
    applyFont(currentFont);
}

export function useFontPreference(): UseFontPreferenceReturn {
    const font = useSyncExternalStore(
        subscribe,
        () => currentFont,
        () => DEFAULT_APP_FONT,
    );

    const updateFont = (nextFont: AppFont): void => {
        currentFont = nextFont;
        localStorage.setItem('app-font', nextFont);
        setCookie('app-font', nextFont);
        applyFont(nextFont);
        notify();
    };

    return { font, updateFont } as const;
}
