<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Support\DemoCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $company = DemoCompany::resolve();
        abort_unless($employee->company_id === $company->id, 404);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'effective_date' => ['required', 'date'],
            'status' => ['required', Rule::in(['draft', 'active', 'superseded'])],
            'contract_pdf' => ['required', 'file', 'mimes:pdf', 'max:15360'],
        ]);

        $nextVersion = ((int) $employee->contracts()->max('version')) + 1;
        $storedPath = $request->file('contract_pdf')->storeAs(
            "contracts/{$company->id}/{$employee->id}",
            sprintf('contract-v%d-%s.pdf', $nextVersion, Str::uuid()->toString()),
            'local',
        );

        $absolutePath = Storage::disk('local')->path($storedPath);
        $fileHash = hash_file('sha256', $absolutePath);

        $employee->contracts()->create([
            'company_id' => $company->id,
            'version' => $nextVersion,
            'file_path' => $storedPath,
            'file_hash' => $fileHash,
            'title' => $validated['title'],
            'effective_date' => $validated['effective_date'],
            'status' => $validated['status'],
            'anchor_contract_pubkey' => null,
        ]);

        return redirect()
            ->route('employees.show', $employee)
            ->with('status', "Contract v{$nextVersion} uploaded and hashed.");
    }

    public function download(EmploymentContract $contract): StreamedResponse
    {
        $company = DemoCompany::resolve();
        abort_unless($contract->company_id === $company->id, 404);

        return Storage::disk('local')->download(
            $contract->file_path,
            basename($contract->file_path),
            ['Content-Type' => 'application/pdf'],
        );
    }
}
