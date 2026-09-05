<?php requireBootstrapped(); ?>
<div class="main">

    <?php $pageHeading = 'Dashboard'; require APP_ROOT . '/app/Views/layouts/navigation.php'; ?>

    <div class="card">

        <div class="grid">

            <div class="info-boxex users">
                <div class="icon">
                    <i class="fa-solid fa-users"></i>
                </div>

                <h3>Total Users</h3>
                <h1 id="totalUsers">0</h1>
            </div>

            <div class="info-boxex transactions">
                <div class="icon">
                    <i class="fa-solid fa-receipt"></i>
                </div>

                <h3 id="totalTransactionsLabel">Total Transactions</h3>
                <h1 id="totalTransactions">0</h1>
            </div>

            <div class="info-boxex collections">
                <div class="icon">
                    <i class="fa-solid fa-hand-holding-dollar"></i>
                </div>

                <h3 id="totalCollectionsLabel">Confirmed Collections</h3>
                <h1 id="totalCollections">0</h1>
            </div>

            <div class="info-boxex deliveries">
                <div class="icon">
                    <i class="fa-solid fa-truck-fast"></i>
                </div>

                <h3 id="totalDeliveriesLabel">Completed Deliveries</h3>
                <h1 id="totalDeliveries">0</h1>
            </div>

        </div>

    </div>
</div>
