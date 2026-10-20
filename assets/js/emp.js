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

    // ── Receipt modal (uses Receipt.html — same renderer as admin) ─────────
    let lastReceiptHtml = null;   // for printing

    function viewReceipt(rawId, trxId) {
        resetReceiptModal();
        document.getElementById('empTxModalTitle').textContent = trxId;
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

    function renderReceipt(tx, trxId) {
        const txNumber = trxId || ('TRX-' + String(tx.id).padStart(6, '0'));
        const pm       = tx.payment_method || 'cash';
        const status   = tx.status || 'completed';
        const total    = parseFloat(tx.total_amount || tx.total || 0);

        // GCash reference stored in notes as "GCash Ref: 123456"
        const reference = tx.gcash_reference
            || ((tx.notes || '').includes('GCash Ref:') ? tx.notes.replace('GCash Ref:', '').trim() : '');

        // ── Header ──
        document.getElementById('empTxModalTitle').textContent = txNumber;
        document.getElementById('empTxModalSub').textContent =
            (typeof Receipt !== 'undefined' && Receipt.formatDate
                ? Receipt.formatDate(tx.created_at)
                : new Date(tx.created_at).toLocaleString('en-PH'))
            + ' · ' + (tx.cashier_name || 'Unknown cashier');

        const statusEl = document.getElementById('empTxModalStatus');
        statusEl.textContent = status.charAt(0).toUpperCase() + status.slice(1);
        statusEl.className = 'tx-status is-' + status;

        // ── Body: use Receipt.html() — the same renderer the admin page uses ──
        const receiptHtml = Receipt.html({
            number  : txNumber,
            date    : tx.created_at,
            cashier : tx.cashier_name || 'Unknown',
            items   : (tx.items || []).map(item => ({
                name     : item.product_name || item.name || 'Product',
                price    : parseFloat(item.price) || 0,
                quantity : parseFloat(item.quantity) || 0,
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

        lastReceiptHtml = receiptHtml;

        const contentEl = document.getElementById('receiptContent');
        contentEl.innerHTML = receiptHtml;

        // Show content, hide loader, show footer
        document.getElementById('receiptLoading').style.display = 'none';
        contentEl.style.display = 'block';
        document.getElementById('receiptFooter').style.display  = 'flex';
    }

    function showReceiptError(msg) {
        document.getElementById('receiptLoading').style.display = 'none';
        document.getElementById('receiptErrorMsg').textContent  = msg;
        document.getElementById('receiptError').style.display   = 'block';
    }

    function resetReceiptModal() {
        document.getElementById('empTxModalTitle').textContent   = 'Transaction';
        document.getElementById('empTxModalSub').textContent     = '';
        document.getElementById('empTxModalStatus').textContent  = '';
        document.getElementById('empTxModalStatus').className    = 'tx-status';
        document.getElementById('receiptLoading').style.display  = 'flex';
        document.getElementById('receiptContent').style.display  = 'none';
        document.getElementById('receiptContent').innerHTML      = '';
        document.getElementById('receiptError').style.display    = 'none';
        document.getElementById('receiptFooter').style.display   = 'none';
        lastReceiptHtml = null;
    }

    function printReceipt() {
        if (!lastReceiptHtml || typeof Receipt === 'undefined') return;
        Receipt.print(lastReceiptHtml);
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
