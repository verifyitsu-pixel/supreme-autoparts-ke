<?php
/** Sitewide styled upload dropzone (camera + gallery + files). */
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
function sa_fd_enqueue(): void
{
    wp_enqueue_style('sa-file-drop', SA_CORE_URL . 'assets/file-drop.css', [], SA_CORE_VERSION);
    wp_enqueue_script('sa-file-drop', SA_CORE_URL . 'assets/file-drop.js', [], SA_CORE_VERSION, true);
}
add_action('wp_enqueue_scripts', 'sa_fd_enqueue', 30);
add_action('admin_enqueue_scripts', static function (): void {
    // Staff screens with front-end style forms (garage/workshop) also benefit; Woo media screens use their own uploader.
    if (isset($_GET['page']) && str_starts_with((string) $_GET['page'], 'sa-')) {
        sa_fd_enqueue();
    }
});
/* Accept iPhone HEIC/HEIF photos in WordPress uploads. */
add_filter('upload_mimes', static function (array $m): array {
    $m['heic'] = 'image/heic';
    $m['heif'] = 'image/heif';
    return $m;
});
