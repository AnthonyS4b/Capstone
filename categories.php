<?php
// categories.php - ORIGINAL + added unit, cost_price, expiration fields
session_start();
require_once __DIR__ . '/includes/security.php';
require_once 'config/config.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['toast_message'] = [
        'type' => 'warning',
        'title' => 'Access Denied!',
        'message' => 'Please login first to access categories.'
    ];
    header("Location: Login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$first_name = $_SESSION['first_name'] ?? 'User';
$last_name = $_SESSION['last_name'] ?? '';
$role = $_SESSION['role'] ?? 'employee';
$position = $_SESSION['position'] ?? '';
$email = $_SESSION['email'] ?? '';

// Local logout removed - now handled by logout.php

$is_owner = ($role === 'owner');
$toast_message = isset($_SESSION['toast_message']) ? $_SESSION['toast_message'] : null;
if ($toast_message) {
    unset($_SESSION['toast_message']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico?v=20260923">
    <link rel="icon" type="image/png" sizes="32x32" href="assets/images/favicon-32x32.png?v=20260923">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/images/favicon-16x16.png?v=20260923">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/images/apple-touch-icon.png?v=20260923">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(security_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <script src="assets/js/security.js?v=20260814-1" defer></script>
    <title>Product Categories · Espenida's Pet & Poultry Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/reco.css">
    <link rel="stylesheet" href="assets/css/categories.css?v=<?= filemtime(__DIR__ . '/assets/css/categories.css') ?>">
    <link rel="stylesheet" href="assets/css/transitions.css">
    <link rel="stylesheet" href="assets/css/notif.css?v=<?= filemtime(__DIR__ . '/assets/css/notif.css') ?>">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <link rel="stylesheet" href="assets/css/inventory-ui.css?v=<?= filemtime(__DIR__ . '/assets/css/inventory-ui.css') ?>">
    <script src="assets/js/responsive.js?v=<?= filemtime(__DIR__ . '/assets/js/responsive.js') ?>"></script>
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime(__DIR__ . '/assets/css/sidebar.css') ?>">
</head>

<body class="page-loading inv-ui" onload="document.body.classList.remove('page-loading')">
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="spinner-overlay" id="loadingSpinner">
        <div class="loading-spinner"></div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- MAIN CONTENT AREA -->
    <div class="main-content">
        <div class="welcome-banner">
            <div class="welcome-text">
                <h1>Inventory</h1>
                <p>Pick a category to see and manage its products.</p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <!-- Notification Bell -->
                <div class="notif-bell-wrap" id="notifBellWrap">
                    <button class="notif-bell-btn" id="notifBellBtn" aria-label="Notifications" aria-expanded="false">
                        <i class="fas fa-bell"></i>
                        <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
                    </button>
                    <div class="notif-panel" id="notifPanel">
                        <div class="notif-panel-header">
                            <span><i class="fas fa-bell me-2"></i>Notifications</span>
                            <button class="notif-mark-all" id="notifMarkAll">Mark all read</button>
                        </div>
                        <div class="notif-list" id="notifList">
                            <div class="notif-empty" id="notifEmpty">
                                <i class="fas fa-check-circle"></i>
                                <p>You're all caught up!</p>
                            </div>
                        </div>
                        <div class="notif-panel-footer" id="notifFooter" style="display:none;">
                            <span id="notifFooterText"></span>
                        </div>
                    </div>
                </div>
                <div class="date-display">
                    <i class="fas fa-calendar-alt me-2"></i><?php echo date('M d, Y'); ?>
                </div>
            </div>
        </div>

        <div class="inv-toolbar">
            <h2 class="inv-section-title">Categories</h2>
            <div class="inv-toolbar-actions">
                <a href="archive_products.php" class="inv-btn inv-btn-quiet">
                    <i class="fas fa-box-archive"></i>Archive
                </a>
                <button class="inv-btn inv-btn-quiet inv-btn-danger-text" onclick="showExpiredProducts()">
                    <i class="fas fa-hourglass-end"></i>Expired products
                </button>
                <?php if ($is_owner): // creating categories is owner-only (the server refuses it for employees too) ?>
                <button class="inv-btn inv-btn-primary" onclick="openCategoryModal()">
                    <i class="fas fa-plus"></i>New category
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="categories-grid" id="categoriesContainer">
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        </div>

        <!-- Products Section -->
        <div class="products-section mt-4" id="productsSection" style="display: none;">
            <div class="section-header">
                <h5>
                    <span id="selectedCategoryName">Category</span>
                    <span id="selectedCategoryBadge" class="category-badge" style="display: none;"></span>
                </h5>
                <div class="inv-section-actions">
                    <label class="inv-product-search" for="productSearch">
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input type="search" id="productSearch" placeholder="Search name, SKU or barcode" autocomplete="off" aria-label="Search products in this category">
                    </label>
                    <button id="addProductBtn" class="inv-btn inv-btn-primary" onclick="openProductModal()">
                        <i class="fas fa-plus"></i>Add product<span id="addProductCategoryName" hidden>Category</span>
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="product-table">
                    <thead>
                        <tr>
                            <th style="width:26%;">Product</th>
                            <th style="width:9%;">Unit</th>
                            <th class="inv-num" style="width:9%;">Cost</th>
                            <th class="inv-num" style="width:9%;">Price</th>
                            <th class="inv-num" style="width:7%;">Stock</th>
                            <th class="inv-num" style="width:8%;">In stock</th>
                            <th style="width:12%;">Expiry</th>
                            <th style="width:8%;">Status</th>
                            <th style="width:12%;"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody id="productsTableBody">
                        <tr>
                            <td colspan="9" class="inv-empty-cell">
                                <p>Select a category to view its products</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Category Modal (add + edit). Field ids are read by categories.js. -->
    <div class="modal fade inv-form-modal cat-form-modal" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="inv-form-head">
                    <div class="inv-form-head-text">
                        <h2 class="inv-form-title" id="categoryModalTitle">Add category</h2>
                        <p class="inv-form-sub" id="categoryModalSub">Group products so they are easy to find at the POS.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="categoryForm" onsubmit="event.preventDefault(); saveCategory();">
                        <input type="hidden" id="categoryId">
                        <input type="hidden" id="categoryIcon" value="fa-paw">

                        <!-- How the category will look in the list -->
                        <div class="cat-preview" aria-hidden="true">
                            <span class="cat-preview-icon" id="catPreviewIcon"><i class="fas fa-paw"></i></span>
                            <div class="cat-preview-text">
                                <strong id="catPreviewName">Category name</strong>
                                <span id="catPreviewDesc">Description</span>
                            </div>
                            <span class="cat-preview-status" id="catPreviewStatus">Active</span>
                        </div>

                        <section class="inv-form-section">
                            <div class="inv-field">
                                <label class="form-label" for="categoryName">Name <span class="inv-req">*</span></label>
                                <input type="text" class="form-control" id="categoryName" placeholder="e.g. Dog Food" maxlength="100" required>
                                <small class="inv-hint">Products you add here belong to this category only.</small>
                            </div>
                            <div class="inv-field">
                                <label class="form-label" for="categoryDescription">Description <span class="inv-optional">optional</span></label>
                                <textarea class="form-control" id="categoryDescription" rows="2" placeholder="What goes in this category"></textarea>
                            </div>
                        </section>

                        <section class="inv-form-section">
                            <h3 class="inv-form-section-title">Icon</h3>
                            <div class="icon-selector" id="iconSelector" role="radiogroup" aria-label="Category icon"></div>
                        </section>

                        <section class="inv-form-section">
                            <div class="cat-form-row">
                                <div class="inv-field">
                                    <span class="form-label" id="catColorLabel">Colour</span>
                                    <div class="cat-swatches" role="radiogroup" aria-labelledby="catColorLabel">
                                        <button type="button" class="cat-swatch" data-color="#2c5530" style="--sw:#2c5530" aria-label="Green"></button>
                                        <button type="button" class="cat-swatch" data-color="#4a6fa5" style="--sw:#4a6fa5" aria-label="Blue"></button>
                                        <button type="button" class="cat-swatch" data-color="#1abc9c" style="--sw:#1abc9c" aria-label="Teal"></button>
                                        <button type="button" class="cat-swatch" data-color="#e67e22" style="--sw:#e67e22" aria-label="Orange"></button>
                                        <button type="button" class="cat-swatch" data-color="#e74c3c" style="--sw:#e74c3c" aria-label="Red"></button>
                                        <button type="button" class="cat-swatch" data-color="#9b59b6" style="--sw:#9b59b6" aria-label="Purple"></button>
                                        <button type="button" class="cat-swatch" data-color="#8b4513" style="--sw:#8b4513" aria-label="Brown"></button>
                                        <label class="cat-swatch cat-swatch-custom" title="Pick any colour">
                                            <input type="color" id="categoryColor" value="#4a6fa5" aria-label="Custom colour">
                                            <i class="fas fa-eye-dropper" aria-hidden="true"></i>
                                        </label>
                                    </div>
                                </div>
                                <div class="inv-field">
                                    <label class="form-label" for="categoryStatus">Status</label>
                                    <select class="form-select" id="categoryStatus">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                    <small class="inv-hint">Inactive categories are hidden from the POS.</small>
                                </div>
                            </div>
                        </section>
                    </form>
                </div>
                <div class="inv-form-foot">
                    <button type="button" class="inv-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="inv-btn inv-btn-primary" id="categorySaveBtn" onclick="saveCategory()">Save category</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Modal — original + ADDED: unit, cost_price, expiration, smart stock/barcode -->
    <div class="modal fade inv-form-modal" id="productModal" tabindex="-1" aria-labelledby="productModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable inv-form-wide">
            <div class="modal-content">
                <div class="inv-form-head">
                    <div>
                        <h2 class="inv-form-title" id="productModalTitle">Add product</h2>
                        <p class="inv-form-sub">Fields marked <span class="inv-req">*</span> are required.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="productForm" onsubmit="event.preventDefault(); saveProduct();">
                        <input type="hidden" id="productId">

                        <!-- Basics -->
                        <section class="inv-form-section">
                            <div class="inv-form-grid">
                                <div class="inv-field">
                                    <label class="form-label" for="productCategory" id="productCategoryLabel">Category <span class="inv-req">*</span></label>
                                    <select class="form-select" id="productCategory" required onchange="onCategoryChange()">
                                        <option value="">Select category</option>
                                    </select>
                                    <!-- Adding a product: fixed to the category it was opened from -->
                                    <div class="inv-locked-field" id="productCategoryLocked" hidden>
                                        <i class="fas fa-tag" aria-hidden="true"></i>
                                        <span id="productCategoryLockedName"></span>
                                    </div>
                                </div>
                                <div class="inv-field">
                                    <!-- Unit / Measurement — the select sets the hidden productUnit -->
                                    <input type="hidden" id="productUnit" value="">
                                    <label class="form-label" for="productUnitType">Sold by <span class="inv-req">*</span></label>
                                    <select class="form-select" id="productUnitType" required onchange="onUnitTypeChange()">
                                        <option value="">Select unit…</option>
                                        <option value="Per Piece">Per Piece</option>
                                        <option value="Per Kilo">Per Kilo</option>
                                        <option value="Per Gram">Per Gram</option>
                                        <option value="Per Sack">Per Sack</option>
                                        <option value="Per Pack">Per Pack</option>
                                        <option value="Per Box">Per Box</option>
                                        <option value="Per Can">Per Can</option>
                                        <option value="Per Pouch">Per Pouch</option>
                                        <option value="Per Bottle">Per Bottle</option>
                                        <option value="Per Sachet">Per Sachet</option>
                                    </select>
                                </div>
                            </div>
                            <div id="unitPreview" class="unit-preview" style="display:none;"></div>
                            <div id="unitHint" class="unit-hint" style="display:none;"></div>

                            <div class="inv-field">
                                <label class="form-label" for="productName">Product name <span class="inv-req">*</span></label>
                                <input type="text" class="form-control" id="productName" required autocomplete="off">
                            </div>
                            <div class="inv-field">
                                <label class="form-label" for="productDescription">Description <span class="inv-optional">optional</span></label>
                                <textarea class="form-control" id="productDescription" rows="2"></textarea>
                            </div>
                        </section>

                        <!-- Photo -->
                        <section class="inv-form-section">
                            <h3 class="inv-form-section-title">Photo <span class="inv-optional">JPG, PNG or WebP · up to 2 MB</span></h3>
                            <input type="hidden" id="productImage" value="">
                            <div class="image-upload-zone" id="imageUploadZone">
                                <div class="image-preview" id="imagePreview" style="display:none;">
                                    <img id="imagePreviewImg" src="" alt="Product preview">
                                    <button type="button" class="image-remove-btn" onclick="removeProductImage()" title="Remove photo" aria-label="Remove photo">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <div class="image-upload-placeholder" id="imageUploadPlaceholder">
                                    <i class="fas fa-image"></i>
                                    <p><span class="inv-link">Choose a photo</span> or drag it here</p>
                                </div>
                                <input type="file" id="imageFileInput" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;">
                            </div>
                        </section>

                        <!-- Price & stock -->
                        <section class="inv-form-section">
                            <h3 class="inv-form-section-title">Price &amp; stock</h3>
                            <div class="inv-form-grid inv-form-grid-3">
                                <div class="inv-field">
                                    <label class="form-label" for="productCostPrice">Cost <span class="inv-optional inv-per-kg" hidden>per kg ·</span> <span class="inv-optional">optional</span></label>
                                    <div class="inv-money">
                                        <span>₱</span>
                                        <input type="text" class="form-control" id="productCostPrice" inputmode="decimal" data-numeric="money" maxlength="12" autocomplete="off" placeholder="0.00">
                                    </div>
                                </div>
                                <div class="inv-field">
                                    <label class="form-label" for="productPrice">Selling price <span class="inv-optional inv-per-kg" hidden>per kg</span> <span class="inv-req">*</span></label>
                                    <div class="inv-money">
                                        <span>₱</span>
                                        <input type="text" class="form-control" id="productPrice" inputmode="decimal" data-numeric="money" maxlength="12" autocomplete="off" placeholder="0.00" required>
                                    </div>
                                </div>
                                <!-- Stock: hidden for Per Gram (auto=1); kilograms (decimals) for Per Kilo; whole numbers otherwise -->
                                <div class="inv-field" id="stockFieldWrapper" style="display:none;">
                                    <label class="form-label" for="productStock">Stock <span class="inv-optional inv-per-kg" hidden>in kg</span> <span class="inv-req">*</span></label>
                                    <input type="text" class="form-control" id="productStock" inputmode="numeric" data-numeric="int" maxlength="7" autocomplete="off" placeholder="0">
                                </div>
                            </div>
                            <p class="inv-hint" id="marginHint" aria-live="polite"></p>
                        </section>

                        <!-- Codes -->
                        <section class="inv-form-section">
                            <h3 class="inv-form-section-title">Codes</h3>
                            <div class="inv-form-grid">
                                <div class="inv-field">
                                    <label class="form-label" for="productSku">SKU</label>
                                    <input type="text" class="form-control" id="productSku" placeholder="e.g. DOG-001" autocomplete="off">
                                    <span class="inv-hint">Leave empty to generate one</span>
                                </div>
                                <!-- Barcode: optional for weight, required for packaged/medicine -->
                                <div class="inv-field">
                                    <label class="form-label" for="productBarcode">
                                        Barcode<span id="barcodeRequiredStar" class="inv-req" style="display:none;"> *</span>
                                        <span id="barcodeOptionalTag" class="inv-optional">optional</span>
                                    </label>
                                    <input type="text" class="form-control" id="productBarcode" placeholder="Scan or type" autocomplete="off">
                                </div>
                            </div>
                        </section>

                        <!-- Dates -->
                        <section class="inv-form-section">
                            <h3 class="inv-form-section-title">Dates</h3>
                            <div class="inv-form-grid">
                                <!-- Expiration: hidden for accessories -->
                                <div class="inv-field" id="expirationRow">
                                    <label class="form-label" for="productExpiration">Expires <span class="inv-optional">optional</span></label>
                                    <input type="date" class="form-control" id="productExpiration">
                                    <div id="expirationWarning" class="inv-hint" style="display:none;"></div>
                                </div>
                                <div class="inv-field">
                                    <label class="form-label" for="productDateAdded">Received</label>
                                    <input type="date" class="form-control" id="productDateAdded">
                                    <span class="inv-hint">Counts the days in stock used by recommendations</span>
                                </div>
                            </div>
                        </section>

                        <button type="submit" hidden></button>
                    </form>
                </div>
                <div class="inv-form-foot">
                    <button type="button" class="inv-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="inv-btn inv-btn-primary" id="saveProductBtn" onclick="saveProduct()">Save product</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stock Update Modal — UNCHANGED -->
    <div class="modal fade inv-form-modal" id="stockModal" tabindex="-1" aria-labelledby="stockModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="inv-form-head">
                    <div class="inv-form-head-text">
                        <h2 class="inv-form-title" id="stockModalTitle">Manage stock</h2>
                        <p class="inv-form-sub" id="stockProductName"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="stockForm" onsubmit="event.preventDefault(); updateStock();">
                        <input type="hidden" id="stockProductId">

                        <!-- Summary: stock on hand + sellable status -->
                        <div class="inv-stock-summary">
                            <div>
                                <span class="inv-summary-label">In stock</span>
                                <span class="inv-summary-value" id="currentStock"></span>
                            </div>
                            <div class="inv-field inv-status-field">
                                <label class="form-label" for="stockProductStatus">Status</label>
                                <select class="form-select" id="stockProductStatus">
                                    <option value="active">Active — sold in POS</option>
                                    <option value="inactive">Inactive — hidden from POS</option>
                                </select>
                            </div>
                        </div>

                        <div class="inv-field">
                            <label class="form-label" for="stockAction">What do you want to do?</label>
                            <select class="form-select" id="stockAction">
                                <option value="add">Add stock</option>
                                <option value="remove">Remove stock</option>
                                <option value="none">Only change status</option>
                            </select>
                        </div>

                        <div class="inv-field" id="stockQtyContainer">
                            <label class="form-label" id="qtyLabel" for="stockQuantity">Quantity</label>
                            <input type="text" class="form-control" id="stockQuantity" inputmode="numeric" data-numeric="int" maxlength="7" autocomplete="off" placeholder="How many units?">
                        </div>

                        <div id="batchOptionsContainer" style="display: none;">
                            <div class="inv-segmented" role="radiogroup" aria-label="Batch">
                                <input type="radio" class="btn-check" name="batchAction" id="batchActionNew" value="new" checked>
                                <label for="batchActionNew">New batch</label>
                                <input type="radio" class="btn-check" name="batchAction" id="batchActionExisting" value="existing">
                                <label for="batchActionExisting">Add to existing batch</label>
                            </div>

                            <div class="inv-field" id="existingBatchContainer" style="display: none;">
                                <label class="form-label" for="existingBatchSelect">Batch</label>
                                <select class="form-select" id="existingBatchSelect">
                                    <option value="">Loading batches...</option>
                                </select>
                            </div>

                            <div id="newBatchContainer" class="inv-field">
                                <div id="batchExpirationRow">
                                    <label class="form-label" for="batchExpirationDate">Expiry of this delivery <span class="inv-optional">optional</span></label>
                                    <input type="date" class="form-control" id="batchExpirationDate">
                                    <span class="inv-hint">Tracked as its own batch — the one expiring soonest is sold first.</span>
                                </div>
                                <input type="hidden" id="batchDateAdded" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>

                        <div class="inv-field" id="removeBatchContainer" style="display: none;">
                            <label class="form-label" for="removeBatchSelect">Take from</label>
                            <select class="form-select" id="removeBatchSelect">
                                <option value="auto">Automatic FIFO (Oldest First)</option>
                            </select>
                        </div>

                        <!-- Batches on hand -->
                        <div id="displayBatchesContainer" class="inv-batches" style="display: none;">
                            <h3 class="inv-form-section-title">Batches on hand <span class="inv-optional">sold top to bottom · click one to change its expiry</span></h3>
                            <div id="batchesList"></div>
                            <div id="batchMismatchWarning" class="alert alert-warning py-2 px-3 mt-2 mb-0 small" role="alert" style="display: none;"></div>
                        </div>

                        <button type="submit" hidden></button>
                    </form>
                </div>
                <div class="inv-form-foot">
                    <button type="button" class="inv-btn" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="inv-btn inv-btn-primary" onclick="updateStock()">Save</button>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/user_roles_modal.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const isOwner = <?php echo $is_owner ? 'true' : 'false'; ?>;
        const currentUser = { id: '<?php echo $user_id; ?>' };
        <?php include 'includes/notifications.php'; ?>
    </script>
    <script src="assets/js/numeric-input.js?v=<?= filemtime(__DIR__ . '/assets/js/numeric-input.js') ?>"></script>
    <script src="assets/js/confirm-dialog.js?v=<?= filemtime(__DIR__ . '/assets/js/confirm-dialog.js') ?>"></script>
    <script src="assets/js/categories.js?v=<?= filemtime(__DIR__ . '/assets/js/categories.js') ?>"></script>
    <script src="assets/js/userManagement.js?v=<?= filemtime(__DIR__ . '/assets/js/userManagement.js') ?>"></script>
    <script src="assets/js/notif.js?v=<?= filemtime(__DIR__ . '/assets/js/notif.js') ?>"></script>
    <script src="assets/js/sidebar-nav.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            loadCategories();
            populateIconSelector();
            loadCategoryOptions();
            <?php if ($toast_message): ?>
                showToast('<?php echo $toast_message['type']; ?>', '<?php echo $toast_message['title']; ?>', '<?php echo $toast_message['message']; ?>');
            <?php endif; ?>
        });
    </script>
</body>

</html>
