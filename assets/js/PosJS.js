let cart     = [];
let products = [];
let usedGcashReferences = new Set(); // Track used GCash reference numbers

// DOM Elements
const productsGrid = document.getElementById('productsGrid');
const cartItemsEl  = document.getElementById('cartItems');
const searchInput  = document.getElementById('searchInput');
const barcodeInput = document.getElementById('barcodeInput');
const clearCartBtn = document.getElementById('clearCartBtn');
const paymentInput = document.getElementById('paymentAmount');
const checkoutBtn  = document.getElementById('checkoutBtn');
const subtotalSpan = document.getElementById('subtotal');
const totalSpan    = document.getElementById('total');
const changeSpan   = document.getElementById('changeAmount');

// Inject editable quantity input styles
(function injectQtyInputStyles() {
    if (document.getElementById('qtyInputStyles')) return;
    const style = document.createElement('style');
    style.id = 'qtyInputStyles';
    style.textContent = `
        .qty-input {
            width: 48px;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            padding: 2px 4px;
            outline: none;
            background: #f8fafc;
            color: #1e293b;
            transition: border-color 0.18s, box-shadow 0.18s;
            -moz-appearance: textfield;
        }
        .qty-input::-webkit-outer-spin-button,
        .qty-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        .qty-input:focus {
            border-color: #2c5530;
            box-shadow: 0 0 0 2px rgba(44,85,48,0.15);
            background: #fff;
        }
        .product-card.discounted {
            border: 2px solid #e74c3c;
            box-shadow: 0 4px 12px rgba(231, 76, 60, 0.15);
        }
    `;
    document.head.appendChild(style);
})();

// Helper Functions
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
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
    const bgColor = type === 'success' ? 'bg-success' : 
                    (type === 'error' ? 'bg-danger' : 
                    (type === 'warning' ? 'bg-warning' : 'bg-info'));
    
    const toastHtml = `
        <div id="${toastId}" class="toast align-items-center text-white ${bgColor} border-0 mb-2" role="alert">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas ${icon} me-2"></i>
                    <strong>${escapeHtml(title)}</strong> ${escapeHtml(message)}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>`;
    
    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    const toastEl = document.getElementById(toastId);
    
    if (toastEl) {
        const toast = new bootstrap.Toast(toastEl, { autohide: true, delay: 3000 });
        toast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }
}

function showLoading() {
    let spinner = document.getElementById('loadingSpinner');
    if (!spinner) {
        spinner = document.createElement('div');
        spinner.id = 'loadingSpinner';
        spinner.className = 'spinner-overlay';
        spinner.innerHTML = '<div class="loading-spinner"></div>';
        document.body.appendChild(spinner);
        
        if (!document.querySelector('#loadingStyles')) {
            const style = document.createElement('style');
            style.id = 'loadingStyles';
            style.textContent = `
                .spinner-overlay { 
                    position: fixed; 
                    top: 0; 
                    left: 0; 
                    width: 100%; 
                    height: 100%; 
                    background: rgba(255,255,255,0.8); 
                    display: none; 
                    z-index: 9999; 
                }
                .spinner-overlay.active {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                }
                .loading-spinner { 
                    width: 50px; 
                    height: 50px; 
                    border: 5px solid #f3f3f3; 
                    border-top: 5px solid #2c5530; 
                    border-radius: 50%; 
                    animation: spin 1s linear infinite; 
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                }
                @keyframes spin { 
                    0% { transform: translate(-50%, -50%) rotate(0deg); } 
                    100% { transform: translate(-50%, -50%) rotate(360deg); } 
                }`;
            document.head.appendChild(style);
        }
    }
    spinner.classList.add('active');
}

function hideLoading() {
    const spinner = document.getElementById('loadingSpinner');
    if (spinner) spinner.classList.remove('active');
}

function getCategoryIcon(category) {
    const cat = (category || '').toLowerCase();
    if (cat.includes('dog')) return 'fa-dog';
    if (cat.includes('cat')) return 'fa-cat';
    if (cat.includes('poultry') || cat.includes('chicken')) return 'fa-dove';
    if (cat.includes('fish')) return 'fa-fish';
    if (cat.includes('accessories')) return 'fa-bone';
    if (cat.includes('medication') || cat.includes('medicine')) return 'fa-capsules';
    if (cat.includes('food')) return 'fa-bowl-food';
    return 'fa-paw';
}

function updateDateTime() {
    const el = document.getElementById('currentDateTime');
    if (!el) return;
    el.textContent = new Date().toLocaleDateString('en-US', {
        weekday: 'short', 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric',
        hour: '2-digit', 
        minute: '2-digit', 
        second: '2-digit'
    });
}
setInterval(updateDateTime, 1000);
updateDateTime();

