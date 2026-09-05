<?php requireBootstrapped(); ?>
<div class="card dashboard-card">
        <button class="menu-toggle" onclick="toggleMenu()">
            <i class="fa-solid fa-bars"></i>
        </button>
    <div class="dashboard-left">
        <h1><?= htmlspecialchars($pageHeading ?? 'Dashboard') ?></h1>
        <small>
            Welcome, <?= ucwords(strtolower(htmlspecialchars($_SESSION['EncoderName'] ?? 'User'))) ?>
        </small>
    </div>

</div>
