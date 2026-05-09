<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? __('ui.app_name') }}</title>
        @if (! app()->runningUnitTests() || file_exists(public_path('build/manifest.json')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    @php
        $user = auth()->user();
        $navItems = [];

        if ($user?->isCompanyAdmin()) {
            $navItems = [
                ['label' => __('ui.nav.dashboard'), 'href' => route('dashboard'), 'active' => request()->routeIs('dashboard')],
                ['label' => __('ui.nav.employees'), 'href' => route('employees.index'), 'active' => request()->routeIs('employees.*', 'contracts.*')],
                ['label' => __('ui.nav.payroll'), 'href' => route('payroll-batches.index'), 'active' => request()->routeIs('payroll-batches.*')],
                ['label' => __('ui.nav.execution_lab'), 'href' => route('payroll-demo.show'), 'active' => request()->routeIs('payroll-demo.*')],
            ];
        } elseif ($user?->isEmployee()) {
            $navItems = [
                ['label' => __('ui.nav.my_portal'), 'href' => route('portal.show'), 'active' => request()->routeIs('portal.show')],
                ['label' => __('ui.nav.my_payroll'), 'href' => route('portal.payroll'), 'active' => request()->routeIs('portal.payroll')],
            ];
        }

        $activeNav = collect($navItems)->firstWhere('active', true);
        $supportedLocales = config('app.supported_locales', []);
        $currentLocale = app()->getLocale();
    @endphp

    <body class="app-theme min-h-screen">
        <div class="app-shell mx-auto flex min-h-screen w-full max-w-[96rem] flex-col px-4 py-4 lg:px-6">
            <header class="app-header mb-8 px-4 py-3 lg:px-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <a href="{{ route('home') }}" class="flex items-center gap-4">
                        <img
                            src="{{ asset('aster-payroll-logo.svg') }}"
                            alt=""
                            class="brand-mark"
                            aria-hidden="true"
                        >
                        <span class="brand-wordmark">{{ __('ui.app_name') }}</span>
                    </a>

                    <div class="command-deck lg:items-end">
                        <div class="control-row">
                            @auth
                                <div class="nav-shell">
                                    @foreach ($navItems as $item)
                                        <a
                                            href="{{ $item['href'] }}"
                                            @class([
                                                'nav-link',
                                                'nav-link-active' => $item['active'],
                                            ])
                                            @if ($item['active']) aria-current="page" @endif
                                        >
                                            {{ $item['label'] }}
                                        </a>
                                    @endforeach
                                </div>
                            @endauth

                            <form method="POST" action="{{ route('locale.update') }}" class="language-switcher">
                                @csrf
                                <label>
                                    <span class="language-switcher-label">{{ __('ui.layout.language') }}</span>
                                    <select name="locale" class="language-switcher-select" onchange="this.form.submit()">
                                        @foreach ($supportedLocales as $locale => $label)
                                            <option value="{{ $locale }}" @selected($currentLocale === $locale)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </form>

                            @auth
                                <div class="identity-chip">
                                    <span class="identity-chip-label">{{ __('ui.layout.access') }}</span>
                                    <span class="identity-chip-value">{{ $user->isCompanyAdmin() ? __('ui.roles.company_admin') : __('ui.roles.employee') }}</span>
                                </div>

                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="app-button app-button-ghost">
                                        {{ __('ui.actions.log_out') }}
                                    </button>
                                </form>
                            @endauth
                        </div>

                        @auth
                            @if ($activeNav)
                                <p class="nav-context">{{ __('ui.layout.current_view') }} · {{ $activeNav['label'] }}</p>
                            @endif
                        @endauth
                    </div>
                </div>
            </header>

            <section class="mb-10 max-w-3xl">
                <p class="brand-kicker">{{ __('ui.layout.hero_kicker') }}</p>
                <h1 class="brand-title mt-5">{{ __('ui.app_name') }}</h1>
                <p class="brand-copy mt-5">
                    {{ __('ui.layout.hero_copy') }}
                </p>
            </section>

            @if (session('status'))
                <div class="flash-banner flash-banner-success mb-6 px-4 py-3 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('error'))
                <div class="flash-banner flash-banner-error mb-6 px-4 py-3 text-sm">
                    {{ session('error') }}
                </div>
            @endif

            @if ($provisionedPortalAccount = session('provisioned_portal_account'))
                <div class="flash-banner flash-banner-warning mb-6 px-4 py-4 text-sm">
                    <p class="font-medium">{{ __('ui.layout.temporary_credentials') }}</p>
                    <p class="mt-2">
                        {{ __('ui.layout.temporary_credentials_copy', ['employee' => $provisionedPortalAccount['employee_name'] ?? __('ui.roles.employee')]) }}
                    </p>
                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        <div class="panel-inset p-3">
                            <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.fields.email') }}</p>
                            <p class="mt-2 break-all font-mono text-xs text-stone-100">{{ $provisionedPortalAccount['email'] ?? __('ui.common.unavailable') }}</p>
                        </div>
                        <div class="panel-inset p-3">
                            <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.fields.temporary_password') }}</p>
                            <p class="mt-2 break-all font-mono text-xs text-stone-100">{{ $provisionedPortalAccount['temporary_password'] ?? __('ui.common.unavailable') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            @if ($errors->any())
                <div class="flash-banner flash-banner-warning mb-6 px-4 py-3 text-sm">
                    <p class="font-medium">{{ __('ui.layout.form_errors') }}</p>
                    <ul class="mt-2 space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <main class="flex-1">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