// Load Products
function loadProducts() {
    if (!productsGrid) return;
    
    productsGrid.innerHTML = `<div class="text-center py-5">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading products...</span>
        </div>
    </div>`;
    
    $.ajax({
        url: 'ajax/category_ajax.php', 
        method: 'POST', 
        data: { action: 'get_pos_products' },
        dataType: 'json', 
        timeout: 10000,
        success: function(response) {
            if (response.success) { 
                products = response.data || []; 
                displayProducts(products); 
            } else { 
                showToast('error', 'Error', response.message || 'Failed to load products'); 
                productsGrid.innerHTML = '<div class="text-center py-5 text-danger">Failed to load products</div>'; 
            }
            setTimeout(restoreBarcodeFocus, 100);
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            showToast('error', 'Error', 'Failed to connect to server');
            productsGrid.innerHTML = '<div class="text-center py-5 text-danger">Connection error. Please refresh.</div>';
        }
    });
}

function displayProducts(productsToShow) {
    if (!productsGrid) return;
    
    if (!productsToShow || productsToShow.length === 0) { 
        productsGrid.innerHTML = '<div class="text-center py-5 text-muted">No products available</div>'; 
        return; 
    }
    
    // Sort products so discounted items appear first
    productsToShow.sort((a, b) => {
        const aDiscount = parseFloat(a.discount_applied) || 0;
        const bDiscount = parseFloat(b.discount_applied) || 0;
        if (aDiscount > 0 && bDiscount <= 0) return -1;
        if (bDiscount > 0 && aDiscount <= 0) return 1;
        return 0;
    });
    
    let html = '';
    productsToShow.forEach(product => {
        const stock = parseInt(product.stock) || 0;
        const price = parseFloat(product.price) || 0;
        const discountApplied = parseFloat(product.discount_applied) || 0;
        const originalPrice = parseFloat(product.original_price) || 0;
        const stockClass = stock <= 5 ? 'product-stock-low' : (stock <= 15 ? 'product-stock-medium' : 'product-stock-high');
        const icon = getCategoryIcon(product.category_name);
        
        const productVisual = product.image
            ? `<div class="product-image"><img src="${escapeHtml(product.image)}" alt="${escapeHtml(product.name)}" loading="lazy"></div>`
            : `<div class="product-icon"><i class="fas ${icon}"></i></div>`;
            
        const isDiscounted = discountApplied > 0;
        const discountedClass = isDiscounted ? 'discounted' : '';
        const discountBadge = isDiscounted ? `<span class="product-badge discount-badge" style="background:#e74c3c; left:8px; right:auto;">-${discountApplied}% OFF</span>` : '';
        const priceHtml = isDiscounted 
            ? `<div class="product-price"><span style="text-decoration: line-through; color: #94a3b8; font-size: 0.85em; margin-right: 5px;">₱${originalPrice.toFixed(2)}</span><span style="color:#e74c3c">₱${price.toFixed(2)}</span></div>` 
            : `<div class="product-price">₱${price.toFixed(2)}</div>`;
        
        html += `
            <div class="product-card ${stock <= 0 ? 'out-of-stock' : ''} ${discountedClass}"
                 data-id="${product.id}" 
                 data-category="${product.category_id || ''}"
                 data-name="${escapeHtml(product.name)}" 
                 data-price="${price}"
                 data-stock="${stock}" 
                 data-sku="${escapeHtml(product.sku || '')}"
                 data-barcode="${escapeHtml(product.barcode || '')}"
                 data-image="${escapeHtml(product.image || '')}"
                 data-strategy="${escapeHtml(product.strategy_id || '')}">
                ${stock <= 5 && stock > 0 ? '<span class="product-badge">Low Stock</span>' : ''}
                ${stock <= 0 ? '<span class="product-badge">Out of Stock</span>' : ''}
                ${discountBadge}
                ${productVisual}
                <div class="product-info">
                    <h3>${escapeHtml(product.name)}</h3>
                    <div class="product-category">${escapeHtml(product.category_name || 'Uncategorized')}</div>
                    ${priceHtml}
                    <div class="product-stock ${stockClass}"><i class="fas fa-box"></i> Stock: ${stock}</div>
                </div>
            </div>`;
    });
    
    productsGrid.innerHTML = html;
    attachProductClickHandlers();
}

function attachProductClickHandlers() {
    document.querySelectorAll('.product-card').forEach(card => {
        card.addEventListener('click', function() {
            if (this.classList.contains('out-of-stock')) { 
                showToast('warning', 'Out of Stock', 'This product is not available'); 
                return; 
            }
            addToCart(
                parseInt(this.dataset.id), 
                this.dataset.name, 
                parseFloat(this.dataset.price), 
                parseInt(this.dataset.stock),
                this.dataset.image || '',
                this.dataset.strategy || ''
            );
        });
    });
}

