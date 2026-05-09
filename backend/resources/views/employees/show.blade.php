<x-layouts.app :title="__('ui.pages.employees.detail_title', ['employee' => $employee->full_name])">
    <section class="grid gap-6 lg:grid-cols-[1.05fr_0.95fr]">
        <div class="space-y-6">
            <div class="panel panel-hero p-6">
                <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ __('ui.pages.employees.detail_kicker') }}</p>
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
                        <dd class="mt-2 text-sm text-stone-100">{{ $currentCompensation?->effective_date?->toDateString() ?: __('ui.pages.employees.record_baseline_first') }}</dd>
                    </div>
                    <div class="metric-tile">
                        <dt class="text-xs uppercase tracking-[0.25em] text-stone-400">{{ __('ui.pages.employees.portal_access') }}</dt>
                        <dd class="mt-2 text-sm text-stone-100">
                            {{ $employee->user ? __('ui.common.provisioned') : __('ui.common.not_provisioned') }}
                        </dd>
                        @if ($employee->user)
                            <p class="mt-2 break-all font-mono text-xs text-stone-400">{{ $employee->user->email }}</p>
                        @endif
                    </div>
                </dl>

                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('employees.payroll.show', $employee) }}" class="app-button app-button-secondary">
                        {{ __('ui.actions.open_payroll_statement') }}
                    </a>
                </div>
            </div>

            <div class="table-shell">
                <div class="border-b border-white/10 px-6 py-4">
                    <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">{{ __('ui.pages.employees.comp_timeline') }}</p>
                    <h3 class="mt-2 text-xl font-semibold text-white">{{ __('ui.pages.employees.salary_history_heading') }}</h3>
                </div>

                <div class="divide-y divide-white/10">
                    @forelse ($employee->compensationAmendments as $amendment)
                        <article class="px-6 py-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-lg font-medium text-white">
                                        {{ number_format($amendment->new_amount_minor / 100, 2) }} {{ $amendment->currency }}
                                    </p>
                                    <p class="mt-1 text-sm text-stone-400">
                                        {{ __('ui.common.effective') }} {{ $amendment->effective_date->toDateString() }}
                                        @if ($amendment->contract)
                                            · Contract v{{ $amendment->contract->version }}
                                        @endif
                                    </p>
                                </div>
                                <div class="meta-chip px-4 py-3 text-xs text-stone-300">
                                    <p>{{ __('ui.fields.previous') }}: <span class="text-white">{{ $amendment->previous_amount_minor !== null ? number_format($amendment->previous_amount_minor / 100, 2).' '.$amendment->currency : __('ui.pages.employees.baseline') }}</span></p>
                                    <p class="mt-1">{{ __('ui.fields.reason') }}: <span class="text-white">{{ $amendment->reason ?: __('ui.common.not_specified') }}</span></p>
                                    <p class="mt-1">{{ __('ui.fields.anchor_account') }}: <span class="break-all text-white">{{ $amendment->anchor_amendment_pubkey ?: __('ui.common.pending') }}</span></p>
                                    <p class="mt-1">{{ __('ui.fields.anchor_tx') }}: <span class="break-all text-white">{{ $amendment->latestAttestation?->tx_signature ?: __('ui.common.pending') }}</span></p>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="px-6 py-8 text-sm text-stone-400">
                            {{ __('ui.pages.employees.no_comp_records') }}
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="table-shell">
                <div class="border-b border-white/10 px-6 py-4">
                    <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">{{ __('ui.fields.contracts') }}</p>
                    <h3 class="mt-2 text-xl font-semibold text-white">{{ __('ui.pages.employees.contracts_heading') }}</h3>
                </div>

                <div class="divide-y divide-white/10">
                    @forelse ($employee->contracts as $contract)
                        <article class="px-6 py-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-lg font-medium text-white">{{ $contract->title }}</p>
                                    <p class="mt-1 text-sm text-stone-400">{{ __('ui.fields.version') }} {{ $contract->version }} · {{ __('ui.common.effective') }} {{ $contract->effective_date->toDateString() }} · {{ __('ui.status.'.$contract->status) }}</p>
                                </div>
                                <a href="{{ route('contracts.download', $contract) }}" class="inline-link text-sm">{{ __('ui.actions.download_pdf') }}</a>
                            </div>
                            <div class="panel-inset mt-4 p-4">
                                <p class="text-xs uppercase tracking-[0.25em] text-stone-500">SHA-256</p>
                                <p class="mt-2 break-all font-mono text-xs text-stone-100">{{ $contract->file_hash }}</p>
                                <p class="mt-3 text-xs text-stone-400">{{ __('ui.pages.employees.stored_privately') }}</p>
                                <p class="mt-3 text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.fields.anchor_account') }}</p>
                                <p class="mt-2 break-all font-mono text-xs text-cyan-100">{{ $contract->anchor_contract_pubkey ?: __('ui.pages.employees.pending_contract_anchor') }}</p>
                                <p class="mt-3 text-xs uppercase tracking-[0.25em] text-stone-500">{{ __('ui.fields.anchor_tx') }}</p>
                                <p class="mt-2 break-all font-mono text-xs text-stone-300">{{ $contract->latestAttestation?->tx_signature ?: __('ui.common.pending') }}</p>
                            </div>
                        </article>
                    @empty
                        <div class="px-6 py-8 text-sm text-stone-400">
                            {{ __('ui.pages.employees.no_contracts') }}
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="table-shell">
                <div class="border-b border-white/10 px-6 py-4">
                    <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ __('ui.pages.employees.statement_kicker') }}</p>
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
                            {{ __('ui.pages.employees.no_entries') }}
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <form method="POST" action="{{ route('employees.contracts.store', $employee) }}" enctype="multipart/form-data" class="panel panel-hero p-6">
                @csrf

                <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ __('ui.pages.employees.upload_contract_pdf') }}</p>
                <h3 class="mt-2 text-2xl font-semibold text-white">{{ __('ui.pages.employees.first_business_loop') }}</h3>
                <p class="mt-2 text-sm leading-6 text-stone-300">
                    {{ __('ui.pages.employees.upload_copy') }}
                </p>

                <div class="mt-6 space-y-5">
                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.contract_title') }}</span>
                        <input type="text" name="title" value="{{ old('title', $employee->full_name . ' Employment Contract') }}" class="app-field px-4 py-3" required>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.effective_date') }}</span>
                        <input type="date" name="effective_date" value="{{ old('effective_date', now()->toDateString()) }}" class="app-field px-4 py-3" required>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.contract_status') }}</span>
                        <select name="status" class="app-field px-4 py-3">
                            @foreach (['draft', 'active', 'superseded'] as $value)
                                <option value="{{ $value }}" @selected(old('status', 'active') === $value)>{{ __('ui.status.'.$value) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.pdf_file') }}</span>
                        <input type="file" name="contract_pdf" accept="application/pdf" class="app-field app-file-field block px-4 py-4 text-sm text-stone-300" required>
                    </label>
                </div>

                <button type="submit" class="app-button app-button-primary mt-6">
                    {{ __('ui.actions.upload_and_hash_contract') }}
                </button>

                <p class="mt-6 text-xs leading-6 text-stone-400">
                    {{ __('ui.pages.employees.detail_note') }}
                </p>
            </form>

            <form method="POST" action="{{ route('employees.compensation-amendments.store', $employee) }}" class="panel panel-soft p-6">
                @csrf

                <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">{{ __('ui.pages.employees.compensation_update') }}</p>
                <h3 class="mt-2 text-2xl font-semibold text-white">{{ __('ui.pages.employees.record_salary') }}</h3>
                <p class="mt-2 text-sm leading-6 text-stone-300">
                    {{ __('ui.pages.employees.record_salary_copy') }}
                </p>

                <div class="mt-6 space-y-5">
                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.amount_with_currency', ['currency' => $employee->currency]) }}</span>
                        <input type="text" name="new_amount" value="{{ old('new_amount') }}" placeholder="2500.00" class="app-field px-4 py-3" required>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.effective_date') }}</span>
                        <input type="date" name="effective_date" value="{{ old('effective_date', now()->toDateString()) }}" class="app-field px-4 py-3" required>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">{{ __('ui.fields.reason') }}</span>
                        <input type="text" name="reason" value="{{ old('reason') }}" placeholder="Initial offer, promotion, annual review..." class="app-field px-4 py-3">
                    </label>
                </div>

                @if ($latestContract)
                    <p class="mt-5 text-xs text-stone-400">
                        {{ __('ui.pages.employees.link_to_contract', ['version' => $latestContract->version]) }}
                    </p>

                    <button type="submit" class="app-button app-button-amber mt-6">
                        {{ __('ui.actions.save_compensation') }}
                    </button>
                @else
                    <p class="mt-5 text-xs text-amber-200">
                        {{ __('ui.pages.employees.upload_contract_before_comp') }}
                    </p>

                    <button type="button" disabled class="app-button app-button-secondary mt-6">
                        {{ __('ui.actions.upload_contract_first') }}
                    </button>
                @endif
            </form>
        </div>
    </section>
</x-layouts.app>
