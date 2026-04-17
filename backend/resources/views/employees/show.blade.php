<x-layouts.app :title="$employee->full_name . ' · Aster Payroll'">
    <section class="grid gap-6 lg:grid-cols-[1.05fr,0.95fr]">
        <div class="space-y-6">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur">
                <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">Employee Detail</p>
                <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-3xl font-semibold text-white">{{ $employee->full_name }}</h2>
                        <p class="mt-2 text-sm text-stone-300">{{ $employee->email }}</p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-xs text-stone-300">
                        <p>Status: <span class="text-white">{{ str($employee->employment_status)->title() }}</span></p>
                        <p class="mt-1">Pay cycle: <span class="text-white">{{ $payCycles[$employee->pay_cycle] ?? $employee->pay_cycle }}</span></p>
                        <p class="mt-1">Currency: <span class="text-white">{{ $employee->currency }}</span></p>
                    </div>
                </div>

                <dl class="mt-6 grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl border border-white/10 bg-stone-900/70 p-4">
                        <dt class="text-xs uppercase tracking-[0.25em] text-stone-400">Wallet</dt>
                        <dd class="mt-2 break-all font-mono text-xs text-stone-100">{{ $employee->wallet_address ?: 'Not set yet' }}</dd>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-stone-900/70 p-4">
                        <dt class="text-xs uppercase tracking-[0.25em] text-stone-400">Start date</dt>
                        <dd class="mt-2 text-sm text-stone-100">{{ optional($employee->start_date)->toDateString() ?: 'Not set yet' }}</dd>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-stone-900/70 p-4">
                        <dt class="text-xs uppercase tracking-[0.25em] text-stone-400">Current compensation</dt>
                        <dd class="mt-2 text-sm text-stone-100">
                            @if ($currentCompensation)
                                {{ number_format($currentCompensation->new_amount_minor / 100, 2) }} {{ $currentCompensation->currency }}
                            @else
                                Not set yet
                            @endif
                        </dd>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-stone-900/70 p-4">
                        <dt class="text-xs uppercase tracking-[0.25em] text-stone-400">Comp effective</dt>
                        <dd class="mt-2 text-sm text-stone-100">{{ $currentCompensation?->effective_date?->toDateString() ?: 'Record baseline pay first' }}</dd>
                    </div>
                </dl>

                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('employees.payroll.show', $employee) }}" class="rounded-full border border-white/10 px-5 py-3 text-sm font-medium text-white transition hover:border-cyan-300/60 hover:text-cyan-100">
                        Open payroll statement
                    </a>
                </div>
            </div>

            <div class="overflow-hidden rounded-3xl border border-white/10 bg-stone-900/70">
                <div class="border-b border-white/10 px-6 py-4">
                    <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">Compensation Timeline</p>
                    <h3 class="mt-2 text-xl font-semibold text-white">Salary history used by payroll drafts</h3>
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
                                        Effective {{ $amendment->effective_date->toDateString() }}
                                        @if ($amendment->contract)
                                            · Contract v{{ $amendment->contract->version }}
                                        @endif
                                    </p>
                                </div>
                                <div class="rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-xs text-stone-300">
                                    <p>Previous: <span class="text-white">{{ $amendment->previous_amount_minor !== null ? number_format($amendment->previous_amount_minor / 100, 2).' '.$amendment->currency : 'Baseline' }}</span></p>
                                    <p class="mt-1">Reason: <span class="text-white">{{ $amendment->reason ?: 'Not specified' }}</span></p>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="px-6 py-8 text-sm text-stone-400">
                            No compensation records yet. Record the baseline salary after uploading the contract.
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="overflow-hidden rounded-3xl border border-white/10 bg-stone-900/70">
                <div class="border-b border-white/10 px-6 py-4">
                    <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">Contracts</p>
                    <h3 class="mt-2 text-xl font-semibold text-white">Stored PDF + hash records</h3>
                </div>

                <div class="divide-y divide-white/10">
                    @forelse ($employee->contracts as $contract)
                        <article class="px-6 py-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-lg font-medium text-white">{{ $contract->title }}</p>
                                    <p class="mt-1 text-sm text-stone-400">Version {{ $contract->version }} · Effective {{ $contract->effective_date->toDateString() }} · {{ str($contract->status)->title() }}</p>
                                </div>
                                <a href="{{ route('contracts.download', $contract) }}" class="text-sm text-cyan-200 transition hover:text-cyan-100">Download PDF</a>
                            </div>
                            <div class="mt-4 rounded-2xl border border-white/10 bg-stone-950/70 p-4">
                                <p class="text-xs uppercase tracking-[0.25em] text-stone-500">SHA-256</p>
                                <p class="mt-2 break-all font-mono text-xs text-stone-100">{{ $contract->file_hash }}</p>
                                <p class="mt-3 text-xs text-stone-400">Stored privately in Laravel storage.</p>
                            </div>
                        </article>
                    @empty
                        <div class="px-6 py-8 text-sm text-stone-400">
                            No contract versions yet. Upload the first employment PDF on the right.
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="overflow-hidden rounded-3xl border border-white/10 bg-stone-900/70">
                <div class="border-b border-white/10 px-6 py-4">
                    <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">Payroll Statement</p>
                    <h3 class="mt-2 text-xl font-semibold text-white">Recent entries</h3>
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
                                        Batch {{ $entry->payrollBatch->period_year }}-{{ str_pad((string) $entry->payrollBatch->period_month, 2, '0', STR_PAD_LEFT) }}
                                        · Due {{ $entry->due_date->toDateString() }}
                                    </p>
                                </div>
                                <span class="rounded-full border border-white/10 px-3 py-1 text-xs uppercase tracking-[0.25em] text-cyan-100">
                                    {{ $entry->paid_at ? 'Paid' : ($entry->due_date->isPast() ? 'Overdue' : str($entry->status)->title()) }}
                                </span>
                            </div>

                            @if ($entry->tx_signature)
                                <p class="mt-4 break-all font-mono text-xs text-cyan-100">{{ $entry->tx_signature }}</p>
                            @endif
                        </article>
                    @empty
                        <div class="px-6 py-8 text-sm text-stone-400">
                            No payroll entries yet. Run the confidential payroll demo and import the receipt for this employee.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <form method="POST" action="{{ route('employees.contracts.store', $employee) }}" enctype="multipart/form-data" class="rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur">
                @csrf

                <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">Upload Contract PDF</p>
                <h3 class="mt-2 text-2xl font-semibold text-white">First business loop</h3>
                <p class="mt-2 text-sm leading-6 text-stone-300">
                    Upload the signed contract PDF, compute its SHA-256 hash on the server, and persist the metadata that
                    will later map to the on-chain employment contract PDA.
                </p>

                <div class="mt-6 space-y-5">
                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">Contract title</span>
                        <input type="text" name="title" value="{{ old('title', $employee->full_name . ' Employment Contract') }}" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60" required>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">Effective date</span>
                        <input type="date" name="effective_date" value="{{ old('effective_date', now()->toDateString()) }}" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60" required>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">Contract status</span>
                        <select name="status" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60">
                            @foreach (['draft' => 'Draft', 'active' => 'Active', 'superseded' => 'Superseded'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', 'active') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">PDF file</span>
                        <input type="file" name="contract_pdf" accept="application/pdf" class="block w-full rounded-2xl border border-dashed border-white/20 bg-stone-950/50 px-4 py-4 text-sm text-stone-300 file:mr-4 file:rounded-full file:border-0 file:bg-cyan-300 file:px-4 file:py-2 file:text-sm file:font-medium file:text-stone-950 hover:file:bg-cyan-200" required>
                    </label>
                </div>

                <button type="submit" class="mt-6 inline-flex rounded-full bg-cyan-300 px-5 py-3 text-sm font-medium text-stone-950 transition hover:bg-cyan-200">
                    Upload and hash contract
                </button>

                <p class="mt-6 text-xs leading-6 text-stone-400">
                    For the hackathon demo, this employee detail page now links directly to a scoped payroll statement.
                    The confidential transfer itself still runs through the dedicated payroll demo flow.
                </p>
            </form>

            <form method="POST" action="{{ route('employees.compensation-amendments.store', $employee) }}" class="rounded-3xl border border-white/10 bg-stone-900/70 p-6">
                @csrf

                <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">Compensation Update</p>
                <h3 class="mt-2 text-2xl font-semibold text-white">Record salary baseline or raise</h3>
                <p class="mt-2 text-sm leading-6 text-stone-300">
                    The next payroll batch draft will pull the latest effective compensation record for each active employee.
                </p>

                <div class="mt-6 space-y-5">
                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">Amount ({{ $employee->currency }})</span>
                        <input type="text" name="new_amount" value="{{ old('new_amount') }}" placeholder="2500.00" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60" required>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">Effective date</span>
                        <input type="date" name="effective_date" value="{{ old('effective_date', now()->toDateString()) }}" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60" required>
                    </label>

                    <label class="block space-y-2">
                        <span class="text-sm text-stone-200">Reason</span>
                        <input type="text" name="reason" value="{{ old('reason') }}" placeholder="Initial offer, promotion, annual review..." class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60">
                    </label>
                </div>

                @if ($latestContract)
                    <p class="mt-5 text-xs text-stone-400">
                        This update will link to contract version {{ $latestContract->version }}.
                    </p>

                    <button type="submit" class="mt-6 inline-flex rounded-full bg-amber-300 px-5 py-3 text-sm font-medium text-stone-950 transition hover:bg-amber-200">
                        Save compensation record
                    </button>
                @else
                    <p class="mt-5 text-xs text-amber-200">
                        Upload the first contract PDF before recording compensation.
                    </p>

                    <button type="button" disabled class="mt-6 inline-flex cursor-not-allowed rounded-full bg-stone-700 px-5 py-3 text-sm font-medium text-stone-300">
                        Upload contract first
                    </button>
                @endif
            </form>
        </div>
    </section>
</x-layouts.app>