// Barcode Scanner
function isBarcodeCapturePaused() {
    if (document.querySelector('.modal.show')) return true;
    const active = document.activeElement;
    if (!active) return false;
    if (active === barcodeInput) return false;
    if (active.classList && active.classList.contains('qty-input')) return true;
    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName);
}

function restoreBarcodeFocus() {
    if (!barcodeInput || isBarcodeCapturePaused()) return;
    barcodeInput.focus();
}

document.addEventListener('click', function(e) {
    const isInteractive = ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON', 'A', 'LABEL'].includes(e.target.tagName) ||
                          e.target.closest('.modal') || 
                          e.target.closest('.dropdown-menu');
    if (!isInteractive) restoreBarcodeFocus();
});

document.addEventListener('hidden.bs.modal', function() { 
    setTimeout(restoreBarcodeFocus, 150); 
});

window.addEventListener('focus', function() { 
    setTimeout(restoreBarcodeFocus, 100); 
});

if (barcodeInput) {
    barcodeInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const barcode = this.value.trim();
            if (barcode) findProductByBarcode(barcode);
            this.value = '';
        }
    });
}

function findProductByBarcode(barcode) {
    showLoading();
    const dot = document.getElementById('scannerDot');
    if (dot) dot.classList.add('scanning');
    
    $.ajax({
        url: 'ajax/category_ajax.php', 
        method: 'POST',
        data: { action: 'get_product_by_barcode', barcode: barcode },
        dataType: 'json', 
        timeout: 8000,
        success: function(response) {
            hideLoading(); 
            if (dot) dot.classList.remove('scanning');
            
            if (response.success && response.data) {
                const p = response.data;
                const id = parseInt(p.id) || 0;
                const name = p.name || '';
                const price = parseFloat(p.price) || 0;
                const stock = parseInt(p.stock) || 0;
                
                if (!id || !name) {
                    showToast('error', 'Not Found', 'Product data is incomplete');
                } else if (stock > 0) { 
                    addToCart(id, name, price, stock, p.image || '', p.strategy_id || ''); 
                    showToast('success', 'Product Found', `Added "${name}" to cart`); 
                } else {
                    showToast('error', 'Out of Stock', `"${name}" is out of stock`);
                }
            } else {
                showToast('error', 'Not Found', 'No product matched that barcode');
            }
            setTimeout(restoreBarcodeFocus, 100);
        },
        error: function() { 
            hideLoading(); 
            if (dot) dot.classList.remove('scanning'); 
            showToast('error', 'Error', 'Failed to reach server'); 
            setTimeout(restoreBarcodeFocus, 100); 
        }
    });
}

// Cart Functions
function addToCart(productId, productName, productPrice, productStock, productImage, strategyId) {
    const qtyToAdd = (strategyId === 'buy_one_take_one') ? 2 : 1;

    if (productStock < qtyToAdd) { 
        showToast('error', 'Out of Stock', `This product requires at least ${qtyToAdd} in stock.`); 
        return; 
    }
    
    const existing = cart.find(item => item.id === productId);
    
    if (existing) {
        if (existing.quantity + qtyToAdd <= productStock) { 
            existing.quantity += qtyToAdd; 
            showToast('success', 'Quantity Updated', `${productName} quantity increased by ${qtyToAdd}`); 
        } else { 
            showToast('warning', 'Stock Limit', 'Cannot add more than available stock'); 
            return; 
        }
    } else {
        cart.push({ 
            id: productId, 
            name: productName, 
            price: productPrice, 
            quantity: qtyToAdd, 
            stock: productStock,
            image: productImage || '',
            strategy_id: strategyId || ''
        });
        showToast('success', 'Added to Cart', `${productName} has been added ${qtyToAdd > 1 ? '(Buy 1 Take 1 applied)' : ''}`);
    }
    updateCartDisplay();
}

