use anchor_lang::prelude::*;

declare_id!("HujT21D3ZcjzBsCPuPy4Q1AkfCQSgbycz7wjaThAUCmv");

const MAX_COMPANY_NAME_LEN: usize = 64;
const MAX_COMPANY_SLUG_LEN: usize = 32;
const MAX_AMENDMENT_REASON_LEN: usize = 128;

const PAY_CYCLE_MONTHLY: u8 = 1;
const PAY_CYCLE_SEMI_MONTHLY: u8 = 2;
const PAY_CYCLE_BI_WEEKLY: u8 = 3;

const CONTRACT_STATUS_ACTIVE: u8 = 1;

const BATCH_STATUS_READY: u8 = 1;
const BATCH_STATUS_EXECUTED: u8 = 2;

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
        company.bump = ctx.bumps.company;

        Ok(())
    }

    pub fn create_employment_contract(
        ctx: Context<CreateEmploymentContract>,
        args: CreateEmploymentContractArgs,
    ) -> Result<()> {
        require!(args.version > 0, PayrollError::InvalidVersion);
        require!(
            args.current_compensation_minor > 0,
            PayrollError::InvalidCompensation
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
        contract.currency = args.currency;
        contract.current_compensation_minor = args.current_compensation_minor;
        contract.status = CONTRACT_STATUS_ACTIVE;
        contract.bump = ctx.bumps.contract;

        Ok(())
    }

    pub fn amend_compensation(
        ctx: Context<AmendCompensation>,
        args: AmendCompensationArgs,
    ) -> Result<()> {
        require!(args.new_amount_minor > 0, PayrollError::InvalidCompensation);
        require!(
            args.reason.len() <= MAX_AMENDMENT_REASON_LEN,
            PayrollError::AmendmentReasonTooLong
        );

        let amendment = &mut ctx.accounts.amendment;
        amendment.company = ctx.accounts.company.key();
        amendment.contract = ctx.accounts.contract.key();
        amendment.previous_amount_minor = args.previous_amount_minor;
        amendment.new_amount_minor = args.new_amount_minor;
        amendment.effective_at = args.effective_at;
        amendment.currency = args.currency;
        amendment.reason = args.reason;
        amendment.bump = ctx.bumps.amendment;

        let contract = &mut ctx.accounts.contract;
        contract.current_compensation_minor = amendment.new_amount_minor;
        contract.latest_amendment = amendment.key();
        contract.status = CONTRACT_STATUS_ACTIVE;

        Ok(())
    }

    pub fn create_payroll_batch(
        ctx: Context<CreatePayrollBatch>,
        args: CreatePayrollBatchArgs,
    ) -> Result<()> {
        require!(
            is_valid_payroll_period(args.period_year, args.period_month),
            PayrollError::InvalidPayrollPeriod
        );
        require!(args.entry_count > 0, PayrollError::InvalidEntryCount);
        require!(
            args.total_amount_minor > 0,
            PayrollError::InvalidPayrollAmount
        );

        let batch = &mut ctx.accounts.payroll_batch;
        batch.company = ctx.accounts.company.key();
        batch.period_year = args.period_year;
        batch.period_month = args.period_month;
        batch.total_amount_minor = args.total_amount_minor;
        batch.currency = args.currency;
        batch.due_at = args.due_at;
        batch.executed_at = 0;
        batch.entry_count = args.entry_count;
        batch.entries_hash = args.entries_hash;
        batch.status = BATCH_STATUS_READY;
        batch.bump = ctx.bumps.payroll_batch;

        Ok(())
    }

    pub fn mark_payroll_batch_executed(ctx: Context<MarkPayrollBatchExecuted>) -> Result<()> {
        require!(
            ctx.accounts.payroll_batch.status == BATCH_STATUS_READY,
            PayrollError::InvalidPayrollBatchState
        );

        let clock = Clock::get()?;
        let batch = &mut ctx.accounts.payroll_batch;
        batch.status = BATCH_STATUS_EXECUTED;
        batch.executed_at = clock.unix_timestamp;

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
    pub currency: [u8; 8],
    pub current_compensation_minor: u64,
}

#[derive(AnchorSerialize, AnchorDeserialize, Clone, Debug)]
pub struct AmendCompensationArgs {
    pub previous_amount_minor: u64,
    pub new_amount_minor: u64,
    pub effective_at: i64,
    pub currency: [u8; 8],
    pub reason: String,
}

#[derive(AnchorSerialize, AnchorDeserialize, Clone, Debug)]
pub struct CreatePayrollBatchArgs {
    pub period_year: u16,
    pub period_month: u8,
    pub total_amount_minor: u64,
    pub currency: [u8; 8],
    pub due_at: i64,
    pub entry_count: u16,
    pub entries_hash: [u8; 32],
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
#[instruction(args: CreatePayrollBatchArgs)]
pub struct CreatePayrollBatch<'info> {
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
pub struct MarkPayrollBatchExecuted<'info> {
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
    pub bump: u8,
}

impl CompanyAccount {
    pub const SPACE: usize = 8 + 32 + 32 + 4 + MAX_COMPANY_NAME_LEN + 4 + MAX_COMPANY_SLUG_LEN + 1;
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
    pub currency: [u8; 8],
    pub current_compensation_minor: u64,
    pub status: u8,
    pub bump: u8,
}

impl EmploymentContract {
    pub const SPACE: usize = 8 + 32 + 32 + 32 + 32 + 2 + 8 + 1 + 8 + 8 + 1 + 1;
}

#[account]
pub struct CompensationAmendment {
    pub company: Pubkey,
    pub contract: Pubkey,
    pub previous_amount_minor: u64,
    pub new_amount_minor: u64,
    pub effective_at: i64,
    pub currency: [u8; 8],
    pub reason: String,
    pub bump: u8,
}

impl CompensationAmendment {
    pub const SPACE: usize = 8 + 32 + 32 + 8 + 8 + 8 + 8 + 4 + MAX_AMENDMENT_REASON_LEN + 1;
}

#[account]
pub struct PayrollBatch {
    pub company: Pubkey,
    pub period_year: u16,
    pub period_month: u8,
    pub total_amount_minor: u64,
    pub currency: [u8; 8],
    pub due_at: i64,
    pub executed_at: i64,
    pub entry_count: u16,
    pub entries_hash: [u8; 32],
    pub status: u8,
    pub bump: u8,
}

impl PayrollBatch {
    pub const SPACE: usize = 8 + 32 + 2 + 1 + 8 + 8 + 8 + 8 + 2 + 32 + 1 + 1;
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
    #[msg("Compensation amendment reason exceeds the maximum supported length.")]
    AmendmentReasonTooLong,
    #[msg("Employment contract version must be greater than zero.")]
    InvalidVersion,
    #[msg("Unsupported pay cycle.")]
    InvalidPayCycle,
    #[msg("Compensation amount must be greater than zero.")]
    InvalidCompensation,
    #[msg("Payroll period is invalid.")]
    InvalidPayrollPeriod,
    #[msg("Payroll batch must contain at least one entry.")]
    InvalidEntryCount,
    #[msg("Payroll amount must be greater than zero.")]
    InvalidPayrollAmount,
    #[msg("The referenced company does not match the account relationship.")]
    CompanyMismatch,
    #[msg("Payroll batch is not ready to be marked as executed.")]
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
