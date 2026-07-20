// ─────────────────────────────────────────────────────────────────────────────
// SERVER-SIDE REQUIREMENTS: category_ajax.php must handle two additional actions:
//
// 'check_category_transactions' — returns:
//   { success: true, has_transactions: bool, transaction_count: int, product_count: int }
//   SQL:
//     SELECT COUNT(DISTINCT ti.transaction_id) AS tx_count,
//            COUNT(DISTINCT p.id)              AS prod_count
//     FROM products p
//     JOIN transaction_items ti ON ti.product_id = p.id
//     JOIN transactions t       ON t.id = ti.transaction_id
//     LEFT JOIN sales s         ON s.transaction_id = t.id
//     WHERE p.category_id = :id
//       AND (s.status IS NULL OR s.status = 'completed');
//
// 'update_category_status' — receives id + status ('active'|'inactive'), returns:
//   { success: true, message: '...' }
//   SQL:  UPDATE categories SET status = :status WHERE id = :id;
// ─────────────────────────────────────────────────────────────────────────────

// Available icons - ONLY PET APPROPRIATE ICONS
const availableIcons = [
    'fa-dog', 'fa-cat', 'fa-dove', 'fa-fish', 'fa-paw', 'fa-bone',
    'fa-cut', 'fa-capsules', 'fa-apple-alt', 'fa-carrot', 'fa-feather',
    'fa-shield-dog', 'fa-shield-cat', 'fa-bowl-food'
];

let currentCategoryId = null;
let currentCategoryName = '';
let categories = [];

const ajaxUrl = 'ajax/category_ajax.php';

// ==================== HELPER FUNCTIONS ====================

function showLoading() {
    const spinner = document.getElementById('loadingSpinner');
    if (spinner) spinner.style.display = 'flex';
}

function hideLoading() {
    const spinner = document.getElementById('loadingSpinner');
    if (spinner) spinner.style.display = 'none';
}

function showToast(type, title, message) {
    const toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) return;

    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    const toastId = 'toast-' + Date.now() + '-' + Math.random();
    const icon = icons[type] || 'fa-bell';
    const bgColor = type === 'success' ? 'bg-success' : (type === 'error' ? 'bg-danger' : (type === 'warning' ? 'bg-warning' : 'bg-info'));

    const toastHtml = `
        <div id="${toastId}" class="toast align-items-center text-white ${bgColor} border-0 mb-2" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body"><i class="fas ${icon} me-2"></i><strong>${escapeHtml(title)}</strong> ${escapeHtml(message)}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>`;
    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    const toastElement = document.getElementById(toastId);
    if (toastElement) {
        const toast = new bootstrap.Toast(toastElement, { autohide: true, delay: 3000 });
        toast.show();
        setTimeout(() => {
            if (toastElement) toastElement.remove();
        }, 3000);
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function (m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// ── Unit type classification ─────────────────────────────────────
const WEIGHT_UNIT_TYPES = ['per kilo', 'per gram'];

function getUnitTypeFromValue(val) {
    if (!val) return 'packaged';
    const parsed = parseStoredUnit(val);
    const ut = parsed.unitType.toLowerCase();
    if (WEIGHT_UNIT_TYPES.includes(ut)) return 'weight';
    return 'packaged';
}

/**
 * Parse a stored unit string (e.g. "Per Kilo", "3 Per Pack", legacy "per 250g")
 * into { unitType: string, qty: number }.
 */
function parseStoredUnit(val) {
    if (!val) return { unitType: '', qty: 1 };
    val = val.trim();

    // Legacy format mapping (old → new)
    const legacyMap = {
        'per 250g': { unitType: 'Per Gram', qty: 250 },
        'per 500g': { unitType: 'Per Gram', qty: 500 },
        'per 1kg':  { unitType: 'Per Kilo', qty: 1 },
        'per 2kg':  { unitType: 'Per Kilo', qty: 2 },
        'per 3kg':  { unitType: 'Per Kilo', qty: 3 },
        'per 5kg':  { unitType: 'Per Kilo', qty: 5 },
        'per 10kg': { unitType: 'Per Kilo', qty: 10 },
        'per 20kg': { unitType: 'Per Kilo', qty: 20 },
        'per kilo': { unitType: 'Per Kilo', qty: 1 },
        'whole sack': { unitType: 'Per Sack', qty: 1 },
        'half sack': { unitType: 'Per Sack', qty: 0.5 },
        'per bag':    { unitType: 'Per Pack', qty: 1 },
        'per box':    { unitType: 'Per Box', qty: 1 },
        'per can':    { unitType: 'Per Can', qty: 1 },
        'per pouch':  { unitType: 'Per Pouch', qty: 1 },
        'per pack':   { unitType: 'Per Pack', qty: 1 },
        'per bottle': { unitType: 'Per Bottle', qty: 1 },
        'per sachet': { unitType: 'Per Sachet', qty: 1 },
        'per tray':   { unitType: 'Per Pack', qty: 1 },
        'per piece':  { unitType: 'Per Piece', qty: 1 },
        'per set':    { unitType: 'Per Pack', qty: 1 },
        'per liter':  { unitType: 'Per Bottle', qty: 1 },
        'per 100ml':  { unitType: 'Per Bottle', qty: 1 },
        'per 250ml':  { unitType: 'Per Bottle', qty: 1 },
        'per 500ml':  { unitType: 'Per Bottle', qty: 1 },
        'per tablet': { unitType: 'Per Piece', qty: 1 },
        'per capsule':{ unitType: 'Per Piece', qty: 1 },
        'per vial':   { unitType: 'Per Bottle', qty: 1 },
        'per ampoule':{ unitType: 'Per Bottle', qty: 1 },
        'per tube':   { unitType: 'Per Piece', qty: 1 },
        'per syringe':{ unitType: 'Per Piece', qty: 1 },
        'per dose':   { unitType: 'Per Piece', qty: 1 },
    };
    const lower = val.toLowerCase();
    if (legacyMap[lower]) return { ...legacyMap[lower] };

    // New format: "3 Per Pack" or just "Per Kilo"
    const match = val.match(/^([\d.]+)\s+(.+)$/);
    if (match) {
        return { qty: parseFloat(match[1]), unitType: match[2] };
    }
    return { qty: 1, unitType: val };
}

/** Build the combined unit string from the two visible fields. */
function buildUnitString() {
    const typeSel = document.getElementById('productUnitType');
    const hidden = document.getElementById('productUnit');
    if (!typeSel || !hidden) return '';

    const unitType = typeSel.value;
    if (!unitType) { hidden.value = ''; return ''; }

    hidden.value = unitType;
    return hidden.value;
}

function getUnitType() {
    const typeSel = document.getElementById('productUnitType');
    if (!typeSel || !typeSel.value) return null;
    return WEIGHT_UNIT_TYPES.includes(typeSel.value.toLowerCase()) ? 'weight' : 'packaged';
}

function detectCategoryType(name) {
    if (!name) return 'food';
    const n = name.toLowerCase();
    if (n.includes('medicine') || n.includes('medic') || n.includes('vitamin') || n.includes('vaccine') || n.includes('supplement')) return 'medicine';
    if (n.includes('accessor') || n.includes('equipment') || n.includes('tool') || n.includes('cage') || n.includes('leash') || n.includes('collar') || n.includes('grooming')) return 'accessory';
    return 'food';
}

// ==================== UNIT & FIELD HANDLERS ====================

function onCategoryChange() {
    const sel = document.getElementById('productCategory');
    if (!sel) return;
    // Trigger unit field update for stock/barcode/expiration logic
    onUnitTypeChange();
}

/** Called when the Unit Type dropdown changes. */
function onUnitTypeChange() {
    buildUnitString();
    updateUnitPreview();
    onUnitChange();
}

/** Called when the Qty/Weight number input changes. */
function onUnitQtyChange() {
    // Left for backwards compatibility if needed, but Qty input is removed.
    buildUnitString();
    updateUnitPreview();
}

/** Update the live preview badge. */
function updateUnitPreview() {
    const typeSel = document.getElementById('productUnitType');
    const preview = document.getElementById('unitPreview');
    if (!preview || !typeSel) return;

    const unitType = typeSel.value;
    if (!unitType) { preview.style.display = 'none'; return; }

    preview.style.display = 'block';
    preview.innerHTML = `<span class="badge bg-light text-dark border" style="font-size:12px;font-weight:500;padding:6px 12px;">` +
        `<i class="fas fa-tag me-1 text-muted"></i>${escapeHtml(unitType)}</span>`;
}

function onUnitChange() {
    const unitType = getUnitType();
    const stockWrapper = document.getElementById('stockFieldWrapper');
    const barcodeReqStar = document.getElementById('barcodeRequiredStar');
    const barcodeOptTag = document.getElementById('barcodeOptionalTag');
    const barcodeInput = document.getElementById('productBarcode');
    const expirationRow = document.getElementById('expirationRow');
    const unitHint = document.getElementById('unitHint');
    const stockInput = document.getElementById('productStock');
    const catSel = document.getElementById('productCategory');
    const catType = detectCategoryType(catSel ? catSel.options[catSel.selectedIndex]?.text : '');

    if (expirationRow) {
        expirationRow.style.display = (catType === 'accessory') ? 'none' : '';
    }

    if (!unitType) {
        if (stockWrapper) stockWrapper.style.display = 'none';
        if (unitHint) unitHint.style.display = 'none';
        if (barcodeReqStar) barcodeReqStar.style.display = 'none';
        if (barcodeOptTag) barcodeOptTag.style.display = '';
        if (barcodeInput) barcodeInput.required = false;
        if (stockInput) stockInput.required = false;
        return;
    }

    if (unitType === 'weight') {
        if (stockWrapper) stockWrapper.style.display = 'none';
        if (barcodeReqStar) barcodeReqStar.style.display = 'none';
        if (barcodeOptTag) barcodeOptTag.style.display = '';
        if (barcodeInput) barcodeInput.required = false;
        if (stockInput) stockInput.required = false;
        if (unitHint) {
            unitHint.style.display = 'block';
            unitHint.className = 'unit-hint unit-hint-weight mt-1';
            unitHint.innerHTML = '<i class="fas fa-info-circle me-1"></i>Stock will be set to <strong>1</strong> automatically. Barcode is optional.';
        }
    } else {
        if (stockWrapper) stockWrapper.style.display = 'block';
        if (barcodeReqStar) barcodeReqStar.style.display = 'inline';
        if (barcodeOptTag) barcodeOptTag.style.display = 'none';
        if (barcodeInput) barcodeInput.required = true;
        if (stockInput) stockInput.required = true;
        if (unitHint) {
            unitHint.style.display = 'block';
            unitHint.className = 'unit-hint unit-hint-packaged mt-1';
            unitHint.innerHTML = '<i class="fas fa-box me-1"></i>Packaged product — enter stock quantity and barcode.';
        }
    }
}

function checkExpirationWarning() {
    const val = document.getElementById('productExpiration')?.value;
    const warning = document.getElementById('expirationWarning');
    if (!warning) return;

    if (!val) {
        warning.style.display = 'none';
        return;
    }
    const diff = Math.ceil((new Date(val) - new Date()) / 86400000);
    if (diff < 0) {
        warning.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle me-1"></i>Date is already expired!</span>';
        warning.style.display = 'block';
    } else if (diff <= 30) {
        warning.innerHTML = `<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>Expires in ${diff} day(s)!</span>`;
        warning.style.display = 'block';
    } else if (diff <= 90) {
        warning.innerHTML = `<span class="text-info"><i class="fas fa-clock me-1"></i>~${Math.ceil(diff / 30)} month(s) left</span>`;
        warning.style.display = 'block';
    } else {
        warning.style.display = 'none';
    }
}

// ==================== CATEGORY FUNCTIONS ====================

function loadCategories() {
    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { action: 'get_categories' },
        dataType: 'json',
        success: function (response) {
            hideLoading();
            if (response.success) {
                categories = response.data;
                displayCategories(categories);
            } else {
                showToast('error', 'Error', response.message || 'Failed to load categories');
            }
        },
        error: function (xhr, status, error) {
            hideLoading();
            showToast('error', 'Error', 'Failed to connect to server');
            console.error(error);
        }
    });
}