function updateCartDisplay() {
    if (!cartItemsEl) return;
    
    if (cart.length === 0) {
        cartItemsEl.innerHTML = `<div class="empty-cart">
            <i class="fas fa-shopping-cart"></i>
            <h6>Cart is Empty</h6>
            <p class="small">Click products or scan barcode</p>
        </div>`;
        updateSummary();
        return;
    }
    
    let html = '';
    cart.forEach((item, index) => {
        const cartVisual = item.image
            ? `<div class="cart-item-icon cart-item-thumb"><img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.name)}"></div>`
            : `<div class="cart-item-icon"><i class="fas fa-paw"></i></div>`;
        html += `
            <div class="cart-item">
                ${cartVisual}
                <div class="cart-item-details">
                    <div class="cart-item-title">${escapeHtml(item.name)}</div>
                    <div class="cart-item-price">₱${item.price.toFixed(2)}</div>
                    <div class="cart-item-quantity">
                        <button class="qty-btn" onclick="updateQuantity(${index},'decrease')" title="Decrease">
                            <i class="fas fa-minus"></i>
                        </button>
                        <input 
                            type="number" 
                            class="qty-input" 
                            value="${item.quantity}" 
                            min="1" 
                            max="${item.stock}"
                            data-index="${index}"
                            title="Edit quantity"
                            onclick="this.select()"
                            onchange="setQuantityDirect(${index}, this)"
                            onkeydown="if(event.key==='Enter'){this.blur();}"
                        >
                        <button class="qty-btn" onclick="updateQuantity(${index},'increase')" title="Increase">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="remove-item" onclick="removeFromCart(${index})" title="Remove">
                    <i class="fas fa-times"></i>
                </div>
            </div>`;
    });
    
    cartItemsEl.innerHTML = html;
    updateSummary();
}

function updateQuantity(index, action) {
    if (!cart[index]) return;
    
    if (action === 'increase') {
        if (cart[index].quantity < cart[index].stock) {
            cart[index].quantity += 1;
        } else { 
            showToast('warning', 'Stock Limit', 'Cannot add more than available stock'); 
            return; 
        }
    } else {
        if (cart[index].quantity > 1) {
            cart[index].quantity -= 1;
        } else { 
            removeFromCart(index); 
            return; 
        }
    }
    updateCartDisplay();
}

function setQuantityDirect(index, inputEl) {
    if (!cart[index]) return;

    let val = parseInt(inputEl.value);

    if (isNaN(val) || val < 1) {
        val = 1;
    } else if (val > cart[index].stock) {
        showToast('warning', 'Stock Limit', `Only ${cart[index].stock} units available`);
        val = cart[index].stock;
    }

    cart[index].quantity = val;
    inputEl.value = val; // fix displayed value without full re-render
    updateSummary();
}

function removeFromCart(index) {
    if (!cart[index]) return;
    const name = cart[index].name;
    cart.splice(index, 1);
    updateCartDisplay();
    showToast('info', 'Removed', `${name} removed from cart`);
}

function clearCart() {
    if (cart.length > 0) { 
        cart = []; 
        updateCartDisplay(); 
        showToast('info', 'Cart Cleared', 'All items removed from cart'); 
    }
}

function updateSummary() {
    const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    if (subtotalSpan) subtotalSpan.textContent = '₱' + total.toFixed(2);
    if (totalSpan) totalSpan.textContent = '₱' + total.toFixed(2);
    if (checkoutBtn) checkoutBtn.disabled = cart.length === 0;
    calculateChange();
}

function calculateChange() {
    const payment = parseFloat(paymentInput?.value) || 0;
    const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    
    if (!changeSpan) return;
    
    if (payment >= total && total > 0) { 
        changeSpan.innerHTML = `<strong>Change: ₱${(payment - total).toFixed(2)}</strong>`; 
        changeSpan.style.color = '#10b981'; 
    } else if (total > 0) { 
        changeSpan.innerHTML = `Remaining: ₱${(total - payment).toFixed(2)}`; 
        changeSpan.style.color = '#e74c3c'; 
    } else { 
        changeSpan.innerHTML = 'Change: ₱0.00'; 
        changeSpan.style.color = ''; 
    }
}

function setQuickCashAmount(button) {
    const amount = Number(button.dataset.cashAmount);
    const targetId = button.dataset.cashTarget;
    const input = document.getElementById(targetId);

    if (!input || !Number.isFinite(amount)) return;

    input.value = String(amount);
    input.dispatchEvent(new Event('input', { bubbles: true }));

    const group = button.closest('.quick-cash-grid');
    group?.querySelectorAll('.quick-cash-btn').forEach(candidate => {
        candidate.classList.toggle('active', candidate === button);
    });

    input.focus();
}

function filterProducts() {
    const searchTerm = (searchInput?.value || '').toLowerCase();
    const activeBtn = document.querySelector('.category-btn.active');
    const activeCategory = activeBtn?.dataset.category || 'all';
    
    const filtered = products.filter(p => {
        const matchesSearch = p.name.toLowerCase().includes(searchTerm) || 
                             (p.sku && p.sku.toLowerCase().includes(searchTerm));
        const matchesCategory = activeCategory === 'all' || p.category_id == activeCategory;
        return matchesSearch && matchesCategory;
    });
    
    displayProducts(filtered);
}

