// Toast notification function with 1-second display
function showToast(type, title, message) {
    const toastContainer = document.getElementById('toastContainer');
    
    // Icon mapping
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    
    const toastId = 'toast-' + Date.now() + '-' + Math.random();
    const icon = icons[type] || 'fa-bell';
    
    const toastHtml = `
        <div id="${toastId}" class="toast align-items-center text-white bg-${type} border-0 mb-2" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="3000">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas ${icon} me-2"></i>
                    <strong>${title}</strong> ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-timer"></div>
        </div>
    `;
    
    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    
    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement, {
        autohide: true,
        delay: 1000
    });
    toast.show();
    
    toastElement.addEventListener('hidden.bs.toast', function () {
        this.remove();
    });
}

// Display welcome toast from PHP session
document.addEventListener('DOMContentLoaded', function() {
    if (typeof phpToastMessage !== 'undefined' && phpToastMessage) {
        showToast(phpToastMessage.type, phpToastMessage.title, phpToastMessage.message);
    }
    
    // Add titles to buttons for tooltips in collapsed state
    addTooltipTitles();
});

// Sidebar collapse functionality
const sidebar = document.getElementById('sidebar');
const collapseBtn = document.getElementById('collapseBtn');
const collapseIcon = document.getElementById('collapseIcon');

if (collapseBtn) {
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
}

// Function to add tooltip titles to buttons
function addTooltipTitles() {
    // Set titles for main buttons
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
    
    const recommendationBtn = document.querySelector('.recommendation-btn');
    if (recommendationBtn && !recommendationBtn.getAttribute('title')) {
        recommendationBtn.setAttribute('title', 'Recommendations');
    }
    
    // System admin button if exists
    if (typeof isOwner !== 'undefined' && isOwner) {
        const systemBtn = document.querySelectorAll('.dropdown .dropdown-toggle')[2];
        if (systemBtn && !systemBtn.getAttribute('title')) {
            systemBtn.setAttribute('title', 'System & Admin');
        }
    }
    
    const logoutBtn = document.querySelector('.logout-btn');
    if (logoutBtn && !logoutBtn.getAttribute('title')) {
        logoutBtn.setAttribute('title', 'Logout');
    }
}

// Logout functionality
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

// Recommendation button functionality
const recommendationBtn = document.getElementById('recommendationBtn');
if (recommendationBtn) {
    recommendationBtn.addEventListener('click', function() {
        window.location.href = 'reco.php';
    });
}

// Filter buttons functionality
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        showToast('info', 'Filter Applied', 'Showing ' + this.textContent + ' items');
    });
});

