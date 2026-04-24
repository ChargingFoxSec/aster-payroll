<x-layouts.app :title="__('ui.pages.payroll.ledger_title')">
    <section class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-[1fr,0.9fr]">
            <div class="panel panel-hero p-6">
                <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ __('ui.pages.payroll.ledger_kicker') }}</p>
                <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-3xl font-semibold text-white">{{ $company->name }}</h2>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-stone-300">
                            {{ __('ui.pages.payroll.ledger_copy') }}
                        </p>
                    </div>

                    <a href="{{ route('payroll-demo.show') }}" class="app-button app-button-primary">
                        {{ __('ui.actions.open_batch_settlement_lab') }}
                    </a>
                </div>
            </div>

            <form method="POST" action="{{ route('payroll-batches.store') }}" class="panel panel-soft p-6">
                @csrf

                <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">{{ __('ui.pages.payroll.create_batch_draft') }}</p>
                <h3 class="mt-2 text-2xl font-semibold text-white">{{ __('ui.pages.payroll.prepare_next_cycle') }}</h3>
                <p class="mt-2 text-sm leading-6 text-stone-300">
                    {{ __('ui.pages.payroll.prepare_copy') }}
                </p>

                <div class="mt-6 grid gap-5 md:grid-cols-2">
                    <label class="space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.payroll_period') }}</span>
                        <input type="month" name="period" value="{{ old('period', $defaultPeriod) }}" class="app-field px-4 py-3" required>
                    </label>

                    <label class="space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.due_date') }}</span>
                        <input type="date" name="due_date" value="{{ old('due_date', $defaultDueDate) }}" class="app-field px-4 py-3" required>
                    </label>
                </div>

                <button type="submit" class="app-button app-button-amber mt-6">
                    {{ __('ui.actions.draft_payroll_batch') }}
                </button>
            </form>
        </div>

        <div class="table-shell">
            <div class="grid grid-cols-[1.25fr,0.75fr,0.75fr,0.75fr,0.5fr] gap-4 border-b border-white/10 px-6 py-4 text-xs uppercase tracking-[0.25em] text-stone-500">
                <span>{{ __('ui.pages.payroll.batch') }}</span>
                <span>{{ __('ui.fields.total') }}</span>
                <span>{{ __('ui.fields.status') }}</span>
                <span>{{ __('ui.common.due') }}</span>
                <span></span>
            </div>

            <div class="divide-y divide-white/10">
                @forelse ($batches as $batch)
                    @php
                        $overdueCount = $batch->entries->filter(fn ($entry) => $entry->paid_at === null && $entry->due_date->isPast())->count();
                        $awaitingApprovalCount = $batch->entries->filter(
                            fn ($entry) => $entry->payoutExecution?->status === \App\Models\PayoutExecution::STATUS_AWAITING_APPROVAL
                        )->count();
                    @endphp
                    <article class="grid gap-4 px-6 py-5 lg:grid-cols-[1.25fr,0.75fr,0.75fr,0.75fr,0.5fr] lg:items-center">
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
                        <a href="{{ route('payroll-batches.show', $batch) }}" class="inline-link text-sm">{{ __('ui.actions.open') }}</a>
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
