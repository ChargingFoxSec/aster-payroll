<x-layouts.app :title="__('ui.pages.employees.index_title')">
    <section class="space-y-6">
        <div class="panel panel-hero flex flex-col gap-4 p-6 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-xs uppercase tracking-[0.35em] text-cyan-200/70">{{ __('ui.pages.employees.registry') }}</p>
                <h2 class="mt-2 text-3xl font-semibold text-white">{{ $company->name }}</h2>
                <p class="mt-2 text-sm text-stone-300">{{ __('ui.pages.employees.registry_copy') }}</p>
            </div>

            <a href="{{ route('employees.create') }}" class="app-button app-button-primary">
                {{ __('ui.actions.create_employee') }}
            </a>
        </div>

        <div class="table-shell">
            <table class="app-data-table text-left text-sm">
                <thead class="bg-white/5 text-stone-400">
                    <tr>
                        <th class="px-5 py-4 font-medium">{{ __('ui.fields.employee') }}</th>
                        <th class="px-5 py-4 font-medium">{{ __('ui.fields.pay_cycle') }}</th>
                        <th class="px-5 py-4 font-medium">{{ __('ui.fields.wallet') }}</th>
                        <th class="px-5 py-4 font-medium">{{ __('ui.fields.contracts') }}</th>
                        <th class="px-5 py-4 text-right font-medium">{{ __('ui.fields.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($employees as $employee)
                        <tr class="align-top">
                            <td class="px-5 py-4">
                                <p class="font-medium text-white">{{ $employee->full_name }}</p>
                                <p class="mt-1 text-xs text-stone-400">{{ $employee->email }}</p>
                            </td>
                            <td class="px-5 py-4 text-stone-200">{{ __('ui.pay_cycles.'.$employee->pay_cycle) }}</td>
                            <td class="px-5 py-4">
                                <p class="max-w-xs break-all font-mono text-xs text-stone-300">{{ $employee->wallet_address ?: __('ui.common.not_set_yet') }}</p>
                            </td>
                            <td class="px-5 py-4 text-stone-200">{{ $employee->contracts_count }}</td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('employees.show', $employee) }}" class="app-button app-button-secondary app-button-compact">
                                    {{ __('ui.actions.open') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-8 text-center text-stone-400">
                                {{ __('ui.pages.employees.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
