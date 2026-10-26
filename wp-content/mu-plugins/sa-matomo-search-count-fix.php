<?php
/**
 * Fix: WP-Matomo search-tracking OOM on high-volume search terms.
 *
 * With the Matomo "track site search" setting enabled (provisioned via
 * sa-matomo-deep.php), TrackingCodeGenerator::apply_search_changes() runs
 * `new WP_Query( 's=<term>&showposts=-1' )` inside wp_footer on every search
 * page purely to count the results. posts_per_page=-1 hydrates EVERY matched
 * product (up to ~9k) and primes its term/meta caches, which exhausts the
 * 128M PHP memory limit on terms with more than ~1.7k matches (500 critical
 * error, fatal in class-wp-term-query.php).
 *
 * That query only ever reads $q->post_count. Forcing fields=ids keeps the
 * count (and the Matomo trackSiteSearch beacon) exact while loading only
 * integers instead of full product objects.
 *
 * Detection is deliberately narrow: front-end, non-main query, search query,
 * posts_per_page=-1, while wp_footer is executing. Only Matomo's counting
 * query has this shape on this site.
 */
add_action('pre_get_posts', static function (WP_Query $q): void {
    if (is_admin() || $q->is_main_query()) {
        return;
    }
    if (!$q->is_search()) {
        return;
    }
    if ((int) $q->get('posts_per_page') !== -1 && (int) $q->get('showposts') !== -1) {
        return;
    }
    if (!did_action('wp_footer')) {
        return;
    }
    $q->set('fields', 'ids');
}, 1);
