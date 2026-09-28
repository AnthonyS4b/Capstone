// userManagement.js - Complete rewritten version

// Initialize when document is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log("userManagement.js loaded");

    const roleModal = document.getElementById('roleManagementModal');
    if (roleModal) {
        roleModal.addEventListener('show.bs.modal', () => refreshUserList(true));
    }

    // Keep the list fresh while it is open — silently, and only when visible
    if (isUserManagementOwner()) {
        setInterval(() => {
            if (roleModal && roleModal.classList.contains('show')) refreshUserList(true);
        }, 30000);
    }

    // PIN fields: digits only
    document.querySelectorAll('.um-pin').forEach(input => input.addEventListener('input', () => {
        const digits = input.value.replace(/\D/g, '').slice(0, 4);
        if (digits !== input.value) input.value = digits;
    }));

    // Role explanation under the role pickers
    document.getElementById('newRole')?.addEventListener('change', e => updateRoleNote('newRoleNote', e.target.value));
    
    // Set up search and filter listeners
    setupFilterListeners();
});

function isUserManagementOwner() {
    if (typeof isOwner !== 'undefined') return Boolean(isOwner);
    return document.getElementById('roleManagementModal') !== null;
}

function getUserManagementBaseUrl() {
    if (typeof baseUrl !== 'undefined') return baseUrl;
    return window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
}

function getCurrentUserId() {
    if (typeof currentUser !== 'undefined' && currentUser?.id != null) return currentUser.id;
    return window.userManagementCurrentUserId ?? null;
}

// Setup filter listeners
function setupFilterListeners() {
    const searchInput = document.getElementById('userSearch');
    const roleFilter = document.getElementById('roleFilter');
    
    if (searchInput) {
        searchInput.addEventListener('keyup', filterUsers);
    }
    
    if (roleFilter) {
        roleFilter.addEventListener('change', filterUsers);
    }
}

// Filter users in the table
function filterUsers() {
    const searchTerm = document.getElementById('userSearch')?.value.toLowerCase() || '';
    const roleFilter = document.getElementById('roleFilter')?.value.toLowerCase() || '';
    const tableBody = document.getElementById('usersTableBody');
    
    if (!tableBody) return;
    
    const rows = tableBody.getElementsByTagName('tr');
    let visibleCount = 0;
    
    for (let row of rows) {
        if (row.id === 'noResultsRow') continue;
        if (row.cells.length < 2) continue;
        
        const userName = row.getAttribute('data-name') || row.cells[1]?.innerText.toLowerCase() || '';
        const userRole = row.getAttribute('data-role') || '';
        
        const matchesSearch = searchTerm === '' || userName.includes(searchTerm);
        const matchesRole = roleFilter === '' || userRole === roleFilter;
        const shouldShow = matchesSearch && matchesRole;
        
        row.style.display = shouldShow ? '' : 'none';
        if (shouldShow) visibleCount++;
    }
    
    // Show "no results" message if all rows are hidden
    const noResultsRow = document.getElementById('noResultsRow');
    
    if (visibleCount === 0) {
        if (!noResultsRow) {
            const newRow = document.createElement('tr');
            newRow.id = 'noResultsRow';
            newRow.innerHTML = '<td colspan="5" class="um-empty">No users match your search</td>';
            tableBody.appendChild(newRow);
        }
    } else if (noResultsRow) {
        noResultsRow.remove();
    }
}

