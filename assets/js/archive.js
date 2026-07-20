// Show loading spinner
function showLoading() {
    document.getElementById('loadingSpinner').style.display = 'flex';
}

function hideLoading() {
    document.getElementById('loadingSpinner').style.display = 'none';
}

// Show error message
function showError(message) {
    const errorContainer = document.getElementById('errorContainer');
    errorContainer.style.display = 'block';
    errorContainer.innerHTML = `
        <div class="error-message">
            <i class="fas fa-exclamation-triangle"></i>
            <span>${message}</span>
            <button class="btn-close ms-auto" onclick="this.parentElement.parentElement.style.display='none'"></button>
        </div>
    `;
}

// Toast notification
function showToast(type, title, message) {
    const toastContainer = document.getElementById('toastContainer');
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    
    const toastId = 'toast-' + Date.now() + '-' + Math.random();
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
    const toast = new bootstrap.Toast(toastElement, { autohide: true, delay: 2000 });
    toast.show();
    setTimeout(() => toastElement.remove(), 2000);
}

// Test connection to AJAX endpoint
function testConnection() {
    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { action: 'test_connection' },
        dataType: 'json',
        timeout: 5000,
        success: function(response) {
            hideLoading();
            showToast('success', 'Connection OK', 'Successfully connected to server');
        },
        error: function(xhr, status, error) {
            hideLoading();
            let errorMsg = 'Failed to connect to server';
            if (status === 'timeout') {
                errorMsg = 'Connection timeout - server not responding';
            } else if (xhr.status === 404) {
                errorMsg = 'AJAX endpoint not found - check file path';
            } else if (xhr.status === 500) {
                errorMsg = 'Server error - check PHP error logs';
            }
            showError(errorMsg + ': ' + error);
            showToast('error', 'Connection Error', errorMsg);
            console.error('AJAX Error:', {status: xhr.status, statusText: xhr.statusText, response: xhr.responseText});
        }
    });
}

// Tab switching
function switchTab(tab) {
    document.getElementById('panel-products').style.display  = tab === 'products'   ? '' : 'none';
    document.getElementById('panel-categories').style.display = tab === 'categories' ? '' : 'none';
    document.getElementById('tab-products').classList.toggle('active',   tab === 'products');
    document.getElementById('tab-categories').classList.toggle('active', tab === 'categories');
    if (tab === 'categories') loadArchivedCategories();
}

// Load archived categories
function loadArchivedCategories() {
    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { action: 'get_archived_categories' },
        dataType: 'json',
        success: function(response) {
            hideLoading();
            if (response.success) {
                displayArchivedCategories(response.data);
            } else {
                showToast('error', 'Error', response.message || 'Failed to load archived categories');
            }
        },
        error: function() {
            hideLoading();
            showToast('error', 'Error', 'Failed to connect to server');
        }
    });
}

