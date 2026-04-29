use anchor_lang::prelude::*;

declare_id!("4SZ4Fdt4pYurKjtdfEkHvRm9zZ2uTnHmdkGFrQxp1EhE");

const MAX_COMPANY_NAME_LEN: usize = 64;
const MAX_COMPANY_SLUG_LEN: usize = 32;

const PAY_CYCLE_MONTHLY: u8 = 1;
const PAY_CYCLE_SEMI_MONTHLY: u8 = 2;
const PAY_CYCLE_BI_WEEKLY: u8 = 3;

const CONTRACT_STATUS_ACTIVE: u8 = 1;

const BATCH_STATUS_COMMITTED: u8 = 1;
const BATCH_STATUS_APPROVED: u8 = 2;
const BATCH_STATUS_FINALIZED: u8 = 3;

#[program]
pub mod aster_payroll {
    use super::*;

    pub fn initialize_company(
        ctx: Context<InitializeCompany>,
        args: InitializeCompanyArgs,
    ) -> Result<()> {
        require!(!args.name.trim().is_empty(), PayrollError::EmptyCompanyName);
        require!(!args.slug.trim().is_empty(), PayrollError::EmptyCompanySlug);
        require!(
            args.name.len() <= MAX_COMPANY_NAME_LEN,
            PayrollError::CompanyNameTooLong
        );
        require!(
            args.slug.len() <= MAX_COMPANY_SLUG_LEN,
            PayrollError::CompanySlugTooLong
        );

        let company = &mut ctx.accounts.company;
        company.authority = ctx.accounts.authority.key();
        company.treasury_wallet = args.treasury_wallet;
        company.name = args.name;
        company.slug = args.slug;
        company.created_at = Clock::get()?.unix_timestamp;
        company.bump = ctx.bumps.company;

        Ok(())
    }

    pub fn create_employment_contract(
        ctx: Context<CreateEmploymentContract>,
        args: CreateEmploymentContractArgs,
    ) -> Result<()> {
        require!(args.version > 0, PayrollError::InvalidVersion);
        require!(
            !is_zero_hash(&args.current_compensation_ref),
            PayrollError::InvalidReferenceHash
        );
        require!(
            is_supported_pay_cycle(args.pay_cycle),
            PayrollError::InvalidPayCycle
        );

        let contract = &mut ctx.accounts.contract;
        contract.company = ctx.accounts.company.key();
        contract.employee_wallet = args.employee_wallet;
        contract.contract_hash = args.contract_hash;
        contract.latest_amendment = Pubkey::default();
        contract.version = args.version;
        contract.effective_at = args.effective_at;
        contract.pay_cycle = args.pay_cycle;
        contract.current_compensation_ref = args.current_compensation_ref;
        contract.status = CONTRACT_STATUS_ACTIVE;
        contract.bump = ctx.bumps.contract;

        Ok(())
    }

    pub fn amend_compensation(
        ctx: Context<AmendCompensation>,
        args: AmendCompensationArgs,
    ) -> Result<()> {
        require!(
            !is_zero_hash(&args.amendment_hash),
            PayrollError::InvalidReferenceHash
        );

        let amendment = &mut ctx.accounts.amendment;
        amendment.company = ctx.accounts.company.key();
        amendment.contract = ctx.accounts.contract.key();
        amendment.effective_at = args.effective_at;
        amendment.amendment_hash = args.amendment_hash;
        amendment.created_at = Clock::get()?.unix_timestamp;
        amendment.bump = ctx.bumps.amendment;

        let contract = &mut ctx.accounts.contract;
        contract.current_compensation_ref = amendment.amendment_hash;
        contract.latest_amendment = amendment.key();
        contract.status = CONTRACT_STATUS_ACTIVE;

        Ok(())
    }

