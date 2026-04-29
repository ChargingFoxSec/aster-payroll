<x-layouts.app :title="__('ui.pages.employees.create_title')">
    <section class="grid gap-6 lg:grid-cols-[1.1fr,0.9fr]">
        <form method="POST" action="{{ route('employees.store') }}" class="panel panel-hero p-6">
            @csrf

            <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ __('ui.pages.employees.create_kicker') }}</p>
            <h2 class="mt-2 text-3xl font-semibold text-white">{{ __('ui.pages.employees.create_heading') }}</h2>

            <div class="mt-6 grid gap-5 md:grid-cols-2">
                <label class="space-y-2 md:col-span-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.full_name') }}</span>
                    <input type="text" name="full_name" value="{{ old('full_name') }}" class="app-field px-4 py-3" required>
                </label>

                <label class="space-y-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.email') }}</span>
                    <input type="email" name="email" value="{{ old('email') }}" class="app-field px-4 py-3" required>
                </label>

                <label class="space-y-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.wallet_address') }}</span>
                    <input type="text" name="wallet_address" value="{{ old('wallet_address') }}" class="app-field px-4 py-3" placeholder="{{ __('ui.pages.employees.optional_for_now') }}">
                </label>

                <label class="space-y-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.employment_status') }}</span>
                    <select name="employment_status" class="app-field px-4 py-3">
                        @foreach (['active', 'paused', 'terminated'] as $value)
                            <option value="{{ $value }}" @selected(old('employment_status', 'active') === $value)>{{ __('ui.status.'.$value) }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.start_date') }}</span>
                    <input type="date" name="start_date" value="{{ old('start_date', now()->toDateString()) }}" class="app-field px-4 py-3">
                </label>

                <label class="space-y-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.pay_cycle') }}</span>
                    <select name="pay_cycle" class="app-field px-4 py-3">
                        @foreach ($payCycles as $value => $label)
                            <option value="{{ $value }}" @selected(old('pay_cycle', 'monthly') === $value)>{{ __('ui.pay_cycles.'.$value) }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.currency') }}</span>
                    <input type="hidden" name="currency" value="{{ config('payroll.currency.code', 'USDC') }}">
                    <div class="app-field px-4 py-3 text-sm text-stone-100">
                        {{ __('ui.pages.employees.currency_only', ['currency' => config('payroll.currency.code', 'USDC')]) }}
                    </div>
                </label>

                <label class="panel-inset flex items-start gap-3 p-4 md:col-span-2">
                    <input
                        type="checkbox"
                        name="provision_portal_account"
                        value="1"
                        class="mt-1 h-4 w-4 rounded border-white/20 bg-transparent text-cyan-300 focus:ring-cyan-300"
                        @checked(old('provision_portal_account'))
                    >
                    <span>
                        <span class="block text-sm font-medium text-stone-100">{{ __('ui.pages.employees.create_portal_account') }}</span>
                        <span class="mt-1 block text-sm leading-6 text-stone-400">
                            {{ __('ui.pages.employees.create_portal_account_copy') }}
                        </span>
                    </span>
                </label>
            </div>

            <button type="submit" class="app-button app-button-primary mt-6">
                {{ __('ui.actions.save_employee') }}
            </button>
        </form>

        <div class="panel panel-soft p-6">
            <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">{{ __('ui.pages.employees.scope_kicker') }}</p>
            <ul class="mt-4 space-y-3 text-sm leading-6 text-stone-300">
                <li>{{ __('ui.pages.employees.scope_1') }}</li>
                <li>{{ __('ui.pages.employees.scope_2') }}</li>
                <li>{{ __('ui.pages.employees.scope_3') }}</li>
                <li>{{ __('ui.pages.employees.scope_4') }}</li>
            </ul>
        </div>
    </section>
</x-layouts.app>
