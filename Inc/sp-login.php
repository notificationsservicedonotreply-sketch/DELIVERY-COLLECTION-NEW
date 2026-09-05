<?php
declare(strict_types=1);
// Kept only so any old direct link to this file still works. The canonical
// entry point is now the repo-root index.php -> AuthController::showLogin().
require_once __DIR__ . '/../bootstrap.php';
(new AuthController())->showLogin();