function displayCategories(categories) {
    const container = document.getElementById('categoriesContainer');
    if (!container) return;

    if (!categories || categories.length === 0) {
        container.innerHTML = `<div class="text-center py-5"><i class="fas fa-layer-group fa-3x text-muted mb-3"></i><h5>No Categories Found</h5><p class="text-muted">Click "New Category" to create your first category</p></div>`;
        return;
    }
    let html = '';
    categories.forEach(category => {
        const isActive = currentCategoryId == category.id;
        html += `
            <div class="category-card ${isActive ? 'active' : ''}" onclick="selectCategory(${category.id}, '${escapeHtml(category.name).replace(/'/g, "\\'")}')">
                <div class="category-actions">
                    <button class="btn-icon" onclick="event.stopPropagation(); editCategory(${category.id})" title="Edit Category"><i class="fas fa-edit"></i></button>
                    <button class="btn-icon archive" onclick="event.stopPropagation(); archiveCategory(${category.id})" title="Archive Category"><i class="fas fa-box-archive"></i></button>
                </div>
                <div class="category-icon-small" style="background: ${category.color}"><i class="fas ${category.icon || 'fa-paw'}"></i></div>
                <h3>${escapeHtml(category.name)}</h3>
                <p>${category.description ? escapeHtml(category.description.substring(0, 30) + (category.description.length > 30 ? '...' : '')) : 'No description'}</p>
                <div class="category-stats">
                    <div class="stat-item"><div class="stat-value">${category.product_count || 0}</div><div class="stat-label">Products</div></div>
                    <div class="stat-item"><div class="stat-value"><span class="badge bg-${category.status === 'active' ? 'success' : 'secondary'}">${category.status}</span></div><div class="stat-label">Status</div></div>
                </div>
            </div>`;
    });
    container.innerHTML = html;
}

function selectCategory(categoryId, categoryName) {
    currentCategoryId = categoryId;
    currentCategoryName = categoryName;

    const selectedNameSpan = document.getElementById('selectedCategoryName');
    const addProductSpan = document.getElementById('addProductCategoryName');
    const productsSection = document.getElementById('productsSection');

    if (selectedNameSpan) selectedNameSpan.textContent = categoryName;
    if (addProductSpan) addProductSpan.textContent = categoryName;
    if (productsSection) productsSection.style.display = 'block';

    const addProductBtn = document.getElementById('addProductBtn');
    if (addProductBtn) addProductBtn.style.display = 'inline-block';

    document.querySelectorAll('.category-card').forEach(card => card.classList.remove('active'));
    if (event && event.currentTarget) event.currentTarget.classList.add('active');

    loadCategoryProducts(categoryId);
}

function showExpiredProducts() {
    currentCategoryId = 'expired';
    currentCategoryName = 'Expired Products';

    const selectedNameSpan = document.getElementById('selectedCategoryName');
    const productsSection = document.getElementById('productsSection');
    const addProductBtn = document.getElementById('addProductBtn');

    if (selectedNameSpan) selectedNameSpan.textContent = 'Expired Products';
    if (productsSection) productsSection.style.display = 'block';
    if (addProductBtn) addProductBtn.style.display = 'none';

    document.querySelectorAll('.category-card').forEach(card => card.classList.remove('active'));

    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { action: 'get_expired_products' },
        dataType: 'json',
        success: function (response) {
            hideLoading();
            if (response.success) {
                displayProducts(response.data);
            } else {
                showToast('error', 'Error', response.message);
            }
        },
        error: function () {
            hideLoading();
            showToast('error', 'Error', 'Failed to load expired products');
        }
    });
}

function editCategory(categoryId) {
    openCategoryModal(categoryId);
}

function archiveCategory(categoryId) {
    // ── Transaction guard ─────────────────────────────────────────
    // Before asking for confirmation, check whether this category has
    // any products that appear in past (completed) transactions.
    // If it does, archiving is blocked to preserve sales history integrity.
    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { action: 'check_category_transactions', id: categoryId },
        dataType: 'json',
        success: function (response) {
            hideLoading();

            if (!response.success) {
                showToast('error', 'Error', response.message || 'Could not verify transaction history.');
                return;
            }

            if (response.has_transactions) {
                // Blocked — category has past transactions
                const txCount = response.transaction_count || 0;
                const prodCount = response.product_count || 0;
                showBlockedModal(categoryId, txCount, prodCount);
                return;
            }

            // Safe to archive — confirm then proceed
            showArchiveConfirmModal(categoryId);
        },
        error: function () {
            hideLoading();
            showToast('error', 'Error', 'Failed to check transaction history. Please try again.');
        }
    });
}

