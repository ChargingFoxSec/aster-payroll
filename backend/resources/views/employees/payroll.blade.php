<x-layouts.app :title="$employee->full_name . ' Payroll · Aster Payroll'">
    <section class="space-y-6">
        <div class="rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur">
            <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ $scopeLabel ?? 'Employee Scope' }}</p>
            <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <h2 class="text-3xl font-semibold text-white">{{ $employee->full_name }}</h2>
                    <p class="mt-2 text-sm leading-6 text-stone-300">
                        This is the employee-scoped payroll statement view for the MVP. It only shows this employee’s
                        own payroll entries, due dates, payment status, and tx signatures.
                    </p>
                </div>

                <a href="{{ $backUrl ?? route('employees.show', $employee) }}" class="rounded-full border border-white/10 px-5 py-3 text-sm font-medium text-white transition hover:border-cyan-300/60 hover:text-cyan-100">
                    {{ $backLabel ?? 'Back to employee' }}
                </a>
            </div>
        </div>

        <div class="overflow-hidden rounded-3xl border border-white/10 bg-stone-900/70">
            <div class="grid grid-cols-[0.85fr,0.85fr,0.75fr,1.55fr] gap-4 border-b border-white/10 px-6 py-4 text-xs uppercase tracking-[0.25em] text-stone-500">
                <span>Batch</span>
                <span>Amount</span>
                <span>Status</span>
                <span>Tx Signature</span>
            </div>

            <div class="divide-y divide-white/10">
                @forelse ($employee->payrollEntries as $entry)
                    @php($displayStatus = $entry->paid_at ? 'paid' : ($entry->due_date->isPast() ? 'overdue' : $entry->status))
                    <article class="grid gap-4 px-6 py-5 lg:grid-cols-[0.85fr,0.85fr,0.75fr,1.55fr] lg:items-center">
                        <div>
                            <p class="text-lg font-medium text-white">{{ $entry->payrollBatch->period_year }}-{{ str_pad((string) $entry->payrollBatch->period_month, 2, '0', STR_PAD_LEFT) }}</p>
                            <p class="mt-1 text-sm text-stone-400">Due {{ $entry->due_date->toDateString() }}</p>
                        </div>
                        <p class="text-lg font-semibold text-white">{{ number_format($entry->amount_minor / 100, 2) }} {{ $entry->currency }}</p>
                        <div>
                            <span class="rounded-full border border-white/10 px-3 py-1 text-xs uppercase tracking-[0.25em] text-cyan-100">{{ str($displayStatus)->replace('_', ' ')->title() }}</span>
                            @if ($entry->paid_at)
                                <p class="mt-2 text-xs text-stone-400">Paid {{ $entry->paid_at->toDateTimeString() }}</p>
                            @endif
                        </div>
                        <p class="break-all font-mono text-xs {{ $entry->tx_signature ? 'text-cyan-100' : 'text-stone-500' }}">{{ $entry->tx_signature ?: 'No tx yet' }}</p>
                    </article>
                @empty
                    <div class="px-6 py-8 text-sm text-stone-400">
                        No payroll entries imported for this employee yet.
                    </div>
                @endforelse
            </div>
        </div>
    </section>
</x-layouts.app>
