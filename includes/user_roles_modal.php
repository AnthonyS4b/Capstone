<?php
// Shared owner-only user-role management modals.
if (empty($is_owner)) {
    return;
}
?>

<div class="modal fade" id="roleManagementModal" tabindex="-1" aria-labelledby="roleManagementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="roleManagementModalLabel">
                    <i class="fas fa-users-cog me-2"></i>User Role Management
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-4">
                    <div class="col-md-4">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" id="userSearch" placeholder="Search users...">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="roleFilter">
                            <option value="">All Roles</option>
                            <option value="owner">Owner</option>
                            <option value="employee">Employee</option>
                        </select>
                    </div>
                    <div class="col-md-5 text-md-end">
                        <button type="button" class="btn btn-success" onclick="addNewUser()">
                            <i class="fas fa-user-plus me-2"></i>Add New User
                        </button>
                        <button type="button" class="btn btn-brown" onclick="refreshUserList()">
                            <i class="fas fa-sync-alt me-2"></i>Refresh
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover" id="usersTable">
                        <thead>
                            <tr>
                                <th>ID</th><th>User</th><th>Email</th><th>Current Role</th>
                                <th>Position</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="fas fa-spinner fa-spin me-2"></i>Loading users...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addUserModalLabel"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="addUserForm">
                    <div class="mb-3">
                        <label class="form-label" for="newFirstName">First Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="first_name" id="newFirstName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="newLastName">Last Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="last_name" id="newLastName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="newEmail">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" id="newEmail" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="newPin">PIN (4 digits) <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" name="pin" id="newPin" maxlength="4" pattern="\d{4}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="newRole">Role <span class="text-danger">*</span></label>
                        <select class="form-select" name="role" id="newRole" required>
                            <option value="employee" selected>Employee</option>
                            <option value="owner">Owner</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="newPosition">Position</label>
                        <input type="text" class="form-control" name="position" id="newPosition" placeholder="e.g., Cashier, Manager">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="saveNewUser()">
                    <i class="fas fa-save me-2"></i>Add User
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editRoleModal" tabindex="-1" aria-labelledby="editRoleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editRoleModalLabel"><i class="fas fa-user-edit me-2"></i>Edit User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center mb-3 p-2 bg-light rounded">
                    <i class="fas fa-user-circle fa-3x me-3 text-brown"></i>
                    <div>
                        <h6 class="mb-0 fw-bold" id="editUserName"></h6>
                        <small class="text-muted" id="editUserEmail"></small>
                    </div>
                    <input type="hidden" id="editUserId">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="userRoleSelect">Role</label>
                    <select class="form-select" id="userRoleSelect" onchange="updatePermissionCheckboxes(this.value)">
                        <option value="owner">Owner — Full system access</option>
                        <option value="employee">Employee — Basic access</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="userPosition">Position / Title</label>
                    <input type="text" class="form-control" id="userPosition" placeholder="e.g., Store Manager, Cashier">
                </div>
                <hr>
                <p class="mb-1 small fw-semibold text-uppercase text-muted"><i class="fas fa-key me-1"></i>Reset User PIN</p>
                <p class="small text-muted">Leave both fields blank to keep the current PIN.</p>
                <div class="row g-2">
                    <div class="col-6">
                        <input type="password" class="form-control form-control-sm" id="resetPinNew" maxlength="4" pattern="\d{4}" placeholder="New PIN">
                    </div>
                    <div class="col-6">
                        <input type="password" class="form-control form-control-sm" id="resetPinConfirm" maxlength="4" pattern="\d{4}" placeholder="Confirm PIN">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-brown btn-sm" onclick="saveRoleChanges()">
                    <i class="fas fa-save me-1"></i>Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<script>
window.userManagementCurrentUserId = <?php echo json_encode((int)($user_id ?? 0)); ?>;
</script>
