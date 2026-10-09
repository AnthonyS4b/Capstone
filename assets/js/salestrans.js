// salestrans.js — Sales Transaction History
// Void transaction restores stock via transaction_ajax.php void_transaction case

const ROWS_PER_PAGE      = 15;
let currentPage          = 1;
let totalPages           = 1;
let currentTransactionId = null;
let transactions         = [];   // rows of the current page, from the server
let totalCount           = 0;    // matching rows across all pages

// Dates and status take effect on Apply; the search box filters as you type
let appliedFilters = { date_from: '', date_to: '', status: 'all' };
let listRequest    = null;       // in-flight list request, dropped when a newer one starts

// ─────────────────────────────────────────────
// LOADING / TOAST
// ─────────────────────────────────────────────
function showLoading() { document.getElementById('loadingSpinner').style.display = 'flex'; }
function hideLoading() { document.getElementById('loadingSpinner').style.display = 'none'; }

function showToast(type, title, message) {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const icons = {
        success : 'fa-check-circle',
        error   : 'fa-exclamation-circle',
        warning : 'fa-exclamation-triangle',
        info    : 'fa-info-circle'
    };

    const id   = 'toast-' + Date.now() + '-' + Math.random();
    const icon = icons[type] || 'fa-bell';

    container.insertAdjacentHTML('beforeend', `
        <div id="${id}" class="toast align-items-center text-white bg-${type} border-0 mb-2" role="alert">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas ${icon} me-2"></i>
                    <strong>${title}</strong> ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>`);

    const el    = document.getElementById(id);
    const toast = new bootstrap.Toast(el, { autohide: true, delay: 3000 });
    toast.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}

