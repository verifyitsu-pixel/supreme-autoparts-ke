<?php
/** Run only on a fresh disposable database. This file cannot clone production. */
if (!defined('SA_SYNTHETIC_STAGING') || SA_SYNTHETIC_STAGING !== true || WP_ENVIRONMENT_TYPE !== 'staging') throw new RuntimeException('Not synthetic staging');
if (!class_exists('WooCommerce')) throw new RuntimeException('WooCommerce not loaded');
WC_Install::install();
update_option('woocommerce_coming_soon','no');
update_option('woocommerce_store_pages_only','no');
if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}") !== 1) throw new RuntimeException('Expected fresh install only');
if (get_posts(['post_type'=>'product','posts_per_page'=>1]) || wc_get_orders(['limit'=>1])) throw new RuntimeException('Refuses an existing catalog/order store');
update_option('blogname','Supreme Autoparts | Private sandbox staging');
update_option('woocommerce_currency','USD');
update_option('woocommerce_default_country','US:OR');
update_option('woocommerce_calc_taxes','no');
update_option('woocommerce_enable_guest_checkout','yes');
update_option('woocommerce_enable_signup_and_login_from_checkout','no');
update_option('woocommerce_enable_checkout_login_reminder','no');
update_option('woocommerce_default_customer_address','base');
update_option('woocommerce_allow_tracking','no');
update_option('woocommerce_store_address','Fictional staging address');
update_option('woocommerce_store_city','Portland');
update_option('woocommerce_store_postcode','97230');
update_option('woocommerce_checkout_phone_field','optional');
update_option('woocommerce-ppcp-is-new-merchant','1');
update_option('woocommerce_ppcp-settings-migration-is-done','1');
foreach(['woocommerce_cod_settings','woocommerce_bacs_settings','woocommerce_cheque_settings','woocommerce_pesapal_settings','woocommerce_paystack_settings','woocommerce_whop_settings'] as $key) update_option($key,['enabled'=>'no']);
foreach(['cart'=>'[woocommerce_cart]','checkout'=>'[woocommerce_checkout]','myaccount'=>'[woocommerce_my_account]','shop'=>''] as $slug=>$content){
    $id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>ucfirst($slug),'post_name'=>$slug,'post_content'=>$content]);
    update_option('woocommerce_'.$slug.'_page_id',$id);
}
$p=new WC_Product_Simple();$p->set_name('Fictional sandbox test part');$p->set_slug('fictional-sandbox-test-part');$p->set_status('publish');$p->set_regular_price('1.00');$p->set_virtual(false);$p->set_weight('1');$p->set_sku('SYNTHETIC-PAYPAL-001');$p->set_manage_stock(true);$p->set_stock_quantity(10);$p->set_description('Fictional test item. No goods, delivery or real charge.');$id=$p->save();
update_option('sa_synthetic_fixture_product_id',$id);
$zone=new WC_Shipping_Zone();$zone->set_zone_name('Fictional staging delivery');$zone->set_zone_order(1);$zone_id=$zone->save();$method_id=$zone->add_shipping_method('flat_rate');update_option('woocommerce_flat_rate_'.$method_id.'_settings',['title'=>'Fictional test delivery, no fulfilment','tax_status'=>'none','cost'=>'0']);
update_option('woocommerce_onboarding_profile',['completed'=>true]);
update_option('woocommerce_task_list_hidden','yes');
update_option('sa_theme_flush_ver',defined('SA_THEME_VERSION')?SA_THEME_VERSION:'');
update_option('sa_email_logo_pin_'.(defined('SA_THEME_VERSION')?SA_THEME_VERSION:''),'1');
// No usable local WP login; actual review access must be separately provisioned securely.
$wpdb->update($wpdb->users,['user_pass'=>'!synthetic-staging-login-disabled'],['ID'=>1]);
echo wp_json_encode(['product_id'=>$id,'cart_page'=>get_option('woocommerce_cart_page_id'),'checkout_page'=>get_option('woocommerce_checkout_page_id'),'currency'=>get_option('woocommerce_currency'),'price'=>$p->get_price(),'products'=>1,'orders'=>count(wc_get_orders(['limit'=>2])),'keys'=>'empty','provider_network'=>'denied']);
