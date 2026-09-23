/**
 * responsive.js — mobile sidebar drawer (≤ 768px).
 * Opens from the floating menu button; closes from the drawer's × button,
 * the dimmed backdrop, Escape, choosing any item in it, or any modal opening
 * (the drawer sits above modals, so it must get out of the way).
 */
document.addEventListener('DOMContentLoaded', function () {
    const menuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!menuBtn || !sidebar || !overlay) return;

    const isOpen = () => sidebar.classList.contains('mobile-open');

    function openDrawer() {
        sidebar.classList.remove('collapsed'); // the drawer is always full width
        sidebar.classList.add('mobile-open');
        overlay.classList.add('active');
        menuBtn.setAttribute('aria-expanded', 'true');
    }

    function closeDrawer() {
        if (!isOpen()) return;
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
        menuBtn.setAttribute('aria-expanded', 'false');
    }

    menuBtn.setAttribute('aria-controls', 'sidebar');
    menuBtn.setAttribute('aria-expanded', 'false');
    menuBtn.addEventListener('click', () => (isOpen() ? closeDrawer() : openDrawer()));
    overlay.addEventListener('click', closeDrawer);

    // The drawer's own close button (includes/sidebar.php)
    sidebar.querySelector('.sb-close')?.addEventListener('click', closeDrawer);

    // Picking anything in the drawer — a page link or a modal like My profile — closes it
    sidebar.addEventListener('click', e => {
        if (e.target.closest('a[href], button[data-bs-toggle="modal"], .logout-btn')) closeDrawer();
    });

    // Any modal opening, from anywhere, must not sit underneath the drawer
    document.addEventListener('show.bs.modal', closeDrawer);

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeDrawer();
    });

    // Rotating or resizing to desktop width leaves no stale drawer state behind
    window.matchMedia('(min-width: 769px)').addEventListener('change', mq => {
        if (mq.matches) closeDrawer();
    });
});
