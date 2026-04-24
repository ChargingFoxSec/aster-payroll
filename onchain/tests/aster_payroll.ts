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

  const createContract = async (employeeWallet: anchor.web3.PublicKey, version: number) => {
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

  before(async () => {
    const existing = await program.account.companyAccount.fetchNullable(companyPda);

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
    const companyAccount = await program.account.companyAccount.fetch(companyPda);

    assert.equal(companyAccount.slug, "aster-demo");
    assert.equal(companyAccount.name, "Aster Payroll Demo");
    assert.isAbove(Number(companyAccount.createdAt), 0);
  });

  it("creates an employment contract account", async () => {
    const employeeWallet = anchor.web3.Keypair.generate().publicKey;
    const contractPda = await createContract(employeeWallet, 1);
    const contractAccount = await program.account.employmentContract.fetch(contractPda);

    assert.equal(contractAccount.company.toBase58(), companyPda.toBase58());
    assert.equal(contractAccount.employeeWallet.toBase58(), employeeWallet.toBase58());
    assert.equal(contractAccount.version, 1);
    assert.deepEqual(Buffer.from(contractAccount.currentCompensationRef), Buffer.alloc(32, 1));
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
      [Buffer.from("amendment"), contractPda.toBuffer(), new anchor.BN(effectiveAt).toArrayLike(Buffer, "le", 8)],
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

    const amendmentAccount = await program.account.compensationAmendment.fetch(amendmentPda);
    const contractAccount = await program.account.employmentContract.fetch(contractPda);

    assert.deepEqual(Buffer.from(amendmentAccount.amendmentHash), Buffer.alloc(32, 9));
    assert.isAbove(Number(amendmentAccount.createdAt), 0);
    assert.equal(contractAccount.latestAmendment.toBase58(), amendmentPda.toBase58());
    assert.deepEqual(Buffer.from(contractAccount.currentCompensationRef), Buffer.alloc(32, 9));
  });

  it("rejects an empty amendment hash", async () => {
    const employeeWallet = anchor.web3.Keypair.generate().publicKey;
    const contractPda = await createContract(employeeWallet, 10);
    const effectiveAt = 1_776_182_400;
    const [amendmentPda] = anchor.web3.PublicKey.findProgramAddressSync(
      [Buffer.from("amendment"), contractPda.toBuffer(), new anchor.BN(effectiveAt).toArrayLike(Buffer, "le", 8)],
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

  it("creates a payroll batch account", async () => {
    const [batchPda] = anchor.web3.PublicKey.findProgramAddressSync(
      [
        Buffer.from("batch"),
        companyPda.toBuffer(),
        u16Le(2026),
        Buffer.from([7]),
      ],
      program.programId
    );

    await program.methods
      .createPayrollBatch({
        periodYear: 2026,
        periodMonth: 7,
        batchHash: Array.from(Buffer.alloc(32, 7)),
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
    assert.deepEqual(Buffer.from(batchAccount.batchHash), Buffer.alloc(32, 7));
    assert.isAbove(Number(batchAccount.createdAt), 0);
    assert.equal(Number(batchAccount.executedAt), 0);
  });

  it("rejects an invalid payroll period", async () => {
    const [batchPda] = anchor.web3.PublicKey.findProgramAddressSync(
      [
        Buffer.from("batch"),
        companyPda.toBuffer(),
        u16Le(2026),
        Buffer.from([13]),
      ],
      program.programId
    );

    await expectAnchorError(
      () =>
        program.methods
          .createPayrollBatch({
            periodYear: 2026,
            periodMonth: 13,
            batchHash: Array.from(Buffer.alloc(32, 7)),
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

  it("rejects an empty batch hash", async () => {
    const [batchPda] = anchor.web3.PublicKey.findProgramAddressSync(
      [
        Buffer.from("batch"),
        companyPda.toBuffer(),
        u16Le(2026),
        Buffer.from([8]),
      ],
      program.programId
    );

    await expectAnchorError(
      () =>
        program.methods
          .createPayrollBatch({
            periodYear: 2026,
            periodMonth: 8,
            batchHash: Array.from(Buffer.alloc(32, 0)),
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

  it("marks a payroll batch as executed", async () => {
    const [batchPda] = anchor.web3.PublicKey.findProgramAddressSync(
      [
        Buffer.from("batch"),
        companyPda.toBuffer(),
        u16Le(2026),
        Buffer.from([7]),
      ],
      program.programId
    );

    await program.methods
      .markPayrollBatchExecuted()
      .accounts({
        company: companyPda,
        payrollBatch: batchPda,
        authority,
      })
      .rpc();

    const batchAccount = await program.account.payrollBatch.fetch(batchPda);

    assert.equal(batchAccount.status, 2);
    assert.isAbove(Number(batchAccount.executedAt), 0);
  });

  it("rejects marking an already executed payroll batch twice", async () => {
    const [batchPda] = anchor.web3.PublicKey.findProgramAddressSync(
      [
        Buffer.from("batch"),
        companyPda.toBuffer(),
        u16Le(2026),
        Buffer.from([7]),
      ],
      program.programId
    );

    await expectAnchorError(
      () =>
        program.methods
          .markPayrollBatchExecuted()
          .accounts({
            company: companyPda,
            payrollBatch: batchPda,
            authority,
          })
          .rpc(),
      "Payroll batch is not ready to be marked as executed"
    );
  });

  it("rejects unauthorized payroll batch execution attempts", async () => {
    const outsider = anchor.web3.Keypair.generate();
    await fundSigner(outsider);

    await expectAnchorError(
      () =>
        program.methods
          .markPayrollBatchExecuted()
          .accounts({
            company: companyPda,
            payrollBatch: anchor.web3.PublicKey.findProgramAddressSync(
              [
                Buffer.from("batch"),
                companyPda.toBuffer(),
                u16Le(2026),
                Buffer.from([7]),
              ],
              program.programId
            )[0],
            authority: outsider.publicKey,
          })
          .signers([outsider])
          .rpc(),
      "A seeds constraint was violated"
    );
  });

  it("rejects company mismatch when marking a batch executed", async () => {
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

    const [batchPda] = anchor.web3.PublicKey.findProgramAddressSync(
      [
        Buffer.from("batch"),
        companyPda.toBuffer(),
        u16Le(2026),
        Buffer.from([7]),
      ],
      program.programId
    );

    await expectAnchorError(
      () =>
        program.methods
          .markPayrollBatchExecuted()
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
