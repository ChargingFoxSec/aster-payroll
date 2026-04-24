<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\User;

final readonly class EmployeeOnboardingResult
{
    public function __construct(
        public Employee $employee,
        public ?User $portalUser = null,
        public ?string $temporaryPassword = null,
    ) {}

    public function portalProvisioned(): bool
    {
        return $this->portalUser !== null && $this->temporaryPassword !== null;
    }
}
