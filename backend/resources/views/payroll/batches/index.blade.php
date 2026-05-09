<x-layouts.app :title="__('ui.pages.payroll.ledger_title')">
    <section class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-[1fr_0.9fr]">
            <div class="panel panel-hero p-6">
                <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ __('ui.pages.payroll.ledger_kicker') }}</p>
                <div class="mt-3">
                    <div>
                        <h2 class="text-3xl font-semibold text-white">{{ $company->name }}</h2>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-stone-300">
                            {{ __('ui.pages.payroll.ledger_copy') }}
                        </p>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('payroll-batches.store') }}" class="panel panel-soft p-6" data-payroll-batch-form>
                @csrf

                <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">{{ __('ui.pages.payroll.create_batch_draft') }}</p>
                <h3 class="mt-2 text-2xl font-semibold text-white">{{ __('ui.pages.payroll.prepare_next_cycle') }}</h3>
                <p class="mt-2 text-sm leading-6 text-stone-300">
                    {{ __('ui.pages.payroll.prepare_copy') }}
                </p>

                <div class="mt-6 grid gap-5 md:grid-cols-2">
                    <label class="space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.payroll_period') }}</span>
                        <input type="month" name="period" value="{{ old('period', $defaultPeriod) }}" class="app-field px-4 py-3" data-payroll-period-input required>
                    </label>

                    <label class="space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.due_date') }}</span>
                        <input type="date" name="due_date" value="{{ old('due_date', $defaultDueDate) }}" class="app-field px-4 py-3" data-payroll-due-date-input required>
                    </label>
                </div>

                <button type="submit" class="app-button app-button-amber mt-6">
                    {{ __('ui.actions.draft_payroll_batch') }}
                </button>
            </form>
        </div>

        <form method="GET" action="{{ route('payroll-batches.index') }}" class="panel panel-soft p-5">
            <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.pages.payroll.filters') }}</p>
            <div class="mt-4 grid gap-4 md:grid-cols-5">
                <label class="space-y-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.payroll_period') }}</span>
                    <input type="month" name="period" value="{{ $filters['period'] }}" class="app-field px-4 py-3">
                </label>
                <label class="space-y-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.status') }}</span>
                    <select name="status" class="app-field px-4 py-3">
                        <option value="">{{ __('ui.common.all_statuses') }}</option>
                        @foreach ($allowedStatuses as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ __('ui.status.'.$status) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.employee') }}</span>
                    <input type="search" name="employee" value="{{ $filters['employee'] }}" class="app-field px-4 py-3" placeholder="{{ __('ui.fields.employee_search') }}">
                </label>
                <label class="space-y-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.due_state') }}</span>
                    <select name="due_state" class="app-field px-4 py-3">
                        <option value="">{{ __('ui.common.all_due_states') }}</option>
                        @foreach ($allowedDueStates as $dueState)
                            <option value="{{ $dueState }}" @selected($filters['due_state'] === $dueState)>{{ __('ui.common.'.$dueState) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-2">
                    <span class="text-sm text-stone-200">{{ __('ui.fields.tx_or_root') }}</span>
                    <input type="search" name="tx_or_root" value="{{ $filters['tx_or_root'] }}" class="app-field px-4 py-3" placeholder="{{ __('ui.fields.tx_or_root') }}">
                </label>
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <button type="submit" class="app-button app-button-secondary">{{ __('ui.actions.apply_filters') }}</button>
                <a href="{{ route('payroll-batches.index') }}" class="app-button app-button-secondary">{{ __('ui.actions.clear_filters') }}</a>
            </div>
        </form>

        <div class="table-shell payroll-ledger-table">
            <div class="payroll-ledger-grid payroll-ledger-header border-b border-white/10 px-6 py-4 text-xs uppercase tracking-[0.25em] text-stone-500">
                <span>{{ __('ui.pages.payroll.batch') }}</span>
                <span>{{ __('ui.fields.total') }}</span>
                <span>{{ __('ui.fields.status') }}</span>
                <span>{{ __('ui.common.due') }}</span>
                <span class="text-right">{{ __('ui.fields.actions') }}</span>
            </div>

            <div class="payroll-ledger-rows">
                @forelse ($batches as $batch)
                    @php
                        $overdueCount = $batch->entries->filter(fn ($entry) => $entry->paid_at === null && $entry->due_date->isPast())->count();
                        $awaitingApprovalCount = $batch->entries->filter(
                            fn ($entry) => $entry->payoutExecution?->status === \App\Models\PayoutExecution::STATUS_AWAITING_APPROVAL
                        )->count();
                    @endphp
                    <article class="payroll-ledger-grid payroll-ledger-row px-6 py-5">
                        <div>
                            <p class="text-lg font-medium text-white">{{ $batch->period_year }}-{{ str_pad((string) $batch->period_month, 2, '0', STR_PAD_LEFT) }}</p>
                            <p class="mt-1 text-sm text-stone-400">{{ $batch->entries_count }} {{ __('ui.common.entries') }} · {{ optional($batch->executed_at)->toDateTimeString() ?: __('ui.common.not_executed_yet') }}</p>
                        </div>
                        <p class="text-lg font-semibold text-white">{{ number_format($batch->total_amount_minor / 100, 2) }} {{ $batch->currency }}</p>
                        <div>
                            <span class="status-pill">{{ __('ui.status.'.$batch->status) }}</span>
                            @if ($overdueCount > 0)
                                <p class="mt-2 text-xs text-amber-200">{{ __('ui.pages.payroll.overdue_entries', ['count' => $overdueCount]) }}</p>
                            @endif
                            @if ($awaitingApprovalCount > 0)
                                <p class="mt-2 text-xs text-sky-200">{{ __('ui.pages.payroll.awaiting_signer', ['count' => $awaitingApprovalCount]) }}</p>
                            @endif
                        </div>
                        <p class="text-sm text-stone-200">{{ $batch->due_date->toDateString() }}</p>
                        <a href="{{ route('payroll-batches.show', $batch) }}" class="app-button app-button-secondary app-button-compact justify-self-end">
                            {{ __('ui.actions.open') }}
                        </a>
                    </article>
                @empty
                    <div class="px-6 py-8 text-sm text-stone-400">
                        {{ __('ui.pages.payroll.empty_batches') }}
                    </div>
                @endforelse
            </div>
        </div>
    </section>
</x-layouts.app>
