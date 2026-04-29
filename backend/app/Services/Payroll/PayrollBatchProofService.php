<?php

namespace App\Services\Payroll;

use App\Exceptions\UserFacingException;
use App\Models\PayoutExecution;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryProof;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class PayrollBatchProofService
{
    private const COMMIT_PROOF_VERSION = 'payroll_entry_commitment_v1';

    /**
     * @return array{entry_count:int,entries_root:string,leaves:Collection<int, string>,proofs:Collection<int, PayrollEntryProof>}
     */
    public function commitProof(PayrollBatch $payrollBatch): array
    {
        $payrollBatch->loadMissing([
            'entries' => fn ($query) => $query
                ->with(['employee', 'compensationAmendment', 'proof'])
                ->orderBy('id'),
        ]);

        if ($payrollBatch->entries->isEmpty()) {
            throw new UserFacingException(__('ui.messages.batch_has_no_entries'));
        }

        $entries = $payrollBatch->entries
            ->sortBy('id')
            ->values();

        $materials = $entries->map(
            fn (PayrollEntry $entry, int $position): array => $this->expectedCommitmentMaterial($payrollBatch, $entry, $position),
        );
        $leaves = $materials->pluck('leaf_hash')->values();
        $proofPaths = $this->proofPathsFromLeaves($leaves);

        $proofs = $entries->map(
            fn (PayrollEntry $entry, int $position): PayrollEntryProof => $this->upsertCommitmentMaterial(
                $payrollBatch,
                $entry,
                array_merge($materials->get($position), ['proof_path' => $proofPaths->get($position, [])]),
            ),
        );

        PayrollEntryProof::query()
            ->where('payroll_batch_id', $payrollBatch->id)
            ->whereNotIn('payroll_entry_id', $entries->pluck('id')->all())
            ->delete();

        return [
            'entry_count' => $leaves->count(),
            'entries_root' => $this->rootFromLeaves($leaves),
            'leaves' => $leaves,
            'proofs' => $proofs,
        ];
    }

    /**
     * @return array{approval_root:string,leaves:Collection<int, string>}
     */
    public function approvalProof(PayrollBatch $payrollBatch): array
    {
        $payrollBatch->loadMissing([
            'entries' => fn ($query) => $query
                ->with(['payoutExecution'])
                ->orderBy('id'),
        ]);

        $executions = $payrollBatch->entries
            ->map(fn (PayrollEntry $entry): ?PayoutExecution => $entry->payoutExecution)
            ->filter(fn (?PayoutExecution $execution): bool => $execution instanceof PayoutExecution && is_string($execution->prepared_payload_path) && $execution->prepared_payload_path !== '')
            ->sortBy('id')
            ->values();

        if ($executions->isEmpty()) {
            throw new UserFacingException(__('ui.messages.batch_has_no_prepared_manifests'));
        }

        $leaves = $executions->map(
            fn (PayoutExecution $execution): string => $this->hashPayload([
                'execution_id' => $execution->id,
                'payroll_entry_id' => $execution->payroll_entry_id,
                'approval_method' => $execution->approval_method,
                'prepared_manifest_hash' => $this->storedFileHash($execution->prepared_payload_path),
            ]),
        );

        return [
            'approval_root' => $this->rootFromLeaves($leaves),
            'leaves' => $leaves,
        ];
    }

    /**
     * @return array{settlement_root:string,leaves:Collection<int, string>}
     */
    public function settlementProof(PayrollBatch $payrollBatch): array
    {
        $payrollBatch->loadMissing([
            'entries' => fn ($query) => $query
                ->with(['payoutExecution'])
                ->orderBy('id'),
        ]);

        $executions = $payrollBatch->entries
            ->map(fn (PayrollEntry $entry): ?PayoutExecution => $entry->payoutExecution)
            ->values();

        if ($executions->isEmpty()) {
            throw new UserFacingException(__('ui.messages.batch_settlement_proof_incomplete'));
        }

        if ($executions->contains(fn (?PayoutExecution $execution): bool => ! $execution instanceof PayoutExecution || ! $execution->isImported() || ! is_string($execution->receipt_path) || $execution->receipt_path === '')) {
            throw new UserFacingException(__('ui.messages.batch_settlement_proof_incomplete'));
        }

        /** @var Collection<int, PayoutExecution> $importedExecutions */
        $importedExecutions = $executions->filter(fn (?PayoutExecution $execution): bool => $execution instanceof PayoutExecution)->sortBy('id')->values();

        $leaves = $importedExecutions->map(
            fn (PayoutExecution $execution): string => $this->hashPayload([
                'execution_id' => $execution->id,
                'payroll_entry_id' => $execution->payroll_entry_id,
                'prepared_manifest_hash' => $this->storedFileHash($execution->prepared_payload_path),
                'tx_signature' => $execution->tx_signature,
                'approved_wallet_address' => $execution->approved_wallet_address,
                'approved_at' => $execution->approved_at?->toIso8601String(),
                'receipt_hash' => $this->storedFileHash($execution->receipt_path),
            ]),
        );

        return [
            'settlement_root' => $this->rootFromLeaves($leaves),
            'leaves' => $leaves,
        ];
    }

    /**
     * @param  Collection<int, string>  $leaves
     */
    private function rootFromLeaves(Collection $leaves): string
    {
        $level = $leaves
            ->values()
            ->map(fn (string $leaf): string => $this->hashLeaf($leaf));

        if ($level->isEmpty()) {
            throw new UserFacingException(__('ui.messages.batch_has_no_entries'));
        }

        while ($level->count() > 1) {
            $level = $level
                ->chunk(2)
                ->map(function (Collection $pair): string {
                    $pair = $pair->values();
                    $left = (string) $pair->get(0);
                    $right = (string) ($pair->get(1) ?? $left);

                    return $this->hashNode($left, $right);
                })
                ->values();
        }

        return (string) $level->first();
    }

    private function upsertCommitmentMaterial(
        PayrollBatch $payrollBatch,
        PayrollEntry $entry,
        array $material,
    ): PayrollEntryProof {
        $proof = $entry->proof;

        if ($this->isCommittedBatch($payrollBatch) && $proof instanceof PayrollEntryProof) {
            if (! $this->proofCoreMatchesMaterial($proof, $material)) {
                throw new UserFacingException(__('ui.messages.payroll_entry_proof_mismatch'));
            }

            if ($proof->proof_path === null) {
                $proof->forceFill(['proof_path' => $material['proof_path']])->save();

                return $proof->fresh() ?? $proof;
            }

            if ($proof->proof_path !== $material['proof_path']) {
                throw new UserFacingException(__('ui.messages.payroll_entry_proof_mismatch'));
            }

            return $proof;
        }

        return PayrollEntryProof::query()->updateOrCreate(
            ['payroll_entry_id' => $entry->id],
            $material,
        );
    }

    /**
     * @return array{
     *     payroll_batch_id:int,
     *     position:int,
     *     proof_version:string,
     *     employee_ref_hash:string,
     *     compensation_ref_hash:?string,
     *     amount_commitment_hash:string,
     *     amount_nonce:string,
     *     leaf_hash:string,
     *     leaf_payload:array<string, mixed>
     * }
     */
    private function expectedCommitmentMaterial(
        PayrollBatch $payrollBatch,
        PayrollEntry $entry,
        int $position,
    ): array {
        $employeeRefHash = $this->hashPayload([
            'version' => 'employee_ref_v1',
            'employee_id' => $entry->employee_id,
            'wallet_address' => trim((string) $entry->employee?->wallet_address),
        ]);

        $compensationRefHash = $entry->compensation_amendment_id ? $this->hashPayload([
            'version' => 'compensation_ref_v1',
            'compensation_amendment_id' => $entry->compensation_amendment_id,
        ]) : null;

        $amountNonce = $this->hashPayload([
            'version' => 'payroll_amount_nonce_v1',
            'payroll_batch_id' => $payrollBatch->id,
            'payroll_entry_id' => $entry->id,
            'employee_id' => $entry->employee_id,
            'compensation_amendment_id' => $entry->compensation_amendment_id,
            'due_date' => $entry->due_date?->toDateString(),
        ]);

        $amountCommitmentHash = $this->hashPayload([
            'version' => 'payroll_amount_commitment_v1',
            'amount_minor' => $entry->amount_minor,
            'currency' => $entry->currency,
            'amount_nonce' => $amountNonce,
        ]);

        $leafPayload = [
            'version' => self::COMMIT_PROOF_VERSION,
            'payroll_batch_id' => $payrollBatch->id,
            'payroll_entry_id' => $entry->id,
            'position' => $position,
            'employee_ref_hash' => $employeeRefHash,
            'compensation_ref_hash' => $compensationRefHash,
            'amount_commitment_hash' => $amountCommitmentHash,
            'currency' => $entry->currency,
            'due_date' => $entry->due_date?->toDateString(),
        ];

        $leafHash = $this->hashPayload($leafPayload);

        return [
            'payroll_batch_id' => $payrollBatch->id,
            'position' => $position,
            'proof_version' => self::COMMIT_PROOF_VERSION,
            'employee_ref_hash' => $employeeRefHash,
            'compensation_ref_hash' => $compensationRefHash,
            'amount_commitment_hash' => $amountCommitmentHash,
            'amount_nonce' => $amountNonce,
            'leaf_hash' => $leafHash,
            'leaf_payload' => $leafPayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $material
     */
    private function proofCoreMatchesMaterial(PayrollEntryProof $proof, array $material): bool
    {
        return $proof->payroll_batch_id === $material['payroll_batch_id']
            && $proof->position === $material['position']
            && $proof->proof_version === $material['proof_version']
            && $proof->employee_ref_hash === $material['employee_ref_hash']
            && $proof->compensation_ref_hash === $material['compensation_ref_hash']
            && $proof->amount_commitment_hash === $material['amount_commitment_hash']
            && $proof->amount_nonce === $material['amount_nonce']
            && $proof->leaf_hash === $material['leaf_hash']
            && $proof->leaf_payload == $material['leaf_payload'];
    }

    public function verifyMembership(PayrollEntryProof $proof, ?string $root): bool
    {
        if (! is_string($root) || $root === '') {
            return false;
        }

        $computed = $this->hashLeaf($proof->leaf_hash);

        foreach ($proof->proof_path ?? [] as $pathItem) {
            if (! is_array($pathItem)) {
                return false;
            }

            $siblingHash = trim((string) data_get($pathItem, 'sibling_hash', ''));
            $direction = trim((string) data_get($pathItem, 'direction', ''));

            if ($siblingHash === '' || ! in_array($direction, ['left', 'right'], true)) {
                return false;
            }

            $computed = $direction === 'left'
                ? $this->hashNode($siblingHash, $computed)
                : $this->hashNode($computed, $siblingHash);
        }

        return hash_equals($root, $computed);
    }

    /**
     * @param  Collection<int, string>  $leaves
     * @return Collection<int, array<int, array{level:int,direction:string,sibling_hash:string}>>
     */
    private function proofPathsFromLeaves(Collection $leaves): Collection
    {
        $paths = array_fill(0, $leaves->count(), []);
        $level = $leaves
            ->values()
            ->map(fn (string $leaf): string => $this->hashLeaf($leaf));
        $indexes = collect(range(0, max(0, $leaves->count() - 1)))
            ->map(fn (int $index): array => [$index]);
        $levelNumber = 0;

        while ($level->count() > 1) {
            $nextLevel = collect();
            $nextIndexes = collect();

            for ($index = 0; $index < $level->count(); $index += 2) {
                $leftHash = (string) $level->get($index);
                $rightHash = (string) ($level->get($index + 1) ?? $leftHash);
                $leftOriginalIndexes = $indexes->get($index, []);
                $rightOriginalIndexes = $indexes->get($index + 1, $leftOriginalIndexes);

                foreach ($leftOriginalIndexes as $leftOriginalIndex) {
                    $paths[$leftOriginalIndex][] = [
                        'level' => $levelNumber,
                        'direction' => 'right',
                        'sibling_hash' => $rightHash,
                    ];
                }

                if ($rightOriginalIndexes !== $leftOriginalIndexes) {
                    foreach ($rightOriginalIndexes as $rightOriginalIndex) {
                        $paths[$rightOriginalIndex][] = [
                            'level' => $levelNumber,
                            'direction' => 'left',
                            'sibling_hash' => $leftHash,
                        ];
                    }
                }

                $nextIndexes->push(
                    $rightOriginalIndexes === $leftOriginalIndexes
                        ? $leftOriginalIndexes
                        : array_merge($leftOriginalIndexes, $rightOriginalIndexes),
                );

                $nextLevel->push($this->hashNode($leftHash, $rightHash));
            }

            $level = $nextLevel;
            $indexes = $nextIndexes;
            $levelNumber++;
        }

        return collect($paths);
    }

    private function isCommittedBatch(PayrollBatch $payrollBatch): bool
    {
        return is_string($payrollBatch->anchor_batch_pubkey)
            && $payrollBatch->anchor_batch_pubkey !== '';
    }

    private function storedFileHash(?string $path): string
    {
        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            throw new UserFacingException(__('ui.messages.batch_settlement_proof_incomplete'));
        }

        return hash('sha256', Storage::disk('local')->get($path));
    }

    private function hashNode(string $left, string $right): string
    {
        return $this->hashPayload([
            'left' => $left,
            'right' => $right,
        ]);
    }

    private function hashLeaf(string $leaf): string
    {
        return $this->hashPayload(['leaf' => $leaf]);
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $payload
     */
    private function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
