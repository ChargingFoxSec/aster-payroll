<?php

namespace App\Services\Contracts;

use App\Exceptions\UserFacingException;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ContractUploadService
{
    /**
     * @param  array{title:string,effective_date:string,status:string}  $attributes
     */
    public function upload(
        Company $company,
        Employee $employee,
        UploadedFile $contractPdf,
        array $attributes,
    ): EmploymentContract {
        $nextVersion = ((int) $employee->contracts()->max('version')) + 1;
        $storedPath = $contractPdf->storeAs(
            "contracts/{$company->id}/{$employee->id}",
            sprintf('contract-v%d-%s.pdf', $nextVersion, Str::uuid()->toString()),
            'local',
        );

        if (! is_string($storedPath) || $storedPath === '') {
            throw new UserFacingException('The contract PDF could not be stored securely.');
        }

        $absolutePath = Storage::disk('local')->path($storedPath);
        $fileHash = hash_file('sha256', $absolutePath);

        if (! is_string($fileHash)) {
            throw new UserFacingException('The contract hash could not be generated.');
        }

        return $employee->contracts()->create([
            'company_id' => $company->id,
            'version' => $nextVersion,
            'file_path' => $storedPath,
            'file_hash' => $fileHash,
            'title' => $attributes['title'],
            'effective_date' => $attributes['effective_date'],
            'status' => $attributes['status'],
            'anchor_contract_pubkey' => null,
        ]);
    }
}
