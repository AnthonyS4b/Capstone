// ═══════════════════════════════════════════════════════════════════
//  Reco.js – Recommendation Engine Frontend
//  Risk-level-driven strategies, proper profit analysis, date tracking
// ═══════════════════════════════════════════════════════════════════

const API_URL = 'ajax/ml_recommendation_ajax.php';
let allRecommendations = [];
let currentFilter = 'all';
let currentView = 'list';
let forecastChart = null;
let _currentSelectedStrategyId = null;

// One source of truth for strategy priority labels.  These ranges correspond
// to strategy_templates.priority and are used everywhere a strategy is shown
// or selected; array position must never determine priority.
function strategyPriorityMeta(strategy) {
    const priority = Number(strategy?.priority ?? 10);
    if (priority <= 3) return { label: 'HIGH PRIORITY', className: 'high', priority };
    if (priority <= 6) return { label: 'MEDIUM PRIORITY', className: 'medium', priority };
    return { label: 'LOW PRIORITY', className: 'low', priority };
}

// ═══════════ INIT ═══════════

$(document).ready(function () {
    loadRecommendations();
    checkApiHealth();
    wireEvents();
});

function wireEvents() {
    // Filter buttons
    $(document).on('click', '.filter-btn', function () {
        $('.filter-btn').removeClass('active');
        $(this).addClass('active');
        currentFilter = $(this).data('filter');
        renderCards(getVisibleRecommendations());
    });

    // Search
    $('#searchBox').on('input', function () {
        renderCards(getVisibleRecommendations());
    });

    // List/card layout. List is intentionally the default on every page load.
    $(document).on('click', '.view-toggle-btn', function () {
        const requestedView = $(this).data('view');
        if (requestedView !== 'list' && requestedView !== 'card') return;

        currentView = requestedView;
        $('.view-toggle-btn')
            .removeClass('active')
            .attr('aria-pressed', 'false');
        $(this)
            .addClass('active')
            .attr('aria-pressed', 'true');
        applyRecommendationView();
    });

    // Sidebar recommendation button
    $('#recommendationBtn').on('click', () => window.location.href = 'reco.php');

    // Logout
    $('#logoutBtn').on('click', function (e) {
        e.preventDefault();
        if (confirm('Are you sure you want to log out?')) window.location.replace('logout.php');
    });

    // Sidebar collapse
    $('#collapseBtn').on('click', function () {
        document.getElementById('sidebar')?.classList.toggle('collapsed');
        document.getElementById('collapseIcon')?.classList.toggle('fa-chevron-right');
    });

    // Train model
    $('#trainModelBtn').on('click', trainModel);

    // Clean up chart on modal close
    $('#forecastModal').on('hidden.bs.modal', function () {
        if (forecastChart) { forecastChart.destroy(); forecastChart = null; }
    });

    // Forecast modal "Apply Strategy" footer button
    $(document).on('click', '.btn-modal-apply', function () {
        if (_currentApplyRec) {
            // Close forecast modal first, then open apply modal
            bootstrap.Modal.getInstance(document.getElementById('forecastModal'))?.hide();
            setTimeout(() => openApplyModal(_currentApplyRec, _currentSelectedStrategyId), 300);
        }
    });
}

// ═══════════ DATA LOADING ═══════════

function loadRecommendations() {
    $.ajax({
        url: API_URL,
        data: { action: 'get_all_recommendations', limit: 50 },
        dataType: 'json',
        success(res) {
            if (res.success && res.recommendations) {
                allRecommendations = res.recommendations;
                updateStats(allRecommendations);
                renderCards(allRecommendations);
            } else {
                showEmptyState('No recommendations available', 'All products are performing well.');
            }
        },
        error() {
            showEmptyState(
                'Recommendation Engine Offline',
                'Start the server with: python ml/api_server.py'
            );
        },
    });
}

function checkApiHealth() {
    $.ajax({
        url: API_URL,
        data: { action: 'health_check' },
        dataType: 'json',
        success(res) {
            const dot = document.querySelector('.status-dot');
            const label = document.querySelector('.api-status');
            if (dot) dot.classList.toggle('connected', res.success);
            if (label) label.textContent = res.success ? 'Connected' : 'Disconnected';
        },
        error() {
            const dot = document.querySelector('.status-dot');
            const label = document.querySelector('.api-status');
            if (dot) dot.classList.remove('connected');
            if (label) label.textContent = 'Disconnected';
        },
    });
}

function trainModel() {
    const btn = $('#trainModelBtn');
    const originalHtml = btn.html();
    btn.prop('disabled', true).html('<i class="fas fa-circle-notch fa-spin me-2"></i>Updating...');
    
    $.ajax({
        url: API_URL,
        method: 'POST',
        data: { action: 'train_model' },
        dataType: 'json',
        success(res) {
            showToast(res.success ? 'success' : 'error', res.success ? 'Done' : 'Error', res.message || '');
            if (res.success) loadRecommendations();
        },
        error() { showToast('error', 'Error', 'Failed to update analysis'); },
        complete() { 
            btn.prop('disabled', false).html('<i class="fas fa-sync-alt me-2"></i>Update Analysis'); 
        },
    });
}

// ═══════════ STATS ═══════════

function updateStats(recs) {
    const slow = recs.filter(r => r.is_slow_moving).length;
    const revenue = recs.reduce((s, r) => s + (r.potential_revenue || 0), 0);
    const avgDays = recs.length
        ? Math.round(recs.reduce((s, r) => s + (r.days_in_stock || r.days_until_expiry || 0), 0) / recs.length)
        : 0;
    const avgConf = recs.length
        ? Math.round(recs.reduce((s, r) => s + (r.confidence || 0.65) * 100, 0) / recs.length)
        : 80;

    $('#slowMovingCount').text(slow);
    $('#slowMovingNote').text(`of ${recs.length} flagged product${recs.length === 1 ? '' : 's'}`);
    $('#potentialRevenue').text('₱' + revenue.toLocaleString('en-PH', { maximumFractionDigits: 0 }));
    $('#avgShelfLife').text(avgDays + ' days');
    $('#confidenceScore').text(avgConf + '%');

    // Counts next to each filter tab
    ['all', 'critical', 'warning', 'monitor', 'slow'].forEach(f => {
        $(`.rp-count[data-count="${f}"]`).text(filterRecommendations(recs, f).length);
    });
}

// ═══════════ FILTER ═══════════

