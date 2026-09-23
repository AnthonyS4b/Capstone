/**
 * switch-account.js — steps of the shared Switch account modal
 * (includes/switch_account_modal.php). Submitting the PIN is handled by the
 * page script (dashboardJS.js / emp.js), which listens on #switchAccountForm.
 */
(function () {
    'use strict';

    const modal = document.getElementById('switchAccountModal');
    if (!modal) return;

    const list   = document.getElementById('accountList');
    const step2  = document.getElementById('quickLoginForm');
    const idIn   = document.getElementById('selectedUserId');
    const nameEl = document.getElementById('selectedAccountName');
    const avatar = document.getElementById('swChosenAvatar');
    const pin    = document.getElementById('accountPin');

    function showStep2(btn) {
        idIn.value = btn.dataset.userId;
        nameEl.textContent = btn.dataset.userName;
        const face = btn.querySelector('.sw-avatar');
        avatar.innerHTML = face ? face.outerHTML : '';
        list.style.display = 'none';
        step2.style.display = 'block';
        if (typeof switchAccountClearError === 'function') switchAccountClearError();
        pin.value = '';
        setTimeout(() => pin.focus(), 50);
    }

    list?.addEventListener('click', e => {
        const btn = e.target.closest('.sw-account');
        if (btn) showStep2(btn);
    });

    // Digits only in the PIN
    pin?.addEventListener('input', () => {
        const digits = pin.value.replace(/\D/g, '').slice(0, 4);
        if (digits !== pin.value) pin.value = digits;
    });

    // Every time the modal closes, start again from the list
    modal.addEventListener('hidden.bs.modal', () => {
        if (typeof cancelAccountSelection === 'function') cancelAccountSelection();
        else { list.style.display = 'block'; step2.style.display = 'none'; }
    });
})();
