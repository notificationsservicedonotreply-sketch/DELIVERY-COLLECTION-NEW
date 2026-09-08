<?php requireBootstrapped(); ?>
<main class="main user-management-page">

    <?php $pageHeading = 'Dashboard'; require APP_ROOT . '/app/Views/layouts/navigation.php'; ?>

    <div id="userMessage" class="notice dc-hidden"></div>

    <!-- User Management -->
    <div class="card user-management-card">

        <div class="portal-actions user-management-heading">
            <div class="user-management-intro">
                <span class="user-management-icon"><i class="fa-solid fa-users-gear"></i></span>
                <div>
                    <div class="card-title">User Management</div>
                    <small>Create secure user accounts and manage portal access.</small>
                </div>
            </div>

            <button type="button" class="btn btn-green add-user-button" id="addUser">
                <i class="fa-solid fa-user-plus"></i> Add user
            </button>
        </div>

        <!-- Users Table -->
        <div class="table-wrapper">
            <table class="table user-management-table dynamic-table">
                <thead>
                    <tr>
                        <th data-sortable>User ID</th>
                        <th data-sortable>Name</th>
                        <th data-sortable>Salesman ID</th>
                        <th data-sortable>DB Name</th>
                        <th data-sortable>SType</th>
                        <th data-sortable>Location lock</th>
                        <th>Delivery</th>
                        <th>Collection</th>
                        <th>Transactions</th>
                        <th>Transaction administrator</th>
                        <th>Delivery transactions</th>
                        <th>Delivery transactions administrator</th>
                        <th>Trip list assign</th>
                        <th>Customer profile</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr data-user="<?= htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8') ?>">
                            <td data-label="User ID"><strong><?= htmlspecialchars($user['USERID']) ?></strong></td>
                            <td data-label="Name"><?= htmlspecialchars($user['NAME']) ?></td>
                            <td data-label="Salesman ID"><?= htmlspecialchars($user['SALESMANID']) ?></td>
                            <td data-label="DB Name"><?= htmlspecialchars((string) ($user['DBNAME'] ?? '')) ?></td>
                            <td data-label="SType"><?= htmlspecialchars((string) ($user['SType'] ?? '')) ?></td>
                            <td data-label="Location lock"><span class="status-pill <?= !empty($user['LocationLock']) ? 'status-pill--on' : 'status-pill--off' ?>"><?= !empty($user['LocationLock']) ? 'Required' : 'Off' ?></span></td>
                            <td><?= !empty($user['CanDelivery']) ? '✓' : '—' ?></td>
                            <td><?= !empty($user['CanCollection']) ? '✓' : '—' ?></td>
                            <td><?= !empty($user['CanTransactions']) ? '✓' : '—' ?></td>
                            <td data-label="Transaction administrator"><?= !empty($user['CanManageTransactions']) ? '✓' : '—' ?></td>
                            <td data-label="Delivery transactions"><?= !empty($user['CanDeliveryTransactions']) ? '✓' : '—' ?></td>
                            <td data-label="Delivery transactions administrator"><?= !empty($user['CanManageDeliveryTransactions']) ? '✓' : '—' ?></td>
                            <td data-label="Trip list assign"><?= !empty($user['CanTriplistAssign']) ? '✓' : '—' ?></td>
                            <td data-label="Customer profile"><?= !empty($user['CanCustomerProfile']) ? '✓' : '—' ?></td>
                            <td data-label="Actions">
                                <div class="user-action-buttons">
                                    <button type="button" class="btn btn-blue edit-user"><i class="fa-solid fa-pen"></i><span>Edit</span></button>
                                    <button type="button" class="btn btn-red delete-user"><i class="fa-solid fa-trash"></i><span>Delete</span></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</main>


