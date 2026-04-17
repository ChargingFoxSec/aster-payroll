<x-layouts.app :title="'Payroll Batch · Aster Payroll'">
    <section class="space-y-6">
        <div class="rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur">
            <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">Batch Detail</p>
            <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h2 class="text-3xl font-semibold text-white">{{ $payrollBatch->period_year }}-{{ str_pad((string) $payrollBatch->period_month, 2, '0', STR_PAD_LEFT) }}</h2>
                    <p class="mt-2 text-sm leading-6 text-stone-300">
                        Drafted off-chain and updated by the confidential settlement demo. This is the ledger view you
                        can show to judges after the on-chain confidential transfer finishes.
                    </p>
                </div>

                <div class="flex flex-col gap-3 lg:items-end">
                    <div class="rounded-2xl border border-white/10 bg-stone-950/70 px-4 py-3 text-xs text-stone-300">
                        <p>Status: <span class="text-white">{{ str($payrollBatch->status)->replace('_', ' ')->title() }}</span></p>
                        <p class="mt-1">Due date: <span class="text-white">{{ $payrollBatch->due_date->toDateString() }}</span></p>
                        <p class="mt-1">Executed at: <span class="text-white">{{ optional($payrollBatch->executed_at)->toDateTimeString() ?: 'Not set' }}</span></p>
                    </div>

                    <a href="{{ route('payroll-demo.show') }}" class="rounded-full border border-white/10 px-5 py-3 text-sm font-medium text-white transition hover:border-cyan-300/60 hover:text-cyan-100">
                        Open confidential settlement
                    </a>
                </div>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-3">
                <div class="rounded-2xl border border-white/10 bg-stone-900/70 p-4">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-500">Total</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ number_format($payrollBatch->total_amount_minor / 100, 2) }}</p>
                    <p class="mt-2 text-sm text-stone-300">{{ $payrollBatch->currency }}</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-stone-900/70 p-4">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-500">Entries</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $payrollBatch->entries->count() }}</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-stone-900/70 p-4">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-500">Paid entries</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $payrollBatch->entries->whereNotNull('paid_at')->count() }}</p>
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-3xl border border-white/10 bg-stone-900/70">
            <div class="grid grid-cols-[1fr,0.75fr,0.75fr,1.25fr,0.5fr] gap-4 border-b border-white/10 px-6 py-4 text-xs uppercase tracking-[0.25em] text-stone-500">
                <span>Employee</span>
                <span>Amount</span>
                <span>Status</span>
                <span>Tx Signature</span>
                <span></span>
            </div>

            <div class="divide-y divide-white/10">
                @foreach ($payrollBatch->entries as $entry)
                    <article class="grid gap-4 px-6 py-5 lg:grid-cols-[1fr,0.75fr,0.75fr,1.25fr,0.5fr] lg:items-center">
                        <div>
                            <p class="text-lg font-medium text-white">{{ $entry->employee->full_name }}</p>
                            <p class="mt-1 text-sm text-stone-400">{{ $entry->employee->email }}</p>
                        </div>
                        <p class="text-lg font-semibold text-white">{{ number_format($entry->amount_minor / 100, 2) }} {{ $entry->currency }}</p>
                        <div>
                            @php($displayStatus = $entry->paid_at ? 'paid' : ($entry->due_date->isPast() ? 'overdue' : $entry->status))
                            <span class="rounded-full border border-white/10 px-3 py-1 text-xs uppercase tracking-[0.25em] text-cyan-100">{{ str($displayStatus)->replace('_', ' ')->title() }}</span>
                            <p class="mt-2 text-xs text-stone-400">Due {{ $entry->due_date->toDateString() }}</p>
                            @if ($entry->payoutExecution)
                                <p class="mt-2 text-xs text-sky-200">Execution {{ str($entry->payoutExecution->status)->replace('_', ' ')->title() }}</p>
                                @if ($entry->payoutExecution->approved_wallet_address)
                                    <p class="mt-2 break-all text-xs text-stone-400">Approved by {{ $entry->payoutExecution->approved_wallet_address }}</p>
                                @endif
                            @endif
                            @if ($entry->compensationAmendment)
                                <p class="mt-2 text-xs text-stone-400">Comp effective {{ $entry->compensationAmendment->effective_date->toDateString() }}</p>
                            @endif
                        </div>
                        <p class="break-all font-mono text-xs {{ $entry->tx_signature ? 'text-cyan-100' : 'text-stone-500' }}">{{ $entry->tx_signature ?: 'No tx yet' }}</p>
                        <a href="{{ route('employees.payroll.show', $entry->employee) }}" class="text-sm text-cyan-200 transition hover:text-cyan-100">Statement</a>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
</x-layouts.app>