    pub fn commit_payroll_batch(
        ctx: Context<CommitPayrollBatch>,
        args: CommitPayrollBatchArgs,
    ) -> Result<()> {
        require!(
            is_valid_payroll_period(args.period_year, args.period_month),
            PayrollError::InvalidPayrollPeriod
        );
        require!(
            args.entry_count > 0,
            PayrollError::InvalidEntryCount
        );
        require!(
            !is_zero_hash(&args.entries_root),
            PayrollError::InvalidReferenceHash
        );

        let batch = &mut ctx.accounts.payroll_batch;
        let clock = Clock::get()?;
        batch.company = ctx.accounts.company.key();
        batch.period_year = args.period_year;
        batch.period_month = args.period_month;
        batch.entry_count = args.entry_count;
        batch.entries_root = args.entries_root;
        batch.approval_root = [0_u8; 32];
        batch.settlement_root = [0_u8; 32];
        batch.approved_by = Pubkey::default();
        batch.finalized_by = Pubkey::default();
        batch.approved_at = 0;
        batch.created_at = clock.unix_timestamp;
        batch.executed_at = 0;
        batch.status = BATCH_STATUS_COMMITTED;
        batch.bump = ctx.bumps.payroll_batch;

        Ok(())
    }

    pub fn approve_payroll_batch(
        ctx: Context<ApprovePayrollBatch>,
        args: ApprovePayrollBatchArgs,
    ) -> Result<()> {
        require!(
            ctx.accounts.payroll_batch.status == BATCH_STATUS_COMMITTED,
            PayrollError::InvalidPayrollBatchState
        );
        require!(
            !is_zero_hash(&args.approval_root),
            PayrollError::InvalidReferenceHash
        );

        let clock = Clock::get()?;
        let batch = &mut ctx.accounts.payroll_batch;
        batch.approval_root = args.approval_root;
        batch.approved_by = ctx.accounts.authority.key();
        batch.approved_at = clock.unix_timestamp;
        batch.status = BATCH_STATUS_APPROVED;

        Ok(())
    }

    pub fn finalize_payroll_batch(
        ctx: Context<FinalizePayrollBatch>,
        args: FinalizePayrollBatchArgs,
    ) -> Result<()> {
        require!(
            ctx.accounts.payroll_batch.status == BATCH_STATUS_APPROVED,
            PayrollError::InvalidPayrollBatchState
        );
        require!(
            !is_zero_hash(&args.settlement_root),
            PayrollError::InvalidReferenceHash
        );

        let clock = Clock::get()?;
        let batch = &mut ctx.accounts.payroll_batch;
        batch.settlement_root = args.settlement_root;
        batch.finalized_by = ctx.accounts.authority.key();
        batch.executed_at = clock.unix_timestamp;
        batch.status = BATCH_STATUS_FINALIZED;

        Ok(())
    }
}

#[derive(AnchorSerialize, AnchorDeserialize, Clone, Debug)]
pub struct InitializeCompanyArgs {
    pub name: String,
    pub slug: String,
    pub treasury_wallet: Pubkey,
}

#[derive(AnchorSerialize, AnchorDeserialize, Clone, Debug)]
pub struct CreateEmploymentContractArgs {
    pub employee_wallet: Pubkey,
    pub contract_hash: [u8; 32],
    pub version: u16,
    pub effective_at: i64,
    pub pay_cycle: u8,
    pub current_compensation_ref: [u8; 32],
}

#[derive(AnchorSerialize, AnchorDeserialize, Clone, Debug)]
pub struct AmendCompensationArgs {
    pub effective_at: i64,
    pub amendment_hash: [u8; 32],
}

#[derive(AnchorSerialize, AnchorDeserialize, Clone, Debug)]
pub struct CommitPayrollBatchArgs {
    pub period_year: u16,
    pub period_month: u8,
    pub entry_count: u16,
    pub entries_root: [u8; 32],
}

#[derive(AnchorSerialize, AnchorDeserialize, Clone, Debug)]
pub struct ApprovePayrollBatchArgs {
    pub approval_root: [u8; 32],
}