<!-- User Modal -->
<div class="custom-modal" id="userModal">

    <div class="custom-modal-content user-modal-content">

        <div class="modal-header">
            <h2 id="userModalTitle"><i class="fa-solid fa-user-plus"></i> Add user</h2>

            <button
                class="close-btn"
                type="button"
                data-close-user
            >&times;</button>
        </div>

        <div
            class="modal-body"
            style="overflow:auto;padding:20px"
        >

            <form id="userForm">

                <input type="hidden" name="pk" id="userPk">

                <!-- User Information -->
                <div class="grid user-form-grid">

                    <label>
                        User ID
                        <input
                            class="input"
                            name="user_id"
                            id="userId"
                            required
                            maxlength="50"
                        >
                    </label>

                    <label>
                        Full name
                        <input
                            class="input"
                            name="name"
                            id="userName"
                            required
                            maxlength="50"
                        >
                    </label>

                    <label>
                        Salesman ID
                        <input
                            class="input"
                            name="salesman_id"
                            id="salesmanId"
                            required
                            maxlength="50"
                        >
                    </label>

                    <label>
                        IMEI / device ID
                        <input
                            class="input"
                            name="imei"
                            id="imei"
                            maxlength="50"
                        >
                    </label>

                    <label>
                        DB Name
                        <input
                            class="input"
                            name="dbname"
                            id="dbName"
                            maxlength="50"
                        >
                    </label>

                    <label>
                        SType
                        <input
                            class="input"
                            name="stype"
                            id="sType"
                            maxlength="50"
                        >
                    </label>

                    <label>
                        Password
                        <small id="passwordHint">(minimum 10 characters)</small>
                        <input
                            class="input"
                            type="password"
                            name="password"
                            id="userPassword"
                            minlength="10"
                            autocomplete="new-password"
                        >
                    </label>

                </div>

                <!-- Module Access -->
                <section class="access-section">

                    <div class="section-heading"><i class="fa-solid fa-shield-halved"></i> Module access</div>
                    <p>Select the areas this user can access.</p>

                    <div class="permission-grid">

                        <label class="permission-option">
                            <input
                                type="checkbox"
                                name="can_collection"
                                id="canCollection"
                            >
                            <span>Collection portal</span>
                        </label>

                        <label class="permission-option">
                            <input
                                type="checkbox"
                                name="can_transactions"
                                id="canTransactions"
                            >
                            <span>Collection Transactions</span>
                        </label>

                        <label class="permission-option permission-option--admin">
                            <input
                                type="checkbox"
                                name="can_manage_transactions"
                                id="canManageTransactions"
                            >
                            <span>Collection Transaction administrator <small>Can view all salesmen records, including without a Salesman ID</small></span>
                        </label>

                        <label class="permission-option">
                            <input
                                type="checkbox"
                                name="can_delivery"
                                id="canDelivery"
                            >
                            <span>Delivery portal</span>
                        </label>

                        <label class="permission-option">
                            <input
                                type="checkbox"
                                name="can_delivery_transactions"
                                id="canDeliveryTransactions"
                            >
                            <span>Delivery transactions</span>
                        </label>

                        <label class="permission-option permission-option--admin">
                            <input
                                type="checkbox"
                                name="can_manage_delivery_transactions"
                                id="canManageDeliveryTransactions"
                            >
                            <span>Delivery transactions administrator <small>Can view every salesmen delivery history</small></span>
                    
                        </label>

                        <label class="permission-option">
                            <input
                                type="checkbox"
                                name="can_triplist_assign"
                                id="canTriplistAssign"
                            >
                            <span>Trip list assign <small>Can view and manage the Trip List Assign menu</small></span>
                        </label>

                        <label class="permission-option">
                            <input
                                type="checkbox"
                                name="can_customer_profile"
                                id="canCustomerProfile"
                            >
                            <span>Customer profile <small>Can view and manage the Customer Profile menu</small></span>
                        </label>

                        <label class="permission-option">
                            <input
                                type="checkbox"
                                name="location_lock"
                                id="locationLock"
                            >
                            <span>Require location lock</span>
                        </label>

                    </div>
                </section>

                <!-- Form Actions -->
                <div
                    class="footer-actions"
                    style="margin-top:22px"
                >

                    <button
                        type="button"
                        class="btn btn-red"
                        data-close-user
                    >
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-green"
                    >
                        <i class="fa-solid fa-floppy-disk"></i> Save user
                    </button>

                </div>

            </form>

        </div>
    </div>

</div>
