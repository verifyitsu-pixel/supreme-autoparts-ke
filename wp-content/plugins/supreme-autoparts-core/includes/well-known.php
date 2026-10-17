<?php
/** Serve the Apple Pay / Whop wallet domain-association file at /.well-known/. */
declare(strict_types=1);
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Whop verifies Apple Pay for the domain by fetching
 * /.well-known/apple-developer-merchantid-domain-association. The Docker image
 * only copies wp-content into the web root, so a physical file never reaches
 * the docroot; serve it through WordPress instead. Runs at priority 1, before
 * redirect_canonical (priority 10), so no canonical or ?v= redirect interferes.
 */
add_action('template_redirect', static function (): void {
    $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (rtrim($path, '/') !== '/.well-known/apple-developer-merchantid-domain-association') {
        return;
    }
    status_header(200);
    header('Content-Type: text/plain; charset=utf-8');
    // Exact bytes of Whop's domain-association file (whop.com/.well-known/apple-platform-integrator/...).
    echo '7b2276657273696f6e223a312c227073704964223a2236343641384242363234393134464232453835354239443531364642353530333338314132444446383545414643463630323336443830413044434235334632222c22637265617465644f6e223a313736303636343737373433327d'; // phpcs:ignore
    exit;
}, 1);
