#!/usr/bin/env node

const anchor = require("@coral-xyz/anchor");
const crypto = require("crypto");
const dns = require("dns").promises;
const fs = require("fs");
const os = require("os");
const path = require("path");
const { spawnSync } = require("child_process");

const { Connection, Keypair, LAMPORTS_PER_SOL, PublicKey } = anchor.web3;

const ROOT_DIR = path.resolve(__dirname, "../..");
const DEFAULT_WORK_DIR = path.join(
  ROOT_DIR,
  "onchain/.artifacts/confidential-payroll-poc"
);
const TOKEN_PROGRAM_ID =
  process.env.ASTER_TOKEN_2022_PROGRAM_ID ||
  "TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb";
const BASE58_RE = /[1-9A-HJ-NP-Za-km-z]{32,44}/g;
const SIGNATURE_RE = /[1-9A-HJ-NP-Za-km-z]{80,90}/g;

function usage() {
  return `Usage:
  ASTER_PAYOUT_MANIFEST=/abs/path/to/execution.json \\
  ASTER_COMPANY_OWNER_KEYPAIR=/abs/path/to/admin-company-wallet.json \\
  yarn signer

  yarn signer --manifest /abs/path/to/execution.json \\
    --company-owner-keypair /abs/path/to/admin-company-wallet.json

Optional environment variables:
  ASTER_SOLANA_RPC_URL
  ASTER_ANCHOR_WALLET
  ASTER_CONFIDENTIAL_POC_DIR
  ASTER_CONFIDENTIAL_POC_OUTPUT
  ASTER_EMPLOYEE_OWNER_KEYPAIR
  ASTER_PAYOUT_FEE_PAYER_KEYPAIR
  ASTER_CONFIDENTIAL_MINT_AMOUNT
`;
}

function parseArgs(argv) {
  const options = {
    manifestPath: process.env.ASTER_PAYOUT_MANIFEST || "",
    companyOwnerKeypair:
      process.env.ASTER_COMPANY_OWNER_KEYPAIR ||
      process.env.ASTER_ANCHOR_WALLET ||
      process.env.ANCHOR_WALLET ||
      "",
  };

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];

    if (arg === "--manifest") {
      options.manifestPath = argv[index + 1] || "";
      index += 1;
      continue;
    }

    if (arg === "--company-owner-keypair") {
      options.companyOwnerKeypair = argv[index + 1] || "";
      index += 1;
      continue;
    }

    if (arg === "--help" || arg === "-h") {
      options.help = true;
      continue;
    }

    throw new Error(`Unknown argument: ${arg}`);
  }

  if (!options.companyOwnerKeypair) {
    const defaultWallet = path.join(os.homedir(), ".config/solana/id.json");
    if (fs.existsSync(defaultWallet)) {
      options.companyOwnerKeypair = defaultWallet;
    }
  }

  return options;
}

function resolveUserPath(value) {
  if (!value) {
    return "";
  }

  if (value === "~") {
    return os.homedir();
  }

  if (value.startsWith("~/")) {
    return path.join(os.homedir(), value.slice(2));
  }

  return path.resolve(value);
}

function readJsonFile(filePath) {
  return JSON.parse(fs.readFileSync(filePath, "utf8"));
}

function writeJsonFile(filePath, value) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(`${filePath}.tmp`, `${JSON.stringify(value, null, 2)}\n`, {
    mode: 0o600,
  });
  fs.renameSync(`${filePath}.tmp`, filePath);
}

function loadOrCreateKeypair(filePath) {
  if (fs.existsSync(filePath)) {
    return Keypair.fromSecretKey(Uint8Array.from(readJsonFile(filePath)));
  }

  const keypair = Keypair.generate();
  writeJsonFile(filePath, Array.from(keypair.secretKey));

  return keypair;
}

function loadKeypair(filePath) {
  return Keypair.fromSecretKey(Uint8Array.from(readJsonFile(filePath)));
}