function filterRecommendations(recs, filter) {
    if (filter === 'all') return recs;
    if (filter === 'critical') return recs.filter(r => r.risk_level === 'CRITICAL');
    if (filter === 'slow') return recs.filter(r => r.is_slow_moving);
    if (filter === 'monitor') return recs.filter(r => r.risk_level === 'MONITOR');
    if (filter === 'warning') return recs.filter(r => r.risk_level === 'WARNING');
    return recs;
}

function getVisibleRecommendations() {
    const q = ($('#searchBox').val() || '').trim().toLowerCase();
    const filtered = filterRecommendations(allRecommendations, currentFilter);
    if (!q) return filtered;

    return filtered.filter(r => {
        const productName = String(r.product_name || '').toLowerCase();
        const category = String(r.category || '').toLowerCase();
        return productName.includes(q) || category.includes(q);
    });
}

// ═══════════ RENDER CARDS ═══════════

function renderCards(recs) {
    const c = document.getElementById('recommendationsContainer');
    if (!c) return;

    applyRecommendationView();
    updateResultCount(recs.length);

    if (!recs.length) {
        showEmptyState('No items match this filter', 'Try a different filter or search term.');
        return;
    }

    c.innerHTML = recs.map(r => buildCard(r)).join('');
    applyRecommendationView();
}

function applyRecommendationView() {
    const c = document.getElementById('recommendationsContainer');
    if (!c) return;

    c.classList.toggle('list-view', currentView === 'list');
    c.classList.toggle('card-view', currentView === 'card');

    const listHeader = document.getElementById('recommendationsListHeader');
    if (listHeader) {
        listHeader.hidden = currentView !== 'list' || !c.querySelector('.rp-item');
        listHeader.setAttribute('aria-hidden', String(listHeader.hidden));
    }
}

function updateResultCount(count) {
    const resultCount = document.getElementById('recommendationResultCount');
    if (!resultCount) return;

    const total = allRecommendations.length;
    resultCount.textContent = count === total
        ? `${count} product${count === 1 ? '' : 's'} · highest risk first`
        : `Showing ${count} of ${total} products`;
}

const RISK_LABELS = { CRITICAL: 'Critical', WARNING: 'Warning', MONITOR: 'Monitor', LOW: 'Low' };

