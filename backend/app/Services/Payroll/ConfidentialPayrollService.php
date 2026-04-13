<?php

namespace App\Services\Payroll;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ConfidentialPayrollService
{
    public function latestReceipt(): ?array
    {
        $receiptPath = $this->receiptPath();

        if (! is_file($receiptPath)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($receiptPath), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function runDemo(): array
    {
        $scriptPath = $this->scriptPath();
        $workDir = $this->workDir();
        $receiptPath = $this->receiptPath();

        if (! is_file($scriptPath)) {
            throw new RuntimeException("Confidential payroll PoC script not found at [{$scriptPath}].");
        }

        if (! is_dir($workDir) && ! mkdir($workDir, 0755, true) && ! is_dir($workDir)) {
            throw new RuntimeException("Unable to create confidential payroll work directory [{$workDir}].");
        }

        if (! is_dir(dirname($receiptPath)) && ! mkdir(dirname($receiptPath), 0755, true) && ! is_dir(dirname($receiptPath))) {
            throw new RuntimeException("Unable to create receipt directory for [{$receiptPath}].");
        }

        $process = new Process(['bash', $scriptPath], dirname($scriptPath), [
            'ASTER_SOLANA_RPC_URL' => config('payroll.confidential.rpc_url'),
            'ASTER_CONFIDENTIAL_POC_DIR' => $workDir,
            'ASTER_CONFIDENTIAL_POC_OUTPUT' => $receiptPath,
            'ASTER_CONFIDENTIAL_MINT_AMOUNT' => (string) config('payroll.confidential.mint_amount'),
            'ASTER_CONFIDENTIAL_TRANSFER_AMOUNT' => (string) config('payroll.confidential.transfer_amount'),
        ]);

        $process->setTimeout((float) config('payroll.confidential.timeout_seconds'));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $receipt = $this->latestReceipt();

        if ($receipt === null) {
            throw new RuntimeException('Confidential payroll PoC finished without a readable receipt.');
        }

        return $receipt;
    }

    public function receiptPath(): string
    {
        return (string) config('payroll.confidential.receipt_path');
    }

    public function scriptPath(): string
    {
        return (string) config('payroll.confidential.poc_script');
    }

    public function workDir(): string
    {
        return (string) config('payroll.confidential.work_dir');
    }
}