#[derive(AnchorSerialize, AnchorDeserialize, Clone, Debug)]
pub struct FinalizePayrollBatchArgs {
    pub settlement_root: [u8; 32],
}

#[derive(Accounts)]
pub struct InitializeCompany<'info> {
    #[account(
        init,
        payer = authority,
        space = CompanyAccount::SPACE,
        seeds = [b"company", authority.key().as_ref()],
        bump
    )]
    pub company: Account<'info, CompanyAccount>,
    #[account(mut)]
    pub authority: Signer<'info>,
    pub system_program: Program<'info, System>,
}

#[derive(Accounts)]
#[instruction(args: CreateEmploymentContractArgs)]
pub struct CreateEmploymentContract<'info> {
    #[account(
        seeds = [b"company", authority.key().as_ref()],
        bump = company.bump,
        has_one = authority @ PayrollError::Unauthorized
    )]
    pub company: Account<'info, CompanyAccount>,
    #[account(
        init,
        payer = authority,
        space = EmploymentContract::SPACE,
        seeds = [
            b"contract",
            company.key().as_ref(),
            args.employee_wallet.as_ref(),
            &args.version.to_le_bytes(),
        ],
        bump
    )]
    pub contract: Account<'info, EmploymentContract>,
    #[account(mut)]
    pub authority: Signer<'info>,
    pub system_program: Program<'info, System>,
}

#[derive(Accounts)]
#[instruction(args: AmendCompensationArgs)]
pub struct AmendCompensation<'info> {
    #[account(
        seeds = [b"company", authority.key().as_ref()],
        bump = company.bump,
        has_one = authority @ PayrollError::Unauthorized
    )]
    pub company: Account<'info, CompanyAccount>,
    #[account(
        mut,
        constraint = contract.company == company.key() @ PayrollError::CompanyMismatch
    )]
    pub contract: Account<'info, EmploymentContract>,
    #[account(
        init,
        payer = authority,
        space = CompensationAmendment::SPACE,
        seeds = [
            b"amendment",
            contract.key().as_ref(),
            &args.effective_at.to_le_bytes(),
        ],
        bump
    )]
    pub amendment: Account<'info, CompensationAmendment>,
    #[account(mut)]
    pub authority: Signer<'info>,
    pub system_program: Program<'info, System>,
}

#[derive(Accounts)]
#[instruction(args: CommitPayrollBatchArgs)]
pub struct CommitPayrollBatch<'info> {
    #[account(
        seeds = [b"company", authority.key().as_ref()],
        bump = company.bump,
        has_one = authority @ PayrollError::Unauthorized
    )]
    pub company: Account<'info, CompanyAccount>,
    #[account(
        init,
        payer = authority,
        space = PayrollBatch::SPACE,
        seeds = [
            b"batch",
            company.key().as_ref(),
            &args.period_year.to_le_bytes(),
            &[args.period_month],
        ],
        bump
    )]
    pub payroll_batch: Account<'info, PayrollBatch>,
    #[account(mut)]
    pub authority: Signer<'info>,
    pub system_program: Program<'info, System>,
}

#[derive(Accounts)]
pub struct ApprovePayrollBatch<'info> {
    #[account(
        seeds = [b"company", authority.key().as_ref()],
        bump = company.bump,
        has_one = authority @ PayrollError::Unauthorized
    )]
    pub company: Account<'info, CompanyAccount>,
    #[account(
        mut,
        constraint = payroll_batch.company == company.key() @ PayrollError::CompanyMismatch
    )]
    pub payroll_batch: Account<'info, PayrollBatch>,
    pub authority: Signer<'info>,
}

#[derive(Accounts)]
pub struct FinalizePayrollBatch<'info> {
    #[account(
        seeds = [b"company", authority.key().as_ref()],
        bump = company.bump,
        has_one = authority @ PayrollError::Unauthorized
    )]
    pub company: Account<'info, CompanyAccount>,
    #[account(
        mut,
        constraint = payroll_batch.company == company.key() @ PayrollError::CompanyMismatch
    )]
    pub payroll_batch: Account<'info, PayrollBatch>,
    pub authority: Signer<'info>,
}

