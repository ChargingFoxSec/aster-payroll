<x-layouts.app :title="__('ui.pages.demo.title')">
    @php
        $batchEntries = $selectedBatch?->entries ?? collect();
        $executions = $batchEntries
            ->map(fn ($entry) => $entry->payoutExecution)
            ->filter()
            ->sortByDesc('updated_at')
            ->values();
        $awaitingApprovalExecutions = $executions->filter(
            fn ($execution) => $execution->status === \App\Models\PayoutExecution::STATUS_AWAITING_APPROVAL
        );
        $importedExecutions = $executions->filter(
            fn ($execution) => $execution->status === \App\Models\PayoutExecution::STATUS_IMPORTED
        );
        $failedExecutions = $executions->filter(
            fn ($execution) => $execution->status === \App\Models\PayoutExecution::STATUS_FAILED
        );
        $importableExecutions = $executions->filter(
            fn ($execution) => in_array($execution->status, [
                \App\Models\PayoutExecution::STATUS_AWAITING_APPROVAL,
                \App\Models\PayoutExecution::STATUS_FAILED,
            ], true)
        );
        $batchOptionLabel = fn (\App\Models\PayrollBatch $batch): string => sprintf('%d-%02d', $batch->period_year, $batch->period_month)
            .' · '.__('ui.status.'.$batch->status)
            .' · '.$batch->entries_count.' '.__('ui.common.entries');
    @endphp

    <section class="space-y-6">
        <div class="panel panel-hero p-6">
            <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ __('ui.pages.demo.kicker') }}</p>
            <h2 class="mt-2 text-3xl font-semibold text-white">{{ __('ui.pages.demo.heading') }}</h2>
            <p class="mt-3 text-sm leading-6 text-stone-300">
                {{ __('ui.pages.demo.copy') }}
            </p>

            <div class="panel-inset settlement-flow mt-6 text-sm text-stone-300">
                <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.pages.demo.operator_boundary') }}</p>
                <p class="mt-2 leading-6 text-stone-300">
                    {{ __('ui.pages.demo.operator_copy') }}
                </p>
                <div class="settlement-flow-steps mt-5">
                    @foreach ([
                        ['number' => '1', 'title' => __('ui.pages.demo.flow_draft_title'), 'copy' => __('ui.pages.demo.flow_draft_copy')],
                        ['number' => '2', 'title' => __('ui.pages.demo.flow_prepare_title'), 'copy' => __('ui.pages.demo.flow_prepare_copy')],
                        ['number' => '3', 'title' => __('ui.pages.demo.flow_sign_title'), 'copy' => __('ui.pages.demo.flow_sign_copy')],
                        ['number' => '4', 'title' => __('ui.pages.demo.flow_import_title'), 'copy' => __('ui.pages.demo.flow_import_copy')],
                    ] as $flowStep)
                        <div class="settlement-flow-step">
                            <span class="settlement-flow-number">{{ $flowStep['number'] }}</span>
                            <div>
                                <p class="font-semibold text-white">{{ $flowStep['title'] }}</p>
                                <p class="mt-1 text-xs leading-5 text-stone-400">{{ $flowStep['copy'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="mt-5 text-xs leading-5 text-stone-400">
                    {{ __('ui.pages.demo.operator_note') }}
                </p>
            </div>

            <form method="POST" action="{{ route('payroll-demo.prepare') }}" class="mt-6">
                @csrf
                <input type="hidden" name="payroll_batch_id" value="{{ old('payroll_batch_id', $selectedBatch?->id) }}">
                <div class="space-y-5">
                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.payroll_batch') }}</span>
                        @if ($selectedBatch)
                            <details class="batch-jump-menu">
                                <summary class="batch-jump-trigger app-field px-4 py-3">
                                    <span>{{ $batchOptionLabel($selectedBatch) }}</span>
                                    <span class="batch-jump-caret" aria-hidden="true">v</span>
                                </summary>
                                <div class="batch-jump-options">
                                    @foreach ($batches as $batch)
                                        <a
                                            href="{{ route('payroll-demo.show', ['payroll_batch_id' => $batch->id]) }}"
                                            @class([
                                                'batch-jump-option',
                                                'batch-jump-option-active' => $selectedBatch->id === $batch->id,
                                            ])
                                            @if ($selectedBatch->id === $batch->id) aria-current="page" @endif
                                        >
                                            {{ $batchOptionLabel($batch) }}
                                        </a>
                                    @endforeach
                                </div>
                            </details>
                        @else
                            <div class="app-field px-4 py-3 text-stone-500">{{ __('ui.pages.demo.draft_first') }}</div>
                        @endif
                    </label>
                </div>

                <button type="submit" @disabled($batches->isEmpty()) class="app-button app-button-primary mt-6">
                    {{ __('ui.actions.prepare_manifests') }}
                </button>
            </form>

            <form method="POST" action="{{ route('payroll-demo.import') }}" enctype="multipart/form-data" class="panel-inset mt-8 p-5">
                @csrf
                <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">{{ __('ui.pages.demo.import_signed_receipt') }}</p>
                <p class="mt-2 text-sm leading-6 text-stone-300">
                    {{ __('ui.pages.demo.import_copy') }}
                </p>

                <div class="mt-5 space-y-5">
                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.prepared_payout') }}</span>
                        <select name="payout_execution_id" class="app-field px-4 py-3" required>
                            @forelse ($importableExecutions as $execution)
                                <option value="{{ $execution->id }}">
                                    #{{ $execution->id }}
                                    · {{ $execution->employee->full_name }}
                                    · {{ number_format($execution->payrollEntry->amount_minor / 100, 2) }} {{ $execution->payrollEntry->currency }}
                                    · {{ __('ui.status.'.$execution->status) }}
                                </option>
                            @empty
                                <option value="">{{ __('ui.pages.demo.no_prepared') }}</option>
                            @endforelse
                        </select>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.receipt_json') }}</span>
                        <input type="file" name="receipt" accept=".json,text/plain,application/json" class="app-field app-file-field px-4 py-3 text-sm text-white" required>
                    </label>
                </div>

                <button type="submit" @disabled($importableExecutions->isEmpty()) class="app-button app-button-amber mt-6">
                    {{ __('ui.actions.import_receipt') }}
                </button>
            </form>

            <ol class="mt-6 space-y-3 text-sm leading-6 text-stone-300">
                <li>{{ __('ui.pages.demo.step_1') }}</li>
                <li>{{ __('ui.pages.demo.step_2') }} <code class="rounded bg-white/10 px-2 py-1 text-xs">./scripts/start-confidential-validator.sh</code>.</li>
                <li>{{ __('ui.pages.demo.step_3') }}</li>
                <li>{{ __('ui.pages.demo.step_4') }}</li>
            </ol>
        </div>

        <div class="panel panel-soft p-6">
            <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">{{ __('ui.pages.demo.progress') }}</p>

            @if ($selectedBatch)
                <div class="mt-5 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.fields.focused_batch') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-white">
                            {{ $selectedBatch->period_year }}-{{ str_pad((string) $selectedBatch->period_month, 2, '0', STR_PAD_LEFT) }}
                        </p>
                        <p class="mt-2 text-sm text-stone-300">
                            {{ __('ui.status.'.$selectedBatch->status) }}
                            · {{ __('ui.common.due') }} {{ $selectedBatch->due_date->toDateString() }}
                        </p>
                    </div>

                    <a href="{{ route('payroll-batches.show', $selectedBatch) }}" class="app-button app-button-secondary">
                        {{ __('ui.actions.open_batch_detail') }}
                    </a>
                </div>

                <div class="mt-6 grid gap-4 md:grid-cols-4">
                    <div class="panel-inset p-4">
                        <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.common.entries') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-white">{{ $batchEntries->count() }}</p>
                    </div>
                    <div class="panel-inset p-4">
                        <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.status.awaiting_approval') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-white">{{ $awaitingApprovalExecutions->count() }}</p>
                    </div>
                    <div class="panel-inset p-4">
                        <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.common.imported') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-white">{{ $importedExecutions->count() }}</p>
                    </div>
                    <div class="panel-inset p-4">
                        <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.common.failed') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-white">{{ $failedExecutions->count() }}</p>
                    </div>
                </div>

                <div class="panel-inset mt-6 p-4 text-sm text-stone-300">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.pages.demo.onchain_traceability') }}</p>
                    <p class="mt-3 text-xs uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.batch_account') }}</p>
                    <p class="mt-1 break-all font-mono text-xs text-cyan-100">{{ $selectedBatch->anchor_batch_pubkey ?: __('ui.common.pending') }}</p>

                    <p class="mt-4 text-xs uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.entries_root') }}</p>
                    <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $selectedBatch->entries_root ?: __('ui.common.pending') }}</p>

                    <p class="mt-4 text-xs uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.batch_commit_tx') }}</p>
                    <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $selectedBatch->latestCommitAttestation?->tx_signature ?: __('ui.common.pending') }}</p>

                    <p class="mt-4 text-xs uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.approval_root') }}</p>
                    <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $selectedBatch->approval_root ?: __('ui.common.pending') }}</p>

                    <p class="mt-4 text-xs uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.batch_approval_tx') }}</p>
                    <p class="mt-1 break-all font-mono text-xs {{ $selectedBatch->latestApprovalAttestation ? 'text-cyan-100' : 'text-stone-300' }}">{{ $selectedBatch->latestApprovalAttestation?->tx_signature ?: __('ui.common.pending') }}</p>

                    <p class="mt-4 text-xs uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.settlement_root') }}</p>
                    <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $selectedBatch->settlement_root ?: ($selectedBatch->status === \App\Models\PayrollBatch::STATUS_EXECUTED ? __('ui.common.pending_executed_attestation') : __('ui.common.not_executed_yet')) }}</p>

                    <p class="mt-4 text-xs uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.batch_finalization_tx') }}</p>
                    <p class="mt-1 break-all font-mono text-xs {{ $selectedBatch->latestFinalizationAttestation ? 'text-cyan-100' : 'text-stone-300' }}">
                        {{ $selectedBatch->latestFinalizationAttestation?->tx_signature ?: ($selectedBatch->status === \App\Models\PayrollBatch::STATUS_EXECUTED ? __('ui.common.pending_executed_attestation') : __('ui.common.not_executed_yet')) }}
                    </p>

                    <p class="mt-4 text-xs uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.finalized_by') }}</p>
                    <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $selectedBatch->finalized_by ?: __('ui.common.pending') }}</p>
                </div>

                <div class="mt-6 space-y-3">
                    @foreach ($batchEntries as $entry)
                        @php($execution = $entry->payoutExecution)
                        @php($receiptSummary = $execution ? ($receiptSummaries[$execution->id] ?? null) : null)
                        <div class="panel-inset p-4">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ $entry->employee->full_name }}</p>
                                    <p class="mt-2 text-sm text-stone-100">
                                        {{ number_format($entry->amount_minor / 100, 2) }} {{ $entry->currency }}
                                        · {{ __('ui.common.due') }} {{ $entry->due_date->toDateString() }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span class="status-pill">{{ __('ui.status.'.$entry->status) }}</span>
                                    <span class="status-pill">{{ $execution ? __('ui.status.'.$execution->status) : __('ui.common.not_prepared') }}</span>
                                </div>
                            </div>

                            @if ($execution?->prepared_payload_path)
                                <div class="mt-3">
                                    <a href="{{ route('payroll-demo.executions.manifest', $execution) }}" class="inline-link text-xs">{{ __('ui.actions.download_manifest_json') }}</a>
                                </div>
                            @endif

                            @if ($execution?->prepared_payload_hash)
                                <p class="mt-3 text-[11px] uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.prepared_manifest_hash') }}</p>
                                <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $execution->prepared_payload_hash }}</p>
                            @endif

                            @if ($execution?->approved_wallet_address)
                                <p class="mt-3 text-[11px] uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.approving_wallet') }}</p>
                                <p class="mt-1 break-all font-mono text-xs text-sky-100">{{ $execution->approved_wallet_address }}</p>
                            @endif

                            @if ($execution?->tx_signature)
                                <p class="mt-3 text-[11px] uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.imported_tx_signature') }}</p>
                                <p class="mt-1 break-all font-mono text-xs text-cyan-100">{{ $execution->tx_signature }}</p>
                                <p class="mt-3 text-[11px] uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.receipt_verified_at') }}</p>
                                <p class="mt-1 font-mono text-xs text-emerald-100">{{ optional($execution->receipt_verified_at)->toDateTimeString() ?: __('ui.common.pending') }}</p>
                                @if ($execution->receipt_hash)
                                    <p class="mt-3 text-[11px] uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.receipt_hash') }}</p>
                                    <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $execution->receipt_hash }}</p>
                                @endif
                            @elseif ($entry->tx_signature)
                                <p class="mt-3 text-[11px] uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.entry_tx_signature') }}</p>
                                <p class="mt-1 break-all font-mono text-xs text-cyan-100">{{ $entry->tx_signature }}</p>
                            @endif

                            @if ($receiptSummary)
                                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <p class="text-[11px] uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.receipt_confidential_amount') }}</p>
                                        <p class="mt-1 font-mono text-xs text-emerald-100">
                                            {{ $receiptSummary['amount_minor'] !== null ? number_format($receiptSummary['amount_minor'] / 100, 2).' '.$entry->currency : __('ui.common.not_reported') }}
                                            @if ($receiptSummary['confidential_transfer_amount'] !== null)
                                                <span class="text-stone-500">{{ __('ui.pages.demo.private_units', ['amount' => $receiptSummary['confidential_transfer_amount']]) }}</span>
                                            @endif
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-[11px] uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.employee_public_balance') }}</p>
                                        <p class="mt-1 font-mono text-xs text-stone-300">{{ $receiptSummary['employee_public_balance'] ?? __('ui.common.hidden_not_reported') }}</p>
                                    </div>
                                </div>
                            @endif

                            @if ($execution?->failure_reason)
                                <p class="mt-3 text-[11px] uppercase tracking-[0.2em] text-stone-500">{{ __('ui.fields.failure_status') }}</p>
                                <p class="mt-1 text-xs text-rose-200">{{ $execution->failure_reason }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm leading-6 text-stone-300">
                    {{ __('ui.pages.demo.no_batch') }}
                </p>
            @endif
        </div>
    </section>
</x-layouts.app>
