#!/usr/bin/env node

const anchor = require("@coral-xyz/anchor");
const fs = require("fs");
const path = require("path");

const { PublicKey, Keypair, SystemProgram, Connection, LAMPORTS_PER_SOL } =
  anchor.web3;

async function readStdin() {
  return new Promise((resolve, reject) => {
    let data = "";
    process.stdin.setEncoding("utf8");
    process.stdin.on("data", (chunk) => {
      data += chunk;
    });
    process.stdin.on("end", () => resolve(data));
    process.stdin.on("error", reject);
  });
}

function requireCommand() {
  const command = process.argv[2];

  if (!command) {
    throw new Error(
      "Usage: anchor-attest.js <create-employment-contract|amend-compensation|commit-payroll-batch|approve-payroll-batch|finalize-payroll-batch>"
    );
  }

  return command;
}

function requireWalletPath() {
  const walletPath =
    process.env.ASTER_ANCHOR_WALLET || process.env.ANCHOR_WALLET;

  if (!walletPath) {
    throw new Error("ASTER_ANCHOR_WALLET or ANCHOR_WALLET is required.");
  }

  return walletPath;
}

function loadWallet(walletPath) {
  const secret = JSON.parse(fs.readFileSync(walletPath, "utf8"));

  return Keypair.fromSecretKey(Uint8Array.from(secret));
}

function hexToBytes(value, expectedLength) {
  const normalized = String(value || "").trim();
  const buffer = Buffer.from(normalized, "hex");

  if (buffer.length !== expectedLength) {
    throw new Error(
      `Expected a ${expectedLength}-byte hex value, received ${buffer.length} bytes.`
    );
  }

  return Array.from(buffer);
}

function toBn(value) {
  return new anchor.BN(String(value));
}

function bnToNumber(value) {
  return Number(value?.toString?.() ?? value ?? 0);
}

function toLeBytes(value, size) {
  return toBn(value).toArrayLike(Buffer, "le", size);
}

function u16Le(value) {
  const buffer = Buffer.alloc(2);
  buffer.writeUInt16LE(Number(value), 0);

  return buffer;
}

function mapPayCycle(value) {
  switch (value) {
    case "monthly":
      return 1;
    case "semi_monthly":
      return 2;
    case "bi_weekly":
      return 3;
    default:
      throw new Error(`Unsupported pay cycle: ${value}`);
  }
}

function canRequestLocalAirdrop(rpcUrl) {
  return /(^|\/\/)(127\.0\.0\.1|localhost|aster-payroll-confidential-validator)(:|\/|$)/.test(
    rpcUrl
  );
}

async function ensureLocalFeePayerBalance(connection, authority, rpcUrl) {
  if (!canRequestLocalAirdrop(rpcUrl)) {
    return;
  }

  const balance = await connection.getBalance(authority.publicKey, "confirmed");

  if (balance >= LAMPORTS_PER_SOL) {
    return;
  }

  const signature = await connection.requestAirdrop(
    authority.publicKey,
    5 * LAMPORTS_PER_SOL
  );
  const latestBlockhash = await connection.getLatestBlockhash("confirmed");

  await connection.confirmTransaction(
    {
      signature,
      ...latestBlockhash,
    },
    "confirmed"
  );
}

async function ensureCompany(program, authority, payload) {
  const [companyPda] = PublicKey.findProgramAddressSync(
    [Buffer.from("company"), authority.publicKey.toBuffer()],
    program.programId
  );

  let existing = null;

  try {
    existing = await program.account.companyAccount.fetchNullable(companyPda);
  } catch (_error) {
    existing = null;
  }

  let initializationSignature = null;

  if (!existing) {
    const treasuryWallet = payload.company.wallet_address
      ? new PublicKey(payload.company.wallet_address)
      : authority.publicKey;

    initializationSignature = await program.methods
      .initializeCompany({
        name: payload.company.name,
        slug: payload.company.slug,
        treasuryWallet,
      })
      .accounts({
        company: companyPda,
        authority: authority.publicKey,
        systemProgram: SystemProgram.programId,
      })
      .rpc();
  }

  return {
    companyPda,
    initializationSignature,
  };
}

