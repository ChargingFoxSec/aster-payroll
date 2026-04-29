import './bootstrap';

const endOfMonthForPeriod = (period) => {
    const match = /^(\d{4})-(\d{2})$/.exec(period);

    if (! match) {
        return '';
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const endOfMonth = new Date(Date.UTC(year, month, 0));

    return endOfMonth.toISOString().slice(0, 10);
};

const dateFallsInsidePeriod = (period, date) => {
    const endOfMonth = endOfMonthForPeriod(period);

    return endOfMonth !== '' && date >= `${period}-01` && date <= endOfMonth;
};

document.querySelectorAll('[data-payroll-batch-form]').forEach((form) => {
    const periodInput = form.querySelector('[data-payroll-period-input]');
    const dueDateInput = form.querySelector('[data-payroll-due-date-input]');

    if (!(periodInput instanceof HTMLInputElement) || !(dueDateInput instanceof HTMLInputElement)) {
        return;
    }

    let lastSyncedDueDate = dueDateInput.value;

    const syncDueDate = () => {
        const nextDueDate = endOfMonthForPeriod(periodInput.value);

        if (nextDueDate === '') {
            return;
        }

        if (
            dueDateInput.value === ''
            || dueDateInput.value === lastSyncedDueDate
            || ! dateFallsInsidePeriod(periodInput.value, dueDateInput.value)
        ) {
            dueDateInput.value = nextDueDate;
            lastSyncedDueDate = nextDueDate;
        }
    };

    syncDueDate();
    periodInput.addEventListener('input', syncDueDate);
    periodInput.addEventListener('change', syncDueDate);
});
