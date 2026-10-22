@php($localeContext = \App\Support\LocaleContext::from(app()->getLocale()))
<!DOCTYPE html>
<html lang="{{ $localeContext['locale'] }}" dir="{{ $localeContext['direction'] }}" data-font="iransansx" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Apply persisted visual preferences before styles render to avoid flashes. --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';
                const allowedFonts = ['iransansx', 'yekan-bakh', 'vazirmatn'];
                const storedFont = localStorage.getItem('app-font');
                const font = allowedFonts.includes(storedFont) ? storedFont : 'iransansx';

                document.documentElement.dataset.font = font;

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'SEO OS') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