async function createEmploymentContract(program, authority, payload) {
  const { companyPda, initializationSignature } = await ensureCompany(
    program,
    authority,
    payload
  );
  const employeeWallet = new PublicKey(payload.employee.wallet_address);
  const version = Number(payload.contract.version);

  const [contractPda] = PublicKey.findProgramAddressSync(
    [
      Buffer.from("contract"),
      companyPda.toBuffer(),
      employeeWallet.toBuffer(),
      u16Le(version),
    ],
    program.programId
  );

  const signature = await program.methods
    .createEmploymentContract({
      employeeWallet,
      contractHash: hexToBytes(payload.contract.file_hash, 32),
      version,
      effectiveAt: toBn(payload.contract.effective_at),
      payCycle: mapPayCycle(payload.contract.pay_cycle),
      currentCompensationRef: hexToBytes(
        payload.contract.current_compensation_ref,
        32
      ),
    })
    .accounts({
      company: companyPda,
      contract: contractPda,
      authority: authority.publicKey,
      systemProgram: SystemProgram.programId,
    })
    .rpc();

  return {
    company_pubkey: companyPda.toBase58(),
    account_pubkey: contractPda.toBase58(),
    tx_signature: signature,
    company_initialization_tx_signature: initializationSignature,
    authority_pubkey: authority.publicKey.toBase58(),
  };
}

async function amendCompensation(program, authority, payload) {
  const { companyPda, initializationSignature } = await ensureCompany(
    program,
    authority,
    payload
  );
  const contractPubkey = new PublicKey(payload.contract.anchor_contract_pubkey);
  const effectiveAt = Number(payload.amendment.effective_at);

  const [amendmentPda] = PublicKey.findProgramAddressSync(
    [
      Buffer.from("amendment"),
      contractPubkey.toBuffer(),
      toLeBytes(effectiveAt, 8),
    ],
    program.programId
  );

  const signature = await program.methods
    .amendCompensation({
      effectiveAt: toBn(effectiveAt),
      amendmentHash: hexToBytes(payload.amendment.amendment_hash, 32),
    })
    .accounts({
      company: companyPda,
      contract: contractPubkey,
      amendment: amendmentPda,
      authority: authority.publicKey,
      systemProgram: SystemProgram.programId,
    })
    .rpc();

  return {
    company_pubkey: companyPda.toBase58(),
    account_pubkey: amendmentPda.toBase58(),
    tx_signature: signature,
    company_initialization_tx_signature: initializationSignature,
    authority_pubkey: authority.publicKey.toBase58(),
  };
}

async function commitPayrollBatch(program, authority, payload) {
  const { companyPda, initializationSignature } = await ensureCompany(
    program,
    authority,
    payload
  );
  const periodYear = Number(payload.batch.period_year);
  const periodMonth = Number(payload.batch.period_month);

  const [batchPda] = PublicKey.findProgramAddressSync(
    [
      Buffer.from("batch"),
      companyPda.toBuffer(),
      u16Le(periodYear),
      Buffer.from([periodMonth]),
    ],
    program.programId
  );

  const signature = await program.methods
    .commitPayrollBatch({
      periodYear,
      periodMonth,
      entryCount: Number(payload.batch.entry_count),
      entriesRoot: hexToBytes(payload.batch.entries_root, 32),
    })
    .accounts({
      company: companyPda,
      payrollBatch: batchPda,
      authority: authority.publicKey,
      systemProgram: SystemProgram.programId,
    })
    .rpc();

  return {
    company_pubkey: companyPda.toBase58(),
    account_pubkey: batchPda.toBase58(),
    tx_signature: signature,
    company_initialization_tx_signature: initializationSignature,
    authority_pubkey: authority.publicKey.toBase58(),
  };
}