// Checkout Functions
function processCheckout() {
    const payment = parseFloat(paymentInput?.value) || 0;
    const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    
    if (cart.length === 0) { 
        showToast('error', 'Empty Cart', 'Please add items to cart first'); 
        return; 
    }
    
    if (payment < total) { 
        showToast('error', 'Insufficient Payment', 'Please enter a sufficient amount'); 
        return; 
    }
    
    showPaymentConfirmation(total, payment);
}

function showPaymentConfirmation(total, paymentAmount) {
    const confirmTotal = document.getElementById('confirmTotal');
    const confirmPayment = document.getElementById('confirmPaymentAmount');
    const confirmItems = document.getElementById('confirmItems');
    
    if (confirmTotal) confirmTotal.textContent = '₱' + total.toFixed(2);
    if (confirmPayment) confirmPayment.value = paymentAmount;
    updateConfirmChange();
    
    if (confirmItems) {
        confirmItems.innerHTML = cart.map(item =>
            `<div class="d-flex justify-content-between small border-bottom py-1">
                <span>${escapeHtml(item.name)} x${item.quantity}</span>
                <span>₱${(item.price * item.quantity).toFixed(2)}</span>
            </div>`
        ).join('');
    }
    
    const methodCashRadio = document.getElementById('methodCash');
    if (methodCashRadio) {
        methodCashRadio.checked = true;
        const cashSection = document.getElementById('cashPaymentSection');
        const gcashSection = document.getElementById('gcashPaymentSection');
        if (cashSection) cashSection.style.display = 'block';
        if (gcashSection) gcashSection.style.display = 'none';
    }
    
    const modalEl = document.getElementById('paymentConfirmModal');
    if (modalEl) new bootstrap.Modal(modalEl).show();
}

function updateConfirmChange() {
    const confirmTotal = document.getElementById('confirmTotal');
    const confirmPayment = document.getElementById('confirmPaymentAmount');
    const changeDisplay = document.getElementById('confirmChangeDisplay');
    
    if (!confirmTotal || !confirmPayment || !changeDisplay) return;
    
    const total = parseFloat(confirmTotal.textContent.replace('₱', '')) || 0;
    const payment = parseFloat(confirmPayment.value) || 0;
    const change = payment - total;
    
    if (payment >= total) { 
        changeDisplay.textContent = '₱' + change.toFixed(2); 
        changeDisplay.style.color = '#10b981'; 
    } else { 
        changeDisplay.textContent = 'Insufficient'; 
        changeDisplay.style.color = '#e74c3c'; 
    }
}

function processPayment(total, payment, change, paymentMethod, reference) {
    showLoading();

    // Get user info from PHP constants - with null checks
    const userId = typeof LOGGED_USER_ID !== 'undefined' ? LOGGED_USER_ID : 0;
    const cashierName = typeof LOGGED_CASHIER !== 'undefined' ? LOGGED_CASHIER : 'Staff';
    const cashierRole = typeof LOGGED_ROLE !== 'undefined' ? LOGGED_ROLE : 'employee';
    const firstName = typeof LOGGED_FIRST_NAME !== 'undefined' ? LOGGED_FIRST_NAME : '';
    const lastName = typeof LOGGED_LAST_NAME !== 'undefined' ? LOGGED_LAST_NAME : '';

    if (!userId || userId <= 0) {
        hideLoading();
        showToast('error', 'Session Error', 'User not logged in. Please refresh the page and log in again.');
        console.error('processPayment: invalid LOGGED_USER_ID =', userId);
        return;
    }

    const cartSnapshot = cart.map(item => ({ 
        id: item.id, 
        name: item.name, 
        price: item.price, 
        quantity: item.quantity 
    }));

    $.ajax({
        url: 'ajax/transaction_ajax.php', 
        method: 'POST', 
        dataType: 'json', 
        timeout: 30000,
        data: {
            action: 'save_transaction',
            user_id: userId,
            first_name: firstName,
            last_name: lastName,
            role: cashierRole,
            customer_name: 'Walk-in Customer',
            items: JSON.stringify(cartSnapshot),
            total: total, 
            payment: payment, 
            change: change,
            payment_method: paymentMethod,
            notes: paymentMethod === 'gcash' ? `GCash Ref: ${reference}` : '',
            gcash_reference: paymentMethod === 'gcash' ? reference : '',
            cashier: cashierName,
            cashier_role: cashierRole
        },
        success: function(response) {
            hideLoading();
            if (response.success) {
                const saved = response.data || {};
                finishTransaction(
                    response,
                    saved.items || cartSnapshot,
                    Number(saved.total ?? total),
                    Number(saved.payment ?? payment),
                    Number(saved.change ?? change),
                    saved.payment_method || paymentMethod,
                    reference,
                    cashierName
                );
            } else {
                showToast('error', 'Error', response.message || 'Failed to save transaction');
            }
        },
        error: function(xhr, status, error) {
            hideLoading();
            let msg = 'Transaction failed. ';
            try {
                const response = JSON.parse(xhr.responseText);
                msg += response.message || 'Server error';
            } catch(e) {
                msg += 'Please try again.';
            }
            showToast('error', 'Error', msg);
            console.error('Transaction AJAX error:', status, error, xhr.responseText);
        }
    });
}

