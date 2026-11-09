<?php
/** Homepage trust benefits. Presentation only; no product or payment changes. */
if (!defined('ABSPATH')) { exit; }
$sa_trust_ke = !empty($sa_is_ke);
$sa_trust_us = !$sa_trust_ke && function_exists('sa_storefront_country') && sa_storefront_country() === 'US';
$sa_trust_items = [
 ['fit', 'Fitment help', 'Send your year, make, model or VIN. We help you check the part number.', home_url('/contact/'), 'Ask about fitment'],
 ['return', '15-day return requests', 'Unused parts may be returned under our policy. Fees and exclusions apply.', home_url('/returns/'), 'Returns policy'],
 ['help', 'WhatsApp order help', 'Ask us about a part, fitment or an order.', 'https://wa.me/supremeautopart', 'Chat on WhatsApp'],
 ['delivery', $sa_trust_ke ? 'Delivery across Kenya' : ($sa_trust_us ? 'Free US shipping' : 'International delivery'), $sa_trust_ke ? 'Courier rates shown before you pay. Track your parcel on our site.' : ($sa_trust_us ? 'No minimum order. Track your delivery on our site.' : 'Check delivery options and cost before you pay.'), home_url('/shipping-policy/'), 'Delivery details'],
 ['parts', 'Car & motorcycle parts', 'Search by part number, brand or vehicle. Find parts for your next job.', home_url('/shop/'), 'Shop parts'],
 ['card', $sa_trust_ke ? 'M-Pesa & card payments' : 'Pay by card', 'Review your total and available payment methods at checkout.', home_url('/shop/'), 'Browse the store'],
];
$sa_trust_icons = [
 'fit' => '<circle cx="20" cy="20" r="12"/><path d="m29 29 10 10"/><path class="sa-trust-accent" d="m15 20 4 4 7-8"/>',
 'return' => '<path d="M13 12h17a12 12 0 0 1 0 24H18"/><path class="sa-trust-accent" d="m21 4-8 8 8 8"/>',
 'help' => '<path d="M9 8h30v24H23l-9 8v-8H9z"/><path class="sa-trust-accent" d="M16 17h16M16 24h10"/>',
 'delivery' => '<path d="M5 12h24v23H5zM29 21h9l6 8v6H29z"/><circle cx="14" cy="36" r="5"/><circle cx="36" cy="36" r="5"/><path class="sa-trust-accent" d="M11 20h12M11 26h8"/>',
 'parts' => '<path d="m20 5 8 0 2 7 7-2 5 7-5 6 5 6-5 7-7-2-2 7h-8l-2-7-7 2-5-7 5-6-5-6 5-7 7 2z"/><circle class="sa-trust-accent" cx="24" cy="23" r="7"/>',
 'card' => '<rect x="5" y="10" width="38" height="28" rx="4"/><path d="M5 20h38"/><path class="sa-trust-accent" d="M12 30h9"/>'
];
?>
<section class="sa-ed-sec sa-ed-why sa-trust" aria-labelledby="sa-ed-why">
 <div class="sa-container">
  <div class="sa-ed-head"><h2 id="sa-ed-why"><?php esc_html_e('Why Supreme Autoparts', 'supreme-autoparts'); ?></h2></div>
  <div class="sa-trust-grid">
  <?php foreach ($sa_trust_items as [$icon, $title, $copy, $url, $label]) : ?>
   <article class="sa-trust-card">
    <svg viewBox="0 0 48 48" width="48" height="48" aria-hidden="true" focusable="false"><?php echo $sa_trust_icons[$icon]; // Fixed local SVG paths only. ?></svg>
    <h3><?php echo esc_html__($title, 'supreme-autoparts'); ?></h3>
    <p><?php echo esc_html__($copy, 'supreme-autoparts'); ?></p>
    <a href="<?php echo esc_url($url); ?>"><?php echo esc_html__($label, 'supreme-autoparts'); ?><span aria-hidden="true"> &rarr;</span></a>
   </article>
  <?php endforeach; ?>
  </div>
 </div>
</section>
