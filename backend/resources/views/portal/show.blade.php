<x-layouts.app :title="__('ui.pages.portal.title')">
    <section class="grid gap-6 lg:grid-cols-[1.05fr_0.95fr]">
        <div class="space-y-6">
            <div class="panel panel-hero p-6">
                <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ __('ui.pages.portal.self_service') }}</p>
                <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-3xl font-semibold text-white">{{ $employee->full_name }}</h2>
                        <p class="mt-2 text-sm text-stone-300">{{ $employee->email }}</p>
                    </div>

                    <div class="meta-chip px-4 py-3 text-xs text-stone-300">
                        <p>{{ __('ui.fields.status') }}: <span class="text-white">{{ __('ui.status.'.$employee->employment_status) }}</span></p>
                        <p class="mt-1">{{ __('ui.fields.pay_cycle') }}: <span class="text-white">{{ __('ui.pay_cycles.'.$employee->pay_cycle) }}</span></p>
                        <p class="mt-1">{{ __('ui.fields.currency') }}: <span class="text-white">{{ $employee->currency }}</span></p>
                    </div>
                </div>

                <dl class="mt-6 grid gap-4 md:grid-cols-2">
                    <div class="metric-tile">
                        <dt class="text-xs uppercase tracking-[0.25em] text-stone-400">{{ __('ui.fields.wallet') }}</dt>
                        <dd class="mt-2 break-all font-mono text-xs text-stone-100">{{ $employee->wallet_address ?: __('ui.common.not_set_yet') }}</dd>
                    </div>
                    <div class="metric-tile">
                        <dt class="text-xs uppercase tracking-[0.25em] text-stone-400">{{ __('ui.fields.start_date') }}</dt>
                        <dd class="mt-2 text-sm text-stone-100">{{ optional($employee->start_date)->toDateString() ?: __('ui.common.not_set_yet') }}</dd>
                    </div>
                    <div class="metric-tile">
                        <dt class="text-xs uppercase tracking-[0.25em] text-stone-400">{{ __('ui.pages.employees.current_compensation') }}</dt>
                        <dd class="mt-2 text-sm text-stone-100">
                            @if ($currentCompensation)
                                {{ number_format($currentCompensation->new_amount_minor / 100, 2) }} {{ $currentCompensation->currency }}
                            @else
                                {{ __('ui.common.not_set_yet') }}
                            @endif
                        </dd>
                    </div>
                    <div class="metric-tile">
                        <dt class="text-xs uppercase tracking-[0.25em] text-stone-400">{{ __('ui.fields.comp_effective') }}</dt>
                        <dd class="mt-2 text-sm text-stone-100">{{ $currentCompensation?->effective_date?->toDateString() ?: __('ui.common.not_set_yet') }}</dd>
                    </div>
                </dl>

                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('portal.payroll') }}" class="app-button app-button-secondary">
                        {{ __('ui.actions.open_my_payroll_history') }}
                    </a>
                </div>
            </div>

            <div class="table-shell">
                <div class="border-b border-white/10 px-6 py-4">
                    <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ __('ui.pages.portal.history') }}</p>
                    <h3 class="mt-2 text-xl font-semibold text-white">{{ __('ui.pages.employees.recent_entries') }}</h3>
                </div>

                <div class="divide-y divide-white/10">
                    @forelse ($employee->payrollEntries as $entry)
                        <article class="px-6 py-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-lg font-medium text-white">
                                        {{ number_format($entry->amount_minor / 100, 2) }} {{ $entry->currency }}
                                    </p>
                                    <p class="mt-1 text-sm text-stone-400">
                                        {{ __('ui.common.batch') }} {{ $entry->payrollBatch->period_year }}-{{ str_pad((string) $entry->payrollBatch->period_month, 2, '0', STR_PAD_LEFT) }}
                                        · {{ __('ui.common.due') }} {{ $entry->due_date->toDateString() }}
                                    </p>
                                </div>
                                <span class="status-pill">
                                    {{ $entry->paid_at ? __('ui.status.paid') : ($entry->due_date->isPast() ? __('ui.status.overdue') : __('ui.status.'.$entry->status)) }}
                                </span>
                            </div>

                            @if ($entry->tx_signature)
                                <p class="mt-4 break-all font-mono text-xs text-cyan-100">{{ $entry->tx_signature }}</p>
                            @endif
                        </article>
                    @empty
                        <div class="px-6 py-8 text-sm text-stone-400">
                            {{ __('ui.pages.portal.history_empty') }}
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="panel panel-soft p-6">
                <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">{{ __('ui.pages.portal.contract_summary') }}</p>
                <h3 class="mt-2 text-2xl font-semibold text-white">{{ __('ui.pages.portal.current_record') }}</h3>
                <p class="mt-2 text-sm leading-6 text-stone-300">
                    {{ __('ui.pages.portal.copy') }}
                </p>

                @if ($latestContract)
                    <div class="mt-6 space-y-4">
                        <div class="panel-inset p-4">
                            <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.fields.title') }}</p>
                            <p class="mt-2 text-sm text-white">{{ $latestContract->title }}</p>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="panel-inset p-4">
                                <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.fields.version') }}</p>
                                <p class="mt-2 text-lg font-semibold text-white">{{ $latestContract->version }}</p>
                            </div>
                            <div class="panel-inset p-4">
                                <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.fields.status') }}</p>
                                <p class="mt-2 text-lg font-semibold text-white">{{ __('ui.status.'.$latestContract->status) }}</p>
                            </div>
                        </div>
                        <div class="panel-inset p-4">
                            <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.fields.effective_date') }}</p>
                            <p class="mt-2 text-sm text-white">{{ $latestContract->effective_date->toDateString() }}</p>
                        </div>
                        <div class="panel-inset p-4">
                            <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.fields.sha256') }}</p>
                            <p class="mt-2 break-all font-mono text-xs text-stone-100">{{ $latestContract->file_hash }}</p>
                        </div>
                    </div>
                @else
                    <p class="mt-6 text-sm leading-6 text-stone-300">
                        {{ __('ui.pages.portal.no_contract') }}
                    </p>
                @endif
            </div>
        </div>
    </section>
</x-layouts.app>
