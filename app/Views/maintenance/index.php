<?php requireBootstrapped(); ?>
<main class="main">
    <?php $pageHeading = 'Dashboard'; require APP_ROOT . '/app/Views/layouts/navigation.php'; ?>

    <div class="card" style="max-width:480px;margin:40px auto;text-align:center;padding:44px 32px">
        <div style="width:64px;height:64px;margin:0 auto 20px;border-radius:50%;background:linear-gradient(135deg,#a71927,#d63d49);display:grid;place-items:center;color:#fff;font-size:26px;box-shadow:0 8px 18px rgba(167,25,39,.28)">
            <i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i>
        </div>
        <h1 style="font-size:22px;margin:0 0 10px;color:#2b1b1e">Under Maintenance</h1>
        <p style="color:#78666a;margin:0 0 26px;line-height:1.5"><?= htmlspecialchars($message) ?></p>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
            <button type="button" class="btn btn-blue" onclick="location.reload()">
                <i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Try again
            </button>
            <a href="<?= Router::url('Logout') ?>" class="btn btn-gray">
                <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Log out
            </a>
        </div>
    </div>
</main>
