<x-layouts.app :title="__('ui.pages.employees.payroll_title', ['employee' => $employee->full_name])">
    <section class="space-y-6">
        <div class="panel panel-hero p-6">
            <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ $scopeLabel ?? __('ui.pages.employees.detail_kicker') }}</p>
            <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h2 class="text-3xl font-semibold text-white">{{ $employee->full_name }}</h2>
                    <p class="mt-2 text-sm leading-6 text-stone-300">
                        {{ __('ui.pages.employees.statement_copy') }}
                    </p>
                </div>

                <a href="{{ $backUrl ?? route('employees.show', $employee) }}" class="app-button app-button-secondary">
                    {{ $backLabel ?? __('ui.actions.back_to_employee') }}
                </a>
            </div>
        </div>

        <div class="table-shell">
            <div class="grid grid-cols-[0.85fr,0.85fr,0.75fr,1.55fr] gap-4 border-b border-white/10 px-6 py-4 text-xs uppercase tracking-[0.25em] text-stone-500">
                <span>{{ __('ui.common.batch') }}</span>
                <span>{{ __('ui.fields.amount') }}</span>
                <span>{{ __('ui.fields.status') }}</span>
                <span>{{ __('ui.fields.tx_signature') }}</span>
            </div>

            <div class="divide-y divide-white/10">
                @forelse ($employee->payrollEntries as $entry)
                    @php($displayStatus = $entry->paid_at ? 'paid' : ($entry->due_date->isPast() ? 'overdue' : $entry->status))
                    <article class="grid gap-4 px-6 py-5 lg:grid-cols-[0.85fr,0.85fr,0.75fr,1.55fr] lg:items-center">
                        <div>
                            <p class="text-lg font-medium text-white">{{ $entry->payrollBatch->period_year }}-{{ str_pad((string) $entry->payrollBatch->period_month, 2, '0', STR_PAD_LEFT) }}</p>
                            <p class="mt-1 text-sm text-stone-400">{{ __('ui.common.due') }} {{ $entry->due_date->toDateString() }}</p>
                        </div>
                        <p class="text-lg font-semibold text-white">{{ number_format($entry->amount_minor / 100, 2) }} {{ $entry->currency }}</p>
                        <div>
                            <span class="status-pill">{{ __('ui.status.'.$displayStatus) }}</span>
                            @if ($entry->paid_at)
                                <p class="mt-2 text-xs text-stone-400">{{ __('ui.status.paid') }} {{ $entry->paid_at->toDateTimeString() }}</p>
                            @endif
                        </div>
                        <p class="break-all font-mono text-xs {{ $entry->tx_signature ? 'text-cyan-100' : 'text-stone-500' }}">{{ $entry->tx_signature ?: __('ui.common.not_set_yet') }}</p>
                    </article>
                @empty
                    <div class="px-6 py-8 text-sm text-stone-400">
                        {{ __('ui.pages.employees.no_employee_entries') }}
                    </div>
                @endforelse
            </div>
        </div>
    </section>
</x-layouts.app>
