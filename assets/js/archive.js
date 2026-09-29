// Escape text before it goes into innerHTML
function escHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Names of the rows on screen, so confirm dialogs can say what they act on
const archivedNames = { product: new Map(), category: new Map() };
function archivedProductName(id) { return archivedNames.product.get(String(id)) || 'This product'; }
function archivedCategoryName(id) { return archivedNames.category.get(String(id)) || 'This category'; }

function archiveEmptyRow(cols, title, text) {
    return `<tr><td colspan="${cols}" class="inv-empty-cell"><p class="inv-empty-title">${escHtml(title)}</p><p>${escHtml(text)}</p></td></tr>`;
}

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
        tbody.innerHTML = archiveEmptyRow(5, 'No archived categories', 'Categories you archive will appear here.');
        return;
    }

    archivedNames.category = new Map(categories.map(c => [String(c.id), c.name]));
    tbody.innerHTML = categories.map(cat => {
        const archivedDate = cat.formatted_deleted_at || new Date(cat.deleted_at).toLocaleString();
        const color = /^#[0-9a-f]{3,8}$/i.test(cat.color || '') ? cat.color : '#2c5530';
        const count = parseInt(cat.product_count, 10) || 0;
        return `
            <tr>
                <td>
                    <div class="inv-product">
                        <span class="inv-cat-icon inv-cat-icon-sm" style="--cat:${color}"><i class="fas ${escHtml(cat.icon || 'fa-paw')}"></i></span>
                        <span class="inv-name">${escHtml(cat.name)}</span>
                    </div>
                </td>
                <td class="inv-muted-cell">${cat.description ? escHtml(cat.description) : '—'}</td>
                <td class="inv-num">${count}</td>
                <td class="inv-muted-cell">${escHtml(archivedDate)}</td>
                <td class="inv-actions">
                    <button class="inv-btn inv-btn-quiet inv-btn-sm" onclick="restoreCategory(${cat.id})" title="Restore the category and its eligible products">
                        <i class="fas fa-undo-alt"></i>Restore
                    </button>
                </td>
            </tr>`;
    }).join('');
}

// Restore entire category
function restoreCategory(categoryId) {
    confirmDialog({
        title: 'Restore category?',
        message: `${archivedCategoryName(categoryId)} will return to inventory.`,
        detail: 'Its products with no past sales come back too. Products with sales history stay in the archive.',
        confirmText: 'Restore',
        tone: 'primary',
        icon: 'fa-undo-alt'
    }).then(ok => {
        if (!ok) return;
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
    });
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
            const tbody = document.getElementById('archiveTableBody');
            if (tbody) tbody.innerHTML = archiveEmptyRow(6, 'Could not load the archive', errorMsg);
        }
    });
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
    const oldest = document.getElementById('oldestArchive');
    if (oldest) {
        oldest.textContent = stats.oldest_archive
            ? new Date(stats.oldest_archive).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
            : '—';
    }
}

// Display archived products
function displayArchivedProducts(products) {
    const tbody = document.getElementById('archiveTableBody');
    if (!tbody) return;

    if (!products || products.length === 0) {
        tbody.innerHTML = archiveEmptyRow(6, 'The archive is empty', 'Products you archive will appear here.');
        return;
    }

    archivedNames.product = new Map(products.map(p => [String(p.id), p.name]));
    tbody.innerHTML = products.map(product => {
        const deletedDate = product.formatted_deleted_at || new Date(product.deleted_at).toLocaleString();
        const price = (parseFloat(product.price) || 0).toFixed(2);
        const thumb = product.image
            ? `<img src="${escHtml(product.image)}" alt="" class="inv-thumb" loading="lazy">`
            : `<span class="inv-thumb inv-thumb-empty"><i class="fas fa-box"></i></span>`;
        const sub = [product.sku, product.description].filter(Boolean).map(escHtml).join(' · ');
        return `
            <tr>
                <td>
                    <div class="inv-product">
                        ${thumb}
                        <div class="inv-product-text">
                            <span class="inv-name">${escHtml(product.name)}</span>
                            ${sub ? `<span class="inv-sub">${sub}</span>` : ''}
                        </div>
                    </div>
                </td>
                <td class="inv-muted-cell">${escHtml(product.category_name || 'Uncategorized')}</td>
                <td class="inv-num">₱${price}</td>
                <td class="inv-num">${String(product.unit || '').trim().toLowerCase() === 'per kilo'
                    ? `${Math.round((parseFloat(product.stock) || 0) * 100) / 100} kg`
                    : (parseInt(product.stock, 10) || 0)}</td>
                <td class="inv-muted-cell">${escHtml(deletedDate)}</td>
                <td class="inv-actions">
                    <button class="inv-btn inv-btn-quiet inv-btn-sm" onclick="restoreProduct(${product.id})" title="Restore to inventory">
                        <i class="fas fa-undo-alt"></i>Restore
                    </button>
                    ${isOwner ? `<button class="btn-icon delete" onclick="permanentDelete(${product.id})" title="Delete permanently">
                        <i class="fas fa-trash-alt"></i>
                    </button>` : ''}
                </td>
            </tr>`;
    }).join('');
}

// Restore product
function restoreProduct(productId) {
    confirmDialog({
        title: 'Restore product?',
        message: `${archivedProductName(productId)} will return to active inventory.`,
        confirmText: 'Restore',
        tone: 'primary',
        icon: 'fa-undo-alt'
    }).then(ok => {
        if (!ok) return;
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
    });
}

// Permanent delete
function permanentDelete(productId) {
    confirmDialog({
        title: 'Delete permanently?',
        message: `${archivedProductName(productId)} will be deleted for good.`,
        detail: 'This cannot be undone.',
        confirmText: 'Delete',
        tone: 'danger'
    }).then(ok => {
        if (!ok) return;
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
    });
}

// Empty archive
function emptyArchive() {
    confirmDialog({
        title: 'Empty the archive?',
        message: 'Every archived product will be deleted for good.',
        detail: 'This cannot be undone.',
        confirmText: 'Delete all',
        tone: 'danger'
    }).then(ok => {
        if (!ok) return;
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
    });
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