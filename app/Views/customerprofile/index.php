<?php requireBootstrapped(); ?>
<style>
    /* Self-contained header styling for this page, same approach as
       tripassign/index.php -- doesn't borrow classes from
       user-management.css so it can't be affected by that file's load
       order/caching on a page that isn't User Management. */
    .customer-profile-heading{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px; margin-bottom:26px; }
    .customer-profile-intro{ display:flex; align-items:center; gap:14px; }
    .customer-profile-icon{
        width:46px; height:46px; flex:0 0 auto; display:grid; place-items:center;
        color:#fff; font-size:19px; border-radius:14px;
        background:linear-gradient(135deg,#b51f2a,#ef5b54);
        box-shadow:0 8px 18px rgba(181,31,42,.24);
    }
    .customer-profile-intro .card-title{ margin-bottom:3px; font-size:20px; color:#2a1b1d; }
    .customer-profile-intro small{ color:#78686b; font-size:13px; }
    .customer-profile-heading + .dynamic-table-toolbar{ margin-top:6px; }
    @media (max-width:640px){
        .customer-profile-heading{ align-items:stretch; }
        .customer-profile-intro{ align-items:flex-start; }
        .customer-profile-icon{ width:40px; height:40px; border-radius:12px; font-size:16px; }
        .customer-profile-intro .card-title{ font-size:17px; }
    }
</style>
<main class="main user-management-page">

    <?php $pageHeading = 'Dashboard'; require APP_ROOT . '/app/Views/layouts/navigation.php'; ?>

    <div id="customerMessage" class="notice dc-hidden"></div>

    <!-- Customer Profile -->
    <div class="card" style="border:1px solid #f1dfe1;box-shadow:0 14px 36px rgba(96,20,30,.09)">

        <div class="customer-profile-heading">
            <div class="customer-profile-intro">
                <span class="customer-profile-icon"><i class="fa-solid fa-address-card" aria-hidden="true"></i></span>
                <div>
                    <div class="card-title">Customer Profile</div>
                    <small>Add, edit, or remove customer records used across Delivery and Collection.</small>
                </div>
            </div>

            <button type="button" class="btn btn-green add-user-button" id="addCustomer">
                <i class="fa-solid fa-user-plus"></i> Add customer
            </button>
        </div>

        <!-- Toolbar: page-size + search -- mirrors dynamic-table.js's own
             toolbar markup/classes (styled in dashboard.css) so this looks
             identical, but drives a real server-side query per keystroke/
             page-size change instead of filtering rows already on screen. -->
        <div class="dynamic-table-toolbar">
            <label class="dynamic-table-pagesize">Show
                <select class="input dynamic-table-pagesize-select" id="customerPageSize" aria-label="Rows per page">
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100" selected>100</option>
                    <option value="200">200</option>
                </select>
            entries</label>
            <input type="search" class="input dynamic-table-search" id="customerSearchInput" placeholder="Search all customers…" aria-label="Search all customers">
        </div>

        <!-- Customers Table -->
        <div class="table-wrapper">
            <table class="table user-management-table dynamic-table" data-no-search>
                <thead>
                    <tr>
                        <th>Customer ID</th>
                        <th>Customer Name</th>
                        <th>Barangay</th>
                        <th>Municipality</th>
                        <th>Province</th>
                        <th>Selling Type</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody id="customerTableBody">
                    <tr><td colspan="7">Loading customers…</td></tr>
                </tbody>
            </table>
        </div>

        <div class="dynamic-table-bottombar">
            <span class="dynamic-table-count" id="customerCount"></span>
            <nav class="dynamic-table-pagination" id="customerPagination" aria-label="Table pagination"></nav>
        </div>

    </div>
</main>


<!-- Customer row template, cloned and filled in by customer-profile.js for
     every row -- keeps the row markup in one place instead of duplicated
     between PHP and JS, since rows are now always rendered client-side
     (there's no server-rendered row anymore -- see CustomerProfileController). -->
<template id="customerRowTemplate">
    <tr>
        <td data-label="Customer ID"><strong class="js-customer-id"></strong></td>
        <td data-label="Customer Name" class="js-customer-name"></td>
        <td data-label="Barangay" class="js-customer-barangay"></td>
        <td data-label="Municipality" class="js-customer-municipality"></td>
        <td data-label="Province" class="js-customer-province"></td>
        <td data-label="Selling Type" class="js-customer-selling-type"></td>
        <td data-label="Action">
            <div class="user-action-buttons">
                <button type="button" class="btn btn-blue edit-customer"><i class="fa-solid fa-pen"></i><span>Edit</span></button>
                <button type="button" class="btn btn-red delete-customer"><i class="fa-solid fa-trash"></i><span>Delete</span></button>
            </div>
        </td>
    </tr>
</template>

<!-- Customer Modal -->
<div class="custom-modal" id="customerModal">

    <div class="custom-modal-content user-modal-content">

        <div class="modal-header">
            <h2 id="customerModalTitle"><i class="fa-solid fa-user-plus"></i> Add customer</h2>

            <button
                class="close-btn"
                type="button"
                data-close-customer
            >&times;</button>
        </div>

        <div
            class="modal-body"
            style="overflow:auto;padding:20px"
        >

            <form id="customerForm">

                <input type="hidden" name="pk" id="customerPk">

                <div class="grid user-form-grid">

                    <label>
                        Customer ID
                        <input
                            class="input"
                            name="customer_id"
                            id="customerId"
                            required
                            maxlength="50"
                        >
                    </label>

                    <label>
                        Customer Name
                        <input
                            class="input"
                            name="customer_name"
                            id="customerName"
                            required
                            maxlength="150"
                        >
                    </label>

                    <label>
                        Street
                        <input
                            class="input"
                            name="street"
                            id="customerStreet"
                            maxlength="150"
                        >
                    </label>

                    <label>
                        Barangay
                        <input
                            class="input"
                            name="barangay"
                            id="customerBarangay"
                            maxlength="100"
                        >
                    </label>

                    <label>
                        Municipality
                        <input
                            class="input"
                            name="municipality"
                            id="customerMunicipality"
                            maxlength="100"
                        >
                    </label>

                    <label>
                        Province
                        <input
                            class="input"
                            name="province"
                            id="customerProvince"
                            maxlength="100"
                        >
                    </label>

                    <label>
                        Latitude <small>(optional)</small>
                        <input
                            class="input"
                            type="number"
                            step="any"
                            name="latitude"
                            id="customerLatitude"
                        >
                    </label>

                    <label>
                        Longitude <small>(optional)</small>
                        <input
                            class="input"
                            type="number"
                            step="any"
                            name="longitude"
                            id="customerLongitude"
                        >
                    </label>

                    <label>
                        Selling Type <small>(optional)</small>
                        <input
                            class="input"
                            name="selling_type"
                            id="customerSellingType"
                            maxlength="20"
                        >
                    </label>

                </div>

                <!-- Form Actions -->
                <div
                    class="footer-actions"
                    style="margin-top:22px"
                >

                    <button
                        type="button"
                        class="btn btn-red"
                        data-close-customer
                    >
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-green"
                    >
                        <i class="fa-solid fa-floppy-disk"></i> Save customer
                    </button>

                </div>

            </form>

        </div>
    </div>

</div>
