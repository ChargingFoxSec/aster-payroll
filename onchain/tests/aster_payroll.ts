import * as anchor from "@coral-xyz/anchor";
import { assert } from "chai";
import { Program } from "@coral-xyz/anchor";
import { AsterPayroll } from "../target/types/aster_payroll";

describe("aster_payroll", () => {
  anchor.setProvider(anchor.AnchorProvider.env());

  const provider = anchor.getProvider() as anchor.AnchorProvider;
  const program = anchor.workspace.asterPayroll as Program<AsterPayroll>;
  const authority = provider.wallet.publicKey;
  const [companyPda] = anchor.web3.PublicKey.findProgramAddressSync(
    [Buffer.from("company"), authority.toBuffer()],
    program.programId
  );

  const u16Le = (value: number): Buffer => {
    const buffer = Buffer.alloc(2);
    buffer.writeUInt16LE(value, 0);

    return buffer;
  };

  const expectAnchorError = async (
    action: () => Promise<unknown>,
    messageFragment: string
  ) => {
    try {
      await action();
      assert.fail(`Expected Anchor error containing "${messageFragment}"`);
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      assert.include(message, messageFragment);
    }
  };

  const fundSigner = async (signer: anchor.web3.Keypair) => {
    const signature = await provider.connection.requestAirdrop(
      signer.publicKey,
      2_000_000_000
    );

    await provider.connection.confirmTransaction(signature, "confirmed");
  };

  const createContract = async (
    employeeWallet: anchor.web3.PublicKey,
    version: number
  ) => {
    const [contractPda] = anchor.web3.PublicKey.findProgramAddressSync(
      [
        Buffer.from("contract"),
        companyPda.toBuffer(),
        employeeWallet.toBuffer(),
        u16Le(version),
      ],
      program.programId
    );

    await program.methods
      .createEmploymentContract({
        employeeWallet,
        contractHash: Array.from(Buffer.alloc(32, version)),
        version,
        effectiveAt: new anchor.BN(1_776_009_600),
        payCycle: 1,
        currentCompensationRef: Array.from(Buffer.alloc(32, version)),
      })
      .accounts({
        company: companyPda,
        contract: contractPda,
        authority,
        systemProgram: anchor.web3.SystemProgram.programId,
      })
      .rpc();

    return contractPda;
  };

  const batchPdaFor = (periodYear: number, periodMonth: number) =>
    anchor.web3.PublicKey.findProgramAddressSync(
      [
        Buffer.from("batch"),
        companyPda.toBuffer(),
        u16Le(periodYear),
        Buffer.from([periodMonth]),
      ],
      program.programId
    )[0];

  before(async () => {
    const existing = await program.account.companyAccount.fetchNullable(
      companyPda
    );

    if (!existing) {
      await program.methods
        .initializeCompany({
          name: "Aster Payroll Demo",
          slug: "aster-demo",
          treasuryWallet: authority,
        })
        .accounts({
          company: companyPda,
          authority,
          systemProgram: anchor.web3.SystemProgram.programId,
        })
        .rpc();
    }
  });

  it("initializes a company account", async () => {
    const companyAccount = await program.account.companyAccount.fetch(
      companyPda
    );

    assert.equal(companyAccount.slug, "aster-demo");
    assert.equal(companyAccount.name, "Aster Payroll Demo");
    assert.isAbove(Number(companyAccount.createdAt), 0);
  });

  it("creates an employment contract account", async () => {
    const employeeWallet = anchor.web3.Keypair.generate().publicKey;
    const contractPda = await createContract(employeeWallet, 1);
    const contractAccount = await program.account.employmentContract.fetch(
      contractPda
    );

    assert.equal(contractAccount.company.toBase58(), companyPda.toBase58());
    assert.equal(
      contractAccount.employeeWallet.toBase58(),
      employeeWallet.toBase58()
    );
    assert.equal(contractAccount.version, 1);
    assert.deepEqual(
      Buffer.from(contractAccount.currentCompensationRef),
      Buffer.alloc(32, 1)
    );
  });

  it("rejects an empty contract compensation reference hash", async () => {
    const employeeWallet = anchor.web3.Keypair.generate().publicKey;
    const version = 9;
    const [contractPda] = anchor.web3.PublicKey.findProgramAddressSync(
      [
        Buffer.from("contract"),
        companyPda.toBuffer(),
        employeeWallet.toBuffer(),
        u16Le(version),
      ],
      program.programId
    );

    await expectAnchorError(
      () =>
        program.methods
          .createEmploymentContract({
            employeeWallet,
            contractHash: Array.from(Buffer.alloc(32, version)),
            version,
            effectiveAt: new anchor.BN(1_776_009_600),
            payCycle: 1,
            currentCompensationRef: Array.from(Buffer.alloc(32, 0)),
          })
          .accounts({
            company: companyPda,
            contract: contractPda,
            authority,
            systemProgram: anchor.web3.SystemProgram.programId,
          })
          .rpc(),
      "Reference hashes must not be empty"
    );
  });

  it("records a compensation amendment and updates the contract pointer", async () => {
    const employeeWallet = anchor.web3.Keypair.generate().publicKey;
    const contractPda = await createContract(employeeWallet, 2);
    const effectiveAt = 1_776_096_000;
    const [amendmentPda] = anchor.web3.PublicKey.findProgramAddressSync(
      [
        Buffer.from("amendment"),
        contractPda.toBuffer(),
        new anchor.BN(effectiveAt).toArrayLike(Buffer, "le", 8),
      ],
      program.programId
    );

    await program.methods
      .amendCompensation({
        effectiveAt: new anchor.BN(effectiveAt),
        amendmentHash: Array.from(Buffer.alloc(32, 9)),
      })
      .accounts({
        company: companyPda,
        contract: contractPda,
        amendment: amendmentPda,
        authority,
        systemProgram: anchor.web3.SystemProgram.programId,
      })
      .rpc();

    const amendmentAccount = await program.account.compensationAmendment.fetch(
      amendmentPda
    );
    const contractAccount = await program.account.employmentContract.fetch(
      contractPda
    );

    assert.deepEqual(
      Buffer.from(amendmentAccount.amendmentHash),
      Buffer.alloc(32, 9)
    );
    assert.isAbove(Number(amendmentAccount.createdAt), 0);
    assert.equal(
      contractAccount.latestAmendment.toBase58(),
      amendmentPda.toBase58()
    );
    assert.deepEqual(
      Buffer.from(contractAccount.currentCompensationRef),
      Buffer.alloc(32, 9)
    );
  });

  it("rejects an empty amendment hash", async () => {
    const employeeWallet = anchor.web3.Keypair.generate().publicKey;
    const contractPda = await createContract(employeeWallet, 10);
    const effectiveAt = 1_776_182_400;
    const [amendmentPda] = anchor.web3.PublicKey.findProgramAddressSync(
      [
        Buffer.from("amendment"),
        contractPda.toBuffer(),
        new anchor.BN(effectiveAt).toArrayLike(Buffer, "le", 8),
      ],
      program.programId
    );

    await expectAnchorError(
      () =>
        program.methods
          .amendCompensation({
            effectiveAt: new anchor.BN(effectiveAt),
            amendmentHash: Array.from(Buffer.alloc(32, 0)),
          })
          .accounts({
            company: companyPda,
            contract: contractPda,
            amendment: amendmentPda,
            authority,
            systemProgram: anchor.web3.SystemProgram.programId,
          })
          .rpc(),
      "Reference hashes must not be empty"
    );
  });

  it("commits a payroll batch account", async () => {
    const batchPda = batchPdaFor(2026, 7);

    await program.methods
      .commitPayrollBatch({
        periodYear: 2026,
        periodMonth: 7,
        entryCount: 2,
        entriesRoot: Array.from(Buffer.alloc(32, 7)),
      })
      .accounts({
        company: companyPda,
        payrollBatch: batchPda,
        authority,
        systemProgram: anchor.web3.SystemProgram.programId,
      })
      .rpc();

    const batchAccount = await program.account.payrollBatch.fetch(batchPda);

    assert.equal(batchAccount.company.toBase58(), companyPda.toBase58());
    assert.equal(batchAccount.periodYear, 2026);
    assert.equal(batchAccount.periodMonth, 7);
    assert.equal(batchAccount.entryCount, 2);
    assert.deepEqual(
      Buffer.from(batchAccount.entriesRoot),
      Buffer.alloc(32, 7)
    );
    assert.deepEqual(
      Buffer.from(batchAccount.approvalRoot),
      Buffer.alloc(32, 0)
    );
    assert.deepEqual(
      Buffer.from(batchAccount.settlementRoot),
      Buffer.alloc(32, 0)
    );
    assert.equal(
      batchAccount.approvedBy.toBase58(),
      anchor.web3.PublicKey.default.toBase58()
    );
    assert.equal(
      batchAccount.finalizedBy.toBase58(),
      anchor.web3.PublicKey.default.toBase58()
    );
    assert.equal(Number(batchAccount.approvedAt), 0);
    assert.isAbove(Number(batchAccount.createdAt), 0);
    assert.equal(Number(batchAccount.executedAt), 0);
  });

  it("rejects an invalid payroll period", async () => {
    const batchPda = batchPdaFor(2026, 13);

    await expectAnchorError(
      () =>
        program.methods
          .commitPayrollBatch({
            periodYear: 2026,
            periodMonth: 13,
            entryCount: 1,
            entriesRoot: Array.from(Buffer.alloc(32, 7)),
          })
          .accounts({
            company: companyPda,
            payrollBatch: batchPda,
            authority,
            systemProgram: anchor.web3.SystemProgram.programId,
          })
          .rpc(),
      "Payroll period is invalid"
    );
  });

  it("rejects an empty entries root", async () => {
    const batchPda = batchPdaFor(2026, 8);
    await expectAnchorError(
      () =>
        program.methods
          .commitPayrollBatch({
            periodYear: 2026,
            periodMonth: 8,
            entryCount: 1,
            entriesRoot: Array.from(Buffer.alloc(32, 0)),
          })
          .accounts({
            company: companyPda,
            payrollBatch: batchPda,
            authority,
            systemProgram: anchor.web3.SystemProgram.programId,
          })
          .rpc(),
      "Reference hashes must not be empty"
    );
  });

  it("rejects an empty entry count", async () => {
    const batchPda = batchPdaFor(2026, 9);

    await expectAnchorError(
      () =>
        program.methods
          .commitPayrollBatch({
            periodYear: 2026,
            periodMonth: 9,
            entryCount: 0,
            entriesRoot: Array.from(Buffer.alloc(32, 9)),
          })
          .accounts({
            company: companyPda,
            payrollBatch: batchPda,
            authority,
            systemProgram: anchor.web3.SystemProgram.programId,
          })
          .rpc(),
      "Payroll batch entry count must be greater than zero"
    );
  });

  it("approves a committed payroll batch", async () => {
    const batchPda = batchPdaFor(2026, 7);

    await program.methods
      .approvePayrollBatch({
        approvalRoot: Array.from(Buffer.alloc(32, 8)),
      })
      .accounts({
        company: companyPda,
        payrollBatch: batchPda,
        authority,
      })
      .rpc();

    const batchAccount = await program.account.payrollBatch.fetch(batchPda);

    assert.equal(batchAccount.status, 2);
    assert.deepEqual(
      Buffer.from(batchAccount.approvalRoot),
      Buffer.alloc(32, 8)
    );
    assert.equal(batchAccount.approvedBy.toBase58(), authority.toBase58());
    assert.isAbove(Number(batchAccount.approvedAt), 0);
    assert.equal(Number(batchAccount.executedAt), 0);
  });

  it("rejects approving an already approved payroll batch twice", async () => {
    const batchPda = batchPdaFor(2026, 7);

    await expectAnchorError(
      () =>
        program.methods
          .approvePayrollBatch({
            approvalRoot: Array.from(Buffer.alloc(32, 9)),
          })
          .accounts({
            company: companyPda,
            payrollBatch: batchPda,
            authority,
          })
          .rpc(),
      "Payroll batch is in an invalid state for this action"
    );
  });

  it("finalizes an approved payroll batch", async () => {
    const batchPda = batchPdaFor(2026, 7);

    await program.methods
      .finalizePayrollBatch({
        settlementRoot: Array.from(Buffer.alloc(32, 10)),
      })
      .accounts({
        company: companyPda,
        payrollBatch: batchPda,
        authority,
      })
      .rpc();

    const batchAccount = await program.account.payrollBatch.fetch(batchPda);

    assert.equal(batchAccount.status, 3);
    assert.deepEqual(
      Buffer.from(batchAccount.settlementRoot),
      Buffer.alloc(32, 10)
    );
    assert.equal(batchAccount.finalizedBy.toBase58(), authority.toBase58());
    assert.isAbove(Number(batchAccount.executedAt), 0);
  });

  it("rejects finalizing an already finalized payroll batch twice", async () => {
    const batchPda = batchPdaFor(2026, 7);

    await expectAnchorError(
      () =>
        program.methods
          .finalizePayrollBatch({
            settlementRoot: Array.from(Buffer.alloc(32, 11)),
          })
          .accounts({
            company: companyPda,
            payrollBatch: batchPda,
            authority,
          })
          .rpc(),
      "Payroll batch is in an invalid state for this action"
    );
  });

  it("rejects finalizing a batch before approval", async () => {
    const batchPda = batchPdaFor(2026, 10);

    await program.methods
      .commitPayrollBatch({
        periodYear: 2026,
        periodMonth: 10,
        entryCount: 1,
        entriesRoot: Array.from(Buffer.alloc(32, 10)),
      })
      .accounts({
        company: companyPda,
        payrollBatch: batchPda,
        authority,
        systemProgram: anchor.web3.SystemProgram.programId,
      })
      .rpc();

    await expectAnchorError(
      () =>
        program.methods
          .finalizePayrollBatch({
            settlementRoot: Array.from(Buffer.alloc(32, 12)),
          })
          .accounts({
            company: companyPda,
            payrollBatch: batchPda,
            authority,
          })
          .rpc(),
      "Payroll batch is in an invalid state for this action"
    );
  });

  it("rejects unauthorized payroll batch approval attempts", async () => {
    const outsider = anchor.web3.Keypair.generate();
    await fundSigner(outsider);

    await expectAnchorError(
      () =>
        program.methods
          .approvePayrollBatch({
            approvalRoot: Array.from(Buffer.alloc(32, 13)),
          })
          .accounts({
            company: companyPda,
            payrollBatch: batchPdaFor(2026, 10),
            authority: outsider.publicKey,
          })
          .signers([outsider])
          .rpc(),
      "A seeds constraint was violated"
    );
  });

  it("rejects company mismatch when finalizing a batch", async () => {
    const outsider = anchor.web3.Keypair.generate();
    await fundSigner(outsider);
    const [outsiderCompanyPda] = anchor.web3.PublicKey.findProgramAddressSync(
      [Buffer.from("company"), outsider.publicKey.toBuffer()],
      program.programId
    );

    await program.methods
      .initializeCompany({
        name: "Other Demo",
        slug: "other-demo",
        treasuryWallet: outsider.publicKey,
      })
      .accounts({
        company: outsiderCompanyPda,
        authority: outsider.publicKey,
        systemProgram: anchor.web3.SystemProgram.programId,
      })
      .signers([outsider])
      .rpc();

    const batchPda = batchPdaFor(2026, 10);

    await expectAnchorError(
      () =>
        program.methods
          .finalizePayrollBatch({
            settlementRoot: Array.from(Buffer.alloc(32, 14)),
          })
          .accounts({
            company: outsiderCompanyPda,
            payrollBatch: batchPda,
            authority: outsider.publicKey,
          })
          .signers([outsider])
          .rpc(),
      "The referenced company does not match the account relationship"
    );
  });
});
