<?php
// categories.php - ORIGINAL + added unit, cost_price, expiration fields
session_start();
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Categories · Espenida's Pet & Poultry Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/reco.css">
    <link rel="stylesheet" href="assets/css/categories.css">
    <link rel="stylesheet" href="assets/css/transitions.css">
    <link rel="stylesheet" href="assets/css/notif.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <script src="assets/js/responsive.js"></script>
</head>

<body class="page-loading" onload="document.body.classList.remove('page-loading')">
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="spinner-overlay" id="loadingSpinner">
        <div class="loading-spinner"></div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <!-- SIDEBAR — UNCHANGED -->
    <div class="sidebar" id="sidebar">
        <div class="store-brand">
            <div class="logo-container">
                <img src="assets/images/sidebar.jpg" alt="Espenida's Logo" class="store-logo-img">
            </div>
            <div class="store-name">
                <span class="store-name-main">Espenida's</span>
                <span class="store-name-sub">PET & POULTRY SUPPLY</span>
            </div>
        </div>

        <button class="collapse-btn" id="collapseBtn">
            <i class="fas fa-chevron-left" id="collapseIcon"></i>
            <span>Espenida Store</span>
        </button>

        <div class="sidebar-user-info">
            <div class="user-avatar-small">
                <?php echo strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1)); ?>
            </div>
            <div class="user-details-small">
                <h4><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></h4>
                <span><?php echo htmlspecialchars($position ?: ucfirst($role)); ?></span>
            </div>
        </div>

        <div class="section-title"><span>DASHBOARD</span></div>
        <a href="dashboard.php" class="dashboard-link">
            <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
        </a>

        <div class="section-title"><span>POINT OF SALE</span></div>
        <div class="dropdown">
            <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-cash-register"></i><span>Point of Sale</span>
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="PosUI_db.php"><i class="fas fa-cash-register"></i>Point of Sale</a>
                </li>
                <?php if ($is_owner): ?>
                <li><a class="dropdown-item" href="sales_transactions.php"><i class="fas fa-history"></i>Sales
                        Transactions</a></li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="section-title"><span>INVENTORY MANAGEMENT</span></div>
        <div class="dropdown">
            <button class="dropdown-toggle active" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-box"></i><span>Inventory Management</span>
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="categories.php"><i class="fa-solid fa-layer-group"></i>Inventory</a></li> 
                <li><a class="dropdown-item" href="Archive_products.php"><i class="fa-solid fa-box-archive"></i> Archive</a></li> 
                <li><a class="dropdown-item" href="Inventory_management.php"><i class="fa-solid fa-arrow-trend-down"></i>Log History</a></li> 
            </ul>
        </div>

        <?php if ($is_owner): ?>
        <div class="section-title"><span>RECOMMENDATIONS</span></div>
        <button class="recommendation-btn" id="recommendationBtn">
            <i class="fas fa-lightbulb"></i><span>Recommendations</span>
        </button>
        <?php endif; ?>

        <?php if ($is_owner): ?>
            <div class="section-title"><span>SYSTEM & ADMIN</span></div>
            <div class="dropdown">
                <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-solid fa-user"></i><span>System & Admin</span>
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#"><i class="fas fa-lock"></i> User Roles</a></li>
                    <li><a class="dropdown-item" href="#"><i class="fas fa-database"></i> Backup & Restore</a></li>
                    <li><a class="dropdown-item" href="#"><i class="fas fa-cloud"></i> Sync</a></li>
                    <li><a class="dropdown-item" href="#"><i class="fas fa-cog"></i> Settings</a></li>
                </ul>
            </div>
        <?php endif; ?>

        <div class="spacer"></div>
        <button class="logout-btn" id="logoutBtn">
            <i class="fas fa-sign-out-alt"></i><span>Log out</span>
        </button>
    </div>

    <!-- MAIN CONTENT AREA -->
    <div class="main-content">
        <div class="welcome-banner">
            <div class="welcome-text">
                <h1><i class="fas fa-paw me-2"></i>Inventory</h1>
                <p>Manage your pet & poultry product categories and inventory</p>
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

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0"><i class="fas fa-layer-group me-2" style="color: #4a6fa5;"></i>All Categories</h5>
            <div>
                <a href="archive_products.php" class="btn btn-outline-secondary btn-sm me-2">
                    <i class="fas fa-box-archive me-1"></i>View Archive
                </a>
                <button class="btn btn-outline-danger btn-sm me-2" onclick="showExpiredProducts()">
                    <i class="fas fa-exclamation-triangle me-1"></i>Expired Products
                </button>
                <button class="btn btn-primary btn-sm" onclick="openCategoryModal()">
                    <i class="fas fa-plus-circle me-1"></i>New Category
                </button>
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
                    <i class="fas fa-box me-2" style="color: #4a6fa5;"></i>
                    Products in <span id="selectedCategoryName">Category</span>
                    <span id="selectedCategoryBadge" class="category-badge" style="display: none;"></span>
                </h5>
                <button id="addProductBtn" class="btn btn-sm btn-primary" onclick="openProductModal()">
                    <i class="fas fa-plus me-1"></i>Add Product to <span id="addProductCategoryName">Category</span>
                </button>
            </div>

            <div class="table-responsive">
                <table class="product-table">
                    <thead>
                        <tr>
                            <th style="width:7%;">Image</th>
                            <th style="width:18%;">Product</th>
                            <th style="width:9%;">Unit</th>
                            <th style="width:9%;">Cost Price</th>
                            <th style="width:9%;">Selling Price</th>
                            <th style="width:9%;">Stock</th>
                            <th style="width:9%;">Days In</th>
                            <th style="width:10%;">Expiry</th>
                            <th style="width:10%;">Status</th>
                            <th style="width:10%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="productsTableBody">
                        <tr>
                            <td colspan="10" class="text-center py-4">
                                <i class="fas fa-box-open fa-2x text-muted mb-2"></i>
                                <p class="text-muted">Select a category to view its products</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Category Modal — UNCHANGED -->
    <div class="modal fade" id="categoryModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="categoryModalTitle">
                        <i class="fas fa-layer-group me-2"></i>Add New Category
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="categoryForm">
                        <input type="hidden" id="categoryId">
                        <div class="mb-3">
                            <label class="form-label">Category Name</label>
                            <input type="text" class="form-control" id="categoryName" placeholder="e.g., Dog Food"
                                required>
                            <small class="text-muted">Products added to this category will be specific to this category
                                only</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="categoryDescription" rows="2"
                                placeholder="Enter category description"></textarea>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Icon Color</label>
                                <input type="color" class="form-control form-control-color" id="categoryColor"
                                    value="#4a6fa5">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="categoryStatus">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Icon</label>
                            <input type="text" class="form-control" id="categoryIcon" value="fa-paw" readonly>
                            <div class="icon-selector" id="iconSelector"></div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="saveCategory()">Save Category</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Modal — original + ADDED: unit, cost_price, expiration, smart stock/barcode -->
    <div class="modal fade" id="productModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="productModalTitle">
                        <i class="fas fa-box me-2"></i>Add New Product
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="productForm">
                        <input type="hidden" id="productId">

                        <div class="mb-3">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select class="form-select" id="productCategory" required onchange="onCategoryChange()">
                                <option value="">Select Category</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Product Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="productName" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" id="productDescription" rows="2"></textarea>
                        </div>

                        <!-- Product Image Upload -->
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-camera me-1" style="color:#8B4513;"></i>
                                Product Image
                                <span class="text-muted" style="font-size:11px;">(JPG, PNG, WebP — max 2MB)</span>
                            </label>
                            <input type="hidden" id="productImage" value="">
                            <div class="image-upload-zone" id="imageUploadZone">
                                <div class="image-preview" id="imagePreview" style="display:none;">
                                    <img id="imagePreviewImg" src="" alt="Product preview">
                                    <button type="button" class="image-remove-btn" onclick="removeProductImage()" title="Remove image">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <div class="image-upload-placeholder" id="imageUploadPlaceholder">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Drag & drop or <span class="text-primary">browse</span></p>
                                    <small>JPG, PNG, WebP — max 2MB</small>
                                </div>
                                <input type="file" id="imageFileInput" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;">
                            </div>
                        </div>

                        <!-- Unit / Measurement — Two-field approach -->
                        <input type="hidden" id="productUnit" value="">
                        <div class="mb-3">
                            <label class="form-label">Unit / Measurement <span class="text-danger">*</span></label>
                            <div class="row g-2 align-items-start">
                                <div class="col-md-12">
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
                            <div id="unitPreview" class="unit-preview mt-2" style="display:none;"></div>
                            <div id="unitHint" class="unit-hint mt-1" style="display:none;"></div>
                        </div>

                        <div class="row">
                            <!-- ADDED: Cost Price -->
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Cost Price (₱)</label>
                                <input type="number" class="form-control" id="productCostPrice" step="0.01" min="0"
                                    placeholder="0.00">
                                <small class="text-muted">Supplier cost</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Selling Price (₱) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="productPrice" step="0.01" min="0"
                                    required>
                            </div>
                            <!-- Stock: hidden for weight (auto=1), shown for packaged/medicine -->
                            <div class="col-md-4 mb-3" id="stockFieldWrapper" style="display:none;">
                                <label class="form-label">Stock <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="productStock" min="1">
                                <small class="text-muted">Units in stock</small>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SKU (Stock Keeping Unit)</label>
                                <input type="text" class="form-control" id="productSku" placeholder="e.g., DOG-001">
                                <small class="text-muted">Leave empty to auto-generate</small>
                            </div>
                            <!-- Barcode: optional for weight, required for packaged/medicine -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    Barcode
                                    <span id="barcodeRequiredStar" class="text-danger" style="display:none;"> *</span>
                                    <span id="barcodeOptionalTag" class="text-muted" style="font-size:11px;">
                                        (optional)</span>
                                </label>
                                <input type="text" class="form-control" id="productBarcode"
                                    placeholder="Scan or enter barcode">
                                <small class="text-muted">For scanner use</small>
                            </div>
                        </div>

                        <!-- ADDED: Expiration Date — hidden for accessories -->
                        <div class="mb-3" id="expirationRow">
                            <label class="form-label">
                                <i class="fas fa-calendar-times me-1 text-warning"></i>
                                Expiration Date
                                <span class="text-muted" style="font-size:11px;">(leave blank if no expiration)</span>
                            </label>
                            <div class="row">
                                <div class="col-md-6">
                                    <input type="date" class="form-control" id="productExpiration">
                                </div>
                                <div class="col-md-6 d-flex align-items-center">
                                    <div id="expirationWarning" style="font-size:12px; display:none;"></div>
                                </div>
                            </div>
                        </div>

                        <!-- ADDED: Date Added for ML Tracking -->
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-calendar-plus me-1 text-info"></i>
                                Date Added
                                <span class="text-muted" style="font-size:11px;">(for ML tracking &mdash; defaults to
                                    today)</span>
                            </label>
                            <div class="row">
                                <div class="col-md-6">
                                    <input type="date" class="form-control" id="productDateAdded">
                                </div>
                                <div class="col-md-6 d-flex align-items-center">
                                    <small class="text-muted"><i class="fas fa-info-circle me-1"></i>Used to calculate
                                        "Days In Stock" for recommendations</small>
                                </div>
                            </div>
                        </div>

                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="saveProduct()">Save Product</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stock Update Modal — UNCHANGED -->
    <div class="modal fade" id="stockModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-boxes me-2"></i>Manage Stock & Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="stockForm">
                        <input type="hidden" id="stockProductId">
                        <div class="mb-3">
                            <label class="form-label">Product</label>
                            <p class="form-control-static fw-bold" id="stockProductName"></p>
                        </div>
                        <div class="row mb-3">
                            <div class="col-6">
                                <label class="form-label">Current Stock</label>
                                <p class="form-control-static" id="currentStock"></p>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Status</label>
                                <select class="form-select" id="stockProductStatus">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <hr>
                        <div class="mb-3">
                            <label class="form-label">Stock Action</label>
                            <select class="form-select" id="stockAction">
                                <option value="add">Add Stock</option>
                                <option value="remove">Remove Stock</option>
                                <option value="none">None (Update Status Only)</option>
                            </select>
                        </div>
                        
                        <div id="displayBatchesContainer" class="mb-3" style="display: none;">
                            <label class="form-label text-muted fw-bold small mb-2"><i class="fas fa-box"></i> Current batches (SELL FIRST = top):</label>
                            <div id="batchesList" class="d-flex flex-column gap-2 bg-light p-2 rounded" style="max-height: 200px; overflow-y: auto;">
                                <!-- Batches will be injected here -->
                            </div>
                        </div>

                        <div class="mb-3" id="stockQtyContainer">
                            <label class="form-label" id="qtyLabel" style="font-weight: 600;">Adjustment <span class="text-muted fw-normal" style="font-size:12px;">(+restock / -deduct)</span></label>
                            <input type="number" class="form-control" id="stockQuantity" min="1" placeholder="Enter quantity">
                        </div>

                        <div id="batchOptionsContainer" style="display: none;">
                            <div class="d-flex gap-2 mb-3">
                                <input type="radio" class="btn-check" name="batchAction" id="batchActionNew" value="new" checked>
                                <label class="btn btn-outline-primary flex-fill" for="batchActionNew">New Batch</label>

                                <input type="radio" class="btn-check" name="batchAction" id="batchActionExisting" value="existing">
                                <label class="btn btn-outline-success flex-fill" for="batchActionExisting">Same Batch</label>
                            </div>
                            
                            <div class="mb-3" id="existingBatchContainer" style="display: none;">
                                <label class="form-label">Select Batch</label>
                                <select class="form-select" id="existingBatchSelect">
                                    <option value="">Loading batches...</option>
                                </select>
                            </div>
                            
                            <div id="newBatchContainer" class="p-3 mb-3 rounded" style="background-color: #f4f6fb; border-left: 4px solid #6b88c4;">
                                <h6><b>New Batch &mdash; FIFO tracked separately</b></h6>
                                <p class="text-muted small mb-3">This restock will be a separate batch. The system sells the nearest-expiry / oldest batch first.</p>
                                
                                <div class="mb-3" id="batchExpirationRow">
                                    <label class="form-label" style="font-size: 13px; font-weight: 600; color: #5a6b8c;">Expiry date of this new batch <span class="text-muted fw-normal">(leave blank if none)</span></label>
                                    <input type="date" class="form-control" id="batchExpirationDate">
                                </div>
                                <input type="hidden" id="batchDateAdded" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>

                        <div class="mb-3" id="removeBatchContainer" style="display: none;">
                            <label class="form-label fw-bold">Remove From Batch</label>
                            <select class="form-select" id="removeBatchSelect">
                                <option value="auto">Automatic FIFO (Oldest First)</option>
                            </select>
                        </div>

                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="updateStock()">Update Stock</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const isOwner = <?php echo $is_owner ? 'true' : 'false'; ?>;
        const currentUser = { id: '<?php echo $user_id; ?>' };
        <?php include 'includes/notifications.php'; ?>
    </script>
    <script src="assets/js/categories.js"></script>
    <script src="assets/js/notif.js"></script>
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