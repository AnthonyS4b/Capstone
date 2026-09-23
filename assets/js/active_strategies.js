// assets/js/active_strategies.js
// "Active promotions" modal (includes/active_promos_modal.php) — POS and Recommendations.

function openActiveStrategiesModal() {
    const el = document.getElementById('activeStrategiesModal');
    if (!el) return;
    loadActiveStrategies();
    bootstrap.Modal.getOrCreateInstance(el).show();
}

function promoPeso(n) {
    return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function promoMessage(html) {
    const list = document.getElementById('activeStrategiesTableBody');
    if (list) list.innerHTML = html;
}

function loadActiveStrategies() {
    promoMessage('<div class="promo-empty"><div class="spinner-border spinner-border-sm" role="status"></div><p>Loading promotions…</p></div>');
    const summary = document.getElementById('activePromosSummary');
    if (summary) summary.textContent = 'Discounts currently applied at the POS.';

    $.ajax({
        url: 'ajax/ml_recommendation_ajax.php',
        type: 'GET',
        data: { action: 'get_active_strategies' },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                renderActiveStrategies(res.strategies);
            } else {
                promoMessage(`<div class="promo-empty is-error"><p class="promo-empty-title">Couldn't load promotions</p><p>${escapeHtmlStr(res.error || 'Unknown error')}</p></div>`);
            }
        },
        error: function () {
            promoMessage('<div class="promo-empty is-error"><p class="promo-empty-title">Couldn\'t load promotions</p><p>The recommendation service did not respond. Try again in a moment.</p></div>');
        }
    });
}

// Time left in plain words, plus how far through its run the promotion is (0–100)
function promoTiming(startedAt, endedAt) {
    if (!endedAt) return { text: 'No end date', pct: null, soon: false };
    const parse = v => new Date(String(v).replace(' ', 'T'));
    const end = parse(endedAt);
    const start = startedAt ? parse(startedAt) : null;
    const now = new Date();
    const msLeft = end - now;
    const endLabel = end.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });

    let text;
    if (msLeft <= 0) text = 'Ending now';
    else if (msLeft < 36e5) text = `Ends in ${Math.max(1, Math.round(msLeft / 6e4))} min`;
    else if (msLeft < 864e5) text = `Ends in ${Math.round(msLeft / 36e5)} h`;
    else {
        const days = Math.ceil(msLeft / 864e5);
        text = `${days} day${days === 1 ? '' : 's'} left · ends ${endLabel}`;
    }

    let pct = null;
    if (start && end > start) pct = Math.min(100, Math.max(0, ((now - start) / (end - start)) * 100));
    return { text, pct, soon: msLeft < 864e5 };
}

function renderActiveStrategies(strategies) {
    const summary = document.getElementById('activePromosSummary');

    if (!strategies || strategies.length === 0) {
        if (summary) summary.textContent = 'Nothing running right now.';
        promoMessage(`
            <div class="promo-empty">
                <span class="promo-empty-icon" aria-hidden="true"><i class="fas fa-tags"></i></span>
                <p class="promo-empty-title">No active promotions</p>
                <p>When you apply a strategy from Recommendations, its discount and time left show up here.</p>
            </div>`);
        return;
    }

    if (summary) {
        summary.textContent = `${strategies.length} promotion${strategies.length === 1 ? '' : 's'} running · prices below are what the POS charges now`;
    }

    const canCancel = window.activePromosCanCancel !== false;
    promoMessage(strategies.map(s => {
        const paired = s.strategy_id === 'cross_sell_pairing';
        const timing = promoTiming(s.started_at, s.ended_at);
        const discount = Number(s.discount_applied) || 0;
        const thumb = s.image
            ? `<img class="promo-thumb" src="${escapeHtmlStr(s.image)}" alt="" loading="lazy">`
            : `<span class="promo-thumb promo-thumb-empty" aria-hidden="true"><i class="fas fa-box"></i></span>`;

        return `
            <article class="promo-card">
                ${thumb}
                <div class="promo-main">
                    <div class="promo-top">
                        <span class="promo-product">${escapeHtmlStr(s.product_name)}</span>
                        ${discount > 0 ? `<span class="promo-off">${discount}% off</span>` : ''}
                    </div>
                    <div class="promo-strategy">${escapeHtmlStr(s.strategy_name || s.strategy_id)}</div>
                    ${paired ? `<div class="promo-note">When bought with ${escapeHtmlStr(s.paired_product_name || 'its paired product')} — one discounted unit per pair</div>` : ''}
                    <div class="promo-price">
                        <span class="promo-new">${promoPeso(s.discounted_price)}${paired ? ' <small>when paired</small>' : ''}</span>
                        <span class="promo-old">${paired ? 'Regular ' : ''}${promoPeso(s.original_price)}</span>
                    </div>
                    <div class="promo-time ${timing.soon ? 'is-soon' : ''}">
                        <span>${timing.text}</span>
                        ${timing.pct !== null ? `<span class="promo-bar" aria-hidden="true"><span style="width:${timing.pct.toFixed(0)}%"></span></span>` : ''}
                    </div>
                </div>
                ${canCancel ? `
                <button type="button" class="promo-cancel" data-promo-id="${Number(s.id)}" data-promo-name="${escapeHtmlStr(s.product_name)}"
                        onclick="cancelActiveStrategy(this.dataset.promoId, this.dataset.promoName, this)">End early</button>` : ''}
            </article>`;
    }).join(''));
}

function promoToast(type, title, message) {
    if (typeof showToast === 'function') showToast(type, title, message);
}

async function cancelActiveStrategy(historyId, productName, btnElement) {
    const ok = typeof confirmDialog === 'function'
        ? await confirmDialog({
            title: 'End this promotion?',
            message: `${productName} goes back to its regular price right away.`,
            confirmText: 'End promotion',
            tone: 'danger',
            icon: 'fa-tag'
        })
        : confirm(`End the promotion for ${productName}? Its regular price returns right away.`);
    if (!ok) return;

    const original = btnElement.innerHTML;
    btnElement.disabled = true;
    btnElement.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';

    const restore = () => { btnElement.disabled = false; btnElement.innerHTML = original; };

    $.ajax({
        url: 'ajax/ml_recommendation_ajax.php',
        type: 'POST',
        data: { action: 'cancel_strategy', id: historyId },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                promoToast('success', 'Promotion ended', res.message || `${productName} is back to its regular price.`);
                loadActiveStrategies();
                // Refresh whatever page is underneath
                if (typeof loadRecommendations === 'function') setTimeout(() => loadRecommendations(), 500);
                if (typeof loadProducts === 'function') setTimeout(() => loadProducts(), 500);
            } else {
                promoToast('error', 'Could not end promotion', res.error || 'Please try again.');
                restore();
            }
        },
        error: function (xhr) {
            const response = xhr.responseJSON || {};
            promoToast('error', 'Could not end promotion', response.error || response.message || 'Server error. Please try again.');
            restore();
        }
    });
}

function escapeHtmlStr(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