// Display archived categories
function displayArchivedCategories(categories) {
    const tbody = document.getElementById('categoryArchiveTableBody');
    if (!tbody) return;

    if (!categories || categories.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="text-center py-4">
                    <div class="empty-state">
                        <i class="fas fa-layer-group"></i>
                        <h5>No Archived Categories</h5>
                        <p class="text-muted">Categories you archive will appear here.</p>
                    </div>
                </td>
            </tr>`;
        return;
    }

    let html = '';
    categories.forEach(cat => {
        const archivedDate = cat.formatted_deleted_at || new Date(cat.deleted_at).toLocaleString();
        const color = cat.color || '#4a6fa5';
        html += `
            <tr>
                <td>
                    <div class="product-info">
                        <div class="product-icon" style="background:${color}">
                            <i class="fas ${cat.icon || 'fa-paw'}"></i>
                        </div>
                        <div>
                            <strong>${cat.name}</strong>
                        </div>
                    </div>
                </td>
                <td>${cat.description ? cat.description.substring(0, 40) + (cat.description.length > 40 ? '...' : '') : '<span class="text-muted">—</span>'}</td>
                <td><span class="category-badge">${cat.product_count || 0} product(s)</span></td>
                <td><span class="deleted-date"><i class="fas fa-clock me-1"></i>${archivedDate}</span></td>
                <td>
                    <button class="btn-icon restore" onclick="restoreCategory(${cat.id})" title="Restore Category and eligible products">
                        <i class="fas fa-undo-alt"></i>
                    </button>
                </td>
            </tr>`;
    });
    tbody.innerHTML = html;
}

// Restore entire category
function restoreCategory(categoryId) {
    if (confirm('Restore this category? Products with no past transactions will be returned to inventory. Products with transaction history will remain in archive.')) {
        showLoading();
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: { action: 'restore_category', id: categoryId },
            dataType: 'json',
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showToast('success', 'Restored', response.message);
                    loadArchivedCategories();
                    loadArchiveStats();
                } else {
                    showToast('error', 'Error', response.message);
                }
            },
            error: function() {
                hideLoading();
                showToast('error', 'Error', 'Failed to restore category');
            }
        });
    }
}

// Load archived products
function loadArchivedProducts() {
    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { action: 'get_archived_products' },
        dataType: 'json',
        timeout: 10000,
        success: function(response) {
            hideLoading();
            if (response.success) {
                displayArchivedProducts(response.data);
                loadArchiveStats();
            } else {
                showToast('error', 'Error', response.message || 'Failed to load archived products');
            }
        },
        error: function(xhr, status, error) {
            hideLoading();
            let errorMsg = 'Failed to connect to server';
            if (xhr.status === 404) {
                errorMsg = 'AJAX endpoint not found at: ' + ajaxUrl;
            } else if (xhr.status === 500) {
                errorMsg = 'Server error - check PHP error logs';
                if (xhr.responseText) {
                    console.error('Server response:', xhr.responseText);
                }
            }
            showError(errorMsg);
            showToast('error', 'Connection Error', errorMsg);
            
            // Show sample data for testing UI
            displaySampleData();
        }
    });
}

// Display sample data for testing
function displaySampleData() {
    const sampleProducts = [
        {id: 1, name: 'Premium Dog Food', category_name: 'Dog Food', price: 1250.00, stock: 0, formatted_deleted_at: 'March 15, 2025 02:30 PM'},
        {id: 2, name: 'Chick Starter Mash', category_name: 'Poultry Feed', price: 850.00, stock: 0, formatted_deleted_at: 'March 14, 2025 10:15 AM'}
    ];
    displayArchivedProducts(sampleProducts);
    
    document.getElementById('totalArchived').textContent = '2';
    document.getElementById('restorable').textContent = '2';
    document.getElementById('oldestArchive').textContent = 'Mar 14';
    document.getElementById('storageUsed').textContent = '2 KB';
}

// Load archive statistics
function loadArchiveStats() {
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { action: 'get_archive_stats' },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                updateStats(response.data);
            }
        }
    });
}

// Update statistics display
function updateStats(stats) {
    document.getElementById('totalArchived').textContent = stats.total_archived || 0;
    document.getElementById('restorable').textContent = stats.total_archived || 0;
    
    if (stats.oldest_archive) {
        const date = new Date(stats.oldest_archive);
        document.getElementById('oldestArchive').textContent = date.toLocaleDateString();
    } else {
        document.getElementById('oldestArchive').textContent = '-';
    }
    
    const storageKB = (stats.total_archived || 0) * 1;
    document.getElementById('storageUsed').textContent = storageKB + ' KB';
}

// Display archived products
function displayArchivedProducts(products) {
    const tbody = document.getElementById('archiveTableBody');
    
    if (!products || products.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-4">
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h5>No Archived Products</h5>
                        <p class="text-muted">The archive is empty. Products you delete will appear here.</p>
                    </div>
                </td>
            </tr>
        `;
        return;
    }
    
    let html = '';
    products.forEach(product => {
        const deletedDate = product.formatted_deleted_at || new Date(product.deleted_at).toLocaleString();
        const price = parseFloat(product.price).toFixed(2);
        const categoryColor = product.category_color || '#4a6fa5';
        
        html += `
            <tr>
                <td>
                    <div class="product-info">
                        <div class="product-icon" style="background: ${categoryColor}">
                            <i class="fas fa-box"></i>
                        </div>
                        <div>
                            <strong>${product.name}</strong>
                            <br>
                            <small class="text-muted">${product.description ? product.description.substring(0, 30) + '...' : 'No description'}</small>
                        </div>
                    </div>
                </td>
                <td><span class="category-badge">${product.category_name || 'Uncategorized'}</span></td>
                <td>₱${price}</td>
                <td>${product.stock}</td>
                <td><span class="deleted-date"><i class="fas fa-clock me-1"></i>${deletedDate}</span></td>
                <td>
                    <button class="btn-icon restore" onclick="restoreProduct(${product.id})" title="Restore Product">
                        <i class="fas fa-undo-alt"></i>
                    </button>
                    ${isOwner ? `<button class="btn-icon delete" onclick="permanentDelete(${product.id})" title="Delete Permanently">
                        <i class="fas fa-trash-alt"></i>
                    </button>` : ''}
                </td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}

// Restore product
function restoreProduct(productId) {
    if (confirm('Restore this product to active inventory?')) {
        showLoading();
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: { action: 'restore_product', id: productId },
            dataType: 'json',
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showToast('success', 'Restored', response.message);
                    loadArchivedProducts();
                } else {
                    showToast('error', 'Error', response.message);
                }
            },
            error: function() {
                hideLoading();
                showToast('error', 'Error', 'Failed to restore product');
            }
        });
    }
}

// Permanent delete
function permanentDelete(productId) {
    if (confirm('WARNING: This will permanently delete the product. This action cannot be undone!')) {
        showLoading();
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: { action: 'permanent_delete', id: productId },
            dataType: 'json',
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showToast('success', 'Deleted', response.message);
                    loadArchivedProducts();
                } else {
                    showToast('error', 'Cannot Delete', response.message || 'Product has transaction records and cannot be deleted');
                }
            },
            error: function() {
                hideLoading();
                showToast('error', 'Error', 'Failed to delete product');
            }
        });
    }
}

// Empty archive
function emptyArchive() {
    if (confirm('WARNING: This will permanently delete ALL archived products. This action cannot be undone!')) {
        showLoading();
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: { action: 'empty_archive' },
            dataType: 'json',
            success: function(response) {
                hideLoading();
                if (response.success) {
                    showToast('success', 'Archive Emptied', response.message);
                    loadArchivedProducts();
                } else {
                    showToast('error', 'Cannot Delete', response.message || 'Some products have transaction records and cannot be deleted');
                }
            },
            error: function() {
                hideLoading();
                showToast('error', 'Error', 'Failed to empty archive');
            }
        });
    }
}

// Refresh archive
function refreshArchive() {
    loadArchivedProducts();
    showToast('info', 'Refreshing', 'Loading latest data...');
}

// Function to add tooltip titles to sidebar buttons
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
    
    const logoutBtn = document.querySelector('.logout-btn');
    if (logoutBtn && !logoutBtn.getAttribute('title')) {
        logoutBtn.setAttribute('title', 'Logout');
    }
    
    // Add tooltips for archive action buttons
    const refreshBtn = document.querySelector('.btn-outline-secondary');
    if (refreshBtn) {
        refreshBtn.setAttribute('title', 'Refresh archive');
    }
    
    const emptyArchiveBtn = document.querySelector('.btn-danger');
    if (emptyArchiveBtn) {
        emptyArchiveBtn.setAttribute('title', 'Empty entire archive');
    }
    
    // Add tooltips for test connection button if exists
    const testBtn = document.querySelector('button[onclick="testConnection()"]');
    if (testBtn) {
        testBtn.setAttribute('title', 'Test database connection');
    }
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
            
            /* Hover effects for archive buttons */
            .btn-icon {
                position: relative;
            }
            
            .btn-icon:hover::before {
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
            }
        `;
        document.head.appendChild(style);
    }
}

// Sidebar functionality
const sidebar = document.getElementById('sidebar');
const collapseBtn = document.getElementById('collapseBtn');
const collapseIcon = document.getElementById('collapseIcon');

if (collapseBtn) {
    collapseBtn.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
        collapseIcon.classList.toggle('fa-chevron-left');
        collapseIcon.classList.toggle('fa-chevron-right');
    });
    collapseBtn.setAttribute('title', 'Toggle sidebar');
}

