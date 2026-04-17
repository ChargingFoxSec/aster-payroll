<x-layouts.app :title="'Log In · Aster Payroll'">
    <section class="mx-auto max-w-5xl grid gap-6 lg:grid-cols-[0.95fr,1.05fr]">
        <div class="rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur">
            <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">Plan A Auth</p>
            <h2 class="mt-2 text-3xl font-semibold text-white">Log in to the payroll portal</h2>
            <p class="mt-3 text-sm leading-6 text-stone-300">
                Company admins get the full payroll dashboard. Employee users are redirected into a self-service portal
                that derives records from their authenticated identity instead of route parameters.
            </p>

            <div class="mt-6 rounded-3xl border border-white/10 bg-stone-950/70 p-5">
                <p class="text-xs uppercase tracking-[0.25em] text-stone-500">Demo accounts</p>
                <div class="mt-4 space-y-4 text-sm text-stone-300">
                    <div>
                        <p class="font-medium text-white">Admin</p>
                        <p class="mt-1 font-mono text-xs text-cyan-100">admin@aster.test / password</p>
                    </div>
                    <div>
                        <p class="font-medium text-white">Employee</p>
                        <p class="mt-1 font-mono text-xs text-cyan-100">employee@aster.test / password</p>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="rounded-3xl border border-white/10 bg-stone-900/70 p-6">
            @csrf

            <p class="text-xs uppercase tracking-[0.35em] text-amber-200/70">Session Login</p>
            <h3 class="mt-2 text-2xl font-semibold text-white">Minimal, role-aware auth</h3>

            <div class="mt-6 space-y-5">
                <label class="block space-y-2">
                    <span class="text-sm text-stone-200">Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60" required autofocus>
                </label>

                <label class="block space-y-2">
                    <span class="text-sm text-stone-200">Password</span>
                    <input type="password" name="password" autocomplete="current-password" class="w-full rounded-2xl border border-white/10 bg-stone-950/80 px-4 py-3 text-white outline-none transition focus:border-cyan-300/60" required>
                </label>
            </div>

            <button type="submit" class="mt-6 inline-flex rounded-full bg-cyan-300 px-5 py-3 text-sm font-medium text-stone-950 transition hover:bg-cyan-200">
                Log in
            </button>

            <p class="mt-6 text-xs leading-6 text-stone-400">
                This pass intentionally excludes registration, password reset, and broad RBAC. It only establishes the
                minimum credible admin/employee boundary required by the V1 demo.
            </p>
        </form>
    </section>
</x-layouts.app>