// ─────────────────────────────────────────────
// LOAD TRANSACTIONS
// ─────────────────────────────────────────────
function loadTransactions(page = 1, opts = {}) {
    const quiet = !!opts.quiet;      // live search: no full-screen spinner, no stats refresh
    if (!quiet) showLoading();

    // Only the newest request may update the table (fast typing sends several)
    if (listRequest) listRequest.abort();

    listRequest = $.ajax({
        url      : 'ajax/transaction_ajax.php',
        method   : 'POST',
        dataType : 'json',
        data     : {
            action    : 'get_transactions',
            page      : page,
            limit     : ROWS_PER_PAGE,
            search    : document.getElementById('searchInput').value.trim(),
            date_from : appliedFilters.date_from,
            date_to   : appliedFilters.date_to,
            status    : appliedFilters.status
        },
        success  : function (response) {
            hideLoading();
            if (response.success) {
                transactions = response.data || [];
                totalCount   = response.total_count || 0;
                totalPages   = Math.max(1, response.total_pages || 1);
                currentPage  = page;
                // A void or a narrower filter can leave us past the last page
                if (page > totalPages && totalCount > 0) {
                    loadTransactions(totalPages, opts);
                    return;
                }
                renderPage();
                if (!quiet) loadStats();
            } else {
                showToast('error', 'Error', response.message || 'Failed to load transactions');
            }
        },
        error    : function (xhr, status, error) {
            if (status === 'abort') return;   // superseded by a newer search
            hideLoading();
            // Log the real server response so we can diagnose the issue
            console.error('=== AJAX ERROR ===');
            console.error('Status:', status);
            console.error('Error:', error);
            console.error('Response text:', xhr.responseText);
            console.error('HTTP code:', xhr.status);

            // Show the actual PHP error in the toast if any
            let detail = error || status || 'Unknown error';
            if (xhr.responseText && xhr.responseText.length < 300) {
                detail = xhr.responseText;
            }
            showToast('error', 'Connection Error', detail);

            // Also show raw response in the table so it is visible without DevTools
            const tbody = document.getElementById('transactionsTableBody');
            if (tbody) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="9" class="text-center py-3">
                            <div class="alert alert-danger text-start mx-3" style="font-size:12px;">
                                <strong>Server response (copy this and share it):</strong><br>
                                <pre style="white-space:pre-wrap;word-break:break-all;">${escapeHtml(xhr.responseText || '(empty — check error.log)')}</pre>
                                <strong>HTTP status:</strong> ${xhr.status}<br>
                                <strong>Error:</strong> ${escapeHtml(error)}
                            </div>
                        </td>
                    </tr>`;
            }
        },
        complete : function () { listRequest = null; }
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

// ─────────────────────────────────────────────
// STATS
// ─────────────────────────────────────────────
function loadStats() {
    $.ajax({
        url      : 'ajax/transaction_ajax.php',
        method   : 'POST',
        dataType : 'json',
        data     : { action: 'get_stats' },
        success  : function (response) {
            if (response.success && response.data) {
                const s = response.data;
                // Thousands separators so large totals are readable (₱37,381,674.50)
                const peso = v => '₱' + Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const set  = (id, text) => {
                    const el = document.getElementById(id);
                    if (!el) return;
                    el.textContent = text;
                    el.classList.remove('is-loading');
                };
                set('totalTransactions', Number(s.total_transactions || 0).toLocaleString('en-PH'));
                set('totalSales',        peso(s.total_sales));
                set('todaySales',        peso(s.today_sales));
                set('avgTransaction',    peso(s.avg_transaction));
            }
        }
    });
}

// keep old name working
function updateStats() { loadStats(); }

// ─────────────────────────────────────────────
// DISPLAY ROWS
// ─────────────────────────────────────────────
function displayTransactionRows(list) {
    const tbody = document.getElementById('transactionsTableBody');

    if (!list || list.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center py-4">
                    <div class="empty-state">
                        <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                        <h5>No Transactions Found</h5>
                        <p class="text-muted">${emptyHint()}</p>
                    </div>
                </td>
            </tr>`;
        return;
    }

    tbody.innerHTML = list.map(t => {
        const date          = new Date(t.created_at).toLocaleString();
        const itemsCount    = t.item_count || 0;
        const txId          = 'TRX-' + String(t.id).padStart(6, '0');
        const pm            = t.payment_method || 'cash';
        const pmIcon        = pm === 'gcash' ? 'fa-mobile-screen' : 'fa-money-bill-wave';
        const pmLabel       = pm === 'gcash' ? 'GCash' : 'Cash';
        const status        = t.status || 'completed';

        let refHtml = '';
        if (t.notes && t.notes.includes('GCash Ref:')) {
            const ref = t.notes.replace('GCash Ref:', '').trim();
            refHtml = `<small class="text-muted d-block">Ref: ${escapeHtml(ref)}</small>`;
        }

        const voidBtn = status === 'completed'
            ? `<button class="btn-icon void" onclick="openVoidModal(${t.id})" title="Void Transaction">
                   <i class="fas fa-ban"></i>
               </button>`
            : '';

        return `
            <tr onclick="viewTransaction(${t.id})" style="cursor:pointer;">
                <td><span class="transaction-id">${txId}</span></td>
                <td>${date}</td>
                <td>${itemsCount} item${itemsCount !== 1 ? 's' : ''}</td>
                <td class="amount-positive">₱${parseFloat(t.total_amount || t.total || 0).toFixed(2)}</td>
                <td>
                    <span class="payment-method payment-${pm}"><i class="fa-solid ${pmIcon}"></i>${pmLabel}</span>
                    ${refHtml}
                </td>
                <td>₱${parseFloat(t.amount_paid || 0).toFixed(2)}</td>
                <td>₱${parseFloat(t.change_amount || 0).toFixed(2)}</td>
                <td><span class="status-badge status-${status}">${status}</span></td>
                <td onclick="event.stopPropagation()">
                    <button class="btn-icon" onclick="viewTransaction(${t.id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    ${voidBtn}
                </td>
            </tr>`;
    }).join('');
}

function emptyHint() {
    const q = document.getElementById('searchInput').value.trim();
    return q ? `Nothing matches “${escapeHtml(q)}” with the current filters.` : 'Try adjusting your filters';
}

// ─────────────────────────────────────────────
// PAGINATION (pages come from the server)
// ─────────────────────────────────────────────
function renderPage() {
    displayTransactionRows(transactions);
    displayPagination();
    updatePageInfo();
}

function updatePageInfo() {
    const el = document.getElementById('pageInfo');
    if (!el) return;
    const start = totalCount === 0 ? 0 : (currentPage - 1) * ROWS_PER_PAGE + 1;
    const end   = Math.min(start + transactions.length - 1, totalCount);
    el.textContent = `Showing ${start}–${Math.max(end, 0)} of ${totalCount.toLocaleString()} transaction${totalCount === 1 ? '' : 's'}`;
}

function displayPagination() {
    const pg = document.getElementById('pagination');
    if (!pg) return;

    if (totalPages <= 1) { pg.innerHTML = ''; return; }

    const pages = [];

    // Prev button
    pages.push(`
        <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="goToPage(${currentPage - 1}); return false;">
                <i class="fas fa-chevron-left" style="font-size:11px;"></i>
            </a>
        </li>`);

    // Page number buttons with windowing
    const delta = 2; // pages on each side of current
    const range = [];
    for (let i = Math.max(1, currentPage - delta); i <= Math.min(totalPages, currentPage + delta); i++) {
        range.push(i);
    }

    if (range[0] > 1) {
        pages.push(pageBtn(1));
        if (range[0] > 2) pages.push(`<li class="page-item disabled"><a class="page-link">…</a></li>`);
    }

    range.forEach(i => pages.push(pageBtn(i)));

    if (range[range.length - 1] < totalPages) {
        if (range[range.length - 1] < totalPages - 1)
            pages.push(`<li class="page-item disabled"><a class="page-link">…</a></li>`);
        pages.push(pageBtn(totalPages));
    }

    // Next button
    pages.push(`
        <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="goToPage(${currentPage + 1}); return false;">
                <i class="fas fa-chevron-right" style="font-size:11px;"></i>
            </a>
        </li>`);

    pg.innerHTML = pages.join('');
}

function pageBtn(i) {
    return `
        <li class="page-item">
            <a class="page-link ${i === currentPage ? 'active' : ''}" href="#"
               onclick="goToPage(${i}); return false;">${i}</a>
        </li>`;
}

function goToPage(page) {
    if (page < 1 || page > totalPages || page === currentPage) return;
    loadTransactions(page, { quiet: true });
    // Scroll table into view smoothly
    const section = document.querySelector('.transactions-section');
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ─────────────────────────────────────────────
// FILTERS
// ─────────────────────────────────────────────
function readFilterInputs() {
    return {
        date_from : document.getElementById('dateFrom').value,
        date_to   : document.getElementById('dateTo').value,
        status    : document.getElementById('statusFilter').value
    };
}

// Highlight Apply while the date/status fields differ from what the table shows
function markPendingFilters() {
    const f   = readFilterInputs();
    const btn = document.querySelector('.btn-filter');
    if (!btn) return;
    const pending = f.date_from !== appliedFilters.date_from
                 || f.date_to   !== appliedFilters.date_to
                 || f.status    !== appliedFilters.status;
    btn.classList.toggle('is-pending', pending);
}

function applyFilters() {
    const f = readFilterInputs();

    // A single date is fine (from X onwards / up to Y); a reversed range is not
    if (f.date_from && f.date_to && f.date_from > f.date_to) {
        showToast('warning', 'Check the dates', '"From" must be on or before "To".');
        document.getElementById('dateFrom').focus();
        return;
    }

    appliedFilters = f;
    markPendingFilters();
    currentPage = 1;
    loadTransactions(1);
}

function resetFilters() {
    ['searchInput','dateFrom','dateTo'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('statusFilter').value = 'all';
    appliedFilters = readFilterInputs();
    markPendingFilters();
    currentPage = 1;
    loadTransactions(1);
}
function refreshTransactions() {
    loadTransactions(currentPage);
    showToast('info', 'Refreshing', 'Loading latest transactions...');
}

// ─────────────────────────────────────────────
// VIEW TRANSACTION DETAIL MODAL
// ─────────────────────────────────────────────
function viewTransaction(transactionId) {
    showLoading();

    $.ajax({
        url      : 'ajax/transaction_ajax.php',
        method   : 'POST',
        dataType : 'json',
        data     : { action: 'get_transaction', id: transactionId },
        success  : function (response) {
            hideLoading();

            if (!response.success || !response.data) {
                showToast('error', 'Error', response.message || 'Failed to load transaction');
                return;
            }

            const tx       = response.data;
            currentTransactionId = tx.id;
            const txNumber = 'TRX-' + String(tx.id).padStart(6, '0');
            const pm       = tx.payment_method || 'cash';
            const status   = tx.status || 'completed';
            const total    = parseFloat(tx.total_amount || tx.total || 0);

            // GCash reference is stored in notes as "GCash Ref: 123456"
            const reference = (tx.notes || '').includes('GCash Ref:')
                ? tx.notes.replace('GCash Ref:', '').trim() : '';

            // Header: which sale this is, when and by whom
            document.getElementById('txModalTitle').textContent = txNumber;
            document.getElementById('txModalSub').textContent =
                `${Receipt.formatDate(tx.created_at)} · ${tx.cashier_name || 'Unknown cashier'}`;
            const statusEl = document.getElementById('txModalStatus');
            statusEl.textContent = status.charAt(0).toUpperCase() + status.slice(1);
            statusEl.className = 'tx-status is-' + status;

            document.getElementById('transactionDetails').innerHTML = Receipt.html({
                number  : txNumber,
                date    : tx.created_at,
                cashier : tx.cashier_name || 'Unknown',
                items   : (tx.items || []).map(item => ({
                    name     : item.product_name || item.name || 'Product',
                    price    : parseFloat(item.price) || 0,
                    quantity : parseFloat(item.quantity) || 0,   // decimal kilograms for Per Kilo
                    unit     : item.unit || ''
                })),
                total   : total,
                vatRate : tx.vat_rate,
                payment : parseFloat(tx.amount_paid || (pm === 'gcash' ? total : 0)),
                change  : parseFloat(tx.change_amount || 0),
                method  : pm,
                reference,
                status
            });

            // Void only makes sense for a completed sale
            const voidBtn = document.getElementById('voidBtn');
            if (voidBtn) {
                voidBtn.style.display = (status === 'completed') ? 'inline-flex' : 'none';
            }

            bootstrap.Modal.getOrCreateInstance(document.getElementById('transactionModal')).show();
        },
        error : function () {
            hideLoading();
            showToast('error', 'Connection Error', 'Failed to load transaction details');
        }
    });
}

// ─────────────────────────────────────────────
// VOID — custom confirmation modal
// ─────────────────────────────────────────────

/**
 * Called from the table row void button OR from the detail modal void button.
 * Opens a styled confirmation modal before actually voiding.
 */
function openVoidModal(transactionId) {
    const id = transactionId || currentTransactionId;
    if (!id) return;

    // Store the ID on the confirm button
    document.getElementById('confirmVoidBtn').dataset.txId = id;

    // Show the formatted transaction number in the modal
    const txNumber = 'TRX-' + String(id).padStart(6, '0');
    document.getElementById('voidTxNumber').textContent = txNumber;

    // Close the detail modal first if open, then open void confirm modal
    const detailModal = bootstrap.Modal.getInstance(document.getElementById('transactionModal'));
    if (detailModal) {
        detailModal.hide();
        document.getElementById('transactionModal')
            .addEventListener('hidden.bs.modal', function handler() {
                this.removeEventListener('hidden.bs.modal', handler);
                new bootstrap.Modal(document.getElementById('voidConfirmModal')).show();
            });
    } else {
        new bootstrap.Modal(document.getElementById('voidConfirmModal')).show();
    }
}

/**
 * Called from the detail modal footer void button — routes through openVoidModal.
 */
function voidTransaction(transactionId) {
    openVoidModal(transactionId || currentTransactionId);
}

/**
 * The actual API call — only runs after user clicks "Yes, Void" in the confirm modal.
 */
function executeVoid(transactionId) {
    showLoading();

    $.ajax({
        url      : 'ajax/transaction_ajax.php',
        method   : 'POST',
        dataType : 'json',
        data     : { action: 'void_transaction', id: transactionId },
        success  : function (response) {
            hideLoading();

            // Close confirm modal
            const confirmModal = bootstrap.Modal.getInstance(document.getElementById('voidConfirmModal'));
            if (confirmModal) confirmModal.hide();

            if (response.success) {
                showToast('success', 'Transaction Voided',
                    'Stock has been restored for all voided items.');
                loadTransactions(currentPage);
            } else {
                showToast('error', 'Void Failed', response.message || 'Could not void transaction');
            }
        },
        error : function () {
            hideLoading();
            showToast('error', 'Connection Error', 'Failed to reach server');
        }
    });
}

// ─────────────────────────────────────────────
// PRINT / EXPORT
// ─────────────────────────────────────────────
function printTransaction() {
    Receipt.print(document.getElementById('transactionDetails')?.innerHTML);
}

function exportTransactions() {
    showLoading();

    $.ajax({
        url      : 'ajax/transaction_ajax.php',
        method   : 'POST',
        dataType : 'json',
        data     : { action: 'get_transactions', limit: 10000 },
        success  : function (response) {
            hideLoading();
            if (!response.success || !response.data) {
                showToast('error', 'Error', 'Failed to export transactions');
                return;
            }

            let csv = 'Transaction ID,Date,Items,Total,Payment Method,Amount Paid,Change,Status,Cashier,Reference\n';
            response.data.forEach(t => {
                const ref = (t.notes || '').includes('GCash Ref:')
                    ? t.notes.replace('GCash Ref:', '').trim() : '';
                csv += [
                    '"TRX-' + String(t.id).padStart(6, '0') + '"',
                    '"' + new Date(t.created_at).toLocaleString() + '"',
                    t.item_count || 0,
                    parseFloat(t.total_amount || 0).toFixed(2),
                    '"' + (t.payment_method || 'cash') + '"',
                    parseFloat(t.amount_paid || 0).toFixed(2),
                    parseFloat(t.change_amount || 0).toFixed(2),
                    t.status || 'completed',
                    t.cashier_name || 'Unknown',
                    '"' + ref + '"'
                ].join(',') + '\n';
            });

            const a    = document.createElement('a');
            a.href     = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
            a.download = 'transactions_' + new Date().toISOString().slice(0, 10) + '.csv';
            a.click();
            URL.revokeObjectURL(a.href);
            showToast('success', 'Exported', 'Transactions exported successfully');
        },
        error : function () {
            hideLoading();
            showToast('error', 'Connection Error', 'Failed to export');
        }
    });
}

// ─────────────────────────────────────────────
// SIDEBAR
// ─────────────────────────────────────────────
const sidebar     = document.getElementById('sidebar');
const collapseBtn = document.getElementById('collapseBtn');
const collapseIcon = document.getElementById('collapseIcon');

if (collapseBtn) {
    collapseBtn.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
        collapseIcon.classList.toggle('fa-chevron-left');
        collapseIcon.classList.toggle('fa-chevron-right');
    });
}

// ─────────────────────────────────────────────
// INIT
// ─────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    // Recommendation button functionality
    const recommendationBtn = document.getElementById('recommendationBtn');
    if (recommendationBtn) {
        recommendationBtn.addEventListener('click', function() {
            window.location.href = 'reco.php';
        });
    }
        // Logout button
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function (e) {
            e.preventDefault();
            showToast('info', 'Logging Out', 'See you next time!');
            setTimeout(() => window.location.replace('logout.php'), 500);
        });
    }

    // Wire up the "Yes, Void" confirm button
    const confirmVoidBtn = document.getElementById('confirmVoidBtn');
    if (confirmVoidBtn) {
        confirmVoidBtn.addEventListener('click', function () {
            const txId = parseInt(this.dataset.txId);
            if (txId) executeVoid(txId);
        });
    }

    // Show server-side toast if any (passed from PHP via INITIAL_TOAST)
    if (typeof INITIAL_TOAST !== 'undefined' && INITIAL_TOAST) {
        showToast(INITIAL_TOAST.type, INITIAL_TOAST.title, INITIAL_TOAST.message);
    }

    // Search as you type; dates and status wait for Apply
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        let searchTimer = null;
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => loadTransactions(1, { quiet: true }), 250);
        });
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(searchTimer);
                loadTransactions(1, { quiet: true });
            } else if (e.key === 'Escape' && this.value) {
                this.value = '';
                clearTimeout(searchTimer);
                loadTransactions(1, { quiet: true });
            }
        });
    }

    ['dateFrom', 'dateTo', 'statusFilter'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', markPendingFilters);
        el.addEventListener('input', markPendingFilters);
        // Enter in a date field applies, like pressing the button
        el.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); applyFilters(); } });
    });

    // Load transactions — jQuery is guaranteed available here
    loadTransactions(1);
});