// Show a styled block modal instead of a plain alert
function showBlockedModal(categoryId, txCount, prodCount) {
    // Remove any existing instance
    const existing = document.getElementById('archiveBlockedModal');
    if (existing) existing.remove();

    const html = `
    <div class="modal fade" id="archiveBlockedModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,#991b1b,#b91c1c);color:white;border-radius:14px 14px 0 0;">
                    <h5 class="modal-title"><i class="fas fa-ban me-2"></i>Cannot Archive Category</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-start gap-3 mb-3 p-3 rounded" style="background:#fef2f2;border:1px solid #fecaca;">
                        <i class="fas fa-exclamation-triangle fa-2x mt-1" style="color:#dc2626;flex-shrink:0;"></i>
                        <div>
                            <strong style="color:#991b1b;">This category has past transaction records.</strong>
                            <p class="mb-0 mt-1" style="font-size:13px;color:#7f1d1d;">
                                Archiving it would break your sales history. 
                                The category and its products must remain active to keep records intact.
                            </p>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="text-center p-3 rounded" style="background:#f8fafc;border:1px solid #e2e8f0;">
                                <div style="font-size:24px;font-weight:700;color:#1e293b;">${txCount.toLocaleString()}</div>
                                <div style="font-size:12px;color:#64748b;">Past Transactions</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-center p-3 rounded" style="background:#f8fafc;border:1px solid #e2e8f0;">
                                <div style="font-size:24px;font-weight:700;color:#1e293b;">${prodCount.toLocaleString()}</div>
                                <div style="font-size:12px;color:#64748b;">Products Affected</div>
                            </div>
                        </div>
                    </div>
                    <p style="font-size:13px;color:#475569;">
                        <i class="fas fa-info-circle me-1" style="color:#8B4513;"></i>
                        If you want to stop using this category, set its status to <strong>Inactive</strong> instead.
                        This hides it from the POS without deleting any records.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-sm" style="background:#8B4513;color:white;border:none;"
                        onclick="setInactiveInstead(${categoryId})">
                        <i class="fas fa-eye-slash me-1"></i>Set Inactive Instead
                    </button>
                </div>
            </div>
        </div>
    </div>`;

    document.body.insertAdjacentHTML('beforeend', html);
    const modal = new bootstrap.Modal(document.getElementById('archiveBlockedModal'));
    modal.show();
}

// Offer a one-click shortcut to set the category inactive
function setInactiveInstead(categoryId) {
    const blockedModal = bootstrap.Modal.getInstance(document.getElementById('archiveBlockedModal'));
    if (blockedModal) blockedModal.hide();

    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { action: 'update_category_status', id: categoryId, status: 'inactive' },
        dataType: 'json',
        success: function (response) {
            hideLoading();
            if (response.success) {
                showToast('success', 'Done', 'Category set to Inactive. It will no longer appear in the POS.');
                loadCategories();
            } else {
                showToast('error', 'Error', response.message || 'Failed to update status.');
            }
        },
        error: function () {
            hideLoading();
            showToast('error', 'Error', 'Failed to connect to server.');
        }
    });
}

// Confirmation modal before archiving (shown only when safe)
function showArchiveConfirmModal(categoryId) {
    const existing = document.getElementById('archiveConfirmModal');
    if (existing) existing.remove();

    const html = `
    <div class="modal fade" id="archiveConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,#2c5530,#8B4513);color:white;border-radius:14px 14px 0 0;">
                    <h5 class="modal-title"><i class="fas fa-box-archive me-2"></i>Archive Category</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-start gap-3 p-3 rounded" style="background:#fff8eb;border:1px solid #fde8c8;">
                        <i class="fas fa-box-archive fa-2x mt-1" style="color:#8B4513;flex-shrink:0;"></i>
                        <div>
                            <strong style="color:#6B3410;">This category has no transaction records.</strong>
                            <p class="mb-0 mt-1" style="font-size:13px;color:#78350f;">
                                All products in this category will also be archived. 
                                You can restore them from the Archive page anytime.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-sm" style="background:#8B4513;color:white;border:none;"
                        onclick="confirmArchiveCategory(${categoryId})">
                        <i class="fas fa-box-archive me-1"></i>Yes, Archive It
                    </button>
                </div>
            </div>
        </div>
    </div>`;

    document.body.insertAdjacentHTML('beforeend', html);
    const modal = new bootstrap.Modal(document.getElementById('archiveConfirmModal'));
    modal.show();
}

function confirmArchiveCategory(categoryId) {
    const confirmModal = bootstrap.Modal.getInstance(document.getElementById('archiveConfirmModal'));
    if (confirmModal) confirmModal.hide();

    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { action: 'archive_category', id: categoryId },
        dataType: 'json',
        success: function (response) {
            hideLoading();
            if (response.success) {
                showToast('success', 'Archived', response.message);
                loadCategories();
                if (currentCategoryId === categoryId) {
                    currentCategoryId = null;
                    const productsSection = document.getElementById('productsSection');
                    if (productsSection) productsSection.style.display = 'none';
                }
            } else {
                showToast('error', 'Error', response.message);
            }
        },
        error: function () {
            hideLoading();
            showToast('error', 'Error', 'Failed to archive category');
        }
    });
}

// ==================== PRODUCT FUNCTIONS ====================

function loadCategoryProducts(categoryId) {
    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { action: 'get_category_products', category_id: categoryId },
        dataType: 'json',
        success: function (response) {
            hideLoading();
            if (response.success) {
                displayProducts(response.data);
            } else {
                showToast('error', 'Error', response.message);
            }
        },
        error: function () {
            hideLoading();
            showToast('error', 'Error', 'Failed to load products');
        }
    });
}

function getDaysInBadgeHtml(product) {
    // Use server-computed days_in_stock (from DATEDIFF in SQL).
    // Fall back to JS calculation only if the field is missing.
    let days = null;

    if (product.days_in_stock !== undefined && product.days_in_stock !== null) {
        days = parseInt(product.days_in_stock);
    } else {
        const rawDate = product.date_added || product.created_at;
        if (rawDate) {
            const added = new Date(rawDate);
            const today = new Date();
            days = Math.floor((today - added) / (1000 * 60 * 60 * 24));
        }
    }

    if (days === null || isNaN(days) || days < 0) days = 0;

    let cls = 'days-in-fresh';
    let icon = 'fa-seedling';
    if (days > 90) { cls = 'days-in-critical'; icon = 'fa-fire'; }
    else if (days > 60) { cls = 'days-in-aging'; icon = 'fa-exclamation-triangle'; }
    else if (days > 30) { cls = 'days-in-moderate'; icon = 'fa-clock'; }

    return `<span class="days-in-badge ${cls}"><i class="fas ${icon} me-1"></i>${days}d</span>`;
}

