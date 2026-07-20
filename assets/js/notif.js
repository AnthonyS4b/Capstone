/**
 * notif.js — Notification Bell Logic
 * Espenida's Pet & Poultry Supply Dashboard
 *
 * Expects `phpNotifications` to be declared in the page's inline <script>
 * before this file is loaded.
 */

(function () {
    'use strict';

    // ── State ────────────────────────────────────────────────────────────────
    let notifications = [];
    const STORAGE_KEY = 'espenida_notif_read_' + (window.currentUser?.id || 'guest');

    // ── DOM refs ─────────────────────────────────────────────────────────────
    const bellBtn   = document.getElementById('notifBellBtn');
    const panel     = document.getElementById('notifPanel');
    const badge     = document.getElementById('notifBadge');
    const list      = document.getElementById('notifList');
    const emptyEl   = document.getElementById('notifEmpty');
    const markAllBtn= document.getElementById('notifMarkAll');
    const footer    = document.getElementById('notifFooter');
    const footerTxt = document.getElementById('notifFooterText');

    if (!bellBtn || !panel) return; // Guard: elements must exist

    // ── Init ─────────────────────────────────────────────────────────────────
    function init() {
        // Merge PHP-seeded notifications with any stored ones
        const readIds = getReadIds();

        if (typeof phpNotifications !== 'undefined' && Array.isArray(phpNotifications)) {
            notifications = phpNotifications.map(n => ({
                ...n,
                read: readIds.has(n.id) ? true : n.read,
            }));
        }

        renderList();
        updateBadge();

        // Shake the bell if there are unread items
        if (countUnread() > 0) {
            bellBtn.classList.add('has-notif');
        }
    }

    // ── Render list ──────────────────────────────────────────────────────────
    function renderList() {
        // Remove existing items (keep the empty state element)
        list.querySelectorAll('.notif-item').forEach(el => el.remove());

        if (notifications.length === 0) {
            emptyEl.style.display = 'flex';
            footer.style.display  = 'none';
            return;
        }

        emptyEl.style.display = 'none';

        notifications.forEach(n => {
            const item = document.createElement('div');
            item.className = 'notif-item' + (n.read ? '' : ' unread');
            item.dataset.id = n.id;
            item.innerHTML = `
                <div class="notif-icon type-${n.type}">
                    <i class="fas ${n.icon}"></i>
                </div>
                <div class="notif-text">
                    <div class="notif-title">${escHtml(n.title)}</div>
                    <div class="notif-body">${escHtml(n.body)}</div>
                    <div class="notif-time"><i class="fas fa-clock me-1"></i>${escHtml(n.time)}</div>
                </div>`;

            item.addEventListener('click', () => markRead(n.id));
            list.appendChild(item);
        });

        const unread = countUnread();
        if (unread > 0) {
            footer.style.display  = 'block';
            footerTxt.textContent = unread + ' unread notification' + (unread !== 1 ? 's' : '');
        } else {
            footer.style.display  = 'none';
        }
    }

    // ── Mark single read ─────────────────────────────────────────────────────
    function markRead(id) {
        const n = notifications.find(x => x.id === id);
        if (!n || n.read) return;
        n.read = true;
        saveReadIds();
        // Update DOM
        const el = list.querySelector(`.notif-item[data-id="${id}"]`);
        if (el) el.classList.remove('unread');
        updateBadge();
        renderFooter();
    }

    // ── Mark all read ─────────────────────────────────────────────────────────
    function markAllRead() {
        notifications.forEach(n => { n.read = true; });
        saveReadIds();
        list.querySelectorAll('.notif-item').forEach(el => el.classList.remove('unread'));
        updateBadge();
        renderFooter();
        bellBtn.classList.remove('has-notif');
    }

    // ── Badge ─────────────────────────────────────────────────────────────────
    function updateBadge() {
        const count = countUnread();
        if (count > 0) {
            badge.textContent    = count > 9 ? '9+' : count;
            badge.style.display  = 'flex';
        } else {
            badge.style.display  = 'none';
        }
    }

    function renderFooter() {
        const unread = countUnread();
        if (unread > 0) {
            footer.style.display  = 'block';
            footerTxt.textContent = unread + ' unread notification' + (unread !== 1 ? 's' : '');
        } else {
            footer.style.display  = 'none';
        }
    }

    function countUnread() {
        return notifications.filter(n => !n.read).length;
    }

    // ── Persistence (localStorage so it persists across tabs) ───────────────────
    function getReadIds() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            return new Set(raw ? JSON.parse(raw) : []);
        } catch { return new Set(); }
    }

    function saveReadIds() {
        try {
            const ids = notifications.filter(n => n.read).map(n => n.id);
            localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
        } catch { /* ignore */ }
    }

    // ── Toggle panel ─────────────────────────────────────────────────────────
    function openPanel() {
        panel.classList.add('open');
        bellBtn.setAttribute('aria-expanded', 'true');
    }

    function closePanel() {
        panel.classList.remove('open');
        bellBtn.setAttribute('aria-expanded', 'false');
    }

    // ── Events ───────────────────────────────────────────────────────────────
    bellBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (panel.classList.contains('open')) {
            closePanel();
        } else {
            openPanel();
        }
    });

    // Close when clicking outside
    document.addEventListener('click', function (e) {
        if (!panel.contains(e.target) && e.target !== bellBtn) {
            closePanel();
        }
    });

    // Close on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePanel();
    });

    markAllBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        markAllRead();
    });

    // ── Helpers ──────────────────────────────────────────────────────────────
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Public API (optional, for adding runtime notifications) ──────────────
    window.notifSystem = {
        /**
         * Push a new notification at runtime.
         * @param {{ type: string, icon: string, title: string, body: string, time: string }} notif
         */
        push(notif) {
            const id = 'rt-' + Date.now();
            notifications.unshift({ id, read: false, ...notif });
            renderList();
            updateBadge();
            bellBtn.classList.add('has-notif');
            // Re-trigger bell shake
            bellBtn.classList.remove('has-notif');
            void bellBtn.offsetWidth; // reflow
            bellBtn.classList.add('has-notif');
        },
        close: closePanel,
        open:  openPanel,
    };

    // ── Boot ─────────────────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();