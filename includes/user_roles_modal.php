<?php
// Shared owner-only user-role management modals.
// Styles: assets/css/user-roles.css · Logic: assets/js/userManagement.js
if (empty($is_owner)) {
    return;
}
$um_css = __DIR__ . '/../assets/css/user-roles.css';
$um_confirm = __DIR__ . '/../assets/js/confirm-dialog.js';
?>
<link rel="stylesheet" href="assets/css/user-roles.css?v=<?php echo @filemtime($um_css); ?>">
<script src="assets/js/confirm-dialog.js?v=<?php echo @filemtime($um_confirm); ?>"></script>

<!-- ─── Users list ─── -->
<div class="modal fade um-modal" id="roleManagementModal" tabindex="-1" aria-labelledby="roleManagementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="um-head">
                <div>
                    <h2 class="um-title" id="roleManagementModalLabel">Users &amp; roles</h2>
                    <p class="um-sub" id="umSummary">Who can sign in, and what they can access.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="um-toolbar">
                <label class="um-search">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" id="userSearch" placeholder="Search name or email" aria-label="Search users">
                </label>
                <select class="um-select" id="roleFilter" aria-label="Filter by role">
                    <option value="">All roles</option>
                    <option value="owner">Owners</option>
                    <option value="employee">Employees</option>
                </select>
                <button type="button" class="um-icon-btn" onclick="refreshUserList()" title="Refresh list" aria-label="Refresh list">
                    <i class="fas fa-sync-alt"></i>
                </button>
                <button type="button" class="um-btn um-btn-primary" onclick="addNewUser()">
                    <i class="fas fa-plus" aria-hidden="true"></i>Add user
                </button>
            </div>

            <div class="modal-body">
                <table class="um-table" id="usersTable">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Position</th>
                            <th>Status</th>
                            <th><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <tr><td colspan="5" class="um-empty">Loading users…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ─── Add user ─── -->
<div class="modal fade um-modal um-form-modal" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="um-head">
                <div>
                    <h2 class="um-title" id="addUserModalLabel">Add user</h2>
                    <p class="um-sub">They sign in by picking their name and entering this PIN.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="addUserForm" onsubmit="event.preventDefault(); saveNewUser();">
                    <div class="um-grid">
                        <div class="um-field">
                            <label for="newFirstName">First name</label>
                            <input type="text" class="um-input" name="first_name" id="newFirstName" required autocomplete="off">
                        </div>
                        <div class="um-field">
                            <label for="newLastName">Last name</label>
                            <input type="text" class="um-input" name="last_name" id="newLastName" required autocomplete="off">
                        </div>
                    </div>
                    <div class="um-field">
                        <label for="newEmail">Email</label>
                        <input type="email" class="um-input" name="email" id="newEmail" required autocomplete="off">
                    </div>
                    <div class="um-grid">
                        <div class="um-field">
                            <label for="newRole">Role</label>
                            <select class="um-input" name="role" id="newRole" required>
                                <option value="employee" selected>Employee</option>
                                <option value="owner">Owner</option>
                            </select>
                        </div>
                        <div class="um-field">
                            <label for="newPosition">Position <span class="um-optional">optional</span></label>
                            <input type="text" class="um-input" name="position" id="newPosition" placeholder="e.g. Cashier" autocomplete="off">
                        </div>
                    </div>
                    <div class="um-field">
                        <label for="newPin">4-digit PIN</label>
                        <input type="password" class="um-input um-pin" name="pin" id="newPin" maxlength="4" pattern="\d{4}" inputmode="numeric" autocomplete="new-password" required>
                        <span class="um-hint">Share it with them privately. Nobody can view it later — only reset it.</span>
                    </div>
                    <p class="um-role-note" id="newRoleNote">Employees can use the POS and inventory. Sales reports, recommendations and admin tools stay hidden.</p>
                    <button type="submit" hidden></button>
                </form>
            </div>
            <div class="um-foot">
                <button type="button" class="um-btn um-btn-ghost" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="um-btn um-btn-primary" onclick="saveNewUser()">
                    <i class="fas fa-plus" aria-hidden="true"></i>Add user
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ─── Edit user ─── -->
<div class="modal fade um-modal um-form-modal" id="editRoleModal" tabindex="-1" aria-labelledby="editRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="um-head">
                <div class="um-identity">
                    <span class="um-avatar um-avatar-lg" id="editUserAvatar" aria-hidden="true"></span>
                    <div>
                        <h2 class="um-title" id="editRoleModalLabel"><span id="editUserName"></span></h2>
                        <p class="um-sub" id="editUserEmail"></p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <input type="hidden" id="editUserId">
            </div>
            <div class="modal-body">
                <div class="um-grid">
                    <div class="um-field">
                        <label for="userRoleSelect">Role</label>
                        <select class="um-input" id="userRoleSelect" onchange="updatePermissionCheckboxes(this.value)">
                            <option value="owner">Owner</option>
                            <option value="employee">Employee</option>
                        </select>
                    </div>
                    <div class="um-field">
                        <label for="userPosition">Position</label>
                        <input type="text" class="um-input" id="userPosition" placeholder="e.g. Store Manager" autocomplete="off">
                    </div>
                </div>
                <p class="um-role-note" id="editRoleNote"></p>

                <div class="um-section">
                    <h3 class="um-section-title">Reset PIN</h3>
                    <p class="um-hint">Leave both blank to keep their current PIN. Nobody can view an existing PIN.</p>
                    <div class="um-grid">
                        <div class="um-field">
                            <label for="resetPinNew">New PIN</label>
                            <input type="password" class="um-input um-pin" id="resetPinNew" maxlength="4" pattern="\d{4}" inputmode="numeric" autocomplete="new-password">
                        </div>
                        <div class="um-field">
                            <label for="resetPinConfirm">Confirm PIN</label>
                            <input type="password" class="um-input um-pin" id="resetPinConfirm" maxlength="4" pattern="\d{4}" inputmode="numeric" autocomplete="new-password">
                        </div>
                    </div>
                </div>
            </div>
            <div class="um-foot">
                <button type="button" class="um-btn um-btn-ghost" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="um-btn um-btn-primary" onclick="saveRoleChanges()">Save changes</button>
            </div>
        </div>
    </div>
</div>

<script>
window.userManagementCurrentUserId = <?php echo json_encode((int)($user_id ?? ($_SESSION['user_id'] ?? 0))); ?>;
</script>