function displayProducts(products) {
    const tbody = document.getElementById('productsTableBody');
    if (!tbody) return;

    if (!products || products.length === 0) {
        tbody.innerHTML = `<tr><td colspan="10" class="text-center py-4"><i class="fas fa-box-open fa-2x text-muted mb-2"></i><p class="text-muted">No products in this category. Click "Add Product to ${escapeHtml(currentCategoryName)}" to add one.</p></td></tr>`;
        return;
    }

    let html = '';
    products.forEach(product => {
        const unitType = getUnitTypeFromValue(product.unit);
        const isWeight = (unitType === 'weight');

        // Stock display
        let stockHtml;
        if (isWeight) {
            stockHtml = '<span class="unit-badge">Per unit</span>';
        } else {
            let stockClass = 'stock-good';
            const stockValue = parseInt(product.stock) || 0;
            if (stockValue <= 0) stockClass = 'stock-out';
            else if (stockValue <= 5) stockClass = 'stock-low';
            else if (stockValue <= 15) stockClass = 'stock-medium';
            stockHtml = `<span class="stock-badge ${stockClass}">${stockValue <= 0 ? 'Out' : stockValue}</span>`;
        }

        // Unit display
        const unitHtml = product.unit ? `<span class="unit-badge">${escapeHtml(product.unit)}</span>` : '<span class="text-muted">—</span>';

        // Cost price display
        const costHtml = product.cost_price && parseFloat(product.cost_price) > 0
            ? `<span class="cost-text">₱${parseFloat(product.cost_price).toFixed(2)}</span>`
            : '<span class="text-muted">—</span>';

        // Expiration display
        let expiryHtml = '<span class="text-muted">—</span>';
        if (product.expiration_date && product.expiration_date !== '0000-00-00' && product.expiration_date !== null) {
            const exp = new Date(product.expiration_date);
            const today = new Date();
            const diff = Math.ceil((exp - today) / (1000 * 60 * 60 * 24));
            const fmt = exp.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });

            if (diff < 0) {
                expiryHtml = `<span class="expiry-badge expiry-expired"><i class="fas fa-times-circle me-1"></i>Expired</span><small class="d-block text-muted">${fmt}</small>`;
            } else if (diff <= 30) {
                expiryHtml = `<span class="expiry-badge expiry-soon"><i class="fas fa-exclamation-triangle me-1"></i>${diff}d</span><small class="d-block text-muted">${fmt}</small>`;
            } else if (diff <= 90) {
                expiryHtml = `<span class="expiry-badge expiry-warning"><i class="fas fa-clock me-1"></i>${Math.ceil(diff / 30)}mo</span><small class="d-block text-muted">${fmt}</small>`;
            } else {
                expiryHtml = `<span class="expiry-badge expiry-good"><i class="fas fa-check-circle me-1"></i>Good</span><small class="d-block text-muted">${fmt}</small>`;
            }
        }

        const currentStatus = product.status || 'active';
        const displayStatus = currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1);
        const statusClass = currentStatus === 'active' ? 'status-active' : 'status-inactive';

        // Image thumbnail
        let imageHtml;
        if (product.image) {
            imageHtml = `<img src="${escapeHtml(product.image)}" alt="${escapeHtml(product.name)}" class="product-thumb" loading="lazy">`;
        } else {
            imageHtml = `<div class="product-thumb-placeholder" style="background:${product.category_color || '#4a6fa5'}"><i class="fas fa-box"></i></div>`;
        }

        html += `
            <tr>
                <td>${imageHtml}</td>
                <td>
                    <div class="d-flex align-items-center">
                        <div>
                            <strong>${escapeHtml(product.name)}</strong><br>
                            <small class="text-muted">${escapeHtml(product.category_name || '')}</small>
                            ${product.sku ? `<small class="text-muted d-block" style="font-family:monospace;">${escapeHtml(product.sku)}</small>` : ''}
                            ${product.barcode ? `<small class="text-muted d-block" style="font-family:monospace;"><i class="fas fa-barcode me-1"></i>${escapeHtml(product.barcode)}</small>` : ''}
                        </div>
                    </div>
                </td>
                <td>${unitHtml}</td>
                <td>${costHtml}</td>
                <td><strong>₱${parseFloat(product.price).toFixed(2)}</strong></td>
                <td>${stockHtml}</td>
                <td class="days-in-cell">${getDaysInBadgeHtml(product)}</td>
                <td>${expiryHtml}</td>
                <td><span class="status-badge ${statusClass}">${displayStatus}</span></td>
                <td>
                    ${isOwner ? `<button class="btn-icon" onclick="editProduct(${product.id})" title="Edit Product"><i class="fas fa-edit"></i></button>` : ''}
                    <button class="btn-icon" onclick="openStockModal(${product.id},'${escapeHtml(product.name).replace(/'/g, "\\'")}',${product.stock || 0}, '${currentStatus}')" title="Manage Stock & Status"><i class="fas ${isWeight ? 'fa-toggle-on' : 'fa-boxes'}"></i></button>
                    <button class="btn-icon archive" onclick="archiveProduct(${product.id})" title="Move to Archive"><i class="fas fa-box-archive"></i></button>
                </td>
            </tr>`;
    });
    tbody.innerHTML = html;
}

function archiveProduct(productId) {
    if (confirm('Move this product to archive?')) {
        showLoading();
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: { action: 'archive_product', id: productId },
            dataType: 'json',
            success: function (response) {
                hideLoading();
                if (response.success) {
                    showToast('success', 'Archived', response.message);
                    if (currentCategoryId) loadCategoryProducts(currentCategoryId);
                } else {
                    showToast('error', 'Error', response.message);
                }
            },
            error: function () {
                hideLoading();
                showToast('error', 'Error', 'Failed to archive product');
            }
        });
    }
}

function editProduct(productId) {
    openProductModal(productId);
}

function saveProduct() {
    // Sync hidden unit field from the two visible fields
    buildUnitString();

    const productId = document.getElementById('productId')?.value;
    const categoryId = document.getElementById('productCategory')?.value;
    const productName = document.getElementById('productName')?.value;
    const description = document.getElementById('productDescription')?.value;
    const price = document.getElementById('productPrice')?.value;
    const costPrice = document.getElementById('productCostPrice')?.value;
    const unit = document.getElementById('productUnit')?.value;
    const barcode = document.getElementById('productBarcode') ? document.getElementById('productBarcode').value.trim() : '';
    const sku = document.getElementById('productSku') ? document.getElementById('productSku').value : '';
    const expiration = document.getElementById('productExpiration')?.value || '';
    const unitType = getUnitType();

    let stock = 0;
    if (unitType === 'weight') {
        stock = 1;
    } else {
        stock = document.getElementById('productStock')?.value || 0;
    }

    const formData = {
        action: productId ? 'update_product' : 'create_product',
        id: productId,
        category_id: categoryId,
        name: productName,
        description: description,
        price: price,
        cost_price: costPrice || 0,
        stock: stock,
        unit: unit,
        sku: sku,
        barcode: barcode,
        image: document.getElementById('productImage')?.value || '',
        expiration_date: expiration,
        date_added: document.getElementById('productDateAdded')?.value || ''
    };

    // Validation
    if (!formData.category_id) {
        showToast('warning', 'Warning', 'Please select a category');
        return;
    }
    if (!formData.name) {
        showToast('warning', 'Warning', 'Product name is required');
        return;
    }
    if (!unit) {
        showToast('warning', 'Warning', 'Please select a unit / measurement');
        return;
    }
    if (!price || parseFloat(price) <= 0) {
        showToast('warning', 'Warning', 'Selling price is required and must be greater than 0');
        return;
    }
    // Cost price must be LESS than selling price
    if (costPrice && parseFloat(costPrice) > 0 && parseFloat(costPrice) >= parseFloat(price)) {
        showToast('error', 'Invalid Price', 'Cost price must be less than the selling price');
        // Highlight both fields
        const costField = document.getElementById('productCostPrice');
        const priceField = document.getElementById('productPrice');
        if (costField) { costField.classList.add('is-invalid'); costField.focus(); }
        if (priceField) priceField.classList.add('is-invalid');
        // Clear error styling on input
        [costField, priceField].forEach(f => {
            if (f) f.addEventListener('input', function clearErr() {
                f.classList.remove('is-invalid');
                f.removeEventListener('input', clearErr);
            }, { once: true });
        });
        return;
    }
    if ((unitType === 'packaged' || unitType === 'medicine') && !barcode) {
        showToast('warning', 'Warning', 'Barcode is required for packaged and medicine products');
        return;
    }
    if (!productId && (unitType === 'packaged' || unitType === 'medicine') && (!stock || parseInt(stock) < 1)) {
        showToast('warning', 'Warning', 'Please enter the stock quantity');
        return;
    }

    // Check if there's a pending image file to upload first
    const imageFileInput = document.getElementById('imageFileInput');
    const pendingFile = imageFileInput?.files?.[0];

    if (pendingFile) {
        // Upload image first, then save product
        showLoading();
        const imgFormData = new FormData();
        imgFormData.append('image', pendingFile);

        $.ajax({
            url: 'ajax/upload_product_image.php',
            method: 'POST',
            data: imgFormData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (imgResponse) {
                if (imgResponse.success) {
                    formData.image = imgResponse.image_path;
                    document.getElementById('productImage').value = imgResponse.image_path;
                    doSaveProduct(formData);
                } else {
                    hideLoading();
                    showToast('error', 'Image Error', imgResponse.message || 'Failed to upload image');
                }
            },
            error: function () {
                hideLoading();
                showToast('error', 'Error', 'Failed to upload image');
            }
        });
    } else {
        doSaveProduct(formData);
    }
}

