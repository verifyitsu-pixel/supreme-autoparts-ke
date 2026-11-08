<?php
// Exact identity-scoped fixed motor-parts warning. No product state writes.
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
function sa_w93441_warning_applies($product):bool {
 return $product instanceof WC_Product && $product->get_id()===76374 && $product->get_sku()==='WHLW93441' && (string)$product->get_meta('_sa_shopify_id')==='7686474236081';
}
function sa_w93441_warning_text():string {return 'Handling passenger or off-highway motor vehicle parts can expose you to chemicals such as phthalates and lead, which can cause cancer and reproductive harm. To minimize exposure, service the vehicle in a well-ventilated area, wear gloves, and wash your hands. For more information see www.P65Warnings.ca.gov/motor-vehicle-parts.';}
function sa_w93441_warning_markup($product):string {
 if (!sa_w93441_warning_applies($product)) return '';
 // Fixed 27 CCR 25607.51 text, no chemical substitution and no composition certification.
 // Warning is ordinary visible HTML outside the product gallery, above cart priority 30.
 $icon='<svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" width="32" height="29" viewBox="0 0 32 29" style="display:inline-block;vertical-align:middle;margin-right:0.35em;min-width:32px;"><path d="M16 2 L30 26 H2 Z" fill="#ffff00" stroke="#000000" stroke-width="2" stroke-linejoin="round"/><text x="16" y="23" text-anchor="middle" fill="#000000" font-family="Arial,sans-serif" font-size="21" font-weight="bold">!</text></svg>';
 return '<div class="sa-w93441-prop65-warning" role="note" style="border:1px solid #333;padding:12px;margin:12px 0;background:#fff;color:#111;font-size:16px;line-height:1.5;">'.$icon.'<strong>WARNING:</strong> '.esc_html(str_replace('www.P65Warnings.ca.gov/motor-vehicle-parts.','',sa_w93441_warning_text())).'<a href="https://www.P65Warnings.ca.gov/motor-vehicle-parts" style="color:#0645ad;text-decoration:underline;overflow-wrap:anywhere;">www.P65Warnings.ca.gov/motor-vehicle-parts</a>.</div>';
}
function sa_w93441_render_warning():void {global $product;echo sa_w93441_warning_markup($product);}
add_action('woocommerce_single_product_summary','sa_w93441_render_warning',25);