// Refresh user list from database
function refreshUserList(silent = false) {
    if (!isUserManagementOwner()) return;
    silent = silent === true;
    
    const baseApiUrl = getUserManagementBaseUrl();
    const url = baseApiUrl + 'ajax/get_user.php';
    
    console.log('Fetching users from:', url);
    
    fetch(url, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(async response => {
        if (!response.ok) {
            throw new Error(`User API returned HTTP ${response.status}`);
        }
        const responseText = await response.text();
        try {
            return JSON.parse(responseText);
        } catch (error) {
            throw new Error('User API returned invalid JSON');
        }
    })
    .then(data => {
        console.log('User data received:', data);
        if (data.success) {
            updateUsersTable(data.users);
            filterUsers();
            if (!silent) showToast('success', 'Updated', 'User list refreshed');
        } else {
            showToast('error', 'Error', data.message || 'Failed to refresh users');
        }
    })
    .catch(error => {
        console.error('Error refreshing users:', error);
        showToast('error', 'Connection Error', error.message || 'Failed to connect to server');
    });
}

// Update users table with new data
// Users currently listed, by id (edit/delete look details up here instead of
// passing names through onclick strings, which broke on apostrophes)
const umUsers = new Map();

function umInitials(first, last) {
    return ((first || '').charAt(0) + (last || '').charAt(0)).toUpperCase() || '?';
}

// Profile picture if the user has one, otherwise their initials
function umAvatarInner(user) {
    return user && user.avatar
        ? `<img src="${escapeHtml(user.avatar)}" alt="">`
        : escapeHtml(umInitials(user?.first_name, user?.last_name));
}

function updateUsersTable(users) {
    const tableBody = document.getElementById('usersTableBody');
    if (!tableBody) return;

    umUsers.clear();
    (users || []).forEach(u => umUsers.set(String(u.id), u));

    const summary = document.getElementById('umSummary');
    if (summary && users) {
        const owners = users.filter(u => u.role === 'owner').length;
        summary.textContent = `${users.length} user${users.length === 1 ? '' : 's'} · ${owners} owner${owners === 1 ? '' : 's'}, ${users.length - owners} employee${users.length - owners === 1 ? '' : 's'}`;
    }

    if (!users || users.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="5" class="um-empty">No users yet</td></tr>';
        return;
    }

    const me = String(getCurrentUserId());
    tableBody.innerHTML = users.map(user => {
        const name = `${user.first_name} ${user.last_name}`;
        const isMe = String(user.id) === me;
        const active = user.is_active === undefined || Number(user.is_active) === 1;
        return `
            <tr data-user-id="${user.id}" data-role="${escapeHtml(user.role)}" data-name="${escapeHtml((name + ' ' + (user.email || '')).toLowerCase())}">
                <td>
                    <div class="um-user">
                        <span class="um-avatar um-avatar-${user.role === 'owner' ? 'owner' : 'employee'}" aria-hidden="true">${umAvatarInner(user)}</span>
                        <div class="um-user-text">
                            <span class="um-name">${escapeHtml(name)}${isMe ? ' <span class="um-you">You</span>' : ''}</span>
                            <span class="um-email">${escapeHtml(user.email || '')}</span>
                        </div>
                    </div>
                </td>
                <td><span class="um-role um-role-${user.role === 'owner' ? 'owner' : 'employee'}">${capitalizeFirst(user.role)}</span></td>
                <td class="um-muted">${user.position ? escapeHtml(user.position) : '—'}</td>
                <td><span class="um-status ${active ? 'is-active' : 'is-inactive'}">${active ? 'Active' : 'Inactive'}</span></td>
                <td class="um-actions">
                    <button type="button" class="um-icon-btn" onclick="editUserById(${user.id})" title="Edit ${escapeHtml(name)}" aria-label="Edit ${escapeHtml(name)}">
                        <i class="fas fa-pen"></i>
                    </button>
                    ${isMe ? '' : `
                    <button type="button" class="um-icon-btn um-danger" onclick="deleteUserById(${user.id})" title="Delete ${escapeHtml(name)}" aria-label="Delete ${escapeHtml(name)}">
                        <i class="fas fa-trash-alt"></i>
                    </button>`}
                </td>
            </tr>`;
    }).join('');
}

function editUserById(id) {
    const u = umUsers.get(String(id));
    if (!u) return;
    editUserRole(u.id, u.first_name, u.last_name, u.email, u.role, u.position || '');
    const avatarEl = document.getElementById('editUserAvatar');
    if (avatarEl) avatarEl.innerHTML = umAvatarInner(u);
}

function deleteUserById(id) {
    const u = umUsers.get(String(id));
    if (u) deleteUser(u.id, `${u.first_name} ${u.last_name}`);
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Helper function to capitalize first letter
function capitalizeFirst(string) {
    if (!string) return '';
    return string.charAt(0).toUpperCase() + string.slice(1);
}

// Add new user - open modal
function addNewUser() {
    console.log("Opening add user modal");
    
    // Get the modal element
    const modalElement = document.getElementById('addUserModal');
    
    if (!modalElement) {
        console.error("Add user modal not found in DOM!");
        showToast('error', 'Error', 'Add user form not found. Please refresh the page.');
        return;
    }
    
    // Reset form first
    const form = document.getElementById('addUserForm');
    if (form) {
        form.reset();
        
        // Set default role to employee
        const roleSelect = document.getElementById('newRole');
        if (roleSelect) roleSelect.value = 'employee';
        updateRoleNote('newRoleNote', 'employee');
    }
    
    // Remove any existing modal backdrops
    document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    
    // Open modal
    try {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
    } catch (e) {
        console.error("Error opening modal:", e);
        showToast('error', 'Error', 'Could not open add user form');
    }
}

// Save new user
function saveNewUser() {
    console.log("saveNewUser started");
    
    // Get form element
    const form = document.getElementById('addUserForm');
    if (!form) {
        showToast('error', 'Error', 'Form not found');
        return;
    }
    
    // Get values directly from inputs
    const firstName = document.getElementById('newFirstName')?.value?.trim() || '';
    const lastName = document.getElementById('newLastName')?.value?.trim() || '';
    const email = document.getElementById('newEmail')?.value?.trim() || '';
    const pin = document.getElementById('newPin')?.value?.trim() || '';
    const role = document.getElementById('newRole')?.value || '';
    const position = document.getElementById('newPosition')?.value?.trim() || '';
    
    console.log("Form values:", { firstName, lastName, email, pin, role, position });
    
    // Validate
    const missing = [];
    if (!firstName) missing.push('First Name');
    if (!lastName) missing.push('Last Name');
    if (!email) missing.push('Email');
    if (!pin) missing.push('PIN');
    if (!role) missing.push('Role');
    
    if (missing.length > 0) {
        showToast('warning', 'Missing Fields', 'Please fill in: ' + missing.join(', '));
        return;
    }
    
    // Validate PIN format
    if (!/^\d{4}$/.test(pin)) {
        showToast('warning', 'Invalid PIN', 'PIN must be exactly 4 digits');
        return;
    }
    
    // Validate email
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showToast('warning', 'Invalid Email', 'Please enter a valid email address');
        return;
    }
    
    // Create data object
    const userData = {
        first_name: firstName,
        last_name: lastName,
        email: email,
        pin: pin,
        role: role,
        position: position
    };
    
    // Show loading state on the Add user button. Not event.target: pressing Enter
    // makes that the whole form (whose fields the spinner would then wipe), and a
    // click on the + icon makes it the icon (so the button never got disabled).
    const saveBtn = document.getElementById('addUserSaveBtn');
    if (!saveBtn || saveBtn.disabled) return; // already saving
    const originalText = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
    saveBtn.disabled = true;
    
    // Determine base URL
    const baseApiUrl = getUserManagementBaseUrl();
    const endpoint = baseApiUrl + 'ajax/add_user.php';
    
    console.log('Sending to endpoint:', endpoint);
    
    // Send request
    fetch(endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(userData)
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        console.log("Server response:", data);
        
        if (data.success) {
            showToast('success', 'Success', 'User added successfully');
            
            // Close modal
            const modalElement = document.getElementById('addUserModal');
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
            
            // Reset form
            form.reset();
            
            // Refresh user list
            refreshUserList();
        } else {
            showToast('error', 'Error', data.message || 'Failed to add user');
        }
    })
    .catch(error => {
        console.error("Error details:", error);
        showToast('error', 'Connection Error', 'Failed to connect to server: ' + error.message);
    })
    .finally(() => {
        // Restore button
        saveBtn.innerHTML = originalText;
        saveBtn.disabled = false;
    });
}

