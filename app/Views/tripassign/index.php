<?php requireBootstrapped(); ?>
<style>
    /* Self-contained header styling for this page -- doesn't borrow classes
       from user-management.css, so it can't be affected by that file's
       load order/caching on a page that isn't User Management. Only the
       .status-pill classes (used in the table below) are still shared. */
    .trip-assign-heading{ display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px; margin-bottom:26px; }
    .trip-assign-intro{ display:flex; align-items:center; gap:14px; }
    .trip-assign-icon{
        width:46px; height:46px; flex:0 0 auto; display:grid; place-items:center;
        color:#fff; font-size:19px; border-radius:14px;
        background:linear-gradient(135deg,#b51f2a,#ef5b54);
        box-shadow:0 8px 18px rgba(181,31,42,.24);
    }
    .trip-assign-intro .card-title{ margin-bottom:3px; font-size:20px; color:#2a1b1d; }
    .trip-assign-intro small{ color:#78686b; font-size:13px; }
    /* Breathing room between the toolbar (Show N entries / Search) that
       dynamic-table.js injects right after this heading, and the heading
       itself -- the toolbar has no top margin of its own since it can land
       after all kinds of different headers site-wide. */
    .trip-assign-heading + .dynamic-table-toolbar{ margin-top:6px; }
    @media (max-width:640px){
        .trip-assign-heading{ align-items:stretch; }
        .trip-assign-intro{ align-items:flex-start; }
        .trip-assign-icon{ width:40px; height:40px; border-radius:12px; font-size:16px; }
        .trip-assign-intro .card-title{ font-size:17px; }
    }
</style>
<main class="main user-management-page">

    <?php $pageHeading = 'Dashboard'; require APP_ROOT . '/app/Views/layouts/navigation.php'; ?>

    <div id="tripAssignMessage" class="notice dc-hidden"></div>

    <!-- Trip List Assign -->
    <div class="card" style="border:1px solid #f1dfe1;box-shadow:0 14px 36px rgba(96,20,30,.09)">

        <div class="trip-assign-heading">
            <div class="trip-assign-intro">
                <span class="trip-assign-icon"><i class="fa-solid fa-list-check" aria-hidden="true"></i></span>
                <div>
                    <div class="card-title">Trip List Assign</div>
                    <small>Assign trip IDs to delivery riders and track their status.</small>
                </div>
            </div>

            <button type="button" class="btn btn-green add-user-button" id="addTripAssign">
                <i class="fa-solid fa-route"></i> Assign trip
            </button>
        </div>

        <!-- Assignments Table -->
        <div class="table-wrapper">
            <table class="table user-management-table dynamic-table">
                <thead>
                    <tr>
                        <th data-sortable>User ID</th>
                        <th data-sortable>Rider Name</th>
                        <th data-sortable>Trip ID</th>
                        <th data-sortable>Status</th>
                        <th data-sortable>Trip Date</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$assignments): ?>
                        <tr><td colspan="6">No trip assignments yet. Click "Assign trip" to create one.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($assignments as $assignment): ?>
                        <?php $isActive = (int) ($assignment['Status'] ?? 0) === 1; ?>
                        <tr data-assignment="<?= htmlspecialchars(json_encode($assignment), ENT_QUOTES, 'UTF-8') ?>">
                            <td data-label="User ID"><strong><?= htmlspecialchars((string) $assignment['USERID']) ?></strong></td>
                            <td data-label="Rider Name"><?= htmlspecialchars((string) ($assignment['RiderName'] ?? '')) ?></td>
                            <td data-label="Trip ID"><?= htmlspecialchars((string) $assignment['TRIPID']) ?></td>
                            <td data-label="Status"><span class="status-pill <?= $isActive ? 'status-pill--on' : 'status-pill--off' ?>"><?= $isActive ? 'Active' : 'Completed' ?></span></td>
                            <td data-label="Trip Date"><?= $assignment['TripDate'] ? htmlspecialchars(date('Y-m-d', strtotime((string) $assignment['TripDate']))) : '—' ?></td>
                            <td data-label="Action">
                                <div class="user-action-buttons">
                                    <button type="button" class="btn btn-blue edit-trip-assign"><i class="fa-solid fa-pen"></i><span>Edit</span></button>
                                    <button type="button" class="btn btn-red delete-trip-assign"><i class="fa-solid fa-trash"></i><span>Delete</span></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</main>


<!-- Trip Assign Modal -->
<div class="custom-modal" id="tripAssignModal">

    <div class="custom-modal-content user-modal-content">

        <div class="modal-header">
            <h2 id="tripAssignModalTitle"><i class="fa-solid fa-route"></i> Assign trip</h2>

            <button
                class="close-btn"
                type="button"
                data-close-trip-assign
            >&times;</button>
        </div>

        <div
            class="modal-body"
            style="overflow:auto;padding:20px"
        >

            <form id="tripAssignForm">

                <input type="hidden" name="original_user_id" id="tripOriginalUserId">
                <input type="hidden" name="original_trip_id" id="tripOriginalTripId">

                <div class="grid user-form-grid">

                    <label>
                        Rider (User ID)
                        <select
                            class="input"
                            name="user_id"
                            id="tripUserId"
                            required
                        >
                            <option value="">Select a rider…</option>
                            <?php foreach ($riders as $rider): ?>
                                <option value="<?= htmlspecialchars((string) $rider['USERID']) ?>">
                                    <?= htmlspecialchars((string) $rider['NAME']) ?> (<?= htmlspecialchars((string) $rider['USERID']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Trip ID
                        <input
                            class="input"
                            name="trip_id"
                            id="tripId"
                            list="tripIdSuggestions"
                            required
                            maxlength="50"
                        >
                        <datalist id="tripIdSuggestions">
                            <?php foreach ($tripIdSuggestions as $tripIdOption): ?>
                                <option value="<?= htmlspecialchars($tripIdOption) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </label>

                    <label>
                        Status
                        <select
                            class="input"
                            name="status"
                            id="tripStatus"
                        >
                            <option value="1">Active</option>
                            <option value="0">Completed</option>
                        </select>
                    </label>

                    <label>
                        Trip Date <small>(optional)</small>
                        <input
                            class="input"
                            type="date"
                            name="trip_date"
                            id="tripDate"
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
                        data-close-trip-assign
                    >
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-green"
                    >
                        <i class="fa-solid fa-floppy-disk"></i> Save assignment
                    </button>

                </div>

            </form>

        </div>
    </div>

</div>