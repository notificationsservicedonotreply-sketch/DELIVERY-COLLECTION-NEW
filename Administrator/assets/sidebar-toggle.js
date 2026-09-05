/**
 * Sidebar open/close for the hamburger button and its overlay.
 * These live in app/Views/layouts/navigation.php and header.php, which are
 * included on every admin page -- so this needs to be loaded on every page
 * too, not just the Dashboard (it used to live in dashboard.js, which is
 * only loaded on the Dashboard page as a performance optimization -- that
 * silently broke the toggle button everywhere else).
 */
function toggleMenu(){
    document.getElementById("sidebar").classList.toggle("active");
    document.getElementById("overlay").classList.toggle("active");
}
