<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? 'Aster Payroll' }}</title>
        @if (! app()->runningUnitTests() || file_exists(public_path('build/manifest.json')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-stone-950 text-stone-100">
        <div class="absolute inset-0 -z-10 overflow-hidden">
            <div class="absolute left-0 top-0 h-80 w-80 rounded-full bg-cyan-500/15 blur-3xl"></div>
            <div class="absolute bottom-0 right-0 h-96 w-96 rounded-full bg-amber-400/10 blur-3xl"></div>
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_top,_rgba(255,255,255,0.08),_transparent_40%),linear-gradient(135deg,_rgba(255,255,255,0.02),_transparent_50%)]"></div>
        </div>

        <div class="mx-auto flex min-h-screen w-full max-w-7xl flex-col px-6 py-6 lg:px-10">
            <header class="mb-8 rounded-3xl border border-white/10 bg-white/5 p-5 backdrop-blur">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/80">Private, Verifiable Payroll</p>
                        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-white">Aster Payroll</h1>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-stone-300">
                            Hackathon MVP focused on contract hashing, employee records, and confidential payroll receipts.
                        </p>
                    </div>

                    <nav class="flex flex-wrap gap-3 text-sm">
                        <a href="{{ route('dashboard') }}" class="rounded-full border border-white/10 px-4 py-2 text-stone-200 transition hover:border-cyan-300/60 hover:text-cyan-100">Dashboard</a>
                        <a href="{{ route('employees.index') }}" class="rounded-full border border-white/10 px-4 py-2 text-stone-200 transition hover:border-cyan-300/60 hover:text-cyan-100">Employees</a>
                        <a href="{{ route('payroll-demo.show') }}" class="rounded-full border border-white/10 px-4 py-2 text-stone-200 transition hover:border-cyan-300/60 hover:text-cyan-100">Confidential Payroll Demo</a>
                    </nav>
                </div>
            </header>

            @if (session('status'))
                <div class="mb-6 rounded-2xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-100">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 rounded-2xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-100">
                    {{ session('error') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 rounded-2xl border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm text-amber-50">
                    <p class="font-medium">Please fix the highlighted form data.</p>
                    <ul class="mt-2 space-y-1 text-amber-100/90">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <main class="flex-1">
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