// Edit user role
function editUserRole(userId, firstName, lastName, email, currentRole, currentPosition) {
    console.log("Editing user role:", userId, firstName, lastName);
    
    // Check if edit role modal exists
    const modalElement = document.getElementById('editRoleModal');
    if (!modalElement) {
        console.error("Edit role modal not found!");
        showToast('error', 'Error', 'Edit role form not found');
        return;
    }
    
    // Set values
    const userIdInput = document.getElementById('editUserId');
    const userNameEl = document.getElementById('editUserName');
    const userEmailEl = document.getElementById('editUserEmail');
    const roleSelect = document.getElementById('userRoleSelect');
    const positionInput = document.getElementById('userPosition');
    
    if (userIdInput) userIdInput.value = userId;
    if (userNameEl) userNameEl.textContent = firstName + ' ' + lastName;
    const avatarEl = document.getElementById('editUserAvatar');
    if (avatarEl) {
        avatarEl.textContent = umInitials(firstName, lastName);
        avatarEl.className = 'um-avatar um-avatar-lg um-avatar-' + (currentRole === 'owner' ? 'owner' : 'employee');
    }
    if (userEmailEl) userEmailEl.textContent = email;
    if (roleSelect) roleSelect.value = currentRole;
    if (positionInput) positionInput.value = currentPosition || '';

    // Always clear PIN reset fields when opening — owner must type intentionally
    const pinNew  = document.getElementById('resetPinNew');
    const pinConf = document.getElementById('resetPinConfirm');
    if (pinNew)  pinNew.value  = '';
    if (pinConf) pinConf.value = '';
    
    // Update permission checkboxes based on role
    updatePermissionCheckboxes(currentRole);
    
    // Show the modal
    try {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
    } catch (e) {
        console.error("Error opening edit role modal:", e);
        showToast('error', 'Error', 'Could not open edit role form');
    }
}

