<?php requireBootstrapped(); ?>
<div class="sidebar" id="sidebar">
    <h2><i class="fa-solid fa-truck-fast" aria-hidden="true"></i> Delivery &amp; Collection</h2>
    <div class="menu">

        <a href="<?= Router::url('Dashboard') ?>">
            <i class="fa-solid fa-chart-line" aria-hidden="true"></i> Dashboard
        </a>

        <?php if (hasModuleAccess('Delivery-Portal')): ?>
        <a href="<?= Router::url('Delivery-Portal') ?>">
            <i class="fa-solid fa-truck" aria-hidden="true"></i> Delivery Portal
        </a>
        <?php endif; ?>

        <?php if (canAccessDeliveryTransactions()): ?>
        <a href="<?= Router::url('Delivery-Transactions') ?>">
            <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i> Delivery Transactions
        </a>
        <?php endif; ?>

        <?php if (hasModuleAccess('Collection-Portal')): ?>
        <a href="<?= Router::url('Collection-Portal') ?>">
            <i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i> Collection Portal
        </a>
        <?php endif; ?>

        <?php if (canAccessCollectionTransactions()): ?>
        <a href="<?= Router::url('Collection-Transactions') ?>">
            <i class="fa-solid fa-receipt" aria-hidden="true"></i> Collection Transactions
        </a>
        <?php endif; ?>

        <?php if (hasModuleAccess('Trip-List-Assign')): ?>
        <a href="<?= Router::url('Trip-List-Assign') ?>">
            <i class="fa-solid fa-list-check" aria-hidden="true"></i> Trip List Assign
        </a>
        <?php endif; ?>

        <?php if (hasModuleAccess('Customer-Profile')): ?>
        <a href="<?= Router::url('Customer-Profile') ?>">
            <i class="fa-solid fa-address-card" aria-hidden="true"></i> Customer Profile
        </a>
        <?php endif; ?>

        <?php if (hasModuleAccess('User-Management')): ?>
        <a href="<?= Router::url('User-Management') ?>">
            <i class="fa-solid fa-users-gear" aria-hidden="true"></i> User Management
        </a>
        <?php endif; ?>

        <a href="<?= Router::url('Device-Settings') ?>">
            <i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i> Settings
        </a>

        <a href="<?= Router::url('Logout') ?>"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Logout</a>
    </div>
</div>
