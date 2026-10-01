// Available icons - ONLY PET APPROPRIATE ICONS
// Category icons (Font Awesome 6.5.2 free, the version categories.php loads)
const availableIcons = [
    'fa-dog', 'fa-cat', 'fa-shield-dog', 'fa-shield-cat', 'fa-paw', 'fa-bone', 'fa-bowl-food',
    'fa-dove', 'fa-crow', 'fa-kiwi-bird', 'fa-feather', 'fa-egg', 'fa-drumstick-bite', 'fa-wheat-awn',
    'fa-fish', 'fa-frog', 'fa-horse', 'fa-seedling', 'fa-carrot', 'fa-apple-alt', 'fa-box',
    'fa-capsules', 'fa-pills', 'fa-syringe', 'fa-cut', 'fa-soap', 'fa-spray-can'
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

const MAX_VISIBLE_TOASTS = 3;

// Remove the same message if it is already showing (clicking Save again shows it once),
// then the oldest toasts so at most MAX_VISIBLE_TOASTS stay on screen
function makeRoomForToast(toastContainer, key) {
    const visible = [...toastContainer.querySelectorAll('.toast')];
    const keep = visible.filter(el => {
        if (el.dataset.toastKey !== key) return true;
        bootstrap.Toast.getInstance(el)?.dispose();
        el.remove();
        return false;
    });
    keep.slice(0, Math.max(0, keep.length - MAX_VISIBLE_TOASTS + 1)).forEach(el => {
        bootstrap.Toast.getInstance(el)?.dispose();
        el.remove();
    });
}

function showToast(type, title, message) {
    const toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) return;

    const toastKey = [type, title, message].join('|');
    makeRoomForToast(toastContainer, toastKey);

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
        toastElement.dataset.toastKey = toastKey;
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
// 'kilo'   : Per Kilo — stock and prices are per kilogram, decimals allowed (25.5 kg)
// 'weight' : Per Gram — stock is not tracked (set to 1)
// 'packaged': every other unit — whole-number stock
const WEIGHT_UNIT_TYPES = ['per gram'];

function isPerKiloUnit(unit) {
    return String(unit || '').trim().toLowerCase() === 'per kilo';
}

// Stock/quantity as people read it. The database sends DECIMAL values ("4.00"),
// so whole-number units are rounded to "4" and Per Kilo shows "25.5 kg".
function formatQty(qty, unit, withUnit = true) {
    const n = Number(qty) || 0;
    if (!isPerKiloUnit(unit)) return String(Math.round(n));
    const text = String(Math.round(n * 100) / 100);
    return withUnit ? text + ' kg' : text;
}

// "12 units" / "1 unit" / "2.5 kg"
function formatQtyAmount(qty, unit) {
    if (isPerKiloUnit(unit)) return formatQty(qty, unit);
    const n = Math.round(Number(qty) || 0);
    return `${n} unit${n === 1 ? '' : 's'}`;
}

function getUnitTypeFromValue(val) {
    if (!val) return 'packaged';
    const parsed = parseStoredUnit(val);
    const ut = parsed.unitType.toLowerCase();
    if (ut === 'per kilo') return 'kilo';
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
    if (isPerKiloUnit(typeSel.value)) return 'kilo';
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

    // Per Kilo: prices are per kg and stock is kilograms with decimals (25.5)
    const perKilo = unitType === 'kilo';
    document.querySelectorAll('#productModal .inv-per-kg').forEach(el => { el.hidden = !perKilo; });
    if (stockInput) {
        stockInput.dataset.numeric = perKilo ? 'money' : 'int';
        stockInput.inputMode = perKilo ? 'decimal' : 'numeric';
        stockInput.placeholder = perKilo ? '0.00' : '0';
        stockInput.maxLength = perKilo ? 10 : 7;
        // Switching away from Per Kilo: drop decimals the whole-number field can't hold
        if (!perKilo && stockInput.value.includes('.')) {
            stockInput.value = String(Math.round(parseFloat(stockInput.value) || 0));
        }
    }
    updateMarginHint();

    if (!unitType) {
        if (stockWrapper) stockWrapper.style.display = 'none';
        if (unitHint) unitHint.style.display = 'none';
        if (barcodeReqStar) barcodeReqStar.style.display = 'none';
        if (barcodeOptTag) barcodeOptTag.style.display = '';
        if (barcodeInput) barcodeInput.required = false;
        if (stockInput) stockInput.required = false;
        return;
    }

    if (unitType === 'kilo') {
        if (stockWrapper) stockWrapper.style.display = 'block';
        if (barcodeReqStar) barcodeReqStar.style.display = 'none';
        if (barcodeOptTag) barcodeOptTag.style.display = '';
        if (barcodeInput) barcodeInput.required = false;
        if (stockInput) stockInput.required = true;
        if (unitHint) {
            unitHint.style.display = 'block';
            unitHint.className = 'unit-hint unit-hint-weight mt-1';
            unitHint.innerHTML = '<i class="fas fa-weight-hanging me-1"></i>Sold by weight — enter stock in kilograms (e.g. 25.5). Cost and selling price are per kg. Barcode is optional.';
        }
    } else if (unitType === 'weight') {
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
        container.innerHTML = `<div class="text-center py-5"><i class="fas fa-layer-group fa-3x text-muted mb-3"></i><h5>No Categories Found</h5><p class="text-muted">${isOwner ? 'Click "New category" to create your first category' : 'Ask the owner to add a category'}</p></div>`;
        return;
    }
    let html = '';
    categories.forEach(category => {
        const isActive = currentCategoryId == category.id;
        const count = parseInt(category.product_count, 10) || 0;
        const color = /^#[0-9a-f]{3,8}$/i.test(category.color || '') ? category.color : '#2c5530';
        html += `
            <div class="category-card ${isActive ? 'active' : ''}" onclick="selectCategory(${category.id}, '${escapeHtml(category.name).replace(/'/g, "\\'")}')">
                <div class="inv-cat-top">
                    <span class="inv-cat-icon" style="--cat:${color}"><i class="fas ${escapeHtml(category.icon || 'fa-paw')}"></i></span>
                    <div class="category-actions">
                        <button class="btn-icon" onclick="event.stopPropagation(); editCategory(${category.id})" title="Edit category"><i class="fas fa-pen"></i></button>
                        <button class="btn-icon archive" onclick="event.stopPropagation(); archiveCategory(${category.id})" title="Archive category"><i class="fas fa-box-archive"></i></button>
                    </div>
                </div>
                <h3>${escapeHtml(category.name)}</h3>
                <p>${category.description ? escapeHtml(category.description) : '<span class="inv-muted">No description</span>'}</p>
                <div class="inv-cat-foot">
                    <span><strong>${count}</strong> product${count === 1 ? '' : 's'}</span>
                    ${category.status !== 'active' ? '<span class="inv-tag">Inactive</span>' : ''}
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
    if (addProductBtn) addProductBtn.style.display = 'inline-flex';

    document.querySelectorAll('.category-card').forEach(card => card.classList.remove('active'));
    if (event && event.currentTarget) event.currentTarget.classList.add('active');

    // A new category starts unfiltered (reloads after saving keep the search)
    clearProductSearch();
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
    clearProductSearch();

    showLoading();
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: { action: 'get_expired_products' },
        dataType: 'json',
        success: function (response) {
            hideLoading();
            if (response.success) {
                setSectionProducts(response.data);
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
    const category = categories.find(c => String(c.id) === String(categoryId));
    const count = parseInt(category?.product_count, 10) || 0;
    confirmDialog({
        title: 'Archive category?',
        message: `${category ? category.name : 'This category'} and its ${count} product${count === 1 ? '' : 's'} will be moved to the archive.`,
        detail: 'None of them have sales records. You can restore them from the Archive page.',
        confirmText: 'Archive',
        tone: 'warning'
    }).then(ok => { if (ok) confirmArchiveCategory(categoryId); });
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

// ── Product search in the open category ──────────────────────────
// Everything the open category (or the Expired view) loaded; the search box filters it
let sectionProducts = [];

function setSectionProducts(products) {
    sectionProducts = products || [];
    applyProductSearch();
}

function clearProductSearch() {
    const box = document.getElementById('productSearch');
    if (box) box.value = '';
}

// Matches name, SKU or barcode; every word typed must appear ("pedigree 3kg")
function applyProductSearch() {
    const query = (document.getElementById('productSearch')?.value || '').trim().toLowerCase();
    if (!query) {
        displayProducts(sectionProducts);
        return;
    }
    const words = query.split(/\s+/);
    const matches = sectionProducts.filter(p => {
        const text = [p.name, p.sku, p.barcode].filter(Boolean).join(' ').toLowerCase();
        return words.every(w => text.includes(w));
    });
    if (matches.length === 0) {
        productNames = new Map();
        const tbody = document.getElementById('productsTableBody');
        if (tbody) tbody.innerHTML = `<tr><td colspan="9" class="inv-empty-cell"><p>No products match "${escapeHtml(query)}".</p></td></tr>`;
        return;
    }
    displayProducts(matches);
}

document.getElementById('productSearch')?.addEventListener('input', applyProductSearch);

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
                setSectionProducts(response.data);
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

/**
 * True when a YYYY-MM-DD date is before today (local time). String compare avoids
 * new Date('YYYY-MM-DD') being parsed as UTC midnight.
 */
function isDateExpired(dateStr) {
    if (!dateStr || dateStr.startsWith('0000-00-00')) return false;
    const now = new Date();
    const today = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    return dateStr.substring(0, 10) < today;
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

    const cls = days > 90 ? 'is-bad' : days > 60 ? 'is-warn' : '';
    const title = days > 90 ? 'In stock over 90 days' : days > 60 ? 'In stock over 60 days' : '';
    return `<span class="inv-days ${cls}" title="${title}">${days}<span class="inv-unit">d</span></span>`;
}

// id → name for the rows on screen (used by the archive confirm dialog)
let productNames = new Map();

function displayProducts(products) {
    productNames = new Map((products || []).map(p => [String(p.id), p.name]));
    const tbody = document.getElementById('productsTableBody');
    if (!tbody) return;

    if (!products || products.length === 0) {
        tbody.innerHTML = `<tr><td colspan="9" class="inv-empty-cell"><p>No products in this category. Click "Add Product to ${escapeHtml(currentCategoryName)}" to add one.</p></td></tr>`;
        return;
    }

    let html = '';
    products.forEach(product => {
        const unitType = getUnitTypeFromValue(product.unit);
        const isWeight = (unitType === 'weight');
        const perKilo = (unitType === 'kilo');

        // Stock: plain number (kilograms for Per Kilo), coloured only when low or out
        let stockHtml;
        if (isWeight) {
            stockHtml = '<span class="inv-muted">By weight</span>';
        } else {
            const stockValue = parseFloat(product.stock) || 0;
            const stockCls = stockValue <= 5 ? 'is-bad' : stockValue <= 15 ? 'is-warn' : '';
            stockHtml = `<span class="${stockCls}">${stockValue <= 0 ? 'Out' : formatQty(stockValue, product.unit)}</span>`;
        }
        const perKgHtml = perKilo ? '<small class="inv-muted">/kg</small>' : '';

        const unitHtml = product.unit ? escapeHtml(product.unit) : '<span class="inv-muted">—</span>';

        const costHtml = product.cost_price && parseFloat(product.cost_price) > 0
            ? `₱${parseFloat(product.cost_price).toFixed(2)}${perKgHtml}`
            : '<span class="inv-muted">—</span>';

        // Expiry: the date, with a short relative note underneath
        let expiryHtml = '<span class="inv-muted">—</span>';
        if (product.expiration_date && product.expiration_date !== '0000-00-00') {
            const exp = new Date(product.expiration_date);
            const diff = Math.ceil((exp - new Date()) / (1000 * 60 * 60 * 24));
            const fmt = exp.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
            let cls = '', note;
            if (isDateExpired(product.expiration_date)) { cls = 'is-bad'; note = 'Expired'; }
            else if (diff <= 30) { cls = 'is-bad'; note = diff <= 1 ? 'Tomorrow' : `In ${diff} days`; }
            else if (diff <= 90) { cls = 'is-warn'; note = `In ${Math.round(diff / 30)} mo`; }
            else { note = `In ${Math.round(diff / 30)} mo`; }
            expiryHtml = `<span class="${cls}">${fmt}</span><span class="inv-sub">${note}</span>`;
        }

        const currentStatus = product.status || 'active';
        // Expired stock outranks the Active/Inactive switch; the tooltip keeps the underlying status visible
        const isExpired = isDateExpired(product.expiration_date);
        const displayStatus = isExpired ? 'Expired' : currentStatus.charAt(0).toUpperCase() + currentStatus.slice(1);
        const statusClass = isExpired ? 'status-expired' : (currentStatus === 'active' ? 'status-active' : 'status-inactive');
        const statusTitle = isExpired ? `Oldest batch expired · product is ${currentStatus}` : '';

        const imageHtml = product.image
            ? `<img src="${escapeHtml(product.image)}" alt="" class="inv-thumb" loading="lazy">`
            : `<span class="inv-thumb inv-thumb-empty"><i class="fas fa-box"></i></span>`;

        const safeName = escapeHtml(product.name).replace(/'/g, "\\'");

        html += `
            <tr>
                <td>
                    <div class="inv-product">
                        ${imageHtml}
                        <div class="inv-product-text">
                            <span class="inv-name">${escapeHtml(product.name)}</span>
                            <span class="inv-sub">${[product.sku, product.barcode].filter(Boolean).map(escapeHtml).join(' · ')}</span>
                        </div>
                    </div>
                </td>
                <td class="inv-muted-cell">${unitHtml}</td>
                <td class="inv-num inv-muted-cell">${costHtml}</td>
                <td class="inv-num"><strong>₱${parseFloat(product.price).toFixed(2)}</strong>${perKgHtml}</td>
                <td class="inv-num">${stockHtml}</td>
                <td class="inv-num days-in-cell">${getDaysInBadgeHtml(product)}</td>
                <td class="inv-expiry">${expiryHtml}</td>
                <td><span class="inv-status ${statusClass}" title="${statusTitle}">${displayStatus}</span></td>
                <td class="inv-actions">
                    ${isOwner ? `<button class="btn-icon" onclick="editProduct(${product.id})" title="Edit product"><i class="fas fa-pen"></i></button>` : ''}
                    <button class="btn-icon" onclick="openStockModal(${product.id},'${safeName}',${parseFloat(product.stock) || 0}, '${currentStatus}', '${escapeHtml(product.unit || '').replace(/'/g, "\\'")}')" title="Manage stock & status"><i class="fas ${isWeight ? 'fa-toggle-on' : 'fa-boxes'}"></i></button>
                    ${isOwner ? `<button class="btn-icon archive" onclick="archiveProduct(${product.id})" title="Move to archive"><i class="fas fa-box-archive"></i></button>` : ''}
                </td>
            </tr>`;
    });
    tbody.innerHTML = html;
}

function archiveProduct(productId) {
    confirmDialog({
        title: 'Archive product?',
        message: `${productNames.get(String(productId)) || 'This product'} will be moved to the archive.`,
        detail: 'It leaves the POS and inventory list. You can restore it from the Archive page.',
        confirmText: 'Archive',
        tone: 'warning'
    }).then(ok => {
        if (!ok) return;
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
    });
}

function editProduct(productId) {
    openProductModal(productId);
}

// Profit per unit shown under Cost / Selling price
function updateMarginHint() {
    const hint = document.getElementById('marginHint');
    if (!hint) return;
    const cost = parseFloat(document.getElementById('productCostPrice')?.value);
    const price = parseFloat(document.getElementById('productPrice')?.value);
    hint.classList.remove('is-bad');
    if (!(price > 0) || !(cost > 0)) { hint.textContent = ''; return; }
    const profit = price - cost;
    const pct = Math.round((profit / price) * 100);
    const peso = n => '₱' + Math.abs(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const per = getUnitType() === 'kilo' ? 'per kg' : 'per unit';
    if (profit <= 0) {
        hint.classList.add('is-bad');
        hint.textContent = profit === 0 ? 'Selling at cost — no profit.' : `Selling at a loss of ${peso(profit)} ${per}.`;
    } else {
        hint.textContent = `Profit ${peso(profit)} ${per} (${pct}% margin)`;
    }
}

document.addEventListener('input', e => {
    if (e.target && (e.target.id === 'productCostPrice' || e.target.id === 'productPrice')) updateMarginHint();
});
document.getElementById('productModal')?.addEventListener('shown.bs.modal', updateMarginHint);

// A save can take many seconds on a slow disk; a second click must not create a duplicate
let productSaveInFlight = false;

function setProductSaving(saving) {
    productSaveInFlight = saving;
    const btn = document.getElementById('saveProductBtn');
    if (!btn) return;
    btn.disabled = saving;
    btn.textContent = saving ? 'Saving…' : 'Save product';
}

function saveProduct() {
    if (productSaveInFlight) return;
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
        stock = (document.getElementById('productStock')?.value || '').trim() || 0;
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
    if (!/^\d+(\.\d{1,2})?$/.test(price || '') || parseFloat(price) <= 0) {
        showToast('warning', 'Warning', 'Selling price is required and must be greater than 0');
        return;
    }
    if (costPrice && !/^\d+(\.\d{1,2})?$/.test(costPrice)) {
        showToast('warning', 'Invalid Cost', 'Cost price must be 0 or a positive amount');
        document.getElementById('productCostPrice')?.focus();
        return;
    }
    if (unitType === 'kilo' && !/^\d+(\.\d{1,2})?$/.test(String(stock))) {
        showToast('warning', 'Invalid Stock', 'Stock must be 0 or more kilograms, with up to 2 decimals (e.g. 25.5)');
        document.getElementById('productStock')?.focus();
        return;
    }
    if (unitType === 'packaged' && !/^\d+$/.test(String(stock))) {
        showToast('warning', 'Invalid Stock', 'Stock must be a whole number of 0 or more');
        document.getElementById('productStock')?.focus();
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
    if (!productId && unitType === 'kilo' && !(parseFloat(stock) > 0)) {
        showToast('warning', 'Warning', 'Please enter the stock in kilograms');
        return;
    }

    // Check if there's a pending image file to upload first
    const imageFileInput = document.getElementById('imageFileInput');
    const pendingFile = imageFileInput?.files?.[0];

    if (pendingFile) {
        // Upload image first, then save product
        showLoading();
        setProductSaving(true);
        const imgFormData = new FormData();
        imgFormData.append('image', pendingFile);

        $.ajax({
            url: 'ajax/upload_product_image.php',
            method: 'POST',
            data: imgFormData,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: 60000,
            success: function (imgResponse) {
                if (imgResponse.success) {
                    formData.image = imgResponse.image_path;
                    document.getElementById('productImage').value = imgResponse.image_path;
                    // Uploaded: a retry reuses this path instead of uploading the photo again
                    imageFileInput.value = '';
                    doSaveProduct(formData);
                } else {
                    hideLoading();
                    setProductSaving(false);
                    showToast('error', 'Image Error', imgResponse.message || 'Failed to upload image');
                }
            },
            error: function () {
                hideLoading();
                setProductSaving(false);
                showToast('error', 'Error', 'Failed to upload image');
            }
        });
    } else {
        doSaveProduct(formData);
    }
}

function doSaveProduct(formData) {
    showLoading();
    setProductSaving(true);
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: formData,
        dataType: 'json',
        // Saving writes several rows; on a slow hard disk that can take well over 10 seconds
        timeout: 60000,
        complete: function () { setProductSaving(false); },
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
            if (status === 'timeout') {
                // The server may still finish the save after the browser stops waiting
                showToast('warning', 'Still saving', 'The server is slow to answer. The product may still be saved — check the list before saving again.');
                if (currentCategoryId) {
                    loadCategoryProducts(currentCategoryId);
                    setTimeout(() => loadCategoryProducts(currentCategoryId), 15000);
                }
                return;
            }
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

    container.innerHTML = availableIcons.map(icon => {
        const label = icon.replace('fa-', '').replace(/-/g, ' ');
        return `<button type="button" class="icon-option" role="radio" aria-checked="false"
                        data-icon="${icon}" onclick="selectIcon('${icon}')" title="${label}" aria-label="${label}">
                    <i class="fas ${icon}" aria-hidden="true"></i>
                </button>`;
    }).join('');

    wireCategoryForm();
}

// Mark the chosen icon (also used when a category is loaded for editing)
function markSelectedIcon(icon) {
    document.querySelectorAll('.icon-option').forEach(opt => {
        const on = opt.dataset.icon === icon;
        opt.classList.toggle('selected', on);
        opt.setAttribute('aria-checked', on ? 'true' : 'false');
    });
}

function selectIcon(icon) {
    const iconInput = document.getElementById('categoryIcon');
    if (iconInput) iconInput.value = icon;
    markSelectedIcon(icon);
    updateCategoryPreview();
}

// Mark the swatch matching the current colour, or the custom picker when none does
function markSelectedColor() {
    const color = (document.getElementById('categoryColor')?.value || '').toLowerCase();
    let matched = false;
    document.querySelectorAll('.cat-swatch[data-color]').forEach(sw => {
        const on = sw.dataset.color.toLowerCase() === color;
        if (on) matched = true;
        sw.classList.toggle('selected', on);
        sw.setAttribute('aria-checked', on ? 'true' : 'false');
    });
    const custom = document.querySelector('.cat-swatch-custom');
    if (custom) {
        custom.classList.toggle('selected', !matched);
        custom.style.setProperty('--sw', color || '#4a6fa5');
    }
}

// Live preview: how the category card will look in the list
function updateCategoryPreview() {
    const name   = document.getElementById('categoryName')?.value.trim();
    const desc   = document.getElementById('categoryDescription')?.value.trim();
    const icon   = document.getElementById('categoryIcon')?.value || 'fa-paw';
    const color  = document.getElementById('categoryColor')?.value || '#4a6fa5';
    const status = document.getElementById('categoryStatus')?.value || 'active';

    const iconBox = document.getElementById('catPreviewIcon');
    if (iconBox) {
        iconBox.style.setProperty('--cat', color);
        iconBox.innerHTML = `<i class="fas ${escapeHtml(icon)}"></i>`;
    }
    const nameEl = document.getElementById('catPreviewName');
    if (nameEl) nameEl.textContent = name || 'Category name';
    const descEl = document.getElementById('catPreviewDesc');
    if (descEl) descEl.textContent = desc || 'No description';
    const statusEl = document.getElementById('catPreviewStatus');
    if (statusEl) {
        statusEl.textContent = status === 'active' ? 'Active' : 'Inactive';
        statusEl.classList.toggle('is-inactive', status !== 'active');
    }
    markSelectedColor();
}

// Swatches and field listeners; runs once
function wireCategoryForm() {
    const form = document.getElementById('categoryForm');
    if (!form || form.dataset.wired) return;
    form.dataset.wired = '1';

    document.querySelectorAll('.cat-swatch[data-color]').forEach(sw => {
        sw.setAttribute('role', 'radio');
        sw.addEventListener('click', () => {
            const input = document.getElementById('categoryColor');
            if (input) input.value = sw.dataset.color;
            updateCategoryPreview();
        });
    });
    ['categoryName', 'categoryDescription', 'categoryColor', 'categoryStatus'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', updateCategoryPreview);
        el.addEventListener('change', updateCategoryPreview);
    });
}

function setCategoryModalHeading(editing) {
    const title = document.getElementById('categoryModalTitle');
    const sub = document.getElementById('categoryModalSub');
    const save = document.getElementById('categorySaveBtn');
    if (title) title.textContent = editing ? 'Edit category' : 'Add category';
    if (sub) sub.textContent = editing
        ? 'Changes show on every product in this category.'
        : 'Group products so they are easy to find at the POS.';
    if (save) save.textContent = editing ? 'Save changes' : 'Save category';
}

function openCategoryModal(categoryId = null) {
    const form = document.getElementById('categoryForm');
    if (form) form.reset();

    const idInput = document.getElementById('categoryId');
    if (idInput) idInput.value = '';

    // Clear a duplicate-name error left from last time
    const nameInput = document.getElementById('categoryName');
    if (nameInput) nameInput.classList.remove('is-invalid');

    setCategoryModalHeading(false);

    const iconField = document.getElementById('categoryIcon');
    if (iconField) iconField.value = 'fa-paw';
    markSelectedIcon('fa-paw');
    updateCategoryPreview();

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

                    markSelectedIcon(cat.icon || 'fa-paw');
                    setCategoryModalHeading(true);
                    updateCategoryPreview();

                    // Only open modal AFTER data is populated
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('categoryModal')).show();
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
        bootstrap.Modal.getOrCreateInstance(document.getElementById('categoryModal')).show();
        setTimeout(() => document.getElementById('categoryName')?.focus(), 300);
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

// Adding: show the open category as a fixed field instead of the dropdown.
// Editing keeps the dropdown so a product can still be moved between categories.
function lockProductCategory(locked) {
    const select = document.getElementById('productCategory');
    const fixed = document.getElementById('productCategoryLocked');
    const label = document.getElementById('productCategoryLabel');
    if (!select || !fixed) return;

    select.hidden = locked;
    fixed.hidden = !locked;
    if (label) label.htmlFor = locked ? '' : 'productCategory';
    if (locked) {
        document.getElementById('productCategoryLockedName').textContent =
            select.options[select.selectedIndex]?.text || currentCategoryName;
    }
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
    if (modalTitle) modalTitle.textContent = 'Add product';

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
        // The list only holds active categories; make sure the open one is there
        if (!productId && !categorySelect.querySelector(`option[value="${currentCategoryId}"]`)) {
            categorySelect.add(new Option(currentCategoryName, currentCategoryId));
        }
        categorySelect.value = currentCategoryId;
        onCategoryChange();
    }
    lockProductCategory(!productId);

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
                    // DECIMAL stock arrives as "4.00": whole units show 4, Per Kilo shows 25.5
                    if (stockField) stockField.value = formatQty(product.stock, product.unit, false);
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
                    if (modalTitle) modalTitle.textContent = 'Edit product';

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

// Unit of the product open in the stock modal ('Per Kilo' takes decimal kilograms)
let stockModalUnit = '';

function openStockModal(productId, productName, currentStock, currentStatus, unit) {
    const idField = document.getElementById('stockProductId');
    const nameField = document.getElementById('stockProductName');
    const stockField = document.getElementById('currentStock');
    const quantityField = document.getElementById('stockQuantity');
    const statusField = document.getElementById('stockProductStatus');
    const actionField = document.getElementById('stockAction');
    const perKilo = isPerKiloUnit(unit);
    stockModalUnit = unit || '';

    if (idField) idField.value = productId;
    if (nameField) nameField.textContent = productName;
    if (stockField) {
        stockField.textContent = formatQty(currentStock, unit);
        stockField.dataset.stock = String(Number(currentStock) || 0);
    }
    if (quantityField) {
        quantityField.value = '';
        quantityField.dataset.numeric = perKilo ? 'money' : 'int';
        quantityField.inputMode = perKilo ? 'decimal' : 'numeric';
        quantityField.placeholder = perKilo ? 'How many kg? (e.g. 2.5)' : 'How many units?';
    }
    const qtyLabel = document.getElementById('qtyLabel');
    if (qtyLabel) qtyLabel.textContent = perKilo ? 'Quantity (kg)' : 'Quantity';

    if (statusField) {
        statusField.value = currentStatus || 'active';
    }

    // Fetch and render batches
    refreshBatchList(productId);

    // Per Gram products keep stock at 1 and are managed by status; Per Kilo has real stock
    const isWeightProduct = !perKilo && (parseInt(currentStock) === 1);

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
                            const isFirst = index === 0;
                            const expired = isDateExpired(batch.expiration_date);
                            const fmtDate = d => d
                                ? new Date(d + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
                                : '';
                            const expText = batch.expiration_date
                                ? `<span class="${expired ? 'is-bad' : ''}">Exp ${fmtDate(batch.expiration_date)}</span>${expired ? ' <span class="inv-batch-tag is-bad">Expired</span>' : ''}`
                                : '<span class="inv-muted">No expiry</span>';

                            batchesList.innerHTML += `
                                <div class="inv-batch ${isFirst ? 'is-first' : ''} batch-row-clickable"
                                     data-batch-id="${batch.id}" data-batch-exp="${batch.expiration_date || ''}"
                                     data-product-id="${productId}"
                                     onclick="toggleBatchExpiryEditor(this)"
                                     role="button" tabindex="0" title="Change this batch's expiry date">
                                    <span class="inv-batch-order">${isFirst ? 'Sells first' : 'Next'}</span>
                                    <span class="inv-batch-qty">${formatQty(batch.stock, stockModalUnit, false)} <small>${isPerKiloUnit(stockModalUnit) ? 'kg' : 'units'}</small></span>
                                    <span class="inv-batch-exp batch-exp-display">${expText}</span>
                                    <span class="inv-batch-recv">Received ${fmtDate(batch.date_added)}</span>
                                    <i class="fas fa-pen inv-batch-edit" aria-hidden="true"></i>
                                </div>
                                <div class="batch-expiry-editor inv-batch-editor" id="batchEditor_${batch.id}" style="display:none;">
                                    <label class="visually-hidden" for="batchExpInput_${batch.id}">Expiry date</label>
                                    <input type="date" class="form-control form-control-sm" id="batchExpInput_${batch.id}" value="${batch.expiration_date || ''}">
                                    <button type="button" class="inv-btn inv-btn-primary inv-btn-sm"
                                            onclick="event.stopPropagation(); saveBatchExpiry(${batch.id}, ${productId})">Save</button>
                                    <button type="button" class="inv-btn inv-btn-sm"
                                            onclick="event.stopPropagation(); closeBatchExpiryEditor(${batch.id})">Cancel</button>
                                </div>
                            `;
                        });
                    } else {
                        displayContainer.style.display = 'none';
                    }
                }

                // Warn when the batches don't add up to the product's stock
                const mismatchEl = document.getElementById('batchMismatchWarning');
                const stockEl = document.getElementById('currentStock');
                if (mismatchEl && stockEl) {
                    // Compared in hundredths so decimal kilograms add up exactly
                    const productStock = Math.round((Number(stockEl.dataset.stock) || 0) * 100);
                    const batchTotal = response.data.reduce((sum, b) => sum + Math.round((Number(b.stock) || 0) * 100), 0);
                    if (response.data.length > 0 && batchTotal !== productStock) {
                        mismatchEl.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i>Batches total <strong>${formatQtyAmount(batchTotal / 100, stockModalUnit)}</strong> but current stock is <strong>${formatQty(productStock / 100, stockModalUnit)}</strong>. Expiry tracking may be off — re-save the product or contact the owner.`;
                        mismatchEl.style.display = 'block';
                    } else {
                        mismatchEl.style.display = 'none';
                    }
                }

                if (addSelect) {
                    addSelect.innerHTML = '<option value="">Select a batch...</option>';
                    response.data.forEach(batch => {
                        let expText = batch.expiration_date ? `(Exp: ${batch.expiration_date})` : '(No expiry)';
                        addSelect.innerHTML += `<option value="${batch.id}">${batch.batch_no} - ${formatQtyAmount(batch.stock, stockModalUnit)} ${expText} [Recv: ${batch.date_added}]</option>`;
                    });
                }
                
                if (removeSelect) {
                    removeSelect.innerHTML = '<option value="auto">Automatic FIFO (Oldest First)</option>';
                    response.data.forEach(batch => {
                        let expText = batch.expiration_date ? `(Exp: ${batch.expiration_date})` : '(No expiry)';
                        removeSelect.innerHTML += `<option value="${batch.id}">${batch.batch_no} - ${formatQtyAmount(batch.stock, stockModalUnit)} ${expText}</option>`;
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
    // Per Kilo takes kilograms with up to 2 decimals (2.5), other units whole numbers
    const qtyText = (rawQuantity || '').trim();
    const perKilo = isPerKiloUnit(stockModalUnit);
    const validQty = perKilo
        ? /^\d+(\.\d{1,2})?$/.test(qtyText) && parseFloat(qtyText) > 0
        : /^[1-9]\d*$/.test(qtyText);
    if (action !== 'none' && validQty) {
        // User wants to add/remove stock
        quantity = perKilo ? qtyText : parseInt(qtyText, 10);
        effectiveType = action;
    } else if (action !== 'none' && qtyText !== '') {
        // Anything else typed ("0", "-5", "--22") is a mistake, not a status-only save
        showToast('warning', 'Invalid Quantity', perKilo ? 'Enter the weight in kg, more than 0 (e.g. 2.5)' : 'Enter a whole number greater than 0');
        document.getElementById('stockQuantity')?.focus();
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