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
    const projection = calculateStrategyProjection(product, strategy);
    close(projection.m1_no, 1.19, 'No-action forecast must preserve expected units');
    close(projection.m1, 1.19 * (1 + (5 / 30)), 'Uplift must only apply during campaign coverage');
    close(projection.withStrategy[0].promotionalUnits, 1.19 * (5 / 30) * 2, 'Promotional units');

    const veryLow = calculateStrategyProjection(
        { current_stock: 5, monthly_sales: 0, predicted_monthly_sales: 0.023 },
        { duration_days: 7, expected_impact_min: 15, expected_impact_max: 30 }
    );
    if (veryLow.m1 >= 1) throw new Error('Low forecasts must not be forced to one unit');

    const capped = calculateStrategyProjection(
        { current_stock: 12, monthly_sales: 10, predicted_monthly_sales: 10 },
        { duration_days: 30, expected_impact_min: 100, expected_impact_max: 100 }
    );
    close(capped.m1 + capped.m2 + capped.m3, 12, 'Projection must not exceed available stock');

    const html = buildProfitTab(product, strategy, projection, ['Oct 2026', 'Nov 2026', 'Dec 2026']);
    if (!html.includes('GROSS PROFIT')) throw new Error('Profit must be labelled as gross profit');
    if (!html.includes('Maximum Discount Before Unit Loss')) throw new Error('Safe discount metric missing');
    if (html.includes('NET PROFIT')) throw new Error('Net profit label is inaccurate');
})();
`;

vm.runInNewContext(source, sandbox);
console.log('PASS: recommendation projection and profit calculations');