// Dark mode toggle
const darkToggle = document.getElementById('darkToggle');
const darkIcon = document.getElementById('darkIcon');
const body = document.body;

if (darkToggle) {
    darkToggle.addEventListener('click', () => {
        sidebar.classList.toggle('dark-mode');
        body.classList.toggle('dark-mode');
        darkIcon.classList.toggle('fa-moon');
        darkIcon.classList.toggle('fa-sun');
        darkToggle.querySelector('span').textContent = body.classList.contains('dark-mode') ? 'Light mode' : 'Dark mode';
    });
    darkToggle.setAttribute('title', 'Toggle dark mode');
}

// Initialization
document.addEventListener('DOMContentLoaded', function() {
    addTooltipTitles();
    addTooltipStyles();
    
    // Logout
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showToast('info', 'Logging Out', 'See you next time!');
            setTimeout(() => window.location.replace('logout.php'), 500);
        });
    }

    // Recommendation button
    const recommendationBtn = document.getElementById('recommendationBtn');
    if (recommendationBtn) {
        recommendationBtn.addEventListener('click', function() {
            window.location.href = 'reco.php';
        });
    }

    loadArchivedProducts();
    
    // Add title to refresh button if exists
    const refreshBtn = document.querySelector('.btn-outline-secondary');
    if (refreshBtn && !refreshBtn.getAttribute('title')) {
        refreshBtn.setAttribute('title', 'Refresh archive');
    }
    
    // Add title to empty archive button if exists
    const emptyBtn = document.querySelector('.btn-danger');
    if (emptyBtn && !emptyBtn.getAttribute('title')) {
        emptyBtn.setAttribute('title', 'Empty entire archive');
    }
    
    // Add titles to section headers in sidebar for better UX
    const sectionTitles = document.querySelectorAll('.section-title');
    sectionTitles.forEach(title => {
        const text = title.textContent.trim();
        title.setAttribute('title', text);
    });
});