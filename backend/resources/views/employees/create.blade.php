<x-layouts.app :title="'Create Employee · Aster Payroll'">
    <section class="grid gap-6 lg:grid-cols-[1.1fr,0.9fr]">
        <form method="POST" action="{{ route('employees.store') }}" class="rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur">
            @csrf

            <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">Create Employee</p>
            <h2 class="mt-2 text-3xl font-semibold text-white">First payroll entity</h2>

            <div class="mt-6 grid gap-5 md:grid-cols-2">
                <label class="space-y-2 md:col-span-2">
                    <span class="text-sm text-stone-200">Full name</span>
                    <input type="text" name="full_name" value="{{ old('full_name') }}" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60" required>
                </label>

                <label class="space-y-2">
                    <span class="text-sm text-stone-200">Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60" required>
                </label>

                <label class="space-y-2">
                    <span class="text-sm text-stone-200">Wallet address</span>
                    <input type="text" name="wallet_address" value="{{ old('wallet_address') }}" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60" placeholder="Optional for now">
                </label>

                <label class="space-y-2">
                    <span class="text-sm text-stone-200">Employment status</span>
                    <select name="employment_status" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60">
                        @foreach (['active' => 'Active', 'paused' => 'Paused', 'terminated' => 'Terminated'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('employment_status', 'active') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-sm text-stone-200">Start date</span>
                    <input type="date" name="start_date" value="{{ old('start_date') }}" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60">
                </label>

                <label class="space-y-2">
                    <span class="text-sm text-stone-200">Pay cycle</span>
                    <select name="pay_cycle" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60">
                        @foreach ($payCycles as $value => $label)
                            <option value="{{ $value }}" @selected(old('pay_cycle', 'monthly') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-sm text-stone-200">Currency</span>
                    <input type="text" name="currency" value="{{ old('currency', 'USDC') }}" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60" required>
                </label>
            </div>

            <button type="submit" class="mt-6 inline-flex rounded-full bg-cyan-300 px-5 py-3 text-sm font-medium text-stone-950 transition hover:bg-cyan-200">
                Save employee
            </button>
        </form>

        <div class="rounded-3xl border border-white/10 bg-stone-900/70 p-6">
            <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">Hackathon Scope</p>
            <ul class="mt-4 space-y-3 text-sm leading-6 text-stone-300">
                <li>Keep the employee schema small enough to support contract uploads and future payroll batches.</li>
                <li>Do not build full HRIS workflow or auth matrix in this pass.</li>
                <li>Wallet can stay optional until the confidential settlement demo is wired into a specific employee flow.</li>
            </ul>
        </div>
    </section>
</x-layouts.app>
