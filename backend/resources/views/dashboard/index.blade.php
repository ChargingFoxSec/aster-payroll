<x-layouts.app :title="'Dashboard · Aster Payroll'">
    <section class="grid gap-6 lg:grid-cols-[1.2fr,0.8fr]">
        <div class="rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur">
            <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">Company Scope</p>
            <h2 class="mt-3 text-3xl font-semibold text-white">{{ $company->name }}</h2>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-stone-300">
                This admin flow is intentionally narrow for the hackathon: create employees, upload contract PDFs,
                hash them, and keep the confidential payroll settlement demo reproducible.
            </p>

            <div class="mt-6 grid gap-4 md:grid-cols-3">
                <div class="rounded-2xl border border-white/10 bg-stone-900/70 p-4">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-400">Employees</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $company->employees_count }}</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-stone-900/70 p-4">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-400">Contracts</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $company->contracts_count }}</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-stone-900/70 p-4">
                    <p class="text-xs uppercase tracking-[0.25em] text-stone-400">Payroll Batches</p>
                    <p class="mt-3 text-3xl font-semibold text-white">{{ $company->payroll_batches_count }}</p>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('employees.create') }}" class="rounded-full bg-cyan-300 px-5 py-3 text-sm font-medium text-stone-950 transition hover:bg-cyan-200">
                    Create employee
                </a>
                <a href="{{ route('payroll-demo.show') }}" class="rounded-full border border-white/10 px-5 py-3 text-sm font-medium text-white transition hover:border-cyan-300/60 hover:text-cyan-100">
                    Open confidential payroll demo
                </a>
            </div>
        </div>

        <div class="rounded-3xl border border-white/10 bg-stone-900/70 p-6">
            <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">Latest Receipt</p>

            @if ($latestReceipt)
                <div class="mt-4 space-y-4">
                    <div>
                        <p class="text-sm text-stone-400">Mint</p>
                        <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $latestReceipt['token']['mint'] ?? 'n/a' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-stone-400">Tracked confidential transfer</p>
                        <p class="mt-1 break-all font-mono text-xs text-cyan-100">{{ $latestReceipt['transactions']['confidential_transfer'] ?? 'n/a' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-stone-400">Generated</p>
                        <p class="mt-1 text-sm text-stone-200">{{ $latestReceipt['generated_at'] ?? 'n/a' }}</p>
                    </div>
                </div>
            @else
                <p class="mt-4 text-sm leading-6 text-stone-300">
                    No confidential payroll receipt yet. Start the native validator helper, then run the demo once to
                    capture a real local Token-2022 confidential transfer trail.
                </p>
            @endif
        </div>
    </section>
</x-layouts.app>