function doSaveProduct(formData) {
    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: formData,
        dataType: 'json',
        timeout: 10000,
        success: function (response) {
            hideLoading();
            if (response.success) {
                showToast('success', 'Success', response.message);
                const modal = bootstrap.Modal.getInstance(document.getElementById('productModal'));
                if (modal) modal.hide();
                document.getElementById('productForm')?.reset();
                document.getElementById('productId').value = '';
                resetImageUpload();
                if (currentCategoryId) loadCategoryProducts(currentCategoryId);
                loadCategories();
            } else {
                showToast('error', 'Error', response.message || 'Failed to save product');
            }
        },
        error: function (xhr, status, error) {
            hideLoading();
            console.error('AJAX Error:', { url: ajaxUrl, status, error, statusCode: xhr.status, responseText: xhr.responseText });
            let errorMsg = 'Failed to connect to server. ';
            if (xhr.status === 404) errorMsg += 'File not found at: ' + ajaxUrl;
            else if (xhr.status === 500) errorMsg += 'Server error. Check error log.';
            else if (status === 'timeout') errorMsg += 'Connection timeout.';
            else errorMsg += 'Status: ' + xhr.status;
            showToast('error', 'Error', errorMsg);
        }
    });
}

// ==================== MODAL FUNCTIONS ====================

function populateIconSelector() {
    const container = document.getElementById('iconSelector');
    if (!container) return;

    let html = '';
    availableIcons.forEach(icon => {
        html += `<div class="icon-option" onclick="selectIcon('${icon}')" title="${icon.replace('fa-', '')}"><i class="fas ${icon}"></i></div>`;
    });
    container.innerHTML = html;
}

function selectIcon(icon) {
    const iconInput = document.getElementById('categoryIcon');
    if (iconInput) iconInput.value = icon;
    document.querySelectorAll('.icon-option').forEach(opt => opt.classList.remove('selected'));
    if (event && event.currentTarget) event.currentTarget.classList.add('selected');
}

function openCategoryModal(categoryId = null) {
    const form = document.getElementById('categoryForm');
    if (form) form.reset();

    const idInput = document.getElementById('categoryId');
    if (idInput) idInput.value = '';

    const modalTitle = document.getElementById('categoryModalTitle');
    if (modalTitle) modalTitle.innerHTML = '<i class="fas fa-layer-group me-2"></i>Add New Category';

    // Reset icon selection
    document.querySelectorAll('.icon-option').forEach(opt => opt.classList.remove('selected'));
    const defaultIcon = document.querySelector('.icon-option[onclick*="fa-paw"]');
    if (defaultIcon) defaultIcon.classList.add('selected');
    const iconField = document.getElementById('categoryIcon');
    if (iconField) iconField.value = 'fa-paw';

    if (categoryId) {
        showLoading();
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: { action: 'get_category', id: categoryId },
            dataType: 'json',
            success: function (response) {
                hideLoading();
                if (response.success) {
                    const cat = response.data;

                    const idField = document.getElementById('categoryId');
                    const nameField = document.getElementById('categoryName');
                    const descField = document.getElementById('categoryDescription');
                    const colorField = document.getElementById('categoryColor');
                    const statusField = document.getElementById('categoryStatus');
                    const iconField = document.getElementById('categoryIcon');

                    if (idField) idField.value = cat.id;
                    if (nameField) nameField.value = cat.name;
                    if (descField) descField.value = cat.description || '';
                    if (colorField) colorField.value = cat.color || '#4a6fa5';
                    if (statusField) statusField.value = cat.status || 'active';
                    if (iconField) iconField.value = cat.icon || 'fa-paw';

                    // Highlight selected icon
                    document.querySelectorAll('.icon-option').forEach(opt => {
                        opt.classList.remove('selected');
                        if (opt.getAttribute('onclick') && opt.getAttribute('onclick').includes(cat.icon || 'fa-paw')) {
                            opt.classList.add('selected');
                        }
                    });

                    if (modalTitle) modalTitle.innerHTML = '<i class="fas fa-edit me-2"></i>Edit Category';

                    // Only open modal AFTER data is populated
                    const modal = new bootstrap.Modal(document.getElementById('categoryModal'));
                    modal.show();
                } else {
                    showToast('error', 'Error', response.message || 'Failed to load category');
                }
            },
            error: function () {
                hideLoading();
                showToast('error', 'Error', 'Failed to load category');
            }
        });
    } else {
        // New category — open immediately
        const modal = new bootstrap.Modal(document.getElementById('categoryModal'));
        modal.show();
    }
}

function saveCategory() {
    const categoryId = document.getElementById('categoryId')?.value;
    const formData = {
        action: categoryId ? 'update_category' : 'create_category',
        id: categoryId,
        name: document.getElementById('categoryName')?.value?.trim(),
        description: document.getElementById('categoryDescription')?.value,
        icon: document.getElementById('categoryIcon')?.value,
        color: document.getElementById('categoryColor')?.value,
        status: document.getElementById('categoryStatus')?.value
    };

    if (!formData.name) {
        showToast('warning', 'Warning', 'Category name is required');
        return;
    }

    // ── Duplicate name check (client-side, fast path) ───────────
    // Only runs when the categories array is populated.
    // The server also enforces uniqueness so this is just UX polish.
    // When editing, skip the check for the category's own current name.
    if (categories && categories.length > 0) {
        const nameNormalized = formData.name.toLowerCase();
        const duplicate = categories.find(cat => {
            // If editing, exclude the current category from the comparison
            const isItself = categoryId && String(cat.id) === String(categoryId);
            return !isItself && cat.name.toLowerCase() === nameNormalized;
        });
        if (duplicate) {
            showToast('error', 'Duplicate Name',
                `A category named "${duplicate.name}" already exists. Please use a different name.`);
            const nameField = document.getElementById('categoryName');
            if (nameField) {
                nameField.classList.add('is-invalid');
                nameField.focus();
                let feedback = nameField.parentElement.querySelector('.invalid-feedback');
                if (!feedback) {
                    feedback = document.createElement('div');
                    feedback.className = 'invalid-feedback';
                    nameField.parentElement.appendChild(feedback);
                }
                feedback.textContent = `"${duplicate.name}" already exists.`;
                nameField.addEventListener('input', function clearError() {
                    nameField.classList.remove('is-invalid');
                    if (feedback) feedback.textContent = '';
                    nameField.removeEventListener('input', clearError);
                }, { once: true });
            }
            return;
        }
    }
    // ─────────────────────────────────────────────────────────────

    console.log('[saveCategory] formData:', formData);
    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function (response) {
            hideLoading();
            console.log('[saveCategory] response:', response);
            if (response.success) {
                showToast('success', 'Success', response.message);
                const modal = bootstrap.Modal.getInstance(document.getElementById('categoryModal'));
                if (modal) modal.hide();
                loadCategories();
                loadCategoryOptions();
            } else {
                showToast('error', 'Error', response.message);
            }
        },
        error: function (xhr) {
            hideLoading();
            console.error('[saveCategory] AJAX error:', xhr.status, xhr.responseText);
            showToast('error', 'Error', 'Failed to save category');
        }
    });
}

function loadCategoryOptions() {
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { action: 'get_categories' },
        dataType: 'json',
        success: function (response) {
            if (response.success) {
                const select = document.getElementById('productCategory');
                if (!select) return;

                let options = '<option value="">Select Category</option>';
                response.data.forEach(category => {
                    if (category.status === 'active') {
                        options += `<option value="${category.id}">${escapeHtml(category.name)}</option>`;
                    }
                });
                select.innerHTML = options;
            }
        }
    });
}