function finishTransaction(response, items, total, payment, change, paymentMethod, reference, cashierName) {
    // Clear cart
    cart = [];
    updateCartDisplay();
    
    // Track used GCash reference
    if (paymentMethod === 'gcash' && reference) {
        usedGcashReferences.add(reference);
    }
    
    // Show receipt
    const transaction = {
        id: response.data?.transaction_number || response.data?.id || response.transaction_id,
        date: new Date(),
        items: items,
        total: total,
        payment: payment,
        change: change,
        payment_method: paymentMethod,
        payment_reference: reference,
        cashier: cashierName
    };
    
    showReceipt(transaction);
    showToast('success', 'Success', 'Transaction completed successfully!');
    
    // Reset payment input
    if (paymentInput) paymentInput.value = '';
    document.querySelectorAll('.quick-cash-btn.active').forEach(button => button.classList.remove('active'));
    calculateChange();
    
    // Reload products to update stock
    loadProducts();
}

// Receipt Functions
function showReceipt(transaction) {
    const date = new Date(transaction.date);
    const formattedDate = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    const cashierName = transaction.cashier || (typeof LOGGED_CASHIER !== 'undefined' ? LOGGED_CASHIER : 'Staff');
    
    // Tax calculation (12% VAT - Philippine standard)
    const TAX_RATE = 0.12;
    const subtotal = transaction.total / (1 + TAX_RATE); // back-calculate net of VAT
    const taxAmount = transaction.total - subtotal;
    
    const itemsHtml = transaction.items.map(item =>
        `<div style="display:flex;justify-content:space-between;font-size:12px;">
            <span>${escapeHtml(item.name)} x${item.quantity}</span>
            <span>₱${(item.price * item.quantity).toFixed(2)}</span>
        </div>`
    ).join('');
    
    const paymentIcon = transaction.payment_method === 'gcash' ? '📱' : '💵';
    const refHtml = transaction.payment_reference
        ? `<div style="display:flex;justify-content:space-between;font-size:12px;">
            <span>Ref #:</span>
            <span>${escapeHtml(transaction.payment_reference)}</span>
          </div>`
        : '';
    
    const receiptHtml = `
        <div style="font-family:'Courier New',monospace;font-size:12px;">
            <div style="text-align:center;font-weight:bold;margin-bottom:5px;">
                Espenida's Pet & Poultry
            </div>
            <div style="text-align:center;margin-bottom:2px;">${formattedDate}</div>
            <div style="text-align:center;margin-bottom:10px;">#: ${transaction.id}</div>
            ${itemsHtml}
            <div style="margin-top:10px;border-top:1px dashed #000;padding-top:5px;">
                <div style="display:flex;justify-content:space-between;font-size:12px;">
                    <span>Subtotal (VAT excl.):</span>
                    <span>₱${subtotal.toFixed(2)}</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:12px;">
                    <span>VAT (12%):</span>
                    <span>₱${taxAmount.toFixed(2)}</span>
                </div>
                <div style="display:flex;justify-content:space-between;font-weight:bold;border-top:1px dashed #000;margin-top:4px;padding-top:4px;">
                    <span>Total (VAT incl.):</span>
                    <span>₱${transaction.total.toFixed(2)}</span>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span>Payment (${paymentIcon}):</span>
                    <span>₱${transaction.payment.toFixed(2)}</span>
                </div>
                ${refHtml}
                <div style="display:flex;justify-content:space-between;color:#10b981;">
                    <span>Change:</span>
                    <span>₱${transaction.change.toFixed(2)}</span>
                </div>
            </div>
            <div style="margin-top:10px;text-align:center;">
                <div>Cashier: ${escapeHtml(cashierName)}</div>
                <div style="margin-top:5px;">Thank you!</div>
            </div>
        </div>`;
    
    const receiptContainer = document.getElementById('receiptDetails');
    if (receiptContainer) receiptContainer.innerHTML = receiptHtml;
    
    const modalEl = document.getElementById('receiptModal');
    if (modalEl) new bootstrap.Modal(modalEl).show();
}