function sha256File(filePath) {
  return crypto
    .createHash("sha256")
    .update(fs.readFileSync(filePath))
    .digest("hex");
}

function extractFromJson(value, candidates) {
  for (const candidate of candidates) {
    const resolved = candidate
      .split(".")
      .reduce(
        (current, key) =>
          current && current[key] !== undefined ? current[key] : undefined,
        value
      );

    if (typeof resolved === "string" && resolved.trim() !== "") {
      return resolved.trim();
    }
  }

  return "";
}

function extractSignature(rawOutput) {
  try {
    const parsed = JSON.parse(rawOutput);
    const signature = extractFromJson(parsed, [
      "commandOutput.signature",
      "signature",
      "txid",
      "transactionSignature",
    ]);

    if (signature) {
      return signature;
    }
  } catch (_error) {
    // Fall through to the regex parser for CLI versions that print text.
  }

  const matches = rawOutput.match(SIGNATURE_RE);

  return matches ? matches[0] : "";
}

function extractAddress(rawOutput) {
  try {
    const parsed = JSON.parse(rawOutput);
    const address = extractFromJson(parsed, [
      "commandOutput.address",
      "commandOutput.account",
      "commandOutput.walletAddress",
      "address",
      "account",
      "walletAddress",
    ]);

    if (address) {
      return address;
    }
  } catch (_error) {
    // Fall through to the regex parser for CLI versions that print text.
  }

  const matches = rawOutput.match(BASE58_RE);

  return matches ? matches[0] : "";
}

function runCommand(command, args, options = {}) {
  const result = spawnSync(command, args, {
    encoding: "utf8",
    maxBuffer: 1024 * 1024 * 20,
    ...options,
  });

  if (result.error) {
    throw result.error;
  }

  if (result.status !== 0) {
    const rendered = [command, ...args].join(" ");
    throw new Error(
      `Command failed (${result.status}): ${rendered}\n${
        result.stderr || result.stdout
      }`
    );
  }

  return result.stdout.trim();
}

function requireBinary(command) {
  const paths = (process.env.PATH || "").split(path.delimiter);
  const found = paths.some((dir) => fs.existsSync(path.join(dir, command)));

  if (!found) {
    throw new Error(`Missing required binary: ${command}`);
  }
}

function writeSolanaConfig(configPath, rpcUrl, keypairPath) {
  fs.mkdirSync(path.dirname(configPath), { recursive: true });
  fs.writeFileSync(
    configPath,
    [
      "---",
      `json_rpc_url: ${rpcUrl}`,
      "websocket_url: ''",
      `keypair_path: ${keypairPath}`,
      "address_labels:",
      "  '11111111111111111111111111111111': System Program",
      "commitment: confirmed",
      "",
    ].join("\n"),
    { mode: 0o600 }
  );
}

function runStep(logDir, name, args) {
  const output = runCommand("spl-token", [...args, "--output", "json-compact"]);
  const logPath = path.join(logDir, `${name}.json`);

  fs.mkdirSync(logDir, { recursive: true });
  fs.writeFileSync(logPath, output ? `${output}\n` : "", { mode: 0o600 });

  return output;
}

function runSplToken(args) {
  return runCommand("spl-token", args);
}

function findAssociatedTokenAccount(configPath, mintAddress) {
  const output = runSplToken([
    "--config",
    configPath,
    "--program-2022",
    "accounts",
    "--output",
    "json-compact",
  ]);
  const parsed = JSON.parse(output);
  const account = (parsed.accounts || []).find(
    (candidate) =>
      candidate.mint === mintAddress && candidate.isAssociated === true
  );

  if (!account || !account.address) {
    return "";
  }

  return account.address;
}

function canRequestLocalAirdrop(rpcUrl) {
  return /(^|\/\/)(127\.0\.0\.1|localhost|aster-payroll-confidential-validator)(:|\/|$)/.test(
    rpcUrl
  );
}

