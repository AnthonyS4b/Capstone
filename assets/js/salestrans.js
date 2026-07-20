// salestrans.js — Sales Transaction History
// Void transaction restores stock via transaction_ajax.php void_transaction case

const ROWS_PER_PAGE      = 15;
let currentPage          = 1;
let totalPages           = 1;
let currentTransactionId = null;
let transactions         = [];   // full list from server

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
function loadTransactions(page = 1) {
    showLoading();

    $.ajax({
        url      : 'ajax/transaction_ajax.php',
        method   : 'POST',
        dataType : 'json',
        data     : {
            action    : 'get_transactions',
            page      : page,
            limit     : 100,
            search    : document.getElementById('searchInput').value,
            date_from : document.getElementById('dateFrom').value,
            date_to   : document.getElementById('dateTo').value,
            status    : document.getElementById('statusFilter').value
        },
        success  : function (response) {
            hideLoading();
            if (response.success) {
                transactions = response.data || [];
                totalPages   = Math.max(1, Math.ceil(transactions.length / ROWS_PER_PAGE));
                currentPage  = Math.min(page, totalPages);
                renderPage(currentPage);
                loadStats();
            } else {
                showToast('error', 'Error', response.message || 'Failed to load transactions');
            }
        },
        error    : function (xhr, status, error) {
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
                                <pre style="white-space:pre-wrap;word-break:break-all;">${xhr.responseText || '(empty — check error.log)'}</pre>
                                <strong>HTTP status:</strong> ${xhr.status}<br>
                                <strong>Error:</strong> ${error}
                            </div>
                        </td>
                    </tr>`;
            }
        }
    });
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
                document.getElementById('totalTransactions').textContent = s.total_transactions || 0;
                document.getElementById('totalSales').textContent        = '₱' + parseFloat(s.total_sales || 0).toFixed(2);
                document.getElementById('todaySales').textContent        = '₱' + parseFloat(s.today_sales || 0).toFixed(2);
                document.getElementById('avgTransaction').textContent    = '₱' + parseFloat(s.avg_transaction || 0).toFixed(2);
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
                        <p class="text-muted">Try adjusting your filters</p>
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
        const pmIcon        = pm === 'gcash' ? '📱' : '💵';
        const pmLabel       = pm === 'gcash' ? 'GCash' : 'Cash';
        const status        = t.status || 'completed';

        let refHtml = '';
        if (t.notes && t.notes.includes('GCash Ref:')) {
            const ref = t.notes.replace('GCash Ref:', '').trim();
            refHtml = `<small class="text-muted d-block">Ref: ${ref}</small>`;
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
                    <span class="payment-method payment-${pm}">${pmIcon} ${pmLabel}</span>
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

// ─────────────────────────────────────────────
// CLIENT-SIDE PAGINATION
// ─────────────────────────────────────────────
function renderPage(page) {
    currentPage = Math.max(1, Math.min(page, totalPages));
    const start = (currentPage - 1) * ROWS_PER_PAGE;
    const slice = transactions.slice(start, start + ROWS_PER_PAGE);
    displayTransactionRows(slice);
    displayPagination();
    updatePageInfo();
}

function updatePageInfo() {
    const el = document.getElementById('pageInfo');
    if (!el) return;
    const total = transactions.length;
    const start = total === 0 ? 0 : (currentPage - 1) * ROWS_PER_PAGE + 1;
    const end   = Math.min(currentPage * ROWS_PER_PAGE, total);
    el.textContent = `Showing ${start}–${end} of ${total} transactions`;
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
    if (page < 1 || page > totalPages) return;
    renderPage(page);
    // Scroll table into view smoothly
    const section = document.querySelector('.transactions-section');
    if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ─────────────────────────────────────────────
// FILTERS
// ─────────────────────────────────────────────
function applyFilters()  { currentPage = 1; loadTransactions(1); }
function resetFilters()  {
    ['searchInput','dateFrom','dateTo'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('statusFilter').value = 'all';
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
            const date     = new Date(tx.created_at).toLocaleString();
            const txNumber = 'TRX-' + String(tx.id).padStart(6, '0');
            const pm       = tx.payment_method || 'cash';
            const pmIcon   = pm === 'gcash' ? '📱' : '💵';
            const pmLabel  = pm === 'gcash' ? 'GCash' : 'Cash';
            const status   = tx.status || 'completed';

            // Items
            let itemsHtml = '';
            if (tx.items && tx.items.length > 0) {
                itemsHtml = tx.items.map(item => {
                    const name     = item.product_name || item.name || 'Product';
                    const lineTotal = (parseFloat(item.price) * parseInt(item.quantity)).toFixed(2);
                    return `
                        <div class="receipt-row">
                            <span>${name} x${item.quantity}</span>
                            <span>₱${lineTotal}</span>
                        </div>`;
                }).join('');
            } else {
                itemsHtml = '<div class="receipt-row text-muted"><span>No items found</span></div>';
            }

            // GCash reference
            let refHtml = '';
            if (tx.notes && tx.notes.includes('GCash Ref:')) {
                const ref = tx.notes.replace('GCash Ref:', '').trim();
                refHtml = `<div class="receipt-row"><span>GCash Ref:</span><span>${ref}</span></div>`;
            }

            document.getElementById('transactionDetails').innerHTML = `
                <div class="receipt-header">
                    <h4>Espenida's Pet &amp; Poultry</h4>
                    <p>${date}</p>
                    <p>Transaction #: ${txNumber}</p>
                    <p>Cashier: ${tx.cashier_name || 'Unknown'}</p>
                </div>
                <div class="receipt-items">
                    <div class="receipt-row header"><span>Item</span><span>Amount</span></div>
                    ${itemsHtml}
                </div>
                <div class="receipt-total">
                    <div class="receipt-row">
                        <span>Total:</span>
                        <span class="amount-positive">₱${parseFloat(tx.total_amount || tx.total || 0).toFixed(2)}</span>
                    </div>
                    <div class="receipt-row">
                        <span>Payment Method:</span>
                        <span class="payment-method payment-${pm}">${pmIcon} ${pmLabel}</span>
                    </div>
                    ${refHtml}
                    <div class="receipt-row">
                        <span>Amount Paid:</span>
                        <span>₱${parseFloat(tx.amount_paid || 0).toFixed(2)}</span>
                    </div>
                    <div class="receipt-row">
                        <span>Change:</span>
                        <span>₱${parseFloat(tx.change_amount || 0).toFixed(2)}</span>
                    </div>
                    <div class="receipt-row">
                        <span>Status:</span>
                        <span class="status-badge status-${status}">${status}</span>
                    </div>
                </div>
                <div class="receipt-footer">
                    <p>Thank you for your purchase!</p>
                    <p>This serves as your official receipt</p>
                </div>`;

            // Show void button only if still completeed
            const voidBtn = document.getElementById('voidBtn');
            if (voidBtn) {
                voidBtn.style.display = (status === 'completed') ? 'inline-block' : 'none';
            }

            new bootstrap.Modal(document.getElementById('transactionModal')).show();
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
    const content = document.getElementById('transactionDetails')?.innerHTML;
    if (!content) return;

    const win = window.open('', '_blank');
    win.document.write(`
        <html>
            <head>
                <title>Receipt – Espenida's Pet &amp; Poultry</title>
                <style>
                    body { font-family:'Courier New',monospace; padding:20px; max-width:300px; margin:0 auto; }
                    .receipt-header { text-align:center; margin-bottom:20px; }
                    .receipt-items  { border-top:1px dashed #000; border-bottom:1px dashed #000; padding:10px 0; margin:10px 0; }
                    .receipt-row    { display:flex; justify-content:space-between; padding:3px 0; }
                    .receipt-row.header { font-weight:bold; border-bottom:1px solid #000; margin-bottom:5px; }
                    .receipt-total  { margin-top:10px; }
                    .amount-positive{ font-weight:bold; }
                    .receipt-footer { text-align:center; margin-top:20px; font-size:12px; }
                    .status-badge   { padding:2px 6px; border-radius:3px; display:inline-block; }
                    .status-completed { background:#d1fae5; color:#065f46; }
                    .status-voided  { background:#fee2e2; color:#991b1b; }
                    .payment-method { display:inline-block; padding:2px 6px; border-radius:3px; }
                    .payment-cash   { background:#fef3c7; color:#92400e; }
                    .payment-gcash  { background:#dbeafe; color:#1e40af; }
                </style>
            </head>
            <body><div class="receipt">${content}</div>
            <script>window.onload=function(){window.print();}<\/script>
            </body>
        </html>`);
    win.document.close();
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

    // Load transactions — jQuery is guaranteed available here
    loadTransactions(1);
});