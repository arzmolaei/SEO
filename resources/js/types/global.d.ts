import type { Auth } from '@/types/auth';
import type { ProjectContext } from '@/types/project';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            locale: 'fa' | 'en';
            direction: 'rtl' | 'ltr';
            auth: Auth;
            flash: {
                export_error?: string | null;
            };
            projectContext: ProjectContext | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
