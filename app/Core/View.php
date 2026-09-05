<?php
declare(strict_types=1);

/**
 * Minimal view renderer: layout (header/sidebar) + page template + footer.
 *
 * $pageScripts controls which of the shared JS bundles the footer loads.
 * Previously every page loaded all eight script tags regardless of need
 * (dashboard.js, user-management.js, CustomerMap.js, etc. all loaded even on
 * pages that never use them) -- this is one of the "fast reaction" fixes:
 * fewer requests and less JS to parse per page load.
 */
final class View
{
    public static function render(string $template, array $data = [], array $pageScripts = []): void
    {
        extract($data, EXTR_SKIP);
        require APP_ROOT . '/app/Views/layouts/header.php';
        require APP_ROOT . '/app/Views/layouts/sidebar.php';
        require APP_ROOT . '/app/Views/' . $template . '.php';
        require APP_ROOT . '/app/Views/layouts/footer.php';
    }
}
