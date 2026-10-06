<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased erp-shell">
        <a href="#main-content" class="erp-skip-link">Aller au contenu</a>
        <div
            class="min-h-screen erp-ui-shell"
            x-data="{ sidebarOpen: false, desktop: window.innerWidth >= 1024 }"
            @resize.window="desktop = window.innerWidth >= 1024; if (desktop) sidebarOpen = false"
            @keydown.escape.window="if (sidebarOpen) { sidebarOpen = false; $refs.navToggle?.focus(); }"
        >
            <x-sidebar />
            <livewire:layout.navigation />

            <!-- Page Heading -->
            @if (isset($header))
                <header class="erp-page-heading">
                    <div>
                        @if(request()->routeIs('*.show', '*.edit'))<div class="mb-4"><x-page-context /></div>@endif
                        {{ $header }}
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main id="main-content" class="erp-main" tabindex="-1">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
