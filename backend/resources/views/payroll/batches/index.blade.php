<x-layouts.app :title="'Payroll Ledger · Aster Payroll'">
    <section class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-[1fr,0.9fr]">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur">
                <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">Payroll Ledger</p>
                <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 class="text-3xl font-semibold text-white">{{ $company->name }}</h2>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-stone-300">
                            Draft the monthly payroll batch off-chain first, then use the confidential settlement demo to
                            attach real private transfer signatures to the matching entries.
                        </p>
                    </div>

                    <a href="{{ route('payroll-demo.show') }}" class="rounded-full bg-cyan-300 px-5 py-3 text-sm font-medium text-stone-950 transition hover:bg-cyan-200">
                        Run another confidential payroll
                    </a>
                </div>
            </div>

            <form method="POST" action="{{ route('payroll-batches.store') }}" class="rounded-3xl border border-white/10 bg-stone-900/70 p-6">
                @csrf

                <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">Create Batch Draft</p>
                <h3 class="mt-2 text-2xl font-semibold text-white">Prepare the next payroll cycle</h3>
                <p class="mt-2 text-sm leading-6 text-stone-300">
                    Uses the latest effective compensation record for each active employee. No public transfer is created here.
                </p>

                <div class="mt-6 grid gap-5 md:grid-cols-2">
                    <label class="space-y-2">
                        <span class="text-sm text-stone-200">Payroll period</span>
                        <input type="month" name="period" value="{{ old('period', $defaultPeriod) }}" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60" required>
                    </label>

                    <label class="space-y-2">
                        <span class="text-sm text-stone-200">Due date</span>
                        <input type="date" name="due_date" value="{{ old('due_date', $defaultDueDate) }}" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60" required>
                    </label>
                </div>

                <button type="submit" class="mt-6 inline-flex rounded-full bg-amber-300 px-5 py-3 text-sm font-medium text-stone-950 transition hover:bg-amber-200">
                    Draft payroll batch
                </button>
            </form>
        </div>

        <div class="overflow-hidden rounded-3xl border border-white/10 bg-stone-900/70">
            <div class="grid grid-cols-[1.25fr,0.75fr,0.75fr,0.75fr,0.5fr] gap-4 border-b border-white/10 px-6 py-4 text-xs uppercase tracking-[0.25em] text-stone-500">
                <span>Batch</span>
                <span>Total</span>
                <span>Status</span>
                <span>Due</span>
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
                            <p class="mt-1 text-sm text-stone-400">{{ $batch->entries_count }} entries · {{ optional($batch->executed_at)->toDateTimeString() ?: 'Not executed yet' }}</p>
                        </div>
                        <p class="text-lg font-semibold text-white">{{ number_format($batch->total_amount_minor / 100, 2) }} {{ $batch->currency }}</p>
                        <div>
                            <span class="rounded-full border border-white/10 px-3 py-1 text-xs uppercase tracking-[0.25em] text-cyan-100">{{ str($batch->status)->replace('_', ' ')->title() }}</span>
                            @if ($overdueCount > 0)
                                <p class="mt-2 text-xs text-amber-200">{{ $overdueCount }} overdue entries</p>
                            @endif
                            @if ($awaitingApprovalCount > 0)
                                <p class="mt-2 text-xs text-sky-200">{{ $awaitingApprovalCount }} awaiting signer approval</p>
                            @endif
                        </div>
                        <p class="text-sm text-stone-200">{{ $batch->due_date->toDateString() }}</p>
                        <a href="{{ route('payroll-batches.show', $batch) }}" class="text-sm text-cyan-200 transition hover:text-cyan-100">Open</a>
                    </article>
                @empty
                    <div class="px-6 py-8 text-sm text-stone-400">
                        No payroll batches yet. Record compensation first, then draft the first batch or import a confidential receipt.
                    </div>
                @endforelse
            </div>
        </div>
    </section>
</x-layouts.app>
