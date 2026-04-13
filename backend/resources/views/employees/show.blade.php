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
                </dl>
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
                                <p class="mt-3 text-xs text-stone-400">Stored at {{ $contract->file_path }}</p>
                            </div>
                        </article>
                    @empty
                        <div class="px-6 py-8 text-sm text-stone-400">
                            No contract versions yet. Upload the first employment PDF on the right.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

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
        </form>
    </section>
</x-layouts.app>