function peso(n) {
    return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function buildCard(r) {
    const strategy = r.strategies?.[0] || {};
    const riskLevel = r.risk_level || 'LOW';
    const riskClass = riskLevel.toLowerCase();
    const riskPct = Math.round((r.risk_score || 0) * 100);
    const daysInStock = r.days_in_stock || 0;
    const monthly = Math.round(r.monthly_sales || 0);
    const sold90 = r.total_sold_90d || 0;
    const stock = r.current_stock || 0;
    const category = r.category || 'General';
    const isMonitor = riskLevel === 'MONITOR';
    const isEscalation = strategy.strategy_id === 'escalate_reevaluate' || strategy.is_escalation;

    // Days in stock: 90+ is a problem, 50+ worth watching
    const daysClass = daysInStock >= 90 ? 'is-bad' : daysInStock >= 50 ? 'is-warn' : '';
    const stockClass = stock <= 5 ? 'is-bad' : stock <= 15 ? 'is-warn' : '';

    // Expiry sits under the product name instead of a full-width banner
    let expiryHTML = '';
    if (r.is_critical_expiry && r.expiration_date) {
        const d = new Date(r.expiration_date);
        const when = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        expiryHTML = `<span class="rp-expiry" title="Near-expiry items are prioritised over days in stock"><i class="fas fa-hourglass-end" aria-hidden="true"></i> Expires ${when}</span>`;
    }

    // Recommendation cell
    let recoHTML;
    if (isEscalation) {
        recoHTML = `
            <span class="rp-reco-name is-bad">Needs manual review</span>
            <span class="rp-reco-note">Standard strategies were tried recently without effect.</span>`;
    } else if (isMonitor && r.monitor_message) {
        recoHTML = `
            <span class="rp-reco-name">Monitor weekly</span>
            <span class="rp-reco-note">${esc(r.monitor_message)}</span>`;
    } else if (strategy.strategy_name) {
        const isBogo = strategy.strategy_id === 'buy_one_take_one' || strategy.strategy_name.toLowerCase().includes('buy 1 take 1');
        const offer = isBogo ? 'Buy 1 Take 1'
            : (strategy.recommended_discount > 0 ? `${Math.round(strategy.recommended_discount)}% off` : '');
        const impact = strategy.expected_impact_min && strategy.expected_impact_max
            ? `+${strategy.expected_impact_min}–${strategy.expected_impact_max}% sales · ${strategy.duration_days} days`
            : 'Recommended for this product';
        recoHTML = `
            <span class="rp-reco-name">${esc(strategy.strategy_name)}${offer ? ` <span class="rp-offer">${offer}</span>` : ''}</span>
            <span class="rp-reco-note">${impact}</span>`;
    } else {
        recoHTML = `<span class="rp-reco-note">No action suggested</span>`;
    }

    // Product photo; a broken or missing image falls back to the placeholder
    const placeholder = `<span class="rp-thumb rp-thumb-empty" aria-hidden="true"><i class="fas fa-box"></i></span>`;
    const thumbHTML = r.image
        ? `<img class="rp-thumb" src="${esc(r.image)}" alt="" loading="lazy"
               onerror="this.outerHTML='<span class=&quot;rp-thumb rp-thumb-empty&quot; aria-hidden=&quot;true&quot;><i class=&quot;fas fa-box&quot;></i></span>'">`
        : placeholder;

    const payload = JSON.stringify(r).replace(/'/g, "&#39;");

    return `
    <article class="rp-row rp-item risk-${riskClass}" data-risk="${riskClass}" data-slow="${r.is_slow_moving}">
        <div class="rp-cell rp-product">
            ${thumbHTML}
            <div class="rp-product-text">
                <span class="rp-name">${esc(r.product_name)}</span>
                <span class="rp-cat">${esc(category)}</span>
                ${expiryHTML}
            </div>
        </div>
        <div class="rp-cell rp-risk" data-label="Risk">
            <span class="rp-risk-label"><span class="rp-dot" aria-hidden="true"></span>${RISK_LABELS[riskLevel] || esc(riskLevel)}</span>
            <span class="rp-meter" title="Risk score ${riskPct}%"><span style="width:${riskPct}%"></span></span>
            <span class="rp-sub">${riskPct}% risk</span>
        </div>
        <div class="rp-cell rp-num" data-label="Price">${peso(r.current_price)}</div>
        <div class="rp-cell rp-num ${stockClass}" data-label="Stock">${stock}</div>
        <div class="rp-cell rp-num ${daysClass}" data-label="Days held">${daysInStock}</div>
        <div class="rp-cell rp-num" data-label="Sales">
            <span>${monthly}/mo</span>
            <span class="rp-sub">${sold90} in 90d</span>
        </div>
        <div class="rp-cell rp-reco">${recoHTML}</div>
        <div class="rp-cell rp-actions">
            <button type="button" class="rp-link" onclick='openForecastModal(${payload})'>Details</button>
            <button type="button" class="rp-btn rp-btn-primary rp-btn-sm"
                onclick='openApplyModal(${payload})'
                ${isEscalation ? 'disabled title="No applicable strategy — review manually"' : ''}>Apply</button>
        </div>
    </article>`;
}

function showEmptyState(title, msg) {
    const c = document.getElementById('recommendationsContainer');
    if (c) {
        c.innerHTML = `
            <div class="rp-empty">
                <p class="rp-empty-title">${esc(title)}</p>
                <p>${esc(msg)}</p>
            </div>`;
    }
    updateResultCount(0);
    applyRecommendationView();
}

// ═══════════ FORECAST MODAL (4 tabs) ═══════════

function openForecastModal(rec) {
    _currentApplyRec = rec; // Store for the Apply Strategy footer button
    _currentSelectedStrategyId = rec.strategies?.[0]?.strategy_id || null;
    const modal = new bootstrap.Modal(document.getElementById('forecastModal'));
    const content = document.getElementById('forecastContent');
    if (!content) return;

    const strategy = rec.strategies?.[0] || {};
    const price = rec.current_price || 0;
    const costPrice = rec.cost_price || price * 0.7;
    const stock = rec.current_stock || 0;
    const monthly = rec.monthly_sales || 0;
    const daysInStock = rec.days_in_stock || 0;
    const discount = strategy.recommended_discount || 0;
    const discountedPrice = price * (1 - discount / 100);
    const riskClass = (rec.risk_level || 'LOW').toLowerCase();
    const confidence = rec.confidence || 0.65;

    // Forecast calculations — use predicted_monthly_sales if available
    const initialProjection = calculateStrategyProjection(rec, strategy);
    const { m1, m2, m3, m1_no, m2_no, m3_no } = initialProjection;

    // Month labels
    const now = new Date();
    const months = [];
    for (let i = 1; i <= 3; i++) {
        const d = new Date(now.getFullYear(), now.getMonth() + i, 1);
        months.push(d.toLocaleString('en', { month: 'short', year: 'numeric' }));
    }

    content.innerHTML = `
        <div class="forecast-header-info">
            <h4>${esc(rec.product_name)} <span class="badge-priority badge-${riskClass}">${rec.risk_level}</span></h4>
            <p class="forecast-subtitle">${esc(rec.category || 'General')} · ₱${price.toFixed(2)} · ${stock} units in stock</p>
        </div>

        <ul class="forecast-tabs" id="forecastTabs">
            <li class="forecast-tab active" data-tab="forecast">Forecast</li>
            <li class="forecast-tab" data-tab="strategy">Marketing Strategy</li>
            <li class="forecast-tab" data-tab="profit">Profit Analysis</li>
            <li class="forecast-tab" data-tab="details">Prediction Details</li>
        </ul>

        <!-- TAB 1: Forecast -->
        <div class="forecast-tab-content active" id="tab-forecast">
            <div class="chart-title">3-Month Sales Projection: With Strategy vs No Action</div>
            <div class="chart-container">
                <canvas id="forecastChart"></canvas>
            </div>
            <div class="forecast-legend">
                <div class="legend-item"><span class="legend-line solid"></span> With Strategy</div>
                <div class="legend-item"><span class="legend-line dashed"></span> No Action</div>
            </div>
            <div class="month-boxes">
                ${[0,1,2].map(i => {
                    const withS = [m1, m2, m3][i];
                    const noS = [m1_no, m2_no, m3_no][i];
                    const change = noS > 0 ? Math.round(((withS - noS) / noS) * 100) : (withS > 0 ? 100 : 0);
                    return `
                    <div class="month-box" data-month-index="${i}">
                        <div class="month-label">${months[i]}</div>
                        <div class="month-value">${formatProjectedUnits(withS)} units</div>
                        <div class="month-change ${change >= 0 ? 'positive' : 'negative'}">${change >= 0 ? '+' : ''}${change}% vs no action</div>
                    </div>`;
                }).join('')}
            </div>
        </div>

        <!-- TAB 2: Marketing Strategy -->
        <div class="forecast-tab-content" id="tab-strategy">
            ${buildStrategyTab(rec, 0)}
        </div>

        <!-- TAB 3: Profit Analysis -->
        <div class="forecast-tab-content" id="tab-profit">
            ${buildProfitTab(rec, strategy, initialProjection, months)}
        </div>

        <!-- TAB 4: Prediction Details -->
        <div class="forecast-tab-content" id="tab-details">
            ${buildDetailsTab(rec)}
        </div>
    `;

    // Wire tab clicks
    content.querySelectorAll('.forecast-tab').forEach(tab => {
        tab.addEventListener('click', function () {
            content.querySelectorAll('.forecast-tab').forEach(t => t.classList.remove('active'));
            content.querySelectorAll('.forecast-tab-content').forEach(tc => tc.classList.remove('active'));
            this.classList.add('active');
            const target = document.getElementById('tab-' + this.dataset.tab);
            if (target) target.classList.add('active');
        });
    });

    content.querySelectorAll('.strategy-plan-card[data-strategy-index]').forEach(card => {
        card.addEventListener('click', function () {
            selectForecastStrategy(rec, Number(this.dataset.strategyIndex), months, content);
        });
        card.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            selectForecastStrategy(rec, Number(this.dataset.strategyIndex), months, content);
        });
    });

    modal.show();

    // Render chart after modal is visible
    setTimeout(() => renderForecastChart(months, [m1, m2, m3], [m1_no, m2_no, m3_no]), 300);
}

