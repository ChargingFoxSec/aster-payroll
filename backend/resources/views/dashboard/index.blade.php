<x-layouts.app :title="__('ui.pages.dashboard.title')">
    <section class="grid gap-6 lg:grid-cols-[1.2fr,0.8fr]">
        <div class="panel panel-hero p-6">
            <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">{{ __('ui.pages.dashboard.kicker') }}</p>
            <h2 class="mt-3 text-3xl font-semibold text-white">{{ $company->name }}</h2>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-stone-300">
                {{ __('ui.pages.dashboard.copy') }}
            </p>

            <div class="mt-6 grid gap-4 md:grid-cols-3">
                <div class="metric-tile">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-400">{{ __('ui.pages.dashboard.active_employees') }}</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $company->active_employees_count }}</p>
                    <p class="mt-1 text-xs text-stone-500">{{ __('ui.common.total_records', ['count' => $company->employees_count]) }}</p>
                </div>
                <div class="metric-tile">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-400">{{ __('ui.fields.contracts') }}</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $company->contracts_count }}</p>
                </div>
                <div class="metric-tile">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-400">{{ __('ui.pages.dashboard.payroll_batches') }}</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $company->payroll_batches_count }}</p>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('employees.create') }}" class="app-button app-button-primary">
                    {{ __('ui.actions.create_employee') }}
                </a>
                <a href="{{ route('payroll-batches.index') }}" class="app-button app-button-secondary">
                    {{ __('ui.actions.review_payroll_ledger') }}
                </a>
                <a href="{{ route('payroll-demo.show') }}" class="app-button app-button-secondary">
                    {{ __('ui.actions.open_confidential_payroll_demo') }}
                </a>
            </div>
        </div>

        <div class="panel panel-soft p-6">
            <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ __('ui.pages.dashboard.latest_payout_state') }}</p>

            @if ($latestExecution)
                <div class="mt-4 space-y-4">
                    <div>
                        <p class="text-sm text-stone-400">{{ __('ui.fields.execution') }}</p>
                        <p class="mt-1 text-sm text-stone-100">#{{ $latestExecution->id }} · {{ __('ui.status.'.$latestExecution->status) }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-stone-400">{{ __('ui.fields.employee') }}</p>
                        <p class="mt-1 text-sm text-stone-100">{{ $latestExecution->employee->full_name }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-stone-400">{{ __('ui.fields.approval_actor') }}</p>
                        <p class="mt-1 break-all font-mono text-xs {{ $latestExecution->approved_wallet_address ? 'text-cyan-100' : 'text-stone-400' }}">
                            {{ $latestExecution->approved_wallet_address ?: ($company->wallet_address ?: __('ui.pages.dashboard.captured_at_import')) }}
                        </p>
                    </div>

                    @if ($latestReceipt)
                        <div>
                            <p class="text-sm text-stone-400">{{ __('ui.pages.dashboard.tracked_transfer') }}</p>
                            <p class="mt-1 break-all font-mono text-xs text-cyan-100">{{ $latestReceipt['transactions']['confidential_transfer'] ?? __('ui.common.not_reported') }}</p>
                        </div>
                    @endif
                </div>
            @else
                <p class="mt-4 text-sm leading-6 text-stone-300">
                    {{ __('ui.pages.dashboard.no_execution') }}
                </p>
            @endif
        </div>
    </section>
</x-layouts.app>
