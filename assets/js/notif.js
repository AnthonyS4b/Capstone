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
    // Alerts that need someone to act come first; routine activity after.
    const GROUPS = [
        { label: 'Needs attention', types: ['critical', 'warning'] },
        { label: 'Activity',        types: ['info', 'success'] },
    ];

    function renderList() {
        // Remove existing items (keep the empty state element)
        list.querySelectorAll('.notif-item, .notif-group').forEach(el => el.remove());

        if (notifications.length === 0) {
            emptyEl.style.display = 'flex';
            renderFooter();
            return;
        }

        emptyEl.style.display = 'none';

        GROUPS.forEach(group => {
            const items = notifications.filter(n => group.types.includes(n.type)
                || (group.label === 'Activity' && !GROUPS[0].types.includes(n.type) && !group.types.includes(n.type)));
            if (!items.length) return;

            const heading = document.createElement('div');
            heading.className = 'notif-group';
            heading.textContent = `${group.label} · ${items.length}`;
            list.appendChild(heading);

            items.forEach(n => {
                const item = document.createElement('div');
                item.className = 'notif-item' + (n.read ? '' : ' unread');
                item.dataset.id = n.id;
                item.setAttribute('role', 'button');
                item.tabIndex = 0;
                item.innerHTML = `
                    <div class="notif-icon type-${escHtml(n.type)}" aria-hidden="true">
                        <i class="fas ${escHtml(n.icon)}"></i>
                    </div>
                    <div class="notif-text">
                        <div class="notif-title">${escHtml(n.title)}</div>
                        <div class="notif-body">${escHtml(n.body)}</div>
                        <div class="notif-time">${escHtml(n.time)}</div>
                    </div>
                    <span class="notif-dot" aria-label="Unread"></span>`;

                item.addEventListener('click', () => activate(n));
                item.addEventListener('keydown', e => {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(n); }
                });
                list.appendChild(item);
            });
        });

        renderFooter();
    }

    // ── Click: mark read, and open the modal the item points to (n.open) ──────
    function activate(n) {
        markRead(n.id);
        const target = n.open ? document.querySelector(n.open) : null;
        if (!target || typeof bootstrap === 'undefined') return;
        closePanel();
        bootstrap.Modal.getOrCreateInstance(target).show();
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
        if (footer) footer.style.display = 'none';

        // "Notifications  3 new" in the header; Mark all read only when useful
        const titleEl = panel.querySelector('.notif-panel-header > span');
        if (titleEl) {
            let count = titleEl.querySelector('.notif-count');
            if (!count) {
                count = document.createElement('span');
                count.className = 'notif-count';
                titleEl.appendChild(count);
            }
            count.textContent = unread > 0 ? `${unread} new` : '';
        }
        if (markAllBtn) markAllBtn.hidden = unread === 0;
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
    // Opens leftwards from the bell by default; flip to open rightwards when
    // that would run under the sidebar or off screen (e.g. the POS header).
    function placePanel() {
        panel.classList.remove('align-left');
        if (window.innerWidth <= 768) return; // fixed full-width panel on phones
        const sidebar = document.getElementById('sidebar');
        const sidebarRight = sidebar && getComputedStyle(sidebar).position !== 'fixed'
            ? sidebar.getBoundingClientRect().right : 0;
        if (panel.getBoundingClientRect().left < sidebarRight + 8) {
            panel.classList.add('align-left');
        }
    }

    function openPanel() {
        placePanel();
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
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
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