function openProductModal(productId = null) {
    if (!currentCategoryId && !productId) {
        showToast('warning', 'Warning', 'Please select a category first');
        return;
    }

    const form = document.getElementById('productForm');
    if (form) form.reset();

    const idField = document.getElementById('productId');
    if (idField) idField.value = '';

    const modalTitle = document.getElementById('productModalTitle');
    if (modalTitle) modalTitle.innerHTML = '<i class="fas fa-box me-2"></i>Add New Product';

    // Reset UI elements
    const stockWrapper = document.getElementById('stockFieldWrapper');
    const expirationRow = document.getElementById('expirationRow');
    const unitHint = document.getElementById('unitHint');
    const barcodeReqStar = document.getElementById('barcodeRequiredStar');
    const barcodeOptTag = document.getElementById('barcodeOptionalTag');
    const expirationWarning = document.getElementById('expirationWarning');
    const unitQtyWrapper = document.getElementById('unitQtyWrapper');
    const unitPreview = document.getElementById('unitPreview');
    const unitTypeSelect = document.getElementById('productUnitType');
    const unitQtyInput = document.getElementById('productUnitQty');
    const unitHidden = document.getElementById('productUnit');

    if (stockWrapper) stockWrapper.style.display = 'none';
    if (expirationRow) expirationRow.style.display = '';
    if (unitHint) unitHint.style.display = 'none';
    if (barcodeReqStar) barcodeReqStar.style.display = 'none';
    if (barcodeOptTag) barcodeOptTag.style.display = '';
    if (expirationWarning) expirationWarning.style.display = 'none';
    if (unitQtyWrapper) unitQtyWrapper.style.display = 'none';
    if (unitPreview) unitPreview.style.display = 'none';
    if (unitTypeSelect) unitTypeSelect.value = '';
    if (unitQtyInput) unitQtyInput.value = '1';
    if (unitHidden) unitHidden.value = '';

    // Reset image upload
    resetImageUpload();

    const categorySelect = document.getElementById('productCategory');
    if (currentCategoryId && categorySelect) {
        categorySelect.value = currentCategoryId;
        onCategoryChange();
    }

    if (productId) {
        showLoading();
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: { action: 'get_product', id: productId },
            dataType: 'json',
            success: function (response) {
                hideLoading();
                if (response.success) {
                    const product = response.data;

                    const idField = document.getElementById('productId');
                    const catField = document.getElementById('productCategory');
                    const nameField = document.getElementById('productName');
                    const descField = document.getElementById('productDescription');
                    const priceField = document.getElementById('productPrice');
                    const costField = document.getElementById('productCostPrice');
                    const unitField = document.getElementById('productUnit');
                    const stockField = document.getElementById('productStock');
                    const skuField = document.getElementById('productSku');
                    const barcodeField = document.getElementById('productBarcode');
                    const expField = document.getElementById('productExpiration');

                    if (idField) idField.value = product.id;
                    if (catField) catField.value = product.category_id;
                    if (nameField) nameField.value = product.name;
                    if (descField) descField.value = product.description || '';
                    if (priceField) priceField.value = product.price;
                    if (costField) costField.value = product.cost_price || '';
                    // Parse unit into two fields
                    if (product.unit) {
                        const parsed = parseStoredUnit(product.unit);
                        const utSel = document.getElementById('productUnitType');
                        const utQty = document.getElementById('productUnitQty');
                        if (utSel) utSel.value = parsed.unitType;
                        if (utQty) utQty.value = parsed.qty;
                    }
                    if (unitField) unitField.value = product.unit || '';
                    if (stockField) stockField.value = product.stock || 0;
                    if (skuField) skuField.value = product.sku || '';
                    if (barcodeField) barcodeField.value = product.barcode || '';
                    if (expField && product.expiration_date && product.expiration_date !== '0000-00-00') {
                        expField.value = product.expiration_date.substring(0, 10);
                    }

                    // Populate date_added
                    const dateAddedField = document.getElementById('productDateAdded');
                    if (dateAddedField && product.date_added && product.date_added !== '0000-00-00') {
                        dateAddedField.value = product.date_added.substring(0, 10);
                    }

                    onUnitTypeChange();
                    if (stockWrapper) stockWrapper.style.display = 'block';
                    checkExpirationWarning();
                    if (modalTitle) modalTitle.innerHTML = '<i class="fas fa-edit me-2"></i>Edit Product';

                    // Load existing image
                    const imgField = document.getElementById('productImage');
                    if (imgField) imgField.value = product.image || '';
                    if (product.image) {
                        showImagePreview(product.image);
                    } else {
                        resetImageUpload();
                    }

                    const modal = new bootstrap.Modal(document.getElementById('productModal'));
                    modal.show();
                } else {
                    showToast('error', 'Error', response.message || 'Failed to load product');
                }
            },
            error: function (xhr, status, error) {
                hideLoading();
                showToast('error', 'Error', 'Failed to connect to server');
                console.error('AJAX Error:', error);
            }
        });
    } else {
        const categorySelect = document.getElementById('productCategory');
        const selectedOption = categorySelect?.options[categorySelect.selectedIndex];
        const categoryText = selectedOption ? selectedOption.text : 'PRD';
        const categoryCode = categoryText.substring(0, 3).toUpperCase();
        // Use last 5 digits of timestamp + 3 random digits for a collision-safe SKU
        const timePart = Date.now().toString().slice(-5);
        const randomPart = Math.floor(100 + Math.random() * 900);
        const skuField = document.getElementById('productSku');
        if (skuField) {
            skuField.value = categoryCode + '-' + timePart + randomPart;
        }

        // Default date_added to today
        const dateAddedField = document.getElementById('productDateAdded');
        if (dateAddedField) {
            dateAddedField.value = new Date().toISOString().substring(0, 10);
        }

        const modal = new bootstrap.Modal(document.getElementById('productModal'));
        modal.show();
    }
}

// ==================== STOCK FUNCTIONS ====================

function openStockModal(productId, productName, currentStock, currentStatus) {
    const idField = document.getElementById('stockProductId');
    const nameField = document.getElementById('stockProductName');
    const stockField = document.getElementById('currentStock');
    const quantityField = document.getElementById('stockQuantity');
    const statusField = document.getElementById('stockProductStatus');
    const actionField = document.getElementById('stockAction');

    if (idField) idField.value = productId;
    if (nameField) nameField.textContent = productName;
    if (stockField) stockField.textContent = currentStock;
    if (quantityField) quantityField.value = '';

    if (statusField) {
        statusField.value = currentStatus || 'active';
    }
    
    // Fetch and render batches
    refreshBatchList(productId);

    const isWeightProduct = (parseInt(currentStock) === 1);

    if (actionField) {
        // Bind UI toggling to batch radios as well
        document.getElementById('batchActionNew').addEventListener('change', toggleBatchFields);
        document.getElementById('batchActionExisting').addEventListener('change', toggleBatchFields);
        
        actionField.onchange = toggleBatchFields;
        
        // Default state
        actionField.value = isWeightProduct ? 'none' : 'add';
        toggleBatchFields();
    }

    const modalEl = document.getElementById('stockModal');
    let modal = bootstrap.Modal.getInstance(modalEl);
    if (!modal) modal = new bootstrap.Modal(modalEl);
    modal.show();
}

/**
 * Refresh the batch list inside the stock modal without re-creating the modal.
 */
