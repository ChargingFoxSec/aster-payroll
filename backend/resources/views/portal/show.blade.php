<x-layouts.app :title="'My Portal · Aster Payroll'">
    <section class="grid gap-6 lg:grid-cols-[1.05fr,0.95fr]">
        <div class="space-y-6">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur">
                <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">Employee Self Service</p>
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
                        <dd class="mt-2 text-sm text-stone-100">{{ $currentCompensation?->effective_date?->toDateString() ?: 'Not set yet' }}</dd>
                    </div>
                </dl>

                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('portal.payroll') }}" class="rounded-full border border-white/10 px-5 py-3 text-sm font-medium text-white transition hover:border-cyan-300/60 hover:text-cyan-100">
                        Open my payroll history
                    </a>
                </div>
            </div>

            <div class="overflow-hidden rounded-3xl border border-white/10 bg-stone-900/70">
                <div class="border-b border-white/10 px-6 py-4">
                    <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">Payroll History</p>
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
                            No payroll entries yet. Your payroll history will appear here once the admin imports payout receipts.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-3xl border border-white/10 bg-stone-900/70 p-6">
                <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">Contract Summary</p>
                <h3 class="mt-2 text-2xl font-semibold text-white">Current employment record</h3>
                <p class="mt-2 text-sm leading-6 text-stone-300">
                    This self-service portal shows the latest contract metadata and payroll history, but direct PDF
                    downloads remain restricted to the admin account in this hackathon pass.
                </p>

                @if ($latestContract)
                    <div class="mt-6 space-y-4">
                        <div class="rounded-2xl border border-white/10 bg-stone-950/70 p-4">
                            <p class="text-xs uppercase tracking-[0.25em] text-stone-500">Title</p>
                            <p class="mt-2 text-sm text-white">{{ $latestContract->title }}</p>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="rounded-2xl border border-white/10 bg-stone-950/70 p-4">
                                <p class="text-xs uppercase tracking-[0.25em] text-stone-500">Version</p>
                                <p class="mt-2 text-lg font-semibold text-white">{{ $latestContract->version }}</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-stone-950/70 p-4">
                                <p class="text-xs uppercase tracking-[0.25em] text-stone-500">Status</p>
                                <p class="mt-2 text-lg font-semibold text-white">{{ str($latestContract->status)->title() }}</p>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-stone-950/70 p-4">
                            <p class="text-xs uppercase tracking-[0.25em] text-stone-500">Effective date</p>
                            <p class="mt-2 text-sm text-white">{{ $latestContract->effective_date->toDateString() }}</p>
                        </div>
                        <div class="rounded-2xl border border-white/10 bg-stone-950/70 p-4">
                            <p class="text-xs uppercase tracking-[0.25em] text-stone-500">SHA-256</p>
                            <p class="mt-2 break-all font-mono text-xs text-stone-100">{{ $latestContract->file_hash }}</p>
                        </div>
                    </div>
                @else
                    <p class="mt-6 text-sm leading-6 text-stone-300">
                        No contract metadata is available yet. The admin needs to upload the first employment contract PDF.
                    </p>
                @endif
            </div>
        </div>
    </section>
</x-layouts.app>