function printReceipt() {
    const content = document.getElementById('receiptDetails')?.innerHTML;
    if (!content) return;
    
    const win = window.open('', '_blank');
    win.document.write(`<html>
        <head>
            <title>Receipt</title>
            <style>
                body {
                    font-family: 'Courier New', monospace;
                    padding: 10px;
                    margin: 0;
                    background: white;
                }
                .receipt {
                    max-width: 280px;
                    margin: 0 auto;
                }
                @media print {
                    body {
                        padding: 0;
                    }
                }
            </style>
        </head>
        <body>
            <div class="receipt">${content}</div>
            <script>
                window.onload = function() {
                    window.print();
                    window.onafterprint = function() {
                        window.close();
                    };
                };
            <\/script>
        </body>
    </html>`);
    win.document.close();
}

// GCash QR Functions
function simulateQRGeneration() {
    const qrImage = document.getElementById('gcashQRImage');
    const qrSpinner = document.getElementById('qrLoadingSpinner');
    const enlargeHint = document.getElementById('enlargeHint');
    
    if (!qrImage || !qrSpinner) return;
    
    qrSpinner.style.display = 'block';
    qrImage.style.opacity = '0';
    if (enlargeHint) enlargeHint.style.display = 'none';
    
    setTimeout(() => { 
        qrSpinner.style.display = 'none'; 
        qrImage.style.opacity = '1'; 
        if (enlargeHint) setTimeout(() => { enlargeHint.style.display = 'block'; }, 300);
    }, 1500);
}

function handleImageError() {
    const qrSpinner = document.getElementById('qrLoadingSpinner');
    const qrImage = document.getElementById('gcashQRImage');
    const container = document.getElementById('qrCodeContainer');
    
    if (qrSpinner) qrSpinner.style.display = 'none';
    if (qrImage) qrImage.style.display = 'none';
    
    if (container && !document.getElementById('qrFallback')) {
        const fb = document.createElement('div');
        fb.id = 'qrFallback';
        fb.className = 'alert alert-warning py-2 px-3 mb-0';
        fb.style.fontSize = '12px';
        fb.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> GCash QR unavailable<br><small>Please use reference # instead</small>';
        container.appendChild(fb);
    }
}

function enlargeQRCode() {
    const qrImage = document.getElementById('gcashQRImage');
    if (!qrImage || qrImage.style.opacity === '0') { 
        showToast('info', 'Please Wait', 'QR code is still loading...'); 
        return; 
    }
    
    const enlargedImg = document.querySelector('#qrEnlargeModal .modal-body img');
    if (enlargedImg) enlargedImg.src = qrImage.src;
    new bootstrap.Modal(document.getElementById('qrEnlargeModal')).show();
}

function addQRCodeStyles() {
    if (document.querySelector('#qrStyles')) return;
    
    const style = document.createElement('style');
    style.id = 'qrStyles';
    style.textContent = `
        @keyframes pulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(74,111,165,.7); }
            50% { transform: scale(1.02); box-shadow: 0 0 0 5px rgba(74,111,165,0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(74,111,165,0); }
        }
        #gcashQRImage {
            cursor: pointer;
            transition: transform .3s, opacity .5s, box-shadow .3s;
            box-shadow: 0 2px 8px rgba(0,0,0,.1);
        }
        #gcashQRImage:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(74,111,165,.3);
        }
        #enlargeHint {
            font-size: 10px;
            animation: fadeIn .5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }
    `;
    document.head.appendChild(style);
}

// Tooltip Functions
function addTooltipTitles() {
    const tooltips = [
        ['.dashboard-link', 'Dashboard'],
        ['#logoutBtn', 'Logout'],
        ['#clearCartBtn', 'Clear all items'],
        ['#searchInput', 'Search products'],
        ['#barcodeInput', 'Scan barcode']
    ];
    
    tooltips.forEach(([selector, title]) => {
        const el = document.querySelector(selector);
        if (el && !el.getAttribute('title')) el.setAttribute('title', title);
    });
    
    document.querySelectorAll('.category-btn').forEach(btn => {
        if (!btn.getAttribute('title')) btn.setAttribute('title', `Filter: ${btn.textContent.trim()}`);
    });
}

