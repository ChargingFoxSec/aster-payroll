<x-layouts.app :title="'Confidential Payroll Demo · Aster Payroll'">
    <section class="grid gap-6 lg:grid-cols-[0.95fr,1.05fr]">
        <div class="rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur">
            <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">Confidential Settlement</p>
            <h2 class="mt-2 text-3xl font-semibold text-white">Local Token-2022 payroll PoC</h2>
            <p class="mt-3 text-sm leading-6 text-stone-300">
                This page drives the real confidential transfer demo path: mint, configure confidential accounts,
                deposit, apply pending balance, confidential transfer, and final receipt capture.
            </p>

            <div class="mt-6 rounded-2xl border border-white/10 bg-stone-950/70 p-4 text-sm text-stone-300">
                <p>RPC URL</p>
                <p class="mt-1 break-all font-mono text-xs text-cyan-100">{{ $rpcUrl }}</p>
                <p class="mt-4">Latest receipt path</p>
                <p class="mt-1 break-all font-mono text-xs text-stone-100">{{ $receiptPath }}</p>
            </div>

            <form method="POST" action="{{ route('payroll-demo.run') }}" class="mt-6">
                @csrf
                <button type="submit" class="inline-flex rounded-full bg-cyan-300 px-5 py-3 text-sm font-medium text-stone-950 transition hover:bg-cyan-200">
                    Run confidential payroll PoC
                </button>
            </form>

            <ol class="mt-6 space-y-3 text-sm leading-6 text-stone-300">
                <li>1. Start the native validator with <code class="rounded bg-white/10 px-2 py-1 text-xs">./scripts/start-confidential-validator.sh</code>.</li>
                <li>2. Keep Laravel in the existing devcontainer; it talks to the validator over <code class="rounded bg-white/10 px-2 py-1 text-xs">host.docker.internal:8899</code>.</li>
                <li>3. Run the PoC here to capture a real confidential transfer signature trail for the demo.</li>
            </ol>
        </div>

        <div class="rounded-3xl border border-white/10 bg-stone-900/70 p-6">
            <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">Receipt / Tx Tracking</p>

            @if ($latestReceipt)
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl border border-white/10 bg-stone-950/70 p-4">
                        <p class="text-xs uppercase tracking-[0.25em] text-stone-500">Mint</p>
                        <p class="mt-2 break-all font-mono text-xs text-stone-100">{{ $latestReceipt['token']['mint'] ?? 'n/a' }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-stone-950/70 p-4">
                        <p class="text-xs uppercase tracking-[0.25em] text-stone-500">Transfer Amount</p>
                        <p class="mt-2 text-2xl font-semibold text-white">{{ $latestReceipt['payroll']['confidential_transfer_amount'] ?? 'n/a' }}</p>
                    </div>
                </div>

                <div class="mt-6 space-y-3">
                    @foreach (($latestReceipt['transactions'] ?? []) as $label => $signature)
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                            <p class="text-xs uppercase tracking-[0.25em] text-stone-500">{{ str($label)->replace('_', ' ')->title() }}</p>
                            <p class="mt-2 break-all font-mono text-xs {{ $signature ? 'text-cyan-100' : 'text-stone-500' }}">
                                {{ $signature ?: 'No signature captured' }}
                            </p>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm leading-6 text-stone-300">
                    No receipt yet. Once the helper validator is up, run the PoC and this panel will show the tracked
                    transaction signatures from mint creation through confidential transfer settlement.
                </p>
            @endif
        </div>
    </section>
</x-layouts.app>
