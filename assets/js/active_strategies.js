// assets/js/active_strategies.js

function openActiveStrategiesModal() {
    const modal = new bootstrap.Modal(document.getElementById('activeStrategiesModal'));
    loadActiveStrategies();
    modal.show();
}

function loadActiveStrategies() {
    const tbody = document.getElementById('activeStrategiesTableBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4"><i class="fas fa-spinner fa-spin me-2"></i>Loading active strategies...</td></tr>';

    $.ajax({
        url: 'ajax/ml_recommendation_ajax.php',
        type: 'GET',
        data: { action: 'get_active_strategies' },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                renderActiveStrategies(res.strategies);
            } else {
                tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-4">Error loading strategies: ${res.error || 'Unknown error'}</td></tr>`;
            }
        },
        error: function() {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Failed to fetch active strategies from server.</td></tr>';
        }
    });
}

function renderActiveStrategies(strategies) {
    const tbody = document.getElementById('activeStrategiesTableBody');
    if (!tbody) return;

    if (!strategies || strategies.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="text-center text-muted py-5">
                    <i class="fas fa-tags fa-3x mb-3 opacity-50"></i>
                    <p>No active promotional strategies currently running.</p>
                </td>
            </tr>`;
        return;
    }

    let html = '';
    strategies.forEach(s => {
        // Calculate remaining duration securely
        let timeInfo = `<span class="badge bg-secondary">Unknown</span>`;
        if (s.ended_at) {
            const endDate = new Date(s.ended_at);
            const now = new Date();
            const diffTime = endDate - now;
            if (diffTime > 0) {
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                timeInfo = `<span class="badge bg-success">${diffDays} days left</span>`;
            } else {
                timeInfo = `<span class="badge bg-warning text-dark">Expiring soon</span>`;
            }
        }

        html += `
            <tr>
                <td class="align-middle fw-bold text-dark">${escapeHtmlStr(s.product_name)}</td>
                <td class="align-middle">
                    <div class="fw-semibold" style="color:#2c5530;">${escapeHtmlStr(s.strategy_name || s.strategy_id)}</div>
                    <small class="text-muted">${Number(s.discount_applied)}% discount</small>
                </td>
                <td class="align-middle">
                    <div class="text-decoration-line-through text-muted small">₱${Number(s.original_price).toFixed(2)}</div>
                    <div class="fw-bold text-danger">₱${Number(s.discounted_price).toFixed(2)}</div>
                </td>
                <td class="align-middle">${timeInfo}</td>
                <td class="align-middle text-end">
                    <button class="btn btn-sm btn-outline-danger" onclick="cancelActiveStrategy(${s.id}, '${escapeHtmlStr(s.product_name)}', this)">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
}

function cancelActiveStrategy(historyId, productName, btnElement) {
    if (!confirm(`Are you sure you want to cancel the active strategy for ${productName}? The price will immediately revert to normal.`)) {
        return;
    }

    const oHtml = btnElement.innerHTML;
    btnElement.disabled = true;
    btnElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    $.ajax({
        url: 'ajax/ml_recommendation_ajax.php',
        type: 'POST',
        data: { 
            action: 'cancel_strategy',
            id: historyId
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                // Show generalized toast function or alert
                if (typeof showToast === 'function') {
                    showToast('success', 'Strategy Cancelled', res.message || 'Product price reverted to normal.');
                } else {
                    alert(res.message || 'Strategy cancelled successfully.');
                }
                
                // Refresh lists
                loadActiveStrategies();

                // If on Reco page, refresh cards too
                if (typeof loadRecommendations === 'function') {
                    setTimeout(() => loadRecommendations(), 500);
                }
                // If on POS, refresh products
                if (typeof loadProducts === 'function') {
                    setTimeout(() => loadProducts(), 500);
                }
            } else {
                alert('Error: ' + (res.error || 'Failed to cancel strategy.'));
                btnElement.disabled = false;
                btnElement.innerHTML = oHtml;
            }
        },
        error: function() {
            alert('Server error occurred while cancelling strategy.');
            btnElement.disabled = false;
            btnElement.innerHTML = oHtml;
        }
    });
}

function escapeHtmlStr(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