function addTooltipStyles() {
    if (document.querySelector('#tooltipStyles')) return;
    
    const style = document.createElement('style');
    style.id = 'tooltipStyles';
    style.textContent = `
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
            box-shadow: 0 2px 8px rgba(0,0,0,.2);
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
    `;
    document.head.appendChild(style);
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // Logout button - Move to TOP to ensure it works even if other parts fail
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) { 
            e.preventDefault();
            showToast('info', 'Logging Out', 'See you next time!'); 
            setTimeout(() => { window.location.replace('logout.php'); }, 500); 
        });
    }

    // Search input
    if (searchInput) searchInput.addEventListener('input', filterProducts);
    
    // Category filters
    document.querySelectorAll('.category-btn[data-category]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.category-btn[data-category]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filterProducts();
        });
    });
    
    // Cart buttons
    if (clearCartBtn) clearCartBtn.addEventListener('click', clearCart);
    if (paymentInput) paymentInput.addEventListener('input', calculateChange);
    if (checkoutBtn) checkoutBtn.addEventListener('click', processCheckout);
    document.querySelectorAll('.quick-cash-btn').forEach(button => {
        button.addEventListener('click', () => setQuickCashAmount(button));
    });
    
    // Sidebar collapse
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
    }
    
    // Payment method toggle
    const methodCashRadio = document.getElementById('methodCash');
    const methodGcashRadio = document.getElementById('methodGcash');
    const cashSection = document.getElementById('cashPaymentSection');
    const gcashSection = document.getElementById('gcashPaymentSection');
    const confirmPaymentIn = document.getElementById('confirmPaymentAmount');
    const gcashReferenceIn = document.getElementById('gcashReference');
    
    if (methodCashRadio && methodGcashRadio) {
        methodCashRadio.addEventListener('change', function() { 
            if (!this.checked) return; 
            if (cashSection) cashSection.style.display = 'block'; 
            if (gcashSection) gcashSection.style.display = 'none'; 
            const qrImg = document.getElementById('gcashQRImage'); 
            if (qrImg) qrImg.style.animation = 'none'; 
        });
        
        methodGcashRadio.addEventListener('change', function() { 
            if (!this.checked) return; 
            if (cashSection) cashSection.style.display = 'none'; 
            if (gcashSection) gcashSection.style.display = 'block'; 
            simulateQRGeneration(); 
            setTimeout(() => { if (gcashReferenceIn) gcashReferenceIn.focus(); }, 1600); 
        });
    }
    
    if (confirmPaymentIn) confirmPaymentIn.addEventListener('input', updateConfirmChange);
    
    // Confirm payment button
    const confirmBtn = document.getElementById('confirmPaymentBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', function() {
            const selectedRadio = document.querySelector('input[name="paymentMethod"]:checked');
            if (!selectedRadio) { 
                showToast('error', 'Select Method', 'Please select a payment method'); 
                return; 
            }
            
            const method = selectedRadio.value;
            const confirmTotalEl = document.getElementById('confirmTotal');
            if (!confirmTotalEl) return;
            
            const total = parseFloat(confirmTotalEl.textContent.replace('₱', '')) || 0;
            let payment, change, reference = null;
            
            if (method === 'cash') {
                payment = parseFloat(document.getElementById('confirmPaymentAmount')?.value) || 0;
                if (payment < total) { 
                    showToast('error', 'Insufficient Payment', 'Please enter a sufficient amount'); 
                    return; 
                }
                change = payment - total;
            } else {
                payment = total;
                change = 0;
                reference = document.getElementById('gcashReference')?.value.trim() || '';
                if (!reference) { 
                    showToast('error', 'Missing Reference', 'Please enter GCash reference number'); 
                    return; 
                }
                // Validate: must be exactly 6 digits only
                if (!/^\d{6}$/.test(reference)) {
                    showToast('error', 'Invalid Reference', 'Reference must be exactly 6 digits');
                    return;
                }
                // Validate: must be unique per session
                if (usedGcashReferences.has(reference)) {
                    showToast('error', 'Duplicate Reference', 'This reference number has already been used. Please enter a unique reference.');
                    document.getElementById('gcashReference').value = '';
                    document.getElementById('gcashReference').focus();
                    return;
                }
            }
            
            const confirmModalEl = document.getElementById('paymentConfirmModal');
            const confirmModal = bootstrap.Modal.getInstance(confirmModalEl);
            if (confirmModal) confirmModal.hide();
            
            processPayment(total, payment, change, method, reference);
        });
    }
    
    addTooltipTitles();
    addTooltipStyles();
    addQRCodeStyles();
    loadProducts();
    setTimeout(restoreBarcodeFocus, 300);
    
    // Recommendation button functionality
    const recommendationBtn = document.getElementById('recommendationBtn');
    if (recommendationBtn) {
        recommendationBtn.addEventListener('click', function() {
            window.location.href = 'reco.php';
        });
    }
    
    // Show PHP session toast if any
    if (typeof INITIAL_TOAST !== 'undefined' && INITIAL_TOAST) {
        showToast(INITIAL_TOAST.type, INITIAL_TOAST.title, INITIAL_TOAST.message);
    }
});

window.addEventListener('load', function() {
    const qrImage = document.getElementById('gcashQRImage');
    if (qrImage && qrImage.complete) imageLoaded();
});
