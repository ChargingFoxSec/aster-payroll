<x-layouts.app :title="__('ui.pages.login.title')">
    <section class="mx-auto max-w-5xl grid gap-6 lg:grid-cols-[0.95fr,1.05fr]">
        <div class="panel panel-hero p-6">
            @if (__('ui.pages.login.kicker') !== '')
                <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ __('ui.pages.login.kicker') }}</p>
            @endif
            <h2 class="text-3xl font-semibold text-white">{{ __('ui.pages.login.heading') }}</h2>
            <p class="mt-3 text-sm leading-6 text-stone-300">
                {{ __('ui.pages.login.copy') }}
            </p>

            <div class="panel-inset mt-6 p-5">
                <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.pages.login.demo_accounts') }}</p>
                <div class="mt-4 space-y-4 text-sm text-stone-300">
                    <div>
                        <p class="font-medium text-white">{{ __('ui.roles.admin') }}</p>
                        <p class="mt-1 font-mono text-xs text-cyan-100">admin@aster.test / password</p>
                    </div>
                    <div>
                        <p class="font-medium text-white">{{ __('ui.roles.employee') }}</p>
                        <p class="mt-1 font-mono text-xs text-cyan-100">alice.payroll.demo@aster.test / password</p>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="panel panel-soft p-6">
            @csrf

            <h3 class="mt-2 text-2xl font-semibold text-white">{{ __('ui.pages.login.auth_heading') }}</h3>

            <div class="mt-6 space-y-5">
                <label class="block space-y-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.email') }}</span>
                    <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" class="app-field px-4 py-3" required autofocus>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.password') }}</span>
                    <input type="password" name="password" autocomplete="current-password" class="app-field px-4 py-3" required>
                </label>
            </div>

            <button type="submit" class="app-button app-button-primary mt-6">
                {{ __('ui.actions.log_in') }}
            </button>

            <p class="mt-6 text-xs leading-6 text-stone-400">
                {{ __('ui.pages.login.scope_note') }}
            </p>
        </form>
    </section>
</x-layouts.app>