// Initialize charts
document.addEventListener('DOMContentLoaded', function() {
    // ── Sales Overview Chart (vertical bar, real data via AJAX) ──────────────
    const ctx = document.getElementById('salesChart').getContext('2d');
    
    const salesChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: [],
            datasets: [{
                label: 'Sales (₱)',
                data: [],
                backgroundColor: '#2c5530',
                borderWidth: 0,
                borderRadius: 4,
                borderSkipped: false,
                barPercentage: 0.6,
                categoryPercentage: 0.7,
                hoverBackgroundColor: '#1f3d23'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#16221a',
                    titleFont: { size: 12, weight: '600' },
                    bodyFont: { size: 12 },
                    padding: 10,
                    cornerRadius: 6,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            return '₱' + context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#eef0ee', drawTicks: false },
                    border: { display: false },
                    ticks: {
                        callback: function(value) {
                            if (value >= 1000000) return '₱' + (value / 1000000).toFixed(1) + 'M';
                            if (value >= 1000) return '₱' + (value / 1000).toFixed(0) + 'K';
                            return '₱' + value.toLocaleString();
                        },
                        font: { size: 11 },
                        color: '#61706a'
                    }
                },
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: { font: { size: 11 }, color: '#61706a' }
                }
            },
            animation: { duration: 600, easing: 'easeOutQuart' }
        }
    });

    // Fetch chart data from server
    function loadSalesChart(range) {
        fetch('ajax/dashboard_chart_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_sales_chart&range=' + encodeURIComponent(range)
        })
        .then(r => r.json())
        .then(response => {
            if (response.success) {
                salesChart.data.labels = response.labels;
                salesChart.data.datasets[0].data = response.values;
                salesChart.update();
            } else {
                console.error('Chart data error:', response.message);
            }
        })
        .catch(err => {
            console.error('Failed to load sales chart data:', err);
        });
    }

    // Load default (weekly view)
    loadSalesChart('week');

    // Range buttons
    document.querySelectorAll('.range-btn[data-range]').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.range-btn[data-range]').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            loadSalesChart(this.dataset.range);
        });
    });

    // ── Sales by Category Chart (vertical bar, real data from PHP) ───────────
    const ctx2 = document.getElementById('categoryChart').getContext('2d');

    const catLabels = (typeof phpCategorySales !== 'undefined' && phpCategorySales.length)
        ? phpCategorySales.map(c => c.category)
        : ['No data'];
    const catValues = (typeof phpCategorySales !== 'undefined' && phpCategorySales.length)
        ? phpCategorySales.map(c => c.revenue)
        : [0];
    const catColors = ['#8B4513', '#2c5530', '#C47A3A', '#416937', '#d4a382', '#1a3d1e', '#A0522D'];

    const categoryChart = new Chart(ctx2, {
        type: 'bar',
        data: {
            labels: catLabels,
            datasets: [{
                label: 'Revenue (₱)',
                data: catValues,
                backgroundColor: catLabels.map((_, i) => catColors[i % catColors.length]),
                borderRadius: 4,
                borderSkipped: false,
                barPercentage: 0.6,
                categoryPercentage: 0.7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#16221a',
                    titleFont: { size: 12, weight: '600' },
                    bodyFont: { size: 12 },
                    padding: 10,
                    cornerRadius: 6,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = total > 0 ? ((context.parsed.y / total) * 100).toFixed(1) : 0;
                            return '₱' + context.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (' + pct + '%)';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#eef0ee', drawTicks: false },
                    border: { display: false },
                    ticks: {
                        callback: function(value) {
                            if (value >= 1000000) return '₱' + (value / 1000000).toFixed(1) + 'M';
                            if (value >= 1000) return '₱' + (value / 1000).toFixed(0) + 'K';
                            return '₱' + value.toLocaleString();
                        },
                        font: { size: 11 },
                        color: '#61706a'
                    }
                },
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: { font: { size: 11 }, color: '#61706a' }
                }
            },
            animation: { duration: 800, easing: 'easeInOutQuart' }
        }
    });
});

// Switch Account Functions
// [first selectAccount block removed — see updated version below]

// Ensure Switch Account modal works properly
document.addEventListener('DOMContentLoaded', function() {
    // Log to confirm button exists
    const switchBtn = document.querySelector('.switch-account-btn');
    if (switchBtn) {
        console.log('Switch Account button found');
        switchBtn.addEventListener('click', function(e) {
            console.log('Switch Account clicked');
        });
    } else {
        console.log('Switch Account button NOT found');
    }
    
    // Test if modal exists
    const switchModal = document.getElementById('switchAccountModal');
    if (switchModal) {
        console.log('Switch Account modal found');
    } else {
        console.log('Switch Account modal NOT found');
    }
});

// ── Switch Account with Confirmation ─────────────────────────────────────────
function selectAccount(userId, userName, userRole, currentName, currentRole) {
    const confirmBox = document.getElementById('switchConfirmStep');
    const confirmMsg = document.getElementById('switchConfirmMsg');

    confirmMsg.innerHTML =
        'Would you like to switch from <strong>' + escHtml(currentName + ' (' + currentRole + ')') + '</strong>'
        + ' to <strong>' + escHtml(userName + ' (' + userRole + ')') + '</strong>?';

    confirmBox.dataset.targetId   = userId;
    confirmBox.dataset.targetName = userName;
    confirmBox.dataset.targetRole = userRole;

    document.getElementById('accountList').style.display = 'none';
    confirmBox.style.display = 'block';
}