function calculateStrategyProjection(rec, strategy = {}) {
    const stock = Number(rec.current_stock) || 0;
    const monthly = Number(rec.monthly_sales) || 0;
    const predicted = Number(rec.predicted_monthly_sales);
    const baseSales = Number.isFinite(predicted) && predicted > 0 ? predicted : Math.max(0, monthly);
    const minImpact = Number(strategy.expected_impact_min) || 0;
    const maxImpact = Number(strategy.expected_impact_max) || 0;
    const uplift = minImpact || maxImpact ? Math.max(0, (minImpact + maxImpact) / 200) : 0;
    const durationDays = Math.max(0, Number(strategy.duration_days) || 0);
    const monthFactors = [1, 0.95, 0.9];
    const coverage = monthFactors.map((_, index) => {
        const daysRemaining = durationDays - (index * 30);
        return Math.max(0, Math.min(1, daysRemaining / 30));
    });

    const noActionDemand = monthFactors.map(factor => baseSales * factor);
    const strategyDemand = noActionDemand.map((demand, index) => (
        demand * (1 + uplift * coverage[index])
    ));
    const noActionUnits = allocateProjectedStock(noActionDemand, stock);
    const strategyUnits = allocateProjectedStock(strategyDemand, stock);
    const withStrategy = strategyUnits.map((units, index) => {
        const rawDemand = strategyDemand[index];
        const promotionalDemand = noActionDemand[index] * coverage[index] * (1 + uplift);
        const promotionalShare = rawDemand > 0 ? Math.min(1, promotionalDemand / rawDemand) : 0;
        const promotionalUnits = units * promotionalShare;

        return {
            units,
            promotionalUnits,
            regularUnits: Math.max(0, units - promotionalUnits),
            campaignCoverage: coverage[index],
        };
    });

    return {
        m1: strategyUnits[0],
        m2: strategyUnits[1],
        m3: strategyUnits[2],
        m1_no: noActionUnits[0],
        m2_no: noActionUnits[1],
        m3_no: noActionUnits[2],
        baseSales,
        withStrategy,
        noAction: noActionUnits.map(units => ({ units })),
    };
}

function allocateProjectedStock(monthlyDemand, stock) {
    let remainingStock = Math.max(0, Number(stock) || 0);
    return monthlyDemand.map(value => {
        const demand = Math.max(0, Number(value) || 0);
        const units = Math.min(demand, remainingStock);
        remainingStock = Math.max(0, remainingStock - units);
        return units;
    });
}

function formatProjectedUnits(value) {
    const units = Math.max(0, Number(value) || 0);
    return units.toLocaleString('en-PH', { maximumFractionDigits: 2 });
}

function selectForecastStrategy(rec, strategyIndex, months, content) {
    const strategy = rec.strategies?.[strategyIndex];
    if (!strategy) return;

    _currentSelectedStrategyId = strategy.strategy_id;
    content.querySelectorAll('.strategy-plan-card[data-strategy-index]').forEach((card, index) => {
        const selected = index === strategyIndex;
        card.classList.toggle('selected', selected);
        card.setAttribute('aria-checked', selected ? 'true' : 'false');
        const label = card.querySelector('.strategy-selected-label');
        if (label) label.hidden = !selected;
    });

    const projection = calculateStrategyProjection(rec, strategy);
    const profitTab = content.querySelector('#tab-profit');
    if (profitTab) {
        profitTab.innerHTML = buildProfitTab(rec, strategy, projection, months);
    }

    updateForecastProjection(content, projection, months);
}

function updateForecastProjection(content, projection, months) {
    const withStrategy = [projection.m1, projection.m2, projection.m3];
    const noAction = [projection.m1_no, projection.m2_no, projection.m3_no];

    content.querySelectorAll('.month-box[data-month-index]').forEach(box => {
        const index = Number(box.dataset.monthIndex);
        const withUnits = withStrategy[index] || 0;
        const noActionUnits = noAction[index] || 0;
        const change = noActionUnits > 0
            ? Math.round(((withUnits - noActionUnits) / noActionUnits) * 100)
            : 0;
        const value = box.querySelector('.month-value');
        const changeLabel = box.querySelector('.month-change');
        if (value) value.textContent = `${formatProjectedUnits(withUnits)} units`;
        if (changeLabel) {
            changeLabel.textContent = `${change >= 0 ? '+' : ''}${change}% vs no action`;
            changeLabel.classList.toggle('positive', change >= 0);
            changeLabel.classList.toggle('negative', change < 0);
        }
    });

    if (forecastChart) {
        forecastChart.data.labels = months;
        forecastChart.data.datasets[0].data = withStrategy;
        forecastChart.data.datasets[1].data = noAction;
        forecastChart.update();
    } else {
        renderForecastChart(months, withStrategy, noAction);
    }
}

// ═══════════ TAB 2: MARKETING STRATEGY ═══════════