function refreshBatchList(productId) {
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { action: 'get_product_batches', id: productId },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                const addSelect = document.getElementById('existingBatchSelect');
                const removeSelect = document.getElementById('removeBatchSelect');
                const batchesList = document.getElementById('batchesList');
                const displayContainer = document.getElementById('displayBatchesContainer');

                if (displayContainer && batchesList) {
                    batchesList.innerHTML = '';
                    if (response.data.length > 0) {
                        displayContainer.style.display = 'block';
                        response.data.forEach((batch, index) => {
                            let isFirst = index === 0;
                            let firstText = isFirst ? `<span class="text-primary fw-bold me-2" style="font-size:11px; width:70px; display:inline-block;"><i class="fas fa-play me-1"></i>SELL 1ST</span>` : `<span class="text-muted fw-semibold me-2" style="font-size:11px; width:70px; display:inline-block;">Batch ${index + 1}</span>`;
                            let expDisplay = batch.expiration_date ? `Exp ${batch.expiration_date}` : `<span class="badge bg-secondary text-white bg-opacity-25 text-opacity-75">No expiry</span>`;
                            
                            let rowBg = isFirst ? 'bg-white border-primary border-start border-3 shadow-sm' : 'bg-white border-light border';
                            
                            batchesList.innerHTML += `
                                <div class="d-flex justify-content-between align-items-center p-2 rounded ${rowBg} batch-row-clickable" 
                                     data-batch-id="${batch.id}" data-batch-exp="${batch.expiration_date || ''}" 
                                     data-product-id="${productId}"
                                     onclick="toggleBatchExpiryEditor(this)" 
                                     style="cursor:pointer; transition: background 0.15s;" 
                                     title="Click to edit expiration date">
                                    <div class="d-flex align-items-center flex-grow-1">
                                        ${firstText}
                                        <strong class="text-dark me-3" style="font-size:13px; width: 60px;">${batch.stock} units</strong>
                                        <span style="font-size:12px;" class="text-muted batch-exp-display">${expDisplay}</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span style="font-size:11px;" class="text-muted">recv: ${batch.date_added}</span>
                                        <i class="fas fa-pen text-muted" style="font-size:10px; opacity:0.5;"></i>
                                    </div>
                                </div>
                                <div class="batch-expiry-editor px-2 pb-2" id="batchEditor_${batch.id}" style="display:none;">
                                    <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#f8f9fa; border:1px solid #dee2e6;">
                                        <i class="fas fa-calendar-alt text-muted"></i>
                                        <input type="date" class="form-control form-control-sm" 
                                               id="batchExpInput_${batch.id}" 
                                               value="${batch.expiration_date || ''}" 
                                               style="max-width:160px; font-size:13px;">
                                        <button class="btn btn-sm btn-primary px-2 py-1" 
                                                onclick="event.stopPropagation(); saveBatchExpiry(${batch.id}, ${productId})" 
                                                style="font-size:12px;">
                                            <i class="fas fa-check me-1"></i>Save
                                        </button>
                                        <button class="btn btn-sm btn-outline-secondary px-2 py-1" 
                                                onclick="event.stopPropagation(); closeBatchExpiryEditor(${batch.id})" 
                                                style="font-size:12px;">
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            `;
                        });
                    } else {
                        displayContainer.style.display = 'none';
                    }
                }
                
                if (addSelect) {
                    addSelect.innerHTML = '<option value="">Select a batch...</option>';
                    response.data.forEach(batch => {
                        let expText = batch.expiration_date ? `(Exp: ${batch.expiration_date})` : '(No expiry)';
                        addSelect.innerHTML += `<option value="${batch.id}">${batch.batch_no} - ${batch.stock} units ${expText} [Recv: ${batch.date_added}]</option>`;
                    });
                }
                
                if (removeSelect) {
                    removeSelect.innerHTML = '<option value="auto">Automatic FIFO (Oldest First)</option>';
                    response.data.forEach(batch => {
                        let expText = batch.expiration_date ? `(Exp: ${batch.expiration_date})` : '(No expiry)';
                        removeSelect.innerHTML += `<option value="${batch.id}">${batch.batch_no} - ${batch.stock} units ${expText}</option>`;
                    });
                }
            }
        }
    });
}

// ==================== BATCH EXPIRY INLINE EDITOR ====================

function toggleBatchExpiryEditor(rowEl) {
    const batchId = rowEl.getAttribute('data-batch-id');
    const editor = document.getElementById('batchEditor_' + batchId);
    if (!editor) return;

    // Close any other open editors first
    document.querySelectorAll('.batch-expiry-editor').forEach(ed => {
        if (ed.id !== 'batchEditor_' + batchId) ed.style.display = 'none';
    });

    // Toggle this one
    if (editor.style.display === 'none') {
        editor.style.display = 'block';
        const input = document.getElementById('batchExpInput_' + batchId);
        if (input) input.focus();
    } else {
        editor.style.display = 'none';
    }
}

function saveBatchExpiry(batchId, productId) {
    const input = document.getElementById('batchExpInput_' + batchId);
    if (!input) return;

    const newExpiry = input.value; // can be empty to clear

    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: {
            action: 'update_batch_expiry',
            batch_id: batchId,
            expiration_date: newExpiry
        },
        dataType: 'json',
        success: function(response) {
            hideLoading();
            if (response.success) {
                showToast('success', 'Updated', response.message);
                // Refresh only the batch list (no modal re-creation)
                refreshBatchList(productId);
                // Also refresh product table to update expiry column
                if (currentCategoryId) loadCategoryProducts(currentCategoryId);
            } else {
                showToast('error', 'Error', response.message || 'Failed to update');
            }
        },
        error: function() {
            hideLoading();
            showToast('error', 'Error', 'Failed to connect to server');
        }
    });
}

function closeBatchExpiryEditor(batchId) {
    const editor = document.getElementById('batchEditor_' + batchId);
    if (editor) editor.style.display = 'none';
}

function toggleBatchFields() {
    const action = document.getElementById('stockAction')?.value;
    const batchOptions = document.getElementById('batchOptionsContainer');
    const removeBatch = document.getElementById('removeBatchContainer');
    const qtyContainer = document.getElementById('stockQtyContainer');
    const existingContainer = document.getElementById('existingBatchContainer');
    const newContainer = document.getElementById('newBatchContainer');
    const qtyInput = document.getElementById('stockQuantity');
    
    // Radio buttons
    const batchActionNew = document.getElementById('batchActionNew');
    const isExisting = !batchActionNew || !batchActionNew.checked;

    if (action === 'none') {
        if (qtyContainer) qtyContainer.style.display = 'none';
        if (batchOptions) batchOptions.style.display = 'none';
        if (removeBatch) removeBatch.style.display = 'none';
        if (qtyInput) qtyInput.required = false;
    } else if (action === 'add') {
        if (qtyContainer) qtyContainer.style.display = 'block';
        if (batchOptions) batchOptions.style.display = 'block';
        if (removeBatch) removeBatch.style.display = 'none';
        if (qtyInput) qtyInput.required = true;
        
        if (isExisting) {
            existingContainer.style.display = 'block';
            newContainer.style.display = 'none';
        } else {
            existingContainer.style.display = 'none';
            newContainer.style.display = 'block';
        }
    } else if (action === 'remove') {
        if (qtyContainer) qtyContainer.style.display = 'block';
        if (batchOptions) batchOptions.style.display = 'none';
        if (removeBatch) removeBatch.style.display = 'block';
        if (qtyInput) qtyInput.required = true;
    }
}

function updateStock() {
    const productId = document.getElementById('stockProductId')?.value;
    const action = document.getElementById('stockAction')?.value;
    const status = document.getElementById('stockProductStatus')?.value;
    const rawQuantity = document.getElementById('stockQuantity')?.value;

    let effectiveType = action;
    let quantity = 0;
    
    // Gather Batch Data
    let batchData = {
        batch_action: document.getElementById('batchActionNew')?.checked ? 'new' : 'existing',
        batch_id: '',
        batch_date_added: document.getElementById('batchDateAdded')?.value,
        batch_expiration_date: document.getElementById('batchExpirationDate')?.value
    };
    
    if (action === 'add' && batchData.batch_action === 'existing') {
        batchData.batch_id = document.getElementById('existingBatchSelect')?.value;
        if (!batchData.batch_id) {
            showToast('warning', 'Warning', 'Please select an existing batch');
            return;
        }
    } else if (action === 'remove') {
        const removeVal = document.getElementById('removeBatchSelect')?.value;
        batchData.batch_id = removeVal === 'auto' ? '' : removeVal;
    }

    // Determine the effective action:
    // If action is 'none' OR quantity is empty/zero, treat as status-only update
    if (action !== 'none' && rawQuantity && parseInt(rawQuantity) > 0) {
        // User wants to add/remove stock
        quantity = parseInt(rawQuantity);
        effectiveType = action;
    } else if (action !== 'none' && rawQuantity && parseInt(rawQuantity) < 0) {
        // Invalid negative quantity
        showToast('warning', 'Warning', 'Quantity cannot be negative');
        return;
    } else {
        // No quantity provided or action is 'none' — status-only update
        effectiveType = 'none';
        quantity = 0;
    }

    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: {
            action: 'update_stock',
            id: productId,
            quantity: quantity,
            type: effectiveType,
            status: status,
            batch_action: batchData.batch_action,
            batch_id: batchData.batch_id,
            batch_date_added: batchData.batch_date_added,
            batch_expiration_date: batchData.batch_expiration_date
        },
        dataType: 'json',
        success: function (response) {
            hideLoading();
            if (response.success) {
                showToast('success', 'Success', response.message);
                const modal = bootstrap.Modal.getInstance(document.getElementById('stockModal'));
                if (modal) modal.hide();
                if (currentCategoryId) loadCategoryProducts(currentCategoryId);
                loadCategories(); // Refresh category list
            } else {
                showToast('error', 'Error', response.message);
            }
        },
        error: function () {
            hideLoading();
            showToast('error', 'Error', 'Failed to update stock');
        }
    });
}

// ==================== SIDEBAR & UI FUNCTIONS ====================

