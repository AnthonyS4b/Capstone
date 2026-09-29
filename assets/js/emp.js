document.addEventListener('DOMContentLoaded', function () {
        if (phpToastMessage) showToast(phpToastMessage.type, phpToastMessage.title, phpToastMessage.message);

        // Sidebar collapse
        const sidebar     = document.getElementById('sidebar');
        const collapseBtn = document.getElementById('collapseBtn');
        const collapseIcon= document.getElementById('collapseIcon');
        if (collapseBtn) {
            collapseBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                collapseIcon.classList.toggle('fa-chevron-left');
                collapseIcon.classList.toggle('fa-chevron-right');
            });
        }

        // Logout
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function (e) {
                e.preventDefault();
                showToast('info', 'Logging Out', 'See you next time!');
                setTimeout(() => window.location.replace('logout.php'), 500);
            });
        }

        // Switch account modal reset on close
        const switchModal = document.getElementById('switchAccountModal');
        if (switchModal) {
            switchModal.addEventListener('hidden.bs.modal', cancelAccountSelection);
        }

        // Switch account form PIN validation

        // Reset receipt modal on close
        const receiptModal = document.getElementById('receiptModal');
        if (receiptModal) {
            receiptModal.addEventListener('hidden.bs.modal', resetReceiptModal);
        }
    });

    // Toast 
    function showToast(type, title, message) {
        const container = document.getElementById('toastContainer');
        const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
        const id   = 'toast-' + Date.now();
        const icon = icons[type] || 'fa-bell';
        const bg   = type === 'error' ? 'bg-danger' : 'bg-' + type;
        container.insertAdjacentHTML('beforeend', `
            <div id="${id}" class="toast align-items-center text-white ${bg} border-0 mb-2" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body"><i class="fas ${icon} me-2"></i><strong>${title}</strong> ${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
                <div class="toast-timer"></div>
            </div>`);
        const el = document.getElementById(id);
        new bootstrap.Toast(el, { autohide: true, delay: 3000 }).show();
        el.addEventListener('hidden.bs.toast', () => el.remove());
    }

    // Switch account helpers 
    function selectAccount(userId, userName, userRole, currentName, currentRole) {
        const confirmBox = document.getElementById('switchConfirmStep');
        const confirmMsg = document.getElementById('switchConfirmMsg');

        confirmMsg.innerHTML =
            'Would you like to switch from <strong>' + escHtml(currentName + ' (' + currentRole + ')') + '</strong>'
            + ' to <strong>' + escHtml(userName + ' (' + userRole + ')') + '</strong>?';

        // Store target info for when user confirms
        confirmBox.dataset.targetId   = userId;
        confirmBox.dataset.targetName = userName;
        confirmBox.dataset.targetRole = userRole;

        document.getElementById('accountList').style.display  = 'none';
        confirmBox.style.display = 'block';
    }

    function confirmAccountSwitch() {
        const confirmBox = document.getElementById('switchConfirmStep');
        const userId   = confirmBox.dataset.targetId;
        const userName = confirmBox.dataset.targetName;
        const userRole = confirmBox.dataset.targetRole;

        confirmBox.style.display = 'none';

        document.getElementById('selectedUserId').value            = userId;
        document.getElementById('selectedAccountName').textContent = userName + ' (' + userRole + ')';
        document.getElementById('quickLoginForm').style.display    = 'block';
    }

    function cancelConfirmSwitch() {
        document.getElementById('switchConfirmStep').style.display = 'none';
        document.getElementById('accountList').style.display       = 'block';
    }

    function cancelAccountSelection() {
        const userId      = document.getElementById('selectedUserId');
        const pin         = document.getElementById('accountPin');
        const list        = document.getElementById('accountList');
        const form        = document.getElementById('quickLoginForm');
        const confirmBox  = document.getElementById('switchConfirmStep');
        if (userId)     userId.value = '';
        if (pin)        pin.value    = '';
        if (list)       list.style.display       = 'block';
        if (form)       form.style.display       = 'none';
        if (confirmBox) confirmBox.style.display = 'none';
        if (typeof switchAccountClearError === 'function') switchAccountClearError();
    }

    // ── Change own PIN ─────────────────────────────────────────────────────────
    function changeOwnPin() {
        const current = document.getElementById('currentPin')?.value?.trim();
        const newPin  = document.getElementById('newPinSelf')?.value?.trim();
        const confirm = document.getElementById('confirmPinSelf')?.value?.trim();

        if (!current || !newPin || !confirm) {
            showToast('warning', 'Missing Fields', 'Please fill in all three PIN fields.'); return;
        }
        if (!/^\d{4}$/.test(current)) {
            showToast('warning', 'Invalid PIN', 'Current PIN must be exactly 4 digits.'); return;
        }
        if (!/^\d{4}$/.test(newPin)) {
            showToast('warning', 'Invalid PIN', 'New PIN must be exactly 4 digits.'); return;
        }
        if (newPin !== confirm) {
            showToast('warning', 'PIN Mismatch', 'New PIN and confirmation do not match.'); return;
        }
        if (current === newPin) {
            showToast('warning', 'No Change', 'New PIN must be different from your current PIN.'); return;
        }

        fetch('ajax/user_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'change_own_pin', current_pin: current, new_pin: newPin })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('success', 'PIN Changed', 'Your PIN has been updated successfully.');
                document.getElementById('currentPin').value     = '';
                document.getElementById('newPinSelf').value     = '';
                document.getElementById('confirmPinSelf').value = '';
                const el = document.getElementById('changePinCollapse');
                if (el) bootstrap.Collapse.getInstance(el)?.hide();
            } else {
                showToast('error', 'Error', data.message);
            }
        })
        .catch(() => showToast('error', 'Error', 'Failed to connect to server.'));
    }

    // ── Receipt modal ──────────────────────────────────────────────────────────
    function viewReceipt(rawId, trxId) {
        // Show modal in loading state
        resetReceiptModal();
        document.getElementById('receiptTrxId').textContent = trxId;
        const modal = new bootstrap.Modal(document.getElementById('receiptModal'));
        modal.show();

        fetch('ajax/transaction_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'get_transaction', id: rawId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data) {
                renderReceipt(data.data, trxId);
            } else {
                showReceiptError(data.message || 'Failed to load receipt.');
            }
        })
        .catch(() => showReceiptError('Network error — could not load receipt.'));
    }

    let lastReceipt = null;   // the sale shown in the modal, for printing

    function renderReceipt(tx, trxId) {
        lastReceipt = { tx, trxId };
        // ── Meta info ──
        const dateStr = new Date(tx.created_at).toLocaleString('en-PH', {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });

        const paymentIcon = tx.payment_method === 'gcash' ? 'fa-mobile-alt' : 'fa-money-bill-wave';
        const paymentLabel = tx.payment_method === 'gcash' ? 'GCash' : 'Cash';

        let metaHTML = `
            <div class="receipt-meta-row"><span class="receipt-meta-label">Date &amp; Time</span><span class="receipt-meta-value">${dateStr}</span></div>
            <div class="receipt-meta-row"><span class="receipt-meta-label">Cashier</span><span class="receipt-meta-value">${escHtml(tx.cashier_name || '—')}</span></div>
            <div class="receipt-meta-row"><span class="receipt-meta-label">Payment</span><span class="receipt-meta-value"><i class="fas ${paymentIcon} me-1"></i>${paymentLabel}</span></div>
            <div class="receipt-meta-row"><span class="receipt-meta-label">Status</span><span class="receipt-meta-value">${statusBadge(tx.status)}</span></div>`;

        if (tx.payment_method === 'gcash' && tx.gcash_reference) {
            metaHTML += `<div class="receipt-meta-row"><span class="receipt-meta-label">GCash Ref #</span><span class="receipt-meta-value" style="font-family:monospace;">${escHtml(tx.gcash_reference)}</span></div>`;
        }

        document.getElementById('receiptMeta').innerHTML = metaHTML;

        // ── Items ──
        let itemsHTML = '';
        const items = Array.isArray(tx.items) ? tx.items : [];
        if (items.length === 0) {
            itemsHTML = '<div style="color:#94a3b8;font-size:13px;padding:8px 0;">No item details available.</div>';
        } else {
            items.forEach(item => {
                const name     = escHtml(item.product_name || item.name || 'Unknown Product');
                // Per Kilo lines are decimal kilograms (2.5 kg); others whole units
                const perKilo  = String(item.unit || '').trim().toLowerCase() === 'per kilo';
                const qty      = Math.round((parseFloat(item.quantity) || 0) * 100) / 100;
                const price    = parseFloat(item.price) || 0;
                const subtotal = Math.round(Math.round(qty * 100) * Math.round(price * 100) / 100) / 100;
                itemsHTML += `
                    <div class="receipt-item-row">
                        <span class="receipt-item-name">${name}</span>
                        <span class="receipt-item-qty">${perKilo ? `${qty} kg` : `×${qty}`}</span>
                        <span class="receipt-item-price">₱${fmtMoney(subtotal)}</span>
                    </div>`;
            });
        }
        document.getElementById('receiptItems').innerHTML = itemsHTML;

        // ── Totals ──
        const total   = parseFloat(tx.total_amount)  || 0;
        const paid    = parseFloat(tx.amount_paid)   || 0;
        const change  = parseFloat(tx.change_amount) || 0;

        let totalsHTML = `
            <div class="receipt-total-row"><span>Subtotal</span><span>₱${fmtMoney(total)}</span></div>
            <div class="receipt-total-row grand"><span>Total</span><span class="receipt-grand-value">₱${fmtMoney(total)}</span></div>`;

        if (paid > 0) {
            totalsHTML += `
                <div class="receipt-total-row" style="margin-top:8px;padding-top:6px;border-top:1px dashed #e2e8f0;">
                    <span>Amount Paid</span><span>₱${fmtMoney(paid)}</span>
                </div>
                <div class="receipt-total-row"><span>Change</span><span>₱${fmtMoney(change)}</span></div>`;
        }

        document.getElementById('receiptTotals').innerHTML = totalsHTML;

        // ── Notes ──
        if (tx.notes && tx.notes.trim()) {
            document.getElementById('receiptNotesText').textContent = tx.notes;
            document.getElementById('receiptNotes').style.display = 'block';
        }

        // Show content, hide loader, show footer
        document.getElementById('receiptLoading').style.display  = 'none';
        document.getElementById('receiptContent').style.display  = 'block';
        document.getElementById('receiptFooter').style.display   = 'flex';
    }

    function showReceiptError(msg) {
        document.getElementById('receiptLoading').style.display = 'none';
        document.getElementById('receiptErrorMsg').textContent  = msg;
        document.getElementById('receiptError').style.display   = 'block';
    }

    function resetReceiptModal() {
        document.getElementById('receiptTrxId').textContent    = '—';
        document.getElementById('receiptLoading').style.display = 'flex';
        document.getElementById('receiptContent').style.display = 'none';
        document.getElementById('receiptError').style.display   = 'none';
        document.getElementById('receiptFooter').style.display  = 'none';
        document.getElementById('receiptMeta').innerHTML        = '';
        document.getElementById('receiptItems').innerHTML       = '';
        document.getElementById('receiptTotals').innerHTML      = '';
        document.getElementById('receiptNotes').style.display   = 'none';
        document.getElementById('receiptNotesText').textContent = '';
    }

    // Print only the receipt, in the thermal-roll layout shared with the POS
    // (window.print() here used to print the whole dashboard page)
    function printReceipt() {
        if (!lastReceipt || typeof Receipt === 'undefined') return;
        const { tx, trxId } = lastReceipt;
        const ref = tx.gcash_reference
            || ((tx.notes || '').includes('GCash Ref:') ? tx.notes.replace('GCash Ref:', '').trim() : '');
        Receipt.print(Receipt.html({
            number:    String(trxId || '').replace(/^#/, ''),
            date:      tx.created_at,
            cashier:   tx.cashier_name,
            items:     (Array.isArray(tx.items) ? tx.items : []).map(i => ({
                           name: i.product_name || i.name || 'Item',
                           price: i.price,
                           quantity: i.quantity
                       })),
            total:     tx.total_amount,
            payment:   tx.amount_paid,
            change:    tx.change_amount,
            method:    tx.payment_method,
            reference: ref,
            status:    tx.status
        }));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function fmtMoney(n) {
        return parseFloat(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function statusBadge(status) {
        const map = {
            completed : '<span style="color:#16a34a;font-weight:600;"><i class="fas fa-check-circle me-1"></i>Completed</span>',
            voided    : '<span style="color:#dc2626;font-weight:600;"><i class="fas fa-ban me-1"></i>Voided</span>',
            pending   : '<span style="color:#d97706;font-weight:600;"><i class="fas fa-clock me-1"></i>Pending</span>',
        };
        return map[status] || `<span>${escHtml(status)}</span>`;
    }

// -- Switch account: submit over fetch so a wrong PIN keeps the modal open ----
function switchAccountShowError(message) {
    const box = document.getElementById('switchAccountError');
    if (box) {
        box.textContent = message;
        box.style.display = 'block';
    }
    const pin = document.getElementById('accountPin');
    if (pin) {
        pin.value = '';
        pin.classList.add('is-invalid');
        pin.focus();
    }
}

function switchAccountClearError() {
    const box = document.getElementById('switchAccountError');
    if (box) {
        box.textContent = '';
        box.style.display = 'none';
    }
    const pin = document.getElementById('accountPin');
    if (pin) pin.classList.remove('is-invalid');
}

document.addEventListener('DOMContentLoaded', function () {
    const switchForm = document.getElementById('switchAccountForm');
    if (!switchForm) return;

    const pinInput = document.getElementById('accountPin');
    if (pinInput) {
        pinInput.addEventListener('input', switchAccountClearError);
    }

    switchForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const pin = pinInput ? pinInput.value.trim() : '';
        if (!/^\d{4}$/.test(pin)) {
            switchAccountShowError('Please enter a valid 4-digit PIN.');
            return;
        }

        const submitBtn = switchForm.querySelector('button[type="submit"]');
        const original  = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled  = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Switching...';
        }
        switchAccountClearError();

        fetch(switchForm.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(switchForm),
            credentials: 'same-origin'
        })
        .then(function (res) {
            return res.json().catch(function () {
                throw new Error('The server returned an unexpected response.');
            });
        })
        .then(function (data) {
            if (data && data.success) {
                // Only a real switch leaves the page.
                window.location.href = data.redirect || 'dashboard.php';
                return;
            }
            if (submitBtn) {
                submitBtn.disabled  = false;
                submitBtn.innerHTML = original;
            }
            switchAccountShowError((data && data.message) || 'Could not switch account.');
        })
        .catch(function (err) {
            if (submitBtn) {
                submitBtn.disabled  = false;
                submitBtn.innerHTML = original;
            }
            switchAccountShowError(err.message || 'Network error. Please try again.');
        });
    });
});
