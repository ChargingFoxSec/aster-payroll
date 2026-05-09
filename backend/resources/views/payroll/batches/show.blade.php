<x-layouts.app :title="__('ui.pages.payroll.batch_title')">
    @php
        $awaitingApprovalCount = $payrollBatch->entries->filter(
            fn ($entry) => $entry->payoutExecution?->status === \App\Models\PayoutExecution::STATUS_AWAITING_APPROVAL
        )->count();
        $importedCount = $payrollBatch->entries->filter(
            fn ($entry) => $entry->payoutExecution?->status === \App\Models\PayoutExecution::STATUS_IMPORTED
        )->count();
        $failedCount = $payrollBatch->entries->filter(
            fn ($entry) => $entry->payoutExecution?->status === \App\Models\PayoutExecution::STATUS_FAILED
        )->count();
    @endphp
    <section class="space-y-6">
        <div class="panel panel-hero p-6">
            <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ __('ui.pages.payroll.batch_detail') }}</p>
            <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h2 class="text-3xl font-semibold text-white">{{ $payrollBatch->period_year }}-{{ str_pad((string) $payrollBatch->period_month, 2, '0', STR_PAD_LEFT) }}</h2>
                    <p class="mt-2 text-sm leading-6 text-stone-300">
                        {{ __('ui.pages.payroll.batch_detail_copy') }}
                    </p>
                </div>

                <div class="flex flex-col gap-3 lg:items-end">
                    <div class="meta-chip px-4 py-3 text-xs text-stone-300">
                        <p>{{ __('ui.fields.status') }}: <span class="text-white">{{ __('ui.status.'.$payrollBatch->status) }}</span></p>
                        <p class="mt-1">{{ __('ui.fields.due_date') }}: <span class="text-white">{{ $payrollBatch->due_date->toDateString() }}</span></p>
                        <p class="mt-1">{{ __('ui.fields.approved_at') }}: <span class="text-white">{{ optional($payrollBatch->approved_at)->toDateTimeString() ?: __('ui.common.not_set') }}</span></p>
                        <p class="mt-1">{{ __('ui.fields.executed_at') }}: <span class="text-white">{{ optional($payrollBatch->executed_at)->toDateTimeString() ?: __('ui.common.not_set') }}</span></p>
                    </div>

                    <a href="{{ route('payroll-demo.show', ['payroll_batch_id' => $payrollBatch->id]) }}" class="app-button app-button-secondary">
                        {{ __('ui.actions.open_confidential_settlement') }}
                    </a>
                </div>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-4">
                <div class="metric-tile">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.fields.total') }}</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ number_format($payrollBatch->total_amount_minor / 100, 2) }}</p>
                    <p class="mt-2 text-sm text-stone-300">{{ $payrollBatch->currency }}</p>
                </div>
                <div class="metric-tile">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.fields.entry_count') }}</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $payrollBatch->entry_count ?: $payrollBatch->entries->count() }}</p>
                </div>
                <div class="metric-tile">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.pages.payroll.paid_entries') }}</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $payrollBatch->entries->whereNotNull('paid_at')->count() }}</p>
                </div>
                <div class="metric-tile">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.pages.payroll.settlement_progress') }}</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $importedCount }}/{{ $payrollBatch->entries->count() }}</p>
                    <p class="mt-2 text-sm text-stone-300">{{ $awaitingApprovalCount }} {{ __('ui.common.awaiting') }} · {{ $failedCount }} {{ __('ui.common.failed') }}</p>
                </div>
            </div>

            <div class="panel-inset mt-6 p-4 text-sm text-stone-300">
                <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.pages.payroll.anchor_traceability') }}</p>
                <p class="mt-3">{{ __('ui.fields.batch_account') }}</p>
                <p class="mt-1 break-all font-mono text-xs text-cyan-100">{{ $payrollBatch->anchor_batch_pubkey ?: __('ui.common.pending') }}</p>
                <p class="mt-4">{{ __('ui.fields.entries_root') }}</p>
                <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $payrollBatch->entries_root ?: __('ui.common.pending') }}</p>
                <p class="mt-4">{{ __('ui.fields.batch_commit_tx') }}</p>
                <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $payrollBatch->latestCommitAttestation?->tx_signature ?: __('ui.common.pending') }}</p>
                <p class="mt-4">{{ __('ui.fields.approval_root') }}</p>
                <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $payrollBatch->approval_root ?: __('ui.common.pending') }}</p>
                <p class="mt-4">{{ __('ui.fields.batch_approval_tx') }}</p>
                <p class="mt-1 break-all font-mono text-xs {{ $payrollBatch->latestApprovalAttestation ? 'text-cyan-100' : 'text-stone-300' }}">{{ $payrollBatch->latestApprovalAttestation?->tx_signature ?: __('ui.common.pending') }}</p>
                <p class="mt-4">{{ __('ui.fields.settlement_root') }}</p>
                <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $payrollBatch->settlement_root ?: ($payrollBatch->status === \App\Models\PayrollBatch::STATUS_EXECUTED ? __('ui.common.pending_executed_attestation') : __('ui.common.not_executed_yet')) }}</p>
                <p class="mt-4">{{ __('ui.fields.batch_finalization_tx') }}</p>
                <p class="mt-1 break-all font-mono text-xs {{ $payrollBatch->latestFinalizationAttestation ? 'text-cyan-100' : 'text-stone-300' }}">
                    {{ $payrollBatch->latestFinalizationAttestation?->tx_signature ?: ($payrollBatch->status === \App\Models\PayrollBatch::STATUS_EXECUTED ? __('ui.common.pending_executed_attestation') : __('ui.common.not_executed_yet')) }}
                </p>
                <p class="mt-4">{{ __('ui.fields.finalized_by') }}</p>
                <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $payrollBatch->finalized_by ?: __('ui.common.pending') }}</p>
            </div>
        </div>

        <div class="table-shell">
            <div class="payroll-batch-entry-grid border-b border-white/10 px-6 py-4 text-xs uppercase tracking-[0.25em] text-stone-500">
                <span>{{ __('ui.fields.employee') }}</span>
                <span>{{ __('ui.fields.amount') }}</span>
                <span>{{ __('ui.fields.status') }}</span>
                <span>{{ __('ui.fields.tx_signature') }}</span>
                <span class="text-right">{{ __('ui.fields.actions') }}</span>
            </div>

            <div>
                @foreach ($payrollBatch->entries as $entry)
                    <article class="payroll-batch-entry-grid payroll-batch-entry-row px-6 py-5">
                        <div>
                            <p class="text-lg font-medium text-white">{{ $entry->employee->full_name }}</p>
                            <p class="mt-1 text-sm text-stone-400">{{ $entry->employee->email }}</p>
                        </div>
                        <p class="text-lg font-semibold text-white">{{ number_format($entry->amount_minor / 100, 2) }} {{ $entry->currency }}</p>
                        <div>
                            @php($displayStatus = $entry->paid_at ? 'paid' : ($entry->due_date->isPast() ? 'overdue' : $entry->status))
                            <span class="status-pill">{{ __('ui.status.'.$displayStatus) }}</span>
                            <p class="mt-2 text-xs text-stone-400">{{ __('ui.common.due') }} {{ $entry->due_date->toDateString() }}</p>
                            @if ($entry->payoutExecution)
                                <p class="mt-2 text-xs text-sky-200">{{ __('ui.fields.execution') }} {{ __('ui.status.'.$entry->payoutExecution->status) }}</p>
                                @if ($entry->payoutExecution->approved_wallet_address)
                                    <p class="mt-2 break-all text-xs text-stone-400">{{ __('ui.pages.payroll.approved_by', ['wallet' => $entry->payoutExecution->approved_wallet_address]) }}</p>
                                @endif
                            @endif
                            @if ($entry->compensationAmendment)
                                <p class="mt-2 text-xs text-stone-400">{{ __('ui.fields.comp_effective') }} {{ $entry->compensationAmendment->effective_date->toDateString() }}</p>
                            @endif
                        </div>
                        <p class="break-all font-mono text-xs {{ $entry->tx_signature ? 'text-cyan-100' : 'text-stone-500' }}">{{ $entry->tx_signature ?: __('ui.common.not_set_yet') }}</p>
                        <a href="{{ route('employees.payroll.show', $entry->employee) }}" class="app-button app-button-secondary app-button-compact justify-self-end">
                            {{ __('ui.actions.statement') }}
                        </a>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
</x-layouts.app>
