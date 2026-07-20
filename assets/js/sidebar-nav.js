/**
 * sidebar-nav.js
 * Auto-opens the relevant sidebar dropdown and highlights the active page
 * based on the current URL. Include this script on every page with a sidebar.
 */
document.addEventListener('DOMContentLoaded', function () {
    const currentPage = window.location.pathname.split('/').pop().toLowerCase();

    // Map page filenames to their dropdown items
    const sidebarLinks = document.querySelectorAll('.sidebar .dropdown-item[href]');

    sidebarLinks.forEach(function (link) {
        const href = (link.getAttribute('href') || '').toLowerCase();
        const linkPage = href.split('/').pop();

        if (linkPage && currentPage === linkPage) {
            // Mark this item as active
            link.classList.add('active');

            // Find the parent dropdown and force it open
            const dropdownMenu = link.closest('.dropdown-menu');
            if (dropdownMenu) {
                dropdownMenu.classList.add('show');
                dropdownMenu.style.display = 'block';

                // Also mark the toggle button as open
                const dropdownEl = dropdownMenu.closest('.dropdown');
                if (dropdownEl) {
                    const toggle = dropdownEl.querySelector('.dropdown-toggle');
                    if (toggle) {
                        toggle.classList.add('show');
                        toggle.setAttribute('aria-expanded', 'true');
                    }
                }
            }
        }
    });
});
