import * as anchor from "@coral-xyz/anchor";
import { assert } from "chai";
import { Program } from "@coral-xyz/anchor";
import { AsterPayroll } from "../target/types/aster_payroll";

describe("aster_payroll", () => {
  // Configure the client to use the local cluster.
  anchor.setProvider(anchor.AnchorProvider.env());
  const provider = anchor.getProvider() as anchor.AnchorProvider;

  const program = anchor.workspace.asterPayroll as Program<AsterPayroll>;

  it("initializes a company account", async () => {
    const [companyPda] = anchor.web3.PublicKey.findProgramAddressSync(
      [Buffer.from("company"), provider.wallet.publicKey.toBuffer()],
      program.programId
    );

    await program.methods
      .initializeCompany({
        name: "Aster Payroll Demo",
        slug: "aster-demo",
        treasuryWallet: provider.wallet.publicKey,
      })
      .accounts({
        company: companyPda,
        authority: provider.wallet.publicKey,
        systemProgram: anchor.web3.SystemProgram.programId,
      })
      .rpc();

    const companyAccount = await program.account.companyAccount.fetch(companyPda);
    assert.equal(companyAccount.slug, "aster-demo");
    assert.equal(companyAccount.name, "Aster Payroll Demo");
  });
});