function buildStrategyTab(rec, selectedStrategyIndex = 0) {
    const strategies = rec.strategies || [];
    const isMonitor = rec.risk_level === 'MONITOR';
    const daysInStock = rec.days_in_stock || 0;
    const monthly = Math.round(rec.monthly_sales || 0);
    const price = rec.current_price || 0;
    const confidence = Math.round((rec.confidence || 0.65) * 100);

    // Detect escalation
    const firstStrategy = strategies[0] || {};
    const isEscalation = firstStrategy.strategy_id === 'escalate_reevaluate' || firstStrategy.is_escalation;

    if (isEscalation) {
        return `
        <div class="detail-card" style="border-left:4px solid #ef4444;">
            <h6 style="color:#ef4444;font-weight:700;">${esc(firstStrategy.strategy_name || 'Business Review Required')}</h6>
            <p class="text-muted small">${esc(firstStrategy.why_it_works || '')}</p>
            <div class="strategy-steps">
                <div class="steps-title">Recommended Actions:</div>
                <ol class="steps-list">
                    ${(firstStrategy.implementation_steps || []).map(step => `<li>${esc(step)}</li>`).join('')}
                </ol>
            </div>
        </div>`;
    }

    // Show every strategy the model returned. This used to slice to 2 while
    // the Apply dialog listed all of them, so a strategy could appear at apply
    // time that was never shown here. Card index maps to rec.strategies index,
    // which selectForecastStrategy() relies on.
    const displayStrategies = strategies;

    if (!displayStrategies.length && !isMonitor) {
        return '<div class="detail-card"><p>No strategies available for this product.</p></div>';
    }

    let html = `
        <div class="strategy-plan-header">
            <h5 class="actions-title">Strategy Plan</h5>
            <div class="strategy-context">
                <span class="ctx-pill">${daysInStock} days in stock</span>
                <span class="ctx-pill">${monthly} units/mo sales</span>
                <span class="ctx-pill">₱${price.toFixed(2)} price</span>
                <span class="ctx-pill">${confidence}% confidence</span>
            </div>
        </div>
    `;

    // For MONITOR products, show the monitoring message prominently
    if (isMonitor && rec.monitor_message) {
        html += `
        <div class="monitor-advisory-box">
            <div class="monitor-advisory-header">
                <span class="monitor-icon">📊</span>
                <strong>Status: Normal Performance</strong>
            </div>
            <p class="monitor-advisory-text">${esc(rec.monitor_message)}</p>
        </div>`;
    }

    displayStrategies.forEach((s, idx) => {
        const priority = strategyPriorityMeta(s);

        const steps = s.implementation_steps || [];
        const impactRange = `${s.expected_impact_min || 0}–${s.expected_impact_max || 0}%`;
        const isBogo = s.strategy_id === 'buy_one_take_one' || s.strategy_name.toLowerCase().includes('buy 1 take 1');
        const discountTagHTML = isBogo 
            ? `<div class="strategy-discount-tag" style="background:rgba(139,92,246,0.1);color:var(--accent);font-weight:600;">Buy 1 Take 1 · ${s.duration_days} days</div>`
            : (s.recommended_discount > 0 ? `<div class="strategy-discount-tag">${Math.round(s.recommended_discount)}% discount · ${s.duration_days} days</div>` : `<div class="strategy-discount-tag">${s.duration_days} day campaign</div>`);

        html += `
        <div class="strategy-plan-card ${idx === selectedStrategyIndex ? 'selected' : ''}"
             data-strategy-index="${idx}" role="radio" tabindex="0"
             aria-checked="${idx === selectedStrategyIndex ? 'true' : 'false'}"
             aria-label="Analyze strategy option ${idx + 1}">
            <div class="strategy-plan-top">
                <div class="strategy-plan-rank">${idx + 1}</div>
                <div class="strategy-plan-info">
                    <div class="strategy-plan-name">
                        ${esc(s.strategy_name)}
                        <span class="priority-badge priority-${priority.className}">${priority.label}</span>
                        <span class="strategy-selected-label" ${idx === selectedStrategyIndex ? '' : 'hidden'}>Selected for analysis</span>
                    </div>
                    ${discountTagHTML}
                </div>
            </div>

            ${s.why_it_works ? `
            <div class="strategy-why">
                <strong>Why this works:</strong> ${esc(s.why_it_works)}
            </div>` : ''}

            ${steps.length ? `
            <div class="strategy-steps">
                <div class="steps-title">Action Plan:</div>
                <ol class="steps-list">
                    ${steps.map(step => `<li>${esc(step)}</li>`).join('')}
                </ol>
            </div>` : ''}

            <div class="strategy-impact">
                <span class="impact-label">Expected Impact:</span>
                <span class="impact-value">${impactRange} sales uplift</span>
            </div>
        </div>`;
    });

    return html;
}

// ═══════════ TAB 3: PROFIT ANALYSIS ═══════════