function batchPubkeyFor(program, companyPda, payload) {
  return payload.batch.anchor_batch_pubkey
    ? new PublicKey(payload.batch.anchor_batch_pubkey)
    : PublicKey.findProgramAddressSync(
        [
          Buffer.from("batch"),
          companyPda.toBuffer(),
          u16Le(Number(payload.batch.period_year)),
          Buffer.from([Number(payload.batch.period_month)]),
        ],
        program.programId
      )[0];
}

async function approvePayrollBatch(program, authority, payload) {
  const { companyPda, initializationSignature } = await ensureCompany(
    program,
    authority,
    payload
  );
  const batchPubkey = batchPubkeyFor(program, companyPda, payload);

  const signature = await program.methods
    .approvePayrollBatch({
      approvalRoot: hexToBytes(payload.batch.approval_root, 32),
    })
    .accounts({
      company: companyPda,
      payrollBatch: batchPubkey,
      authority: authority.publicKey,
    })
    .rpc();
  const batchAccount = await program.account.payrollBatch.fetch(batchPubkey);

  return {
    company_pubkey: companyPda.toBase58(),
    account_pubkey: batchPubkey.toBase58(),
    tx_signature: signature,
    company_initialization_tx_signature: initializationSignature,
    authority_pubkey: authority.publicKey.toBase58(),
    approved_at: bnToNumber(batchAccount.approvedAt),
  };
}

async function finalizePayrollBatch(program, authority, payload) {
  const { companyPda, initializationSignature } = await ensureCompany(
    program,
    authority,
    payload
  );
  const batchPubkey = batchPubkeyFor(program, companyPda, payload);

  const signature = await program.methods
    .finalizePayrollBatch({
      settlementRoot: hexToBytes(payload.batch.settlement_root, 32),
    })
    .accounts({
      company: companyPda,
      payrollBatch: batchPubkey,
      authority: authority.publicKey,
    })
    .rpc();
  const batchAccount = await program.account.payrollBatch.fetch(batchPubkey);

  return {
    company_pubkey: companyPda.toBase58(),
    account_pubkey: batchPubkey.toBase58(),
    tx_signature: signature,
    company_initialization_tx_signature: initializationSignature,
    authority_pubkey: authority.publicKey.toBase58(),
    finalized_by: batchAccount.finalizedBy.toBase58(),
    executed_at: bnToNumber(batchAccount.executedAt),
  };
}

async function main() {
  const command = requireCommand();
  const input = await readStdin();
  const payload = JSON.parse(input || "{}");
  const walletPath = requireWalletPath();
  const authority = loadWallet(walletPath);
  const idl = JSON.parse(
    fs.readFileSync(
      path.resolve(__dirname, "../target/idl/aster_payroll.json"),
      "utf8"
    )
  );
  idl.address = process.env.ASTER_ANCHOR_PROGRAM_ID || idl.address;
  const rpcUrl = process.env.ASTER_SOLANA_RPC_URL || "http://127.0.0.1:8899";
  const connection = new Connection(rpcUrl, "confirmed");
  await ensureLocalFeePayerBalance(connection, authority, rpcUrl);
  const provider = new anchor.AnchorProvider(
    connection,
    new anchor.Wallet(authority),
    {
      commitment: "confirmed",
    }
  );

  anchor.setProvider(provider);

  const program = new anchor.Program(idl, provider);

  let result;

  switch (command) {
    case "create-employment-contract":
      result = await createEmploymentContract(program, authority, payload);
      break;
    case "amend-compensation":
      result = await amendCompensation(program, authority, payload);
      break;
    case "commit-payroll-batch":
      result = await commitPayrollBatch(program, authority, payload);
      break;
    case "approve-payroll-batch":
      result = await approvePayrollBatch(program, authority, payload);
      break;
    case "finalize-payroll-batch":
      result = await finalizePayrollBatch(program, authority, payload);
      break;
    default:
      throw new Error(`Unsupported command: ${command}`);
  }

  process.stdout.write(`${JSON.stringify(result)}\n`);
}

main().catch((error) => {
  process.stderr.write(`${error?.stack || error?.message || String(error)}\n`);
  process.exit(1);
});