function confirmAccountSwitch() {
    const confirmBox = document.getElementById('switchConfirmStep');
    const userId   = confirmBox.dataset.targetId;
    const userName = confirmBox.dataset.targetName;
    const userRole = confirmBox.dataset.targetRole;

    confirmBox.style.display = 'none';
    document.getElementById('selectedUserId').value            = userId;
    document.getElementById('selectedAccountName').textContent = userName + ' (' + userRole + ')';
    document.getElementById('quickLoginForm').style.display    = 'block';
}

function cancelConfirmSwitch() {
    document.getElementById('switchConfirmStep').style.display = 'none';
    document.getElementById('accountList').style.display       = 'block';
}

function cancelAccountSelection() {
    const userIdInput    = document.getElementById('selectedUserId');
    const accountPin     = document.getElementById('accountPin');
    const accountList    = document.getElementById('accountList');
    const quickLoginForm = document.getElementById('quickLoginForm');
    const confirmBox     = document.getElementById('switchConfirmStep');
    if (userIdInput)    userIdInput.value = '';
    if (accountPin)     accountPin.value  = '';
    if (accountList)    accountList.style.display    = 'block';
    if (quickLoginForm) quickLoginForm.style.display = 'none';
    if (confirmBox)     confirmBox.style.display     = 'none';
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Handle form submission with better validation
document.addEventListener('DOMContentLoaded', function() {
    const switchForm = document.getElementById('switchAccountForm');
    if (switchForm) {
        switchForm.addEventListener('submit', function(e) {
            const pin = document.getElementById('accountPin').value;
            if (!pin || pin.length !== 4 || !/^\d+$/.test(pin)) {
                e.preventDefault();
                showToast('warning', 'Invalid PIN', 'Please enter a valid 4-digit PIN');
            }
        });
    }

    // Reset form when modal is closed
    const switchModal = document.getElementById('switchAccountModal');
    if (switchModal) {
        switchModal.addEventListener('hidden.bs.modal', function() {
            cancelAccountSelection();
        });
    }
});
// ==================== USER MANAGEMENT FUNCTIONS ====================

function refreshUserList() {
    location.reload();
}

function refreshProfileData() {
    showToast('info', 'Refreshed', 'Profile data is up to date.');
}

function addNewUser() {
    document.getElementById('addUserForm')?.reset();
    const modal = new bootstrap.Modal(document.getElementById('addUserModal'));
    modal.show();
}

function saveNewUser() {
    const firstName = document.getElementById('newFirstName')?.value?.trim();
    const lastName  = document.getElementById('newLastName')?.value?.trim();
    const email     = document.getElementById('newEmail')?.value?.trim();
    const pin       = document.getElementById('newPin')?.value?.trim();
    const role      = document.getElementById('newRole')?.value;
    const position  = document.getElementById('newPosition')?.value?.trim();

    if (!firstName || !lastName || !email || !pin || !role) {
        showToast('warning', 'Missing Fields', 'Please fill in all required fields.');
        return;
    }
    if (!/^\d{4}$/.test(pin)) {
        showToast('warning', 'Invalid PIN', 'PIN must be exactly 4 digits.');
        return;
    }

    fetch('ajax/add_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ first_name: firstName, last_name: lastName, email, pin, role, position })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('success', 'User Added', data.message);
            bootstrap.Modal.getInstance(document.getElementById('addUserModal'))?.hide();
            setTimeout(() => location.reload(), 800);
        } else {
            showToast('error', 'Error', data.message);
        }
    })
    .catch(() => showToast('error', 'Error', 'Failed to connect to server.'));
}

function editUserRole(userId, firstName, lastName, email, role, position) {
    document.getElementById('editUserId').value    = userId;
    document.getElementById('editUserName').textContent  = firstName + ' ' + lastName;
    document.getElementById('editUserEmail').textContent = email;
    document.getElementById('userRoleSelect').value      = role;
    document.getElementById('userPosition').value        = position;
    // Clear PIN reset fields
    const rNew = document.getElementById('resetPinNew');
    const rCon = document.getElementById('resetPinConfirm');
    if (rNew) rNew.value = '';
    if (rCon) rCon.value = '';

    new bootstrap.Modal(document.getElementById('editRoleModal')).show();
}

