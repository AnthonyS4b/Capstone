    // ===== INVENTORY HISTORY JAVASCRIPT =====

    // Toast notification function
    function showToast(type, title, message) {
        const toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) return;

        // At most 3 on screen, and the same message only once
        const toastKey = [type, title, message].join('|');
        const visible = [...toastContainer.querySelectorAll('.toast')].filter(el => {
            if (el.dataset.toastKey !== toastKey) return true;
            bootstrap.Toast.getInstance(el)?.dispose();
            el.remove();
            return false;
        });
        visible.slice(0, Math.max(0, visible.length - 2)).forEach(el => {
            bootstrap.Toast.getInstance(el)?.dispose();
            el.remove();
        });

        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };

        const toastId = 'toast-' + Date.now() + '-' + Math.random().toString(36).slice(2);
        const icon = icons[type] || 'fa-bell';
        
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white bg-${type} border-0 mb-2" role="alert">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas ${icon} me-2"></i>
                        <strong>${title}</strong> ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        const toastElement = document.getElementById(toastId);
        toastElement.dataset.toastKey = toastKey;
        const toast = new bootstrap.Toast(toastElement, { autohide: true, delay: 3000 });
        toast.show();
        
        toastElement.addEventListener('hidden.bs.toast', function() {
            this.remove();
        });
    }

    // Update date and time
    function updateDateTime() {
        const now = new Date();
        const options = { 
            weekday: 'short', 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric', 
            hour: '2-digit', 
            minute: '2-digit', 
            second: '2-digit' 
        };
        const dateTimeElement = document.getElementById('currentDateTime');
        if (dateTimeElement) {
            dateTimeElement.textContent = now.toLocaleDateString('en-US', options);
        }
    }
    setInterval(updateDateTime, 1000);
    updateDateTime();

    // ===== SIDEBAR FUNCTIONALITY =====
    function initSidebar() {
        const sidebar = document.getElementById('sidebar');
        const collapseBtn = document.getElementById('collapseBtn');
        const collapseIcon = document.getElementById('collapseIcon');
        
        if (collapseBtn && sidebar) {
            collapseBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                if (sidebar.classList.contains('collapsed')) {
                    collapseIcon.classList.remove('fa-chevron-left');
                    collapseIcon.classList.add('fa-chevron-right');
                } else {
                    collapseIcon.classList.remove('fa-chevron-right');
                    collapseIcon.classList.add('fa-chevron-left');
                }
            });
            collapseBtn.setAttribute('title', 'Toggle sidebar');
        }
    }

    // ===== TOOLTIP FUNCTIONALITY =====
    function addTooltipTitles() {
        // Set titles for main buttons in sidebar
        const profileBtn = document.querySelector('.profile-btn');
        if (profileBtn && !profileBtn.getAttribute('title')) {
            profileBtn.setAttribute('title', 'My Profile');
        }
        
        const switchAccountBtn = document.querySelector('.switch-account-btn');
        if (switchAccountBtn && !switchAccountBtn.getAttribute('title')) {
            switchAccountBtn.setAttribute('title', 'Switch Account');
        }
        
        const posBtn = document.querySelector('.dropdown-toggle');
        if (posBtn && !posBtn.getAttribute('title')) {
            posBtn.setAttribute('title', 'Point of Sale');
        }
        
        const inventoryBtn = document.querySelectorAll('.dropdown .dropdown-toggle')[1];
        if (inventoryBtn && !inventoryBtn.getAttribute('title')) {
            inventoryBtn.setAttribute('title', 'Inventory Management');
        }
        
        const dashboardLink = document.querySelector('.dashboard-link');
        if (dashboardLink && !dashboardLink.getAttribute('title')) {
            dashboardLink.setAttribute('title', 'Dashboard');
        }
        
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn && !logoutBtn.getAttribute('title')) {
            logoutBtn.setAttribute('title', 'Logout');
        }
        
        // const refreshBtn = document.querySelector('.refresh-btn');
        // if (refreshBtn && !refreshBtn.getAttribute('title')) {
        //     refreshBtn.setAttribute('title', 'Refresh page');
        // }
        
        // Add tooltips to tab buttons
        const tabButtons = document.querySelectorAll('.nav-link');
        tabButtons.forEach(btn => {
            const text = btn.textContent.trim();
            btn.setAttribute('title', text);
        });
    }

    // Add CSS for tooltips in collapsed state
    function addTooltipStyles() {
        if (!document.querySelector('#tooltipStyles')) {
            const style = document.createElement('style');
            style.id = 'tooltipStyles';
            style.textContent = `
                /* Tooltip styles for collapsed sidebar */
                .sidebar.collapsed [title] {
                    position: relative;
                }

                .sidebar.collapsed [title]:hover::before {
                    content: attr(title);
                    position: absolute;
                    left: 75px;
                    background: #1e293b;
                    color: white;
                    padding: 6px 12px;
                    border-radius: 6px;
                    font-size: 12px;
                    white-space: nowrap;
                    z-index: 1000;
                    pointer-events: none;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                    font-weight: normal;
                    letter-spacing: 0.3px;
                }

                .sidebar.collapsed [title]:hover::after {
                    content: '';
                    position: absolute;
                    left: 68px;
                    top: 50%;
                    transform: translateY(-50%);
                    border-width: 5px;
                    border-style: solid;
                    border-color: transparent #1e293b transparent transparent;
                    pointer-events: none;
                }
                
                /* Hover effects for buttons */
                .btn, .btn-icon, .refresh-btn, .filter-select {
                    position: relative;
                }
                
                .btn:hover::before, 
                .btn-icon:hover::before, 
                .refresh-btn:hover::before,
                .filter-select:hover::before {
                    content: attr(title);
                    position: absolute;
                    bottom: 100%;
                    left: 50%;
                    transform: translateX(-50%);
                    background: #1e293b;
                    color: white;
                    padding: 4px 8px;
                    border-radius: 4px;
                    font-size: 11px;
                    white-space: nowrap;
                    margin-bottom: 5px;
                    z-index: 1000;
                    pointer-events: none;
                }
            `;
            document.head.appendChild(style);
        }
    }

    // ===== RESTORE PRODUCT FUNCTIONALITY =====
    let currentProductId = null;

    function restoreProduct(productId) {
        currentProductId = productId;
        const modal = new bootstrap.Modal(document.getElementById('restoreConfirmModal'));
        modal.show();
    }

    function initRestoreButton() {
        const confirmBtn = document.getElementById('confirmRestoreBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                if (currentProductId) {
                    $.ajax({
                        url: ajaxUrl,
                        method: 'POST',
                        data: { 
                            action: 'restore_product', 
                            id: currentProductId,
                            user_id: phpData.user_id
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                showToast('success', 'Success', 'Product restored successfully');
                                bootstrap.Modal.getInstance(document.getElementById('restoreConfirmModal')).hide();
                                setTimeout(() => location.reload(), 1000);
                            } else {
                                showToast('error', 'Error', response.message || 'Failed to restore product');
                            }
                        },
                        error: function() {
                            showToast('error', 'Error', 'Failed to connect to server');
                        }
                    });
                }
            });
        }
    }

    // ===== FILTER FUNCTIONS =====

    // Tab 1: Who Added Items - Filter
    function initInventoryFilters() {
        const searchInventory = document.getElementById('searchInventory');
        const categoryFilter = document.getElementById('categoryFilter');
        const roleFilter = document.getElementById('roleFilter');
        
        if (searchInventory) {
            searchInventory.addEventListener('input', filterInventory);
            searchInventory.setAttribute('title', 'Search by product name');
        }
        if (categoryFilter) {
            categoryFilter.addEventListener('change', filterInventory);
            categoryFilter.setAttribute('title', 'Filter by category');
        }
        if (roleFilter) {
            roleFilter.addEventListener('change', filterInventory);
            roleFilter.setAttribute('title', 'Filter by user role');
        }
    }

    function filterInventory() {
        const searchTerm = document.getElementById('searchInventory').value.toLowerCase();
        const categoryFilter = document.getElementById('categoryFilter').value;
        const roleFilter = document.getElementById('roleFilter').value;
        const rows = document.querySelectorAll('#inventoryTable tbody tr');
        
        rows.forEach(row => {
            const productName = row.querySelector('td:first-child').textContent.toLowerCase();
            const category = row.dataset.category;
            const role = row.dataset.role;
            
            let matchesSearch = searchTerm === '' || productName.includes(searchTerm);
            let matchesCategory = categoryFilter === '' || category == categoryFilter;
            let matchesRole = roleFilter === '' || role === roleFilter;
            
            if (matchesSearch && matchesCategory && matchesRole) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    // Tab 2: Archived/Deleted Filter
    function initArchiveFilters() {
        const archiveTypeFilter = document.getElementById('archiveTypeFilter');
        const searchArchived = document.getElementById('searchArchived');
        
        if (archiveTypeFilter) {
            archiveTypeFilter.addEventListener('change', filterArchived);
            archiveTypeFilter.setAttribute('title', 'Filter by action type');
        }
        if (searchArchived) {
            searchArchived.addEventListener('input', filterArchived);
            searchArchived.setAttribute('title', 'Search archived items');
        }
    }

    function filterArchived() {
        const filter = document.getElementById('archiveTypeFilter').value.toLowerCase();
        const searchTerm = document.getElementById('searchArchived').value.toLowerCase();
        const rows = document.querySelectorAll('#archived tbody tr');
        
        rows.forEach(row => {
            const action = row.querySelector('.action-badge')?.textContent.toLowerCase().trim() || '';
            const text = row.textContent.toLowerCase();
            
            let matchesType = filter === '' || action.includes(filter);
            let matchesSearch = searchTerm === '' || text.includes(searchTerm);
            
            if (matchesType && matchesSearch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    // Tab 3: Activity Log Filter
    function initActivityFilters() {
        const activityTypeFilter = document.getElementById('activityTypeFilter');
        const activityRoleFilter = document.getElementById('activityRoleFilter');
        const searchActivity = document.getElementById('searchActivity');
        
        if (activityTypeFilter) {
            activityTypeFilter.addEventListener('change', filterActivity);
            activityTypeFilter.setAttribute('title', 'Filter by activity type');
        }
        if (activityRoleFilter) {
            activityRoleFilter.addEventListener('change', filterActivity);
            activityRoleFilter.setAttribute('title', 'Filter by user role');
        }
        if (searchActivity) {
            searchActivity.addEventListener('input', filterActivity);
            searchActivity.setAttribute('title', 'Search activities');
        }
    }

    function filterActivity() {
        const typeFilter = document.getElementById('activityTypeFilter').value.toLowerCase();
        const roleFilter = document.getElementById('activityRoleFilter').value.toLowerCase();
        const searchTerm = document.getElementById('searchActivity').value.toLowerCase();
        
        const items = document.querySelectorAll('.activity-log .session-item');
        
        items.forEach(item => {
            const action = item.dataset.action || '';
            const role = item.dataset.role || '';
            const text = item.textContent.toLowerCase();
            
            let matchesType = typeFilter === '' || action.includes(typeFilter);
            let matchesRole = roleFilter === '' || role.includes(roleFilter);
            let matchesSearch = searchTerm === '' || text.includes(searchTerm);
            
            if (matchesType && matchesRole && matchesSearch) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    }

    // Tab 4: Session Filter
    function initSessionFilters() {
        const sessionTypeFilter = document.getElementById('sessionTypeFilter');
        const sessionRoleFilter = document.getElementById('sessionRoleFilter');
        const searchSessions = document.getElementById('searchSessions');
        
        if (sessionTypeFilter) {
            sessionTypeFilter.addEventListener('change', filterSessions);
            sessionTypeFilter.setAttribute('title', 'Filter by session type');
        }
        if (sessionRoleFilter) {
            sessionRoleFilter.addEventListener('change', filterSessions);
            sessionRoleFilter.setAttribute('title', 'Filter by user role');
        }
        if (searchSessions) {
            searchSessions.addEventListener('input', filterSessions);
            searchSessions.setAttribute('title', 'Search sessions');
        }
    }

    function filterSessions() {
        const typeFilter = document.getElementById('sessionTypeFilter').value.toLowerCase();
        const roleFilter = document.getElementById('sessionRoleFilter').value.toLowerCase();
        const searchTerm = document.getElementById('searchSessions').value.toLowerCase();
        
        const items = document.querySelectorAll('.sessions-list .session-item');
        
        items.forEach(item => {
            const action = item.dataset.action || '';
            const role = item.dataset.role || '';
            const text = item.textContent.toLowerCase();
            
            let matchesType = typeFilter === '' || action.includes(typeFilter);
            let matchesRole = roleFilter === '' || role.includes(roleFilter);
            let matchesSearch = searchTerm === '' || text.includes(searchTerm);
            
            if (matchesType && matchesRole && matchesSearch) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    }

    // ===== LOGOUT FUNCTIONALITY =====
    function initLogout() {
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function(e) {
                e.preventDefault();
                showToast('info', 'Logging Out', 'See you next time!');
                setTimeout(() => {
                    window.location.replace('logout.php');
                }, 500);
            });
        }
    }

    // ===== INITIALIZATION =====
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize all functions
        initSidebar();
        addTooltipTitles();
        addTooltipStyles();
        initRestoreButton();
        initInventoryFilters();
        initArchiveFilters();
        initActivityFilters();
        initSessionFilters();
        initLogout();
        
        // Show toast message if exists
        if (phpData.toast_message) {
            showToast(
                phpData.toast_message.type, 
                phpData.toast_message.title, 
                phpData.toast_message.message
            );
        }
        
        // Add titles to section headers
        const sectionTitles = document.querySelectorAll('.section-title');
        sectionTitles.forEach(title => {
            const text = title.textContent.trim();
            title.setAttribute('title', text);
        });
        
        // Recommendation button functionality
        const recommendationBtn = document.getElementById('recommendationBtn');
        if (recommendationBtn) {
            recommendationBtn.addEventListener('click', function() {
                window.location.href = 'reco.php';
            });
        }
    });