async function defaultRpcUrl() {
  try {
    await dns.lookup("aster-payroll-confidential-validator");
    return "http://aster-payroll-confidential-validator:8899";
  } catch (_error) {
    return "http://host.docker.internal:8899";
  }
}

async function ensureLocalFeePayerBalance(connection, payer, rpcUrl) {
  if (!canRequestLocalAirdrop(rpcUrl)) {
    return;
  }

  const balance = await connection.getBalance(payer.publicKey, "confirmed");

  if (balance >= LAMPORTS_PER_SOL) {
    return;
  }

  const signature = await connection.requestAirdrop(
    payer.publicKey,
    100 * LAMPORTS_PER_SOL
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

function assertPublicKey(value, label) {
  try {
    return new PublicKey(value).toBase58();
  } catch (error) {
    throw new Error(`${label} is not a valid Solana public key: ${value}`);
  }
}

async function main() {
  process.umask(0o077);

  const options = parseArgs(process.argv.slice(2));

  if (options.help) {
    process.stdout.write(usage());
    return;
  }

  const manifestPath = resolveUserPath(options.manifestPath);
  const companyOwnerKeypairPath = resolveUserPath(options.companyOwnerKeypair);

  if (!manifestPath || !companyOwnerKeypairPath) {
    process.stderr.write(usage());
    process.exitCode = 1;
    return;
  }

  if (!fs.existsSync(manifestPath)) {
    throw new Error(`Prepared payout manifest not found: ${manifestPath}`);
  }

  if (!fs.existsSync(companyOwnerKeypairPath)) {
    throw new Error(
      `Admin-controlled company signer keypair not found: ${companyOwnerKeypairPath}`
    );
  }

  requireBinary("spl-token");

  const workDir =
    resolveUserPath(process.env.ASTER_CONFIDENTIAL_POC_DIR) || DEFAULT_WORK_DIR;
  const logDir = path.join(workDir, "logs");
  const rpcUrl =
    process.env.ASTER_SOLANA_RPC_URL ||
    process.env.RPC_URL ||
    (await defaultRpcUrl());
  const payerKeypairPath =
    resolveUserPath(process.env.ASTER_PAYOUT_FEE_PAYER_KEYPAIR) ||
    path.join(workDir, "payer.json");
  const employeeOwnerKeypairPath =
    resolveUserPath(process.env.ASTER_EMPLOYEE_OWNER_KEYPAIR) ||
    path.join(workDir, "employee-owner.json");
  const payerConfigPath = path.join(workDir, "payer-config.yml");
  const companyOwnerConfigPath = path.join(workDir, "company-owner-config.yml");
  const employeeOwnerConfigPath = path.join(
    workDir,
    "employee-owner-config.yml"
  );
  const manifest = readJsonFile(manifestPath);
  const mintDecimals = Number(
    manifest.payroll && manifest.payroll.mint_decimals !== undefined
      ? manifest.payroll.mint_decimals
      : 2
  );
  const amountMinor = Number(manifest.payroll && manifest.payroll.amount_minor);
  const transferAmount = Number(
    manifest.payroll && manifest.payroll.confidential_transfer_amount
  );
  const executionId = Number(
    manifest.execution && manifest.execution.execution_id
  );
  const payrollEntryId = Number(
    manifest.execution && manifest.execution.payroll_entry_id
  );
  const payrollBatchId = Number(
    manifest.execution && manifest.execution.payroll_batch_id
  );
  const manifestDir = path.dirname(manifestPath);
  const receiptFileHint =
    (manifest.artifacts && manifest.artifacts.receipt_file_hint) ||
    `execution-${executionId}-receipt.json`;
  const outputPath =
    resolveUserPath(process.env.ASTER_CONFIDENTIAL_POC_OUTPUT) ||
    path.join(manifestDir, receiptFileHint);
  const companyExpectedWallet =
    (manifest.company && manifest.company.wallet_address) || "";
  const companyName =
    (manifest.company && manifest.company.name) || "Aster Payroll Demo Co.";
  const employeeName =
    (manifest.employee && manifest.employee.full_name) || "Demo Employee";
  const manifestHash = sha256File(manifestPath);
  const mintAmount =
    process.env.ASTER_CONFIDENTIAL_MINT_AMOUNT ||
    String(Math.floor(transferAmount * 4));

  if (!Number.isFinite(amountMinor) || !Number.isFinite(transferAmount)) {
    throw new Error("Manifest payroll amount is missing or invalid.");
  }

  const payer = loadOrCreateKeypair(payerKeypairPath);
  const companyOwner = loadKeypair(companyOwnerKeypairPath);
  const employeeOwner = loadOrCreateKeypair(employeeOwnerKeypairPath);

  writeSolanaConfig(payerConfigPath, rpcUrl, payerKeypairPath);
  writeSolanaConfig(companyOwnerConfigPath, rpcUrl, companyOwnerKeypairPath);
  writeSolanaConfig(employeeOwnerConfigPath, rpcUrl, employeeOwnerKeypairPath);

  const payerPubkey = payer.publicKey.toBase58();
  const companyOwnerPubkey = companyOwner.publicKey.toBase58();
  const employeeOwnerPubkey = employeeOwner.publicKey.toBase58();

  if (companyExpectedWallet && companyExpectedWallet !== companyOwnerPubkey) {
    throw new Error(
      `Manifest expects company wallet ${companyExpectedWallet}, but provided signer resolves to ${companyOwnerPubkey}.`
    );
  }

  const connection = new Connection(rpcUrl, "confirmed");
  const provider = new anchor.AnchorProvider(
    connection,
    new anchor.Wallet(companyOwner),
    anchor.AnchorProvider.defaultOptions()
  );
  anchor.setProvider(provider);

  await ensureLocalFeePayerBalance(connection, payer, rpcUrl);

  const createMintOutput = runStep(logDir, "create_mint", [
    "--config",
    payerConfigPath,
    "--program-2022",
    "create-token",
    "--decimals",
    String(mintDecimals),
    "--enable-confidential-transfers",
    "auto",
  ]);
  const mintAddress = extractAddress(createMintOutput);

  if (!mintAddress) {
    throw new Error("Failed to determine mint address.");
  }

  assertPublicKey(mintAddress, "Mint address");

  runStep(logDir, "create_company_account", [
    "--config",
    companyOwnerConfigPath,
    "--program-2022",
    "create-account",
    mintAddress,
    "--fee-payer",
    payerKeypairPath,
  ]);
  const companyTokenAccount = findAssociatedTokenAccount(
    companyOwnerConfigPath,
    mintAddress
  );

  if (!companyTokenAccount) {
    throw new Error("Failed to determine company token account.");
  }

  runStep(logDir, "create_employee_account", [
    "--config",
    payerConfigPath,
    "--program-2022",
    "create-account",
    mintAddress,
    "--owner",
    employeeOwnerPubkey,
    "--fee-payer",
    payerKeypairPath,
  ]);
  const employeeTokenAccount = findAssociatedTokenAccount(
    employeeOwnerConfigPath,
    mintAddress
  );

  if (!employeeTokenAccount) {
    throw new Error("Failed to determine employee token account.");
  }

  runStep(logDir, "configure_company_confidential_account", [
    "--config",
    companyOwnerConfigPath,
    "--program-2022",
    "configure-confidential-transfer-account",
    mintAddress,
    "--fee-payer",
    payerKeypairPath,
  ]);

  runStep(logDir, "configure_employee_confidential_account", [
    "--config",
    employeeOwnerConfigPath,
    "--program-2022",
    "configure-confidential-transfer-account",
    mintAddress,
    "--fee-payer",
    payerKeypairPath,
  ]);

  runStep(logDir, "mint_company_balance", [
    "--config",
    payerConfigPath,
    "--program-2022",
    "mint",
    mintAddress,
    mintAmount,
    companyTokenAccount,
  ]);

  runStep(logDir, "deposit_company_confidential_balance", [
    "--config",
    companyOwnerConfigPath,
    "--program-2022",
    "deposit-confidential-tokens",
    mintAddress,
    mintAmount,
    "--fee-payer",
    payerKeypairPath,
  ]);

  runStep(logDir, "apply_company_pending_balance", [
    "--config",
    companyOwnerConfigPath,
    "--program-2022",
    "apply-pending-balance",
    mintAddress,
    "--fee-payer",
    payerKeypairPath,
  ]);

  runStep(logDir, "confidential_transfer", [
    "--config",
    companyOwnerConfigPath,
    "--program-2022",
    "transfer",
    mintAddress,
    String(transferAmount),
    employeeTokenAccount,
    "--confidential",
    "--from",
    companyTokenAccount,
    "--allow-non-system-account-recipient",
    "--fee-payer",
    payerKeypairPath,
  ]);

  runStep(logDir, "apply_employee_pending_balance", [
    "--config",
    employeeOwnerConfigPath,
    "--program-2022",
    "apply-pending-balance",
    mintAddress,
    "--fee-payer",
    payerKeypairPath,
  ]);

  const companyPublicBalance = runSplToken([
    "--config",
    companyOwnerConfigPath,
    "--program-2022",
    "balance",
    mintAddress,
  ]);
  const employeePublicBalance = runSplToken([
    "--config",
    employeeOwnerConfigPath,
    "--program-2022",
    "balance",
    mintAddress,
  ]);
  const generatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, "Z");

  const signatureFor = (name) =>
    extractSignature(
      fs.readFileSync(path.join(logDir, `${name}.json`), "utf8")
    );

  const receipt = {
    generated_at: generatedAt,
    execution: {
      execution_id: executionId,
      payroll_entry_id: payrollEntryId,
      payroll_batch_id: payrollBatchId,
    },
    network: {
      rpc_url: rpcUrl,
      token_program_id: TOKEN_PROGRAM_ID,
    },
    approval: {
      method: "local_signer",
      approving_wallet_address: companyOwnerPubkey,
      approved_at: generatedAt,
      manifest_path: manifestPath,
      prepared_manifest_hash: manifestHash,
    },
    actors: {
      payer: payerPubkey,
      company_owner: companyOwnerPubkey,
      employee_owner: employeeOwnerPubkey,
    },
    token: {
      mint: mintAddress,
      decimals: mintDecimals,
      company_token_account: companyTokenAccount,
      employee_token_account: employeeTokenAccount,
    },
    payroll: {
      company_name: companyName,
      employee_name: employeeName,
      amount_minor: amountMinor,
      confidential_transfer_amount: transferAmount,
    },
    transactions: {
      create_mint: signatureFor("create_mint"),
      create_company_account: signatureFor("create_company_account"),
      create_employee_account: signatureFor("create_employee_account"),
      configure_company_confidential_account: signatureFor(
        "configure_company_confidential_account"
      ),
      configure_employee_confidential_account: signatureFor(
        "configure_employee_confidential_account"
      ),
      mint_company_balance: signatureFor("mint_company_balance"),
      deposit_company_confidential_balance: signatureFor(
        "deposit_company_confidential_balance"
      ),
      apply_company_pending_balance: signatureFor(
        "apply_company_pending_balance"
      ),
      confidential_transfer: signatureFor("confidential_transfer"),
      apply_employee_pending_balance: signatureFor(
        "apply_employee_pending_balance"
      ),
    },
    balances: {
      company_public_balance: companyPublicBalance,
      employee_public_balance: employeePublicBalance,
    },
    artifacts: {
      prepared_manifest_hash: manifestHash,
      work_dir: workDir,
      raw_logs_dir: logDir,
      signer_script: "onchain/scripts/confidential-payroll-signer.js",
    },
  };

  writeJsonFile(outputPath, receipt);
  process.stdout.write(`${JSON.stringify(receipt, null, 2)}\n`);
}

main().catch((error) => {
  process.stderr.write(`${error.message}\n`);
  process.exitCode = 1;
});