function saveRoleChanges() {
    const userId   = document.getElementById('editUserId')?.value;
    const role     = document.getElementById('userRoleSelect')?.value;
    const position = document.getElementById('userPosition')?.value?.trim();
    const pinNew   = document.getElementById('resetPinNew')?.value?.trim();
    const pinConf  = document.getElementById('resetPinConfirm')?.value?.trim();

    if (!userId || !role) {
        showToast('warning', 'Error', 'Missing user data.');
        return;
    }

    // If owner filled in PIN reset fields, validate them first
    if (pinNew || pinConf) {
        if (!/^\d{4}$/.test(pinNew)) {
            showToast('warning', 'Invalid PIN', 'New PIN must be exactly 4 digits.');
            return;
        }
        if (pinNew !== pinConf) {
            showToast('warning', 'PIN Mismatch', 'The two PIN entries do not match.');
            return;
        }
    }

    // Step 1: save role + position
    fetch('ajax/update_user_role.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: parseInt(userId), role, position })
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            showToast('error', 'Error', data.message);
            return;
        }

        // Step 2: if a new PIN was provided, reset it
        if (pinNew) {
            return fetch('ajax/user_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'reset_user_pin', user_id: userId, new_pin: pinNew })
            })
            .then(r => r.json())
            .then(pinData => {
                if (pinData.success) {
                    showToast('success', 'Saved', 'Role and PIN updated successfully.');
                } else {
                    showToast('warning', 'Partial Save', 'Role saved but PIN reset failed: ' + pinData.message);
                }
                bootstrap.Modal.getInstance(document.getElementById('editRoleModal'))?.hide();
                setTimeout(() => location.reload(), 800);
            });
        }

        showToast('success', 'Saved', 'User updated successfully.');
        bootstrap.Modal.getInstance(document.getElementById('editRoleModal'))?.hide();
        setTimeout(() => location.reload(), 800);
    })
    .catch(() => showToast('error', 'Error', 'Failed to connect to server.'));
}

function deleteUser(userId, userName) {
    if (!confirm(`Delete user "${userName}"? This cannot be undone.`)) return;

    fetch('ajax/delete_user.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: parseInt(userId) })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('success', 'Deleted', data.message);
            document.querySelector(`tr[data-user-id="${userId}"]`)?.remove();
        } else {
            showToast('error', 'Error', data.message);
        }
    })
    .catch(() => showToast('error', 'Error', 'Failed to connect to server.'));
}

// ── Change own PIN (profile modal — all users) ──────────────────────────────
function changeOwnPin() {
    const current = document.getElementById('currentPin')?.value?.trim();
    const newPin  = document.getElementById('newPinSelf')?.value?.trim();
    const confirm = document.getElementById('confirmPinSelf')?.value?.trim();

    if (!current || !newPin || !confirm) {
        showToast('warning', 'Missing Fields', 'Please fill in all three PIN fields.');
        return;
    }
    if (!/^\d{4}$/.test(current)) {
        showToast('warning', 'Invalid PIN', 'Current PIN must be exactly 4 digits.');
        return;
    }
    if (!/^\d{4}$/.test(newPin)) {
        showToast('warning', 'Invalid PIN', 'New PIN must be exactly 4 digits.');
        return;
    }
    if (newPin !== confirm) {
        showToast('warning', 'PIN Mismatch', 'New PIN and confirmation do not match.');
        return;
    }
    if (current === newPin) {
        showToast('warning', 'No Change', 'New PIN must be different from your current PIN.');
        return;
    }

    fetch('ajax/user_ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'change_own_pin', current_pin: current, new_pin: newPin })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('success', 'PIN Changed', 'Your PIN has been updated successfully.');
            // Clear the fields
            document.getElementById('currentPin').value    = '';
            document.getElementById('newPinSelf').value    = '';
            document.getElementById('confirmPinSelf').value = '';
            // Collapse the section
            const collapseEl = document.getElementById('changePinCollapse');
            if (collapseEl) bootstrap.Collapse.getInstance(collapseEl)?.hide();
        } else {
            showToast('error', 'Error', data.message);
        }
    })
    .catch(() => showToast('error', 'Error', 'Failed to connect to server.'));
}