function addTooltipTitles() {
    const profileBtn = document.querySelector('.profile-btn');
    if (profileBtn && !profileBtn.getAttribute('title')) profileBtn.setAttribute('title', 'My Profile');
    const switchAccountBtn = document.querySelector('.switch-account-btn');
    if (switchAccountBtn && !switchAccountBtn.getAttribute('title')) switchAccountBtn.setAttribute('title', 'Switch Account');
    const posBtn = document.querySelector('.dropdown-toggle');
    if (posBtn && !posBtn.getAttribute('title')) posBtn.setAttribute('title', 'Point of Sale');
    const inventoryBtns = document.querySelectorAll('.dropdown .dropdown-toggle');
    if (inventoryBtns[1] && !inventoryBtns[1].getAttribute('title')) inventoryBtns[1].setAttribute('title', 'Inventory Management');
    const dashboardLink = document.querySelector('.dashboard-link');
    if (dashboardLink && !dashboardLink.getAttribute('title')) dashboardLink.setAttribute('title', 'Dashboard');
    const logoutBtn = document.querySelector('.logout-btn');
    if (logoutBtn && !logoutBtn.getAttribute('title')) logoutBtn.setAttribute('title', 'Logout');
    const newCategoryBtn = document.querySelector('button[onclick="openCategoryModal()"]');
    if (newCategoryBtn) newCategoryBtn.setAttribute('title', 'Create new category');
    const addProductBtn = document.querySelector('button[onclick="openProductModal()"]');
    if (addProductBtn) addProductBtn.setAttribute('title', 'Add new product to selected category');
}

function addTooltipStyles() {
    if (!document.querySelector('#tooltipStyles')) {
        const style = document.createElement('style');
        style.id = 'tooltipStyles';
        style.textContent = `
            .sidebar.collapsed [title] { position: relative; }
            .sidebar.collapsed [title]:hover::before { content: attr(title); position: absolute; left: 75px; background: #1e293b; color: white; padding: 6px 12px; border-radius: 6px; font-size: 12px; white-space: nowrap; z-index: 1000; pointer-events: none; box-shadow: 0 2px 8px rgba(0,0,0,0.2); font-weight: normal; letter-spacing: 0.3px; }
            .sidebar.collapsed [title]:hover::after { content: ''; position: absolute; left: 68px; top: 50%; transform: translateY(-50%); border-width: 5px; border-style: solid; border-color: transparent #1e293b transparent transparent; pointer-events: none; }
            .btn-icon { position: relative; }
            .btn-icon:hover::before { content: attr(title); position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: #1e293b; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; white-space: nowrap; margin-bottom: 5px; z-index: 1000; pointer-events: none; }
            .btn-primary, .btn-outline-secondary { position: relative; }
            .btn-primary:hover::before, .btn-outline-secondary:hover::before { content: attr(title); position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: #1e293b; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px; white-space: nowrap; margin-bottom: 5px; z-index: 1000; pointer-events: none; }`;
        document.head.appendChild(style);
    }
}

// ==================== INITIALIZATION ====================

document.addEventListener('DOMContentLoaded', function () {
    loadCategories();
    loadCategoryOptions();
    populateIconSelector();
    addTooltipTitles();
    addTooltipStyles();

    const newCategoryBtn = document.querySelector('button[onclick="openCategoryModal()"]');
    if (newCategoryBtn) newCategoryBtn.setAttribute('title', 'Create new category');
    const addProductBtn = document.querySelector('button[onclick="openProductModal()"]');
    if (addProductBtn) addProductBtn.setAttribute('title', 'Add new product to selected category');
    const sectionTitles = document.querySelectorAll('.section-title');
    sectionTitles.forEach(title => {
        const text = title.textContent.trim();
        title.setAttribute('title', text);
    });

    // Add expiration warning listener
    const expInput = document.getElementById('productExpiration');
    if (expInput) expInput.addEventListener('change', checkExpirationWarning);

    // Sidebar collapse functionality
    const sidebar = document.getElementById('sidebar');
    const collapseBtn = document.getElementById('collapseBtn');
    const collapseIcon = document.getElementById('collapseIcon');
    if (collapseBtn && sidebar) {
        collapseBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            if (collapseIcon) {
                collapseIcon.classList.toggle('fa-chevron-left');
                collapseIcon.classList.toggle('fa-chevron-right');
            }
        });
        collapseBtn.setAttribute('title', 'Toggle sidebar');
    }

    // Recommendation button functionality
    const recommendationBtn = document.getElementById('recommendationBtn');
    if (recommendationBtn) {
        recommendationBtn.addEventListener('click', function () {
            window.location.href = 'reco.php';
        });
    }

    // Logout functionality
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function (e) {
            e.preventDefault();
            showToast('info', 'Logging Out', 'See you next time!');
            setTimeout(() => window.location.replace('logout.php'), 500);
        });
    }

    // Dark mode toggle (if exists)
    const darkToggle = document.getElementById('darkToggle');
    const darkIcon = document.getElementById('darkIcon');
    const body = document.body;
    if (darkToggle && darkIcon && body) {
        darkToggle.addEventListener('click', () => {
            if (sidebar) sidebar.classList.toggle('dark-mode');
            body.classList.toggle('dark-mode');
            darkIcon.classList.toggle('fa-moon');
            darkIcon.classList.toggle('fa-sun');
            const span = darkToggle.querySelector('span');
            if (span) span.textContent = body.classList.contains('dark-mode') ? 'Light mode' : 'Dark mode';
        });
        darkToggle.setAttribute('title', 'Toggle dark mode');
    }

    // ── Image Upload Zone Handlers ──
    initImageUploadZone();
});

// ==================== IMAGE UPLOAD FUNCTIONS ====================

function initImageUploadZone() {
    const zone = document.getElementById('imageUploadZone');
    const fileInput = document.getElementById('imageFileInput');
    if (!zone || !fileInput) return;

    // Click to browse
    zone.addEventListener('click', function (e) {
        if (e.target.closest('.image-remove-btn')) return;
        fileInput.click();
    });

    // File selected
    fileInput.addEventListener('change', function () {
        if (this.files && this.files[0]) {
            handleImageFile(this.files[0]);
        }
    });

    // Drag & drop
    zone.addEventListener('dragover', function (e) {
        e.preventDefault();
        zone.classList.add('drag-over');
    });
    zone.addEventListener('dragleave', function () {
        zone.classList.remove('drag-over');
    });
    zone.addEventListener('drop', function (e) {
        e.preventDefault();
        zone.classList.remove('drag-over');
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            // Set the file to the input so saveProduct can grab it
            const dt = new DataTransfer();
            dt.items.add(e.dataTransfer.files[0]);
            fileInput.files = dt.files;
            handleImageFile(e.dataTransfer.files[0]);
        }
    });
}

function handleImageFile(file) {
    // Validate type
    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!allowedTypes.includes(file.type)) {
        showToast('error', 'Invalid File', 'Only JPG, PNG, WebP, and GIF images are allowed');
        return;
    }

    // Validate size (2MB)
    if (file.size > 2 * 1024 * 1024) {
        showToast('error', 'File Too Large', 'Maximum image size is 2 MB');
        return;
    }

    // Preview
    const reader = new FileReader();
    reader.onload = function (e) {
        showImagePreview(e.target.result);
    };
    reader.readAsDataURL(file);
}

function showImagePreview(src) {
    const preview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('imagePreviewImg');
    const placeholder = document.getElementById('imageUploadPlaceholder');

    if (previewImg) previewImg.src = src;
    if (preview) preview.style.display = 'inline-block';
    if (placeholder) placeholder.style.display = 'none';
}

function removeProductImage() {
    const imgField = document.getElementById('productImage');
    const fileInput = document.getElementById('imageFileInput');
    if (imgField) imgField.value = '';
    if (fileInput) fileInput.value = '';
    resetImageUpload();
}

function resetImageUpload() {
    const preview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('imagePreviewImg');
    const placeholder = document.getElementById('imageUploadPlaceholder');
    const imgField = document.getElementById('productImage');
    const fileInput = document.getElementById('imageFileInput');

    if (preview) preview.style.display = 'none';
    if (previewImg) previewImg.src = '';
    if (placeholder) placeholder.style.display = 'block';
    if (imgField) imgField.value = '';
    if (fileInput) fileInput.value = '';
}