// Update permission checkboxes based on role
// What each role can reach — shown under the role picker
const ROLE_NOTES = {
    owner: 'Owners have full access: sales reports, recommendations, user roles and backups.',
    employee: 'Employees can use the POS and inventory. Sales reports, recommendations and admin tools stay hidden.'
};

function updateRoleNote(elementId, role) {
    const el = document.getElementById(elementId);
    if (el) el.textContent = ROLE_NOTES[role] || '';
}

function updatePermissionCheckboxes(role) {
    updateRoleNote('editRoleNote', role);
}

// Save role changes (role + position, and optionally reset PIN)
function saveRoleChanges() {
    const userId      = document.getElementById('editUserId')?.value;
    const newRole     = document.getElementById('userRoleSelect')?.value;
    const newPosition = document.getElementById('userPosition')?.value?.trim() || '';
    const pinNew      = document.getElementById('resetPinNew')?.value?.trim()     || '';
    const pinConfirm  = document.getElementById('resetPinConfirm')?.value?.trim() || '';

    if (!userId || !newRole) {
        showToast('error', 'Error', 'Missing required data');
        return;
    }

    // Validate PIN fields only if the owner actually typed something
    if (pinNew || pinConfirm) {
        if (!/^\d{4}$/.test(pinNew)) {
            showToast('warning', 'Invalid PIN', 'New PIN must be exactly 4 digits.');
            return;
        }
        if (pinNew !== pinConfirm) {
            showToast('warning', 'PIN Mismatch', 'The two PIN entries do not match.');
            return;
        }
    }

    const baseApiUrl = getUserManagementBaseUrl();

    // Step 1 — save role + position
    fetch(baseApiUrl + 'ajax/update_user_role.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ user_id: userId, role: newRole, position: newPosition })
    })
    .then(r => { if (!r.ok) throw new Error('Network error'); return r.json(); })
    .then(data => {
        if (!data.success) {
            showToast('error', 'Error', data.message || 'Failed to update user role');
            return;
        }

        // Step 2 — reset PIN only if the owner filled in the fields
        if (pinNew) {
            const params = new URLSearchParams({ action: 'reset_user_pin', user_id: userId, new_pin: pinNew });
            return fetch(baseApiUrl + 'ajax/user_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: params
            })
            .then(r => r.json())
            .then(pinData => {
                if (pinData.success) {
                    showToast('success', 'Saved', 'Role and PIN updated successfully.');
                } else {
                    showToast('warning', 'Partial Save', 'Role saved but PIN reset failed: ' + pinData.message);
                }
                closeEditModalAndRefresh(userId);
            });
        }

        showToast('success', 'Saved', 'User updated successfully.');
        closeEditModalAndRefresh(userId);
    })
    .catch(error => {
        console.error('Error updating user:', error);
        showToast('error', 'Connection Error', 'Failed to connect to server');
    });
}

function closeEditModalAndRefresh(userId) {
    const modalElement = document.getElementById('editRoleModal');
    const modal = bootstrap.Modal.getInstance(modalElement);
    if (modal) modal.hide();
    refreshUserList();
    if (userId == getCurrentUserId()) updateCurrentUserSession();
}

// Delete user
async function deleteUser(userId, userName) {
    const ok = typeof confirmDialog === 'function'
        ? await confirmDialog({
            title: 'Delete user?',
            message: `${userName} will no longer be able to sign in.`,
            detail: 'Their past sales stay on record. This cannot be undone.',
            confirmText: 'Delete user',
            tone: 'danger'
        })
        : confirm(`Delete ${userName}? This cannot be undone.`);
    if (!ok) return;
    
    const baseApiUrl = getUserManagementBaseUrl();
    const endpoint = baseApiUrl + 'ajax/delete_user.php';
    
    fetch(endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            user_id: userId
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            showToast('success', 'Deleted', `User ${userName} has been removed`);
            refreshUserList();
        } else {
            showToast('error', 'Error', data.message || 'Failed to delete user');
        }
    })
    .catch(error => {
        console.error('Error deleting user:', error);
        showToast('error', 'Connection Error', 'Failed to connect to server');
    });
}

// Update current user session
function updateCurrentUserSession() {
    const baseApiUrl = getUserManagementBaseUrl();
    const endpoint = baseApiUrl + 'ajax/refresh_session.php';
    
    fetch(endpoint, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reload page to reflect changes
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        }
    })
    .catch(error => {
        console.error('Error refreshing session:', error);
    });
}

// Refresh profile data function
function refreshProfileData() {
    const refreshBtn = event.target.closest('button');
    if (!refreshBtn) return;
    
    const originalHtml = refreshBtn.innerHTML;
    
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Refreshing...';
    refreshBtn.disabled = true;

    const baseApiUrl = getUserManagementBaseUrl();
    const endpoint = baseApiUrl + 'ajax/get_current_user.php';
    
    console.log('Fetching from:', endpoint);

    fetch(endpoint, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        console.log('Response data:', data);
        if (data.success) {
            updateProfileDisplay(data.user);
            updateWelcomeBanner(data.user);
            showToast('success', 'Success', 'Profile data refreshed');
        } else {
            showToast('error', 'Error', data.message || 'Failed to refresh profile');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('error', 'Connection Error', 'Failed to connect to server');
    })
    .finally(() => {
        setTimeout(() => {
            refreshBtn.innerHTML = originalHtml;
            refreshBtn.disabled = false;
        }, 500);
    });
}

// Update profile display with new data
function updateProfileDisplay(userData) {
    // Update full name in profile modal
    const profileFullName = document.getElementById('profileFullName');
    if (profileFullName) {
        profileFullName.textContent = userData.first_name + ' ' + userData.last_name;
    }

    // Update role badge in profile modal
    const roleBadge = document.getElementById('profileRoleBadge');
    if (roleBadge) {
        const roleClass = userData.role === 'owner' ? 'danger' : 'info';
        const roleIconClass = userData.role === 'owner' ? 'crown' : 'user';
        
        roleBadge.className = `badge bg-${roleClass} me-2`;
        roleBadge.innerHTML = `<i class="fas fa-${roleIconClass} me-1"></i> ${capitalizeFirst(userData.role)}`;
    }

    // Update position in profile modal
    const profilePosition = document.getElementById('profilePosition');
    if (profilePosition) {
        profilePosition.textContent = userData.position || capitalizeFirst(userData.role);
    }

    // Update email in profile modal
    const profileEmail = document.getElementById('profileEmail');
    if (profileEmail) {
        profileEmail.textContent = userData.email || 'Not provided';
    }

    // Update user ID in profile modal
    const profileUserID = document.getElementById('profileUserID');
    if (profileUserID) {
        profileUserID.textContent = userData.id;
    }

    // Update member since if it exists
    const profileMemberSince = document.getElementById('profileMemberSince');
    if (profileMemberSince && userData.created_at) {
        const date = new Date(userData.created_at);
        profileMemberSince.textContent = date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    }
}

// Update welcome banner with new user data
function updateWelcomeBanner(userData) {
    // Update welcome banner text
    const welcomeHeading = document.querySelector('.welcome-text h1');
    if (welcomeHeading) {
        welcomeHeading.innerHTML = `<i class="fas fa-paw"></i> Welcome back, ${userData.first_name} ${userData.last_name}!`;
    }

    // Update position in welcome banner
    const welcomePosition = document.querySelector('.welcome-text p');
    if (welcomePosition) {
        const datePart = welcomePosition.textContent.split('·')[0].trim();
        welcomePosition.innerHTML = `${datePart} · ${userData.position ? userData.position : capitalizeFirst(userData.role)}`;
    }
}

// Manage user roles button click
function manageUserRoles() {
    const modalElement = document.getElementById('roleManagementModal');
    if (!modalElement) {
        console.error("Role management modal not found!");
        showToast('error', 'Error', 'Role management form not found');
        return;
    }
    
    const modal = new bootstrap.Modal(modalElement);
    modal.show();
    refreshUserList(); // Refresh data when opening
}


// ── Change own PIN (My Profile modal — all users) ─────────────────────────────
// Requires current PIN verification. Owner can also use this for their own account.
// For resetting OTHER users' PINs, see saveRoleChanges() in the edit user modal.
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

    const baseApiUrl = getUserManagementBaseUrl();
    const params = new URLSearchParams({ action: 'change_own_pin', current_pin: current, new_pin: newPin });

    fetch(baseApiUrl + 'ajax/user_ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: params
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('success', 'PIN Changed', 'Your PIN has been updated successfully.');
            // Clear fields
            document.getElementById('currentPin').value     = '';
            document.getElementById('newPinSelf').value     = '';
            document.getElementById('confirmPinSelf').value = '';
            // Collapse the section
            const collapseEl = document.getElementById('changePinCollapse');
            if (collapseEl) {
                const instance = bootstrap.Collapse.getInstance(collapseEl);
                if (instance) instance.hide();
            }
        } else {
            showToast('error', 'Error', data.message);
        }
    })
    .catch(() => showToast('error', 'Error', 'Failed to connect to server.'));
}

// Toast notification function
if (typeof showToast !== 'function') {
    window.showToast = function(type, title, message) {
        console.log(`Toast [${type}]: ${title} - ${message}`);
        
        // Check if we have a toast container
        const toastContainer = document.getElementById('toastContainer');
        if (toastContainer) {
            const icons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };
            
            const icon = icons[type] || 'fa-bell';
            const toastId = 'toast-' + Date.now();
            
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
            const toast = new bootstrap.Toast(toastElement, { delay: 3000 });
            toast.show();
            
            toastElement.addEventListener('hidden.bs.toast', function() {
                this.remove();
            });
        } else {
            // Fallback to alert if no toast container
            alert(`${title}: ${message}`);
        }
    };
}
