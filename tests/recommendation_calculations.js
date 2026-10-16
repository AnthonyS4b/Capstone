const fs = require('fs');
const vm = require('vm');

const sandbox = {
    document: {
        createElement: () => ({
            innerHTML: '',
            set textContent(value) { this.innerHTML = String(value); },
        }),
    },
    $: () => ({ ready: () => {} }),
    console,
};

const source = fs.readFileSync('assets/js/Reco.js', 'utf8') + `
(() => {
    function close(actual, expected, message) {
        if (Math.abs(actual - expected) > 0.000001) {
            throw new Error(message + ': expected ' + expected + ', received ' + actual);
        }
    }

    const product = {
        current_price: 210,
        cost_price: 155,
        current_stock: 35,
        monthly_sales: 0,
        predicted_monthly_sales: 1.19,
    };
    const strategy = {
        strategy_id: 'deep_discount_liquidation',
        strategy_name: 'Deep Discount Liquidation',
        recommended_discount: 30,
        duration_days: 5,
        expected_impact_min: 80,
        expected_impact_max: 120,
    };
    // Fixed "today" on the 1st, so the three months shown are all of Nov 2026 (30 days), Dec and Jan
    const asOf = new Date(2026, 10, 1);
    const projection = calculateStrategyProjection(product, strategy, asOf);
    close(projection.m1_no, 1.19, 'No-action forecast must preserve expected units');
    close(projection.m1, 1.19 * (1 + (5 / 30)), 'Uplift must only apply during campaign coverage');
    close(projection.withStrategy[0].promotionalUnits, 1.19 * (5 / 30) * 2, 'Promotional units');

    const veryLow = calculateStrategyProjection(
        { current_stock: 5, monthly_sales: 0, predicted_monthly_sales: 0.023 },
        { duration_days: 7, expected_impact_min: 15, expected_impact_max: 30 },
        asOf
    );
    if (veryLow.m1 >= 1) throw new Error('Low forecasts must not be forced to one unit');

    const capped = calculateStrategyProjection(
        { current_stock: 12, monthly_sales: 10, predicted_monthly_sales: 10 },
        { duration_days: 30, expected_impact_min: 100, expected_impact_max: 100 },
        asOf
    );
    close(capped.m1 + capped.m2 + capped.m3, 12, 'Projection must not exceed available stock');

    // Expired before the months shown (Meow Mix, Oct 20): nothing sells, all 24 expire
    const meow = calculateStrategyProjection(
        { current_stock: 24, monthly_sales: 0, predicted_monthly_sales: 0.76,
          expiration_date: '2026-10-20', batches: [{ stock: 24, expiration_date: '2026-10-20' }] },
        strategy, asOf
    );
    close(meow.m1 + meow.m2 + meow.m3 + meow.m1_no + meow.m2_no + meow.m3_no, 0, 'Expired stock must not count as sales');
    close(meow.expired[0].units, 24, 'Unsold units of an expired batch are reported as expired');
    if (!projectionExpiryNote(meow).includes('Oct 20, 2026')) throw new Error('Expiry note must name the date');

    // Expires on Nov 10: one unit a day sells on Nov 1 to 10, the other 20 expire
    const midMonth = calculateStrategyProjection(
        { current_stock: 30, monthly_sales: 0, predicted_monthly_sales: 30,
          batches: [{ stock: 30, expiration_date: '2026-11-10' }] },
        {}, asOf
    );
    close(midMonth.m1_no, 10, 'Sales stop after the expiry date (it can still be sold that day)');
    close(midMonth.m2_no + midMonth.m3_no, 0, 'Nothing sells from an expired batch later');
    close(midMonth.expired[0].units, 20, 'The rest of the batch expires');

    // First expiry, first out: the batch expiring Nov 9 sells before the later one
    const twoBatches = calculateStrategyProjection(
        { current_stock: 10, monthly_sales: 0, predicted_monthly_sales: 30, batches: [
            { stock: 5, expiration_date: '2027-06-01' }, { stock: 5, expiration_date: '2026-11-09' },
        ] },
        {}, asOf
    );
    close(twoBatches.m1_no, 10, 'Both batches sell when the earliest goes first');
    if (twoBatches.expired.length) throw new Error('Nothing should expire when the earliest batch sells first');

    // Stock outside any batch record keeps the product expiry date
    const untracked = calculateStrategyProjection(
        { current_stock: 8, monthly_sales: 0, predicted_monthly_sales: 3,
          expiration_date: '2026-11-01', batches: [{ stock: 3, expiration_date: null }] },
        {}, asOf
    );
    close(untracked.expired[0].units, 4.9, 'Untracked units expire on the product date after one day of sales');

    // Opened mid-month (Oct 17): the chart starts today with the rest of October, so stock
    // expiring Oct 20 still sells for 4 days (Oct 17 to 20) at 0.76 a month (31-day October)
    const midOct = new Date(2026, 9, 17);
    const labels = projectionMonthLabels(midOct);
    if (labels.join('|') !== 'Rest of Oct 2026|Nov 2026|Dec 2026') throw new Error('Unexpected month labels: ' + labels.join('|'));
    if (projectionMonthLabels(asOf)[0] !== 'Nov 2026') throw new Error('On the 1st the whole month is shown');
    const meowToday = calculateStrategyProjection(
        { current_stock: 24, monthly_sales: 0, predicted_monthly_sales: 0.76,
          batches: [{ stock: 24, expiration_date: '2026-10-20' }] },
        { duration_days: 3, expected_impact_min: 70, expected_impact_max: 105 }, midOct
    );
    close(meowToday.m1_no, 0.76 / 31 * 4, 'No action sells 4 days of demand before expiry');
    close(meowToday.m1, 0.76 / 31 * (3 * 1.875 + 1), 'The 3-day promo runs from today, before expiry');
    close(meowToday.m2 + meowToday.m3, 0, 'Nothing sells after the batch expires');
    close(meowToday.expired[0].units, 24 - meowToday.m1, 'The rest expires on Oct 20');
    // A full month of demand spread over its days: 15 of October's 31 days are left
    const fullOct = calculateStrategyProjection({ current_stock: 100, monthly_sales: 0, predicted_monthly_sales: 31 }, {}, midOct);
    close(fullOct.m1_no, 15, 'The rest of the month gets its share of the monthly forecast');
    close(fullOct.m2_no, 31 * 0.95, 'The next month is a full month');

    const html = buildProfitTab(product, strategy, projection, ['Oct 2026', 'Nov 2026', 'Dec 2026']);
    if (!html.includes('GROSS PROFIT')) throw new Error('Profit must be labelled as gross profit');
    if (!html.includes('Maximum Discount Before Unit Loss')) throw new Error('Safe discount metric missing');
    if (html.includes('NET PROFIT')) throw new Error('Net profit label is inaccurate');
})();
`;

vm.runInNewContext(source, sandbox);
console.log('PASS: recommendation projection and profit calculations');
