<x-layouts.app :title="'Employees · Aster Payroll'">
    <section class="space-y-6">
        <div class="flex flex-col gap-4 rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">Employee Registry</p>
                <h2 class="mt-2 text-3xl font-semibold text-white">{{ $company->name }}</h2>
                <p class="mt-2 text-sm text-stone-300">Only the minimum fields needed for the first admin-side payroll loop are live right now.</p>
            </div>

            <a href="{{ route('employees.create') }}" class="inline-flex rounded-full bg-cyan-300 px-5 py-3 text-sm font-medium text-stone-950 transition hover:bg-cyan-200">
                Create employee
            </a>
        </div>

        <div class="overflow-hidden rounded-3xl border border-white/10 bg-stone-900/70">
            <table class="min-w-full divide-y divide-white/10 text-left text-sm">
                <thead class="bg-white/5 text-stone-400">
                    <tr>
                        <th class="px-5 py-4 font-medium">Employee</th>
                        <th class="px-5 py-4 font-medium">Pay Cycle</th>
                        <th class="px-5 py-4 font-medium">Wallet</th>
                        <th class="px-5 py-4 font-medium">Contracts</th>
                        <th class="px-5 py-4 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                    @forelse ($employees as $employee)
                        <tr class="align-top">
                            <td class="px-5 py-4">
                                <p class="font-medium text-white">{{ $employee->full_name }}</p>
                                <p class="mt-1 text-xs text-stone-400">{{ $employee->email }}</p>
                            </td>
                            <td class="px-5 py-4 text-stone-200">{{ str($employee->pay_cycle)->replace('_', ' ')->title() }}</td>
                            <td class="px-5 py-4">
                                <p class="max-w-xs break-all font-mono text-xs text-stone-300">{{ $employee->wallet_address ?: 'Not set yet' }}</p>
                            </td>
                            <td class="px-5 py-4 text-stone-200">{{ $employee->contracts_count }}</td>
                            <td class="px-5 py-4">
                                <a href="{{ route('employees.show', $employee) }}" class="text-cyan-200 transition hover:text-cyan-100">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-8 text-center text-stone-400">
                                No employees yet. Create the first employee and upload a contract PDF.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