function buildProfitTab(rec, strategy, projection, months) {
    const price = rec.current_price || 0;
    const suppliedCost = Number(rec.cost_price) || 0;
    const costPrice = suppliedCost > 0 ? suppliedCost : (price * 0.7);
    const costIsEstimated = Boolean(rec.cost_is_estimated) || suppliedCost <= 0;
    const stock = rec.current_stock || 0;
    strategy = strategy || {};
    const discount = Math.round(strategy.recommended_discount || 0);
    const isBogo = strategy.strategy_id === 'buy_one_take_one' || (strategy.strategy_name || '').toLowerCase().includes('buy 1 take 1');
    const effectiveDiscount = isBogo ? 50 : discount;
    const discountedPrice = price * (1 - effectiveDiscount / 100);
    const durationDays = Math.max(0, Math.round(Number(strategy.duration_days) || 0));
    const impactMin = Math.round(Number(strategy.expected_impact_min) || 0);
    const impactMax = Math.round(Number(strategy.expected_impact_max) || 0);

    // Margins
    const currentMargin = price - costPrice;
    const currentMarginPct = price > 0 ? ((currentMargin / price) * 100).toFixed(1) : 0;
    const discountMargin = discountedPrice - costPrice;
    const discountMarginPct = discountedPrice > 0 ? ((discountMargin / discountedPrice) * 100).toFixed(1) : 0;
    const profitPerUnitAtDiscount = discountedPrice - costPrice;

    // Profitability verdict
    let verdictClass, verdictTitle, verdictText;
    if (profitPerUnitAtDiscount <= 0) {
        verdictClass = 'not-profitable';
        verdictTitle = 'NOT PROFITABLE — Reconsider Discount';
        verdictText = `At ₱${discountedPrice.toFixed(2)}, each unit sold loses ₱${Math.abs(profitPerUnitAtDiscount).toFixed(2)}. Consider reducing the discount or bundling instead.`;
    } else if (discountMarginPct < 15) {
        verdictClass = 'marginally-profitable';
        verdictTitle = 'MARGINALLY PROFITABLE — Proceed with Care';
        verdictText = `The discount reduces unit profit from ₱${currentMargin.toFixed(2)} to ₱${profitPerUnitAtDiscount.toFixed(2)}, but increased volume could compensate. Monitor closely and adjust if needed.`;
    } else {
        verdictClass = 'profitable';
        verdictTitle = 'PROFITABLE — Good to Apply';
        verdictText = isBogo 
            ? `Even with a Buy 1 Take 1 deal, each unit earns ₱${profitPerUnitAtDiscount.toFixed(2)} profit. The deal should drive healthy volume.`
            : `Even at ${discount}% off, each unit earns ₱${profitPerUnitAtDiscount.toFixed(2)} profit (${discountMarginPct}% margin). The discount should drive healthy volume.`;
    }

    // Month-by-month scenarios
    const scenarioLabel = strategy.strategy_name || (isBogo ? 'Buy 1 Take 1' : `${discount}% Discount`);
    const scenarios = [];
    (projection.noAction || []).forEach((month, index) => {
        const strategyMonth = projection.withStrategy?.[index] || {
            units: 0,
            regularUnits: 0,
            promotionalUnits: 0,
        };
        const monthLabel = months[index] || `Month ${index + 1}`;
        const baselineProfit = month.units * (price - costPrice);
        scenarios.push({
            label: `No Action — ${monthLabel}`,
            units: month.units,
            revenue: month.units * price,
            cost: month.units * costPrice,
            isBaseline: true,
        });
        scenarios.push({
            label: `${scenarioLabel} — ${monthLabel}`,
            units: strategyMonth.units,
            revenue: (strategyMonth.regularUnits * price) + (strategyMonth.promotionalUnits * discountedPrice),
            cost: strategyMonth.units * costPrice,
            isBaseline: false,
            comparisonProfit: baselineProfit,
        });
    });

    // Stock value impact
    const currentValue = stock * price;
    const discountedValue = stock * discountedPrice;
    const valueConceded = currentValue - discountedValue;
    const inventoryCostBasis = stock * costPrice;
    const holdingCostMonthly = inventoryCostBasis * 0.02; // ~2% of inventory cost basis
    const maxNonLossDiscount = price > 0 && price > costPrice
        ? ((price - costPrice) / price) * 100
        : 0;

    return `
        <div class="profit-strategy-context">
            Analyzing: <strong>${esc(strategy.strategy_name || 'Selected strategy')}</strong>
            <span>${isBogo ? 'Buy 1 Take 1' : `${discount}% discount`} · ${durationDays} days · ${impactMin}–${impactMax}% expected uplift</span>
        </div>
        <div class="price-boxes">
            <div class="price-box">
                <div class="price-box-label">CURRENT PRICE</div>
                <div class="price-box-value">₱${price.toFixed(2)}</div>
                <div class="price-box-sub">Margin: ${currentMarginPct}%</div>
            </div>
            <div class="price-box highlight">
                <div class="price-box-label">${isBogo ? 'BUY 1 TAKE 1 EFFECTIVE PRICE' : `DISCOUNTED PRICE (${discount}% OFF)`}</div>
                <div class="price-box-value">₱${discountedPrice.toFixed(2)}</div>
                <div class="price-box-sub">Margin: ${discountMarginPct}%</div>
            </div>
            <div class="price-box cost-box">
                <div class="price-box-label">${costIsEstimated ? 'ESTIMATED UNIT COST' : 'UNIT COST'}</div>
                <div class="price-box-value">₱${costPrice.toFixed(2)}</div>
                <div class="price-box-sub">Profit/unit at discount: ₱${profitPerUnitAtDiscount.toFixed(2)}</div>
            </div>
        </div>

        <div class="profitability-banner ${verdictClass}">
            <div class="verdict-body">
                <strong>${verdictTitle}</strong>
                <div class="verdict-text">${verdictText}</div>
            </div>
        </div>

        <h6 class="section-subtitle">Month-by-Month Profit Projection</h6>
        <div class="table-responsive">
            <table class="profit-table">
                <thead>
                    <tr>
                        <th>SCENARIO</th>
                        <th>UNITS SOLD</th>
                        <th>REVENUE</th>
                        <th>TOTAL COST</th>
                        <th>GROSS PROFIT</th>
                        <th>VERDICT</th>
                    </tr>
                </thead>
                <tbody>
                    ${scenarios.map(s => {
                        const rev = s.revenue;
                        const tc = s.cost;
                        const net = rev - tc;
                        const isProfit = net > 0;
                        let verdictBadge;
                        if (s.isBaseline) {
                            verdictBadge = '<span class="verdict-neutral">Baseline</span>';
                        } else if (!isProfit) {
                            verdictBadge = '<span class="verdict-bad">Loss</span>';
                        } else if (net >= s.comparisonProfit) {
                            verdictBadge = '<span class="verdict-good">Higher</span>';
                        } else {
                            verdictBadge = '<span class="verdict-bad">Lower</span>';
                        }
                        return `<tr${s.isBaseline ? ' class="baseline-row"' : ''}>
                            <td>${esc(s.label)}</td>
                            <td>${formatProjectedUnits(s.units)}</td>
                            <td>₱${rev.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                            <td>₱${tc.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                            <td class="${isProfit ? 'text-profit' : 'text-loss'}">₱${net.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                            <td>${verdictBadge}</td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>
        </div>

        <h6 class="section-subtitle">Stock Value Impact</h6>
        <div class="table-responsive">
            <table class="profit-table stock-impact-table">
                <thead>
                    <tr>
                        <th>METRIC</th>
                        <th>VALUE</th>
                        <th>NOTES</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Potential Retail Value</td>
                        <td class="text-profit">₱${currentValue.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                        <td>${stock} units × ₱${price.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                    </tr>
                    <tr>
                        <td>Potential Promotional Revenue (all stock)</td>
                        <td class="text-profit">₱${discountedValue.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                        <td>${stock} units × ₱${discountedPrice.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                    </tr>
                    <tr>
                        <td>Inventory Cost Basis</td>
                        <td>₱${inventoryCostBasis.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                        <td>${stock} units × ₱${costPrice.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                    </tr>
                    <tr>
                        <td>Maximum Value Conceded by Discount</td>
                        <td class="text-loss">-₱${valueConceded.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                        <td>Maximum reduction if every unit is sold at the promotional price</td>
                    </tr>
                    <tr>
                        <td>Cost of Holding Stock (monthly, est.)</td>
                        <td class="text-loss">-₱${holdingCostMonthly.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
                        <td>~2% of inventory cost basis (storage + opportunity cost)</td>
                    </tr>
                    <tr>
                        <td>Maximum Discount Before Unit Loss</td>
                        <td style="color:#3b82f6;font-weight:700;">${maxNonLossDiscount.toFixed(1)}%</td>
                        <td>${maxNonLossDiscount > 0 ? 'A higher discount makes the selling price lower than unit cost' : 'The current price does not cover unit cost'}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="profit-note">
            <strong>Calculation assumptions:</strong> ${costIsEstimated ? 'Unit cost is estimated at 70% of selling price because no cost is recorded. ' : ''}
            No-action demand starts from the model forecast and uses a 5% monthly decline. Expected uplift comes from the selected strategy template and is applied only during its campaign period. Projections are limited by current stock and assume no restocking.
            Gross profit excludes operating and campaign expenses. Holding cost is estimated at 2% per month of inventory cost basis.
            ${strategy.strategy_id === 'cross_sell_pairing' || strategy.strategy_id === 'bundle_deal' ? 'Partner-product revenue and cost are excluded until a partner product is selected.' : ''}
        </div>
    `;
}

// ═══════════ TAB 4: PREDICTION DETAILS ═══════════

function buildDetailsTab(rec) {
    const confidence = Math.round((rec.confidence || 0.65) * 100);
    const strategy = rec.strategies?.[0] || {};
    const discount = strategy.recommended_discount || 0;
    const riskScore = Math.round((rec.risk_score || 0) * 100);
    const daysInStock = rec.days_in_stock || 0;
    const monthly = rec.monthly_sales || 0;
    const stock = rec.current_stock || 0;
    const historyCount = rec.history_count || 0;

    // Format date_added
    let dateAddedDisplay = 'N/A';
    if (rec.date_added) {
        try {
            const d = new Date(rec.date_added);
            dateAddedDisplay = d.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
        } catch (e) {
            dateAddedDisplay = rec.date_added;
        }
    }

    // Determine age status
    let ageStatus = 'Fresh';
    let ageColor = '#10b981';
    if (daysInStock > 90) { ageStatus = 'Aging'; ageColor = '#ef4444'; }
    else if (daysInStock > 60) { ageStatus = 'Moderate'; ageColor = '#f59e0b'; }
    else if (daysInStock > 30) { ageStatus = 'Normal'; ageColor = '#3b82f6'; }

    // Feature importance (based on actual product data)
    const features = [
        { name: 'Days in stock', value: Math.min(100, (daysInStock / 120) * 100), color: '#ef4444' },
        { name: 'Monthly sales velocity', value: Math.min(100, Math.max(5, 100 - monthly * 5)), color: '#f59e0b' },
        { name: 'Current stock level', value: Math.min(100, (stock / 50) * 100), color: '#3b82f6' },
        { name: 'Seasonal demand factor', value: 35, color: '#8b5cf6' },
        { name: 'Price point sensitivity', value: 25, color: '#10b981' },
    ];

    // Key insight based on risk level
    let keyInsight = '';
    if (rec.risk_level === 'CRITICAL') {
        if (rec.is_critical_expiry) {
            keyInsight = `This product has critical expiry urgency. The model heavily weights the ${rec.days_until_expiry} days until expiration, recommending immediate action to avoid spoilage losses.`;
        } else {
            keyInsight = `This product has critical risk indicators. Very low sales velocity (${monthly.toFixed(1)} units/month) combined with ${daysInStock} days in stock signals an urgent need for clearance strategies.`;
        }
    } else if (rec.risk_level === 'WARNING') {
        keyInsight = `Sales velocity of ${monthly.toFixed(1)} units/month is below the threshold. The model identifies days in stock (${daysInStock} days) as the primary risk driver, suggesting promotional intervention.`;
    } else if (rec.risk_level === 'MONITOR') {
        keyInsight = `This product shows moderate risk indicators but is performing within acceptable bounds. Regular monitoring is recommended to catch early signs of decline.`;
    } else {
        keyInsight = `This product shows moderate risk indicators. The model balances stock age, sales velocity, and inventory levels to generate this recommendation.`;
    }

    return `
        <h6 class="section-subtitle">Model Metrics</h6>
        <div class="detail-card">
            <div class="detail-row"><span>Model Type</span> <strong>Random Forest Regressor + Data History</strong></div>
            <div class="detail-row"><span>Confidence Score</span> <strong>${confidence}%</strong></div>
            <div class="detail-row"><span>Risk Priority Score</span> <strong>${riskScore}%</strong></div>
            <div class="detail-row"><span>Days in Stock</span> <strong>${daysInStock} days</strong></div>
            <div class="detail-row"><span>Date Added</span> <strong>${dateAddedDisplay}</strong></div>
            <div class="detail-row"><span>Historical Strategies Applied</span> <strong>${historyCount}</strong></div>
        </div>

        <h6 class="section-subtitle">What Influences This Prediction?</h6>
        <div class="detail-card">
            ${features.map(f => `
                <div class="feature-row">
                    <div class="feature-name">${f.name}</div>
                    <div class="feature-bar-wrap">
                        <div class="feature-bar" style="width:${f.value.toFixed(0)}%; background:${f.color}"></div>
                    </div>
                    <div class="feature-pct">${f.value.toFixed(0)}%</div>
                </div>
            `).join('')}
        </div>
    `;
}

// ═══════════ CHART ═══════════

function renderForecastChart(labels, withStrategy, noAction) {
    const canvas = document.getElementById('forecastChart');
    if (!canvas) return;

    if (forecastChart) { forecastChart.destroy(); forecastChart = null; }

    const ctx = canvas.getContext('2d');
    forecastChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'With Strategy',
                    data: withStrategy,
                    borderColor: '#2c5530',
                    backgroundColor: 'rgba(44,85,48,0.08)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointBackgroundColor: '#2c5530',
                },
                {
                    label: 'No Action',
                    data: noAction,
                    borderColor: '#94a3b8',
                    borderDash: [6, 4],
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#94a3b8',
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y} units`,
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Projected Units Sold', font: { size: 12 } },
                    grid: { color: 'rgba(0,0,0,0.05)' },
                },
                x: {
                    grid: { display: false },
                },
            },
        },
    });
}

// ═══════════ APPLY STRATEGY MODAL ═══════════

let _currentApplyRec = null; // Store current rec for forecast modal button

function openApplyModal(rec, preferredStrategyId = null) {
    _currentApplyRec = rec;
    const modal = new bootstrap.Modal(document.getElementById('applyModal'));
    const content = document.getElementById('applyContent');
    if (!content) return;

    const strategies = rec.strategies || [];
    const preferredIndex = strategies.findIndex(s => s.strategy_id === preferredStrategyId);
    const selectedStrategyIndex = preferredIndex >= 0 ? preferredIndex : 0;
    const firstStrategy = strategies[0] || {};
    const isEscalation = firstStrategy.strategy_id === 'escalate_reevaluate' || firstStrategy.is_escalation;

    if (isEscalation) {
        content.innerHTML = `
            <div class="text-center py-3">
                <h6 style="color:#ef4444;font-weight:700;">${esc(firstStrategy.strategy_name || 'Business Review Required')}</h6>
                <p class="text-muted small mb-3">${esc(firstStrategy.why_it_works || `A business review is needed for ${rec.product_name}.`)}</p>
            </div>
            <div class="escalation-steps" style="background:rgba(239,68,68,0.05);border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:16px;">
                <div style="font-weight:600;margin-bottom:10px;color:#ef4444;">Recommended Actions:</div>
                <ol style="padding-left:18px;margin:0;">
                    ${(firstStrategy.implementation_steps || []).map(step => `<li style="margin-bottom:6px;font-size:13px;">${esc(step)}</li>`).join('')}
                </ol>
            </div>`;
        const confirmBtn = document.getElementById('confirmApplyBtn');
        if (confirmBtn) confirmBtn.style.display = 'none';
        modal.show();
        return;
    }

    if (!strategies.length) {
        content.innerHTML = `<div class="text-center text-muted py-4">
            <i class="fas fa-info-circle fa-2x mb-2"></i>
            <p>No applicable strategies for this product.</p>
        </div>`;
        const confirmBtn = document.getElementById('confirmApplyBtn');
        if (confirmBtn) confirmBtn.style.display = 'none';
        modal.show();
        return;
    }

    content.innerHTML = `
        <h6>Select a strategy to apply for <strong>${esc(rec.product_name)}</strong>:</h6>
        <p class="text-muted small mb-3">Current price: ₱${(rec.current_price || 0).toFixed(2)}</p>
        <div class="strategies-list">
            ${strategies.map((s, i) => {
                const priority = strategyPriorityMeta(s);
                const isBogo = s.strategy_id === 'buy_one_take_one' || (s.strategy_name || '').toLowerCase().includes('buy 1 take 1');
                const badgeHTML = isBogo 
                    ? `<span class="discount-pill bogo-pill" style="background:var(--accent);color:white;">Buy 1 Take 1</span>` 
                    : (s.recommended_discount > 0 ? `<span class="discount-pill">${Math.round(s.recommended_discount)}% off</span>` : '');
                const effectiveDiscount = isBogo ? 50 : s.recommended_discount;
                const optionPrice = isBogo ? ((rec.current_price || 0) * 0.5) : ((rec.current_price || 0) * (1 - s.recommended_discount / 100));

                return `
                <div class="strategy-option ${i === selectedStrategyIndex ? 'selected' : ''}" data-strategy="${s.strategy_id}" data-discount="${effectiveDiscount}" data-name="${esc(s.strategy_name)}" onclick="selectStrategy(this)">
                    <div class="strategy-option-header">
                        <strong>${esc(s.strategy_name)}</strong>
                        <span class="priority-badge priority-${priority.className}">${priority.label}</span>
                        ${badgeHTML}
                    </div>
                    <div class="strategy-option-details">
                        <span>Duration: ${s.duration_days} days</span>
                        <span>Impact: ${s.expected_impact_min}–${s.expected_impact_max}%</span>
                    </div>
                    ${s.recommended_discount > 0 || isBogo ? `<div class="strategy-option-price text-muted small mt-1">${s.strategy_id === 'cross_sell_pairing' ? 'Price when paired' : (isBogo ? 'Effective price/unit' : 'New price')}: ₱${optionPrice.toFixed(2)}</div>` : ''}
                </div>
                `;
            }).join('')}
        </div>
        <div id="pairingSetup" class="mt-3" hidden>
            <label for="pairedProduct" class="form-label">Pair with a related product</label>
            <select id="pairedProduct" class="form-select"><option value="">Loading products...</option></select>
            <p class="small text-muted mt-2">Products are ranked by units sold in the last 30 days. Choose a complementary item. One discounted unit per partner unit in the same purchase; standalone units keep their regular price.</p>
        </div>
        <div class="mt-3">
            <label class="form-label">Notes (optional)</label>
            <textarea class="form-control" id="strategyNotes" rows="2" placeholder="Add notes about this strategy application..."></textarea>
        </div>
    `;

    // Wire confirm button
    const confirmBtn = document.getElementById('confirmApplyBtn');
    if (confirmBtn) {
        confirmBtn.style.display = '';
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = 'Apply strategy';
        confirmBtn.onclick = () => {
            const selected = content.querySelector('.strategy-option.selected');
            if (!selected) { showToast('warning', 'Warning', 'Please select a strategy'); return; }
            applyStrategy(
                rec.product_id,
                selected.dataset.strategy,
                selected.dataset.discount,
                document.getElementById('strategyNotes')?.value || '',
                confirmBtn,
                document.getElementById('pairedProduct')?.value || ''
            );
        };
    }

    const pairingSetup = document.getElementById('pairingSetup');
    pairingSetup.hidden = strategies[selectedStrategyIndex]?.strategy_id !== 'cross_sell_pairing';
    if (strategies.some(s => s.strategy_id === 'cross_sell_pairing')) {
        const select = document.getElementById('pairedProduct');
        $.ajax({
            url: API_URL, method: 'GET', dataType: 'json',
            data: { action: 'get_pairing_products', product_id: rec.product_id },
            success(res) {
                const options = res.products || [];
                select.innerHTML = '<option value="">Choose a paired product</option>' + options.map(p =>
                    `<option value="${Number(p.id)}">${esc(p.name)} — ${Number(p.monthly_sales)} sold / 30 days</option>`
                ).join('');
                if (!options.length) select.innerHTML = '<option value="">No available products in this category</option>';
            },
            error() { select.innerHTML = '<option value="">Could not load partners. Reopen this dialog to retry.</option>'; }
        });
    }
    modal.show();
}

function selectStrategy(el) {
    document.querySelectorAll('.strategy-option').forEach(o => o.classList.remove('selected'));
    el.classList.add('selected');
    const setup = document.getElementById('pairingSetup');
    if (setup) setup.hidden = el.dataset.strategy !== 'cross_sell_pairing';
}

function applyStrategy(productId, strategyId, discount, notes, btn, pairedProductId = '') {
    if (strategyId === 'cross_sell_pairing' && !pairedProductId) {
        showToast('warning', 'Choose a partner', 'Select a paired product before applying this strategy.');
        return;
    }
    // Show loading state
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>Applying...';
    }

    $.ajax({
        url: API_URL,
        method: 'POST',
        data: {
            action: 'save_recommendation',
            product_id: productId,
            strategy_id: strategyId,
            discount_percentage: discount,
            paired_product_id: pairedProductId,
            notes: notes,
        },
        dataType: 'json',
        success(res) {
            if (res.success) {
                showToast('success', 'Strategy Applied!', res.message || 'Strategy has been applied successfully.');
                // Close both modals
                bootstrap.Modal.getInstance(document.getElementById('applyModal'))?.hide();
                bootstrap.Modal.getInstance(document.getElementById('forecastModal'))?.hide();
                // Refresh recommendation cards after a short delay
                setTimeout(() => loadRecommendations(), 500);
            } else {
                showToast('error', 'Error', res.error || res.message || 'Failed to apply strategy');
            }
        },
        error(xhr) {
            const response = xhr.responseJSON || {};
            showToast('error', 'Error', response.error || response.message || 'Failed to apply strategy. Check API connection.');
        },
        complete() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = 'Apply strategy';
            }
        },
    });
}

// ═══════════ UTILITIES ═══════════

function esc(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function showToast(type, title, message) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const bgClass = { success: 'bg-success', error: 'bg-danger', warning: 'bg-warning', info: 'bg-info' }[type] || 'bg-info';
    const id = 'toast-' + Date.now();
    container.insertAdjacentHTML('beforeend', `
        <div id="${id}" class="toast align-items-center text-white ${bgClass} border-0 mb-2" role="alert">
            <div class="d-flex">
                <div class="toast-body"><strong>${esc(title)}</strong> ${esc(message)}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `);
    const el = document.getElementById(id);
    if (el) {
        new bootstrap.Toast(el, { autohide: true, delay: 3000 }).show();
        setTimeout(() => el?.remove(), 3500);
    }
}