#[account]
pub struct CompanyAccount {
    pub authority: Pubkey,
    pub treasury_wallet: Pubkey,
    pub name: String,
    pub slug: String,
    pub created_at: i64,
    pub bump: u8,
}

impl CompanyAccount {
    pub const SPACE: usize = 8 + 32 + 32 + 4 + MAX_COMPANY_NAME_LEN + 4 + MAX_COMPANY_SLUG_LEN + 8 + 1;
}

#[account]
pub struct EmploymentContract {
    pub company: Pubkey,
    pub employee_wallet: Pubkey,
    pub contract_hash: [u8; 32],
    pub latest_amendment: Pubkey,
    pub version: u16,
    pub effective_at: i64,
    pub pay_cycle: u8,
    pub current_compensation_ref: [u8; 32],
    pub status: u8,
    pub bump: u8,
}

impl EmploymentContract {
    pub const SPACE: usize = 8 + 32 + 32 + 32 + 32 + 2 + 8 + 1 + 32 + 1 + 1;
}

#[account]
pub struct CompensationAmendment {
    pub company: Pubkey,
    pub contract: Pubkey,
    pub effective_at: i64,
    pub amendment_hash: [u8; 32],
    pub created_at: i64,
    pub bump: u8,
}

impl CompensationAmendment {
    pub const SPACE: usize = 8 + 32 + 32 + 8 + 32 + 8 + 1;
}

#[account]
pub struct PayrollBatch {
    pub company: Pubkey,
    pub period_year: u16,
    pub period_month: u8,
    pub entry_count: u16,
    pub entries_root: [u8; 32],
    pub approval_root: [u8; 32],
    pub settlement_root: [u8; 32],
    pub approved_by: Pubkey,
    pub finalized_by: Pubkey,
    pub approved_at: i64,
    pub created_at: i64,
    pub executed_at: i64,
    pub status: u8,
    pub bump: u8,
}

impl PayrollBatch {
    pub const SPACE: usize = 8 + 32 + 2 + 1 + 2 + 32 + 32 + 32 + 32 + 32 + 8 + 8 + 8 + 1 + 1;
}

#[error_code]
pub enum PayrollError {
    #[msg("Only the company authority can perform this action.")]
    Unauthorized,
    #[msg("Company name cannot be empty.")]
    EmptyCompanyName,
    #[msg("Company slug cannot be empty.")]
    EmptyCompanySlug,
    #[msg("Company name exceeds the maximum supported length.")]
    CompanyNameTooLong,
    #[msg("Company slug exceeds the maximum supported length.")]
    CompanySlugTooLong,
    #[msg("Employment contract version must be greater than zero.")]
    InvalidVersion,
    #[msg("Unsupported pay cycle.")]
    InvalidPayCycle,
    #[msg("Reference hashes must not be empty.")]
    InvalidReferenceHash,
    #[msg("Payroll period is invalid.")]
    InvalidPayrollPeriod,
    #[msg("Payroll batch entry count must be greater than zero.")]
    InvalidEntryCount,
    #[msg("The referenced company does not match the account relationship.")]
    CompanyMismatch,
    #[msg("Payroll batch is in an invalid state for this action.")]
    InvalidPayrollBatchState,
}

fn is_supported_pay_cycle(pay_cycle: u8) -> bool {
    matches!(
        pay_cycle,
        PAY_CYCLE_MONTHLY | PAY_CYCLE_SEMI_MONTHLY | PAY_CYCLE_BI_WEEKLY
    )
}

fn is_valid_payroll_period(period_year: u16, period_month: u8) -> bool {
    (2000..=2100).contains(&period_year) && (1..=12).contains(&period_month)
}

fn is_zero_hash(value: &[u8; 32]) -> bool {
    value.iter().all(|byte| *byte == 0)
}
