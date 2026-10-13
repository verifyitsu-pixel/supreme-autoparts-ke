=== TheBunch KE Pesapal Gateway for Woocommerce ===
Contributors: Rixeo & Pesapal
Donate link: http://dev.thebunch.co.ke/donate/
Tags: pesapal, woocommerce, ecommerce, gateway, payment
Requires at least: 4.0
Tested up to: 6.5.2
Stable tag: 3.1.2
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

== Description ==

Simple and easy to use plugin for pesapal.com payment gateway. The plugins allows for currencies from Kenya, Tanzania and Uganda

Please raise any issues though [our support page](https://wordpress.org/support/plugin/thebunch-ke-pesapal-woocommerce), thanks.

You will need to set up an IPN notification Id. Cron's are not supported in this version

If you like this plugin consider [donating](http://www.dukagate.info/donate/) a few bob for a coffee :)



== Installation ==

1. Upload `thebunchke-pesapal-woocommerce` directory to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Enter your consumer and secret key in the Payment Gateway section of the Woocommerce settings page.
1. For IPN Nofification Id, generate one through the link provided in the Payment Gateway section of the Woocommerce settings page.
1. Enable the gateway.
1. **Test before production!**

== Changelog ==
= 3.1.2 =
- Removed check order status check on IPN

= 3.1.1 =
- Removed deprecation errors for PHP 8.2 and bug fix on order status update on transaction completion.
- Update order status only once either on callback or IPN

= 3.1.0 =
- Accommodate the "Sequential Order Number" plugin to ensure the new order id is what's sent to Pesapal as the reference number for easier reconciliations.

== Changelog ==
= 3.0.9 =
- Added app_id parameter to Pesapal submitOrderRequest endpoint.

== Changelog ==
= 3.0.8 =
- Added recurring/subscription feature for merchants.

== Changelog ==
= 3.0.7 =
- Fixed a bug on automated IPN genarationon in version 3.0.6

== Changelog ==
= 3.0.6 =
- Fixed order deprecation on version 3.0.5
- Automated IPN Notification Id generation
- Added Phone validation - KE, UG & TZ

= 3.0.5 =
- Formally introduced Pesapal API 3 for all countries.
- Deprecated older versions of Pesapal.

= 3.0.4 =
Include Pesapal v2.6 which has Mpesa STK via API and Cards processing via API 3

= 3.0.3 =
Added:	Option for the admin to enter a custom text. | Cron Job to log response


= 3.0.2 =
Include Pesapal v2.5 which has Mpesa via API and the rest of the payments via the old iframe

= 3.0.1 =
Added a surcharge option on the settings 

= 3.0.0
 - New PesaPal API. We have introduced STK / 2 step mobile payment checkout and also added 3D secure. - Beta version (Contact developer@pesapal.com to enroll for this service)
 - Store PesaPal Tracking ID for PesaPal v2 API. 
 - Upate v2 cron function to use PesaPal tracking id if available.

= 2.0.0
Override thankyou.php to show payment status and appropriate message on callbackpage.

= 1.2.6 =
Item names with an ampersand sign (&) were breaking the xml. Fix added by Team PesaPal to ensure the error 'Problem: parameter_rejected | Advice: unknown_error_occured> oauth_parameters_rejected | request_xml_data' doesn't appear.

= 1.2.5 =
Bug on CRON function fixed


= 1.2.4 =
Added unique prefix to be attached to the order number ensuring your payment pesapal_merchant_reference do not conflict with others posted from your other applications using the same PesaPal account. 

Added IPN tracking feature that allows you to list emails to be notified when IPN is executed. This should only be used to debug IPN issues.

Added CRON function which can be used as an alternative to IPN. Not advisable since it has load impact on your server depending on how frequent you call it.

= 1.2.3 =
Bug on order completion not updating status fixed.

= 1.2.2 =
Enabled order items processed to appear in the description of pesapal receipt

= 1.2.1 =
Enabled orders processed using pesapal to enter processing state to enable store owners do shipping of products 

= 1.2.0 =
Enabled payments to complete at woocomerce thank you page when payment status is complete 
Enabled payment status to be saved when payment completes
Enable IPN response condition

= 1.1.9 =
Raised the version number to ensure no updates from wordpress 

= 1.1.8 =
Fixed Tanzania Shs currency code issue

= 1.1.7 =
enable https on demo account

= 1.1.6 =
Version bump


= 1.1.5 =
Fix on order status

= 1.1.4 =
Version bump

= 1.1.3 =
Added a Preloader when the PesaPal payment page is still loading

= 1.1.2 =
Fixes

= 1.1.1 =
Fix to redirect

= 1.1.0 =
1. Fixes on the WooCommerce API Methods
1. Removed unecessary functions

= 1.0.5 =
Fix on the function before_pay() which prevents a user from paying twice for the same order

= 1.0.4 =
Fixes on Oauth libs to avoid conflict with Twitters libraries if present

= 1.0.3 =
Working now. Redirects from PesaPal Payment to thank you page

= 1.0.2 =
Redirect URL from PesaPal

= 1.0.1 =
Fixed functions that were preventing checkout from completing. 


= 1.0 =
New Plugin to handle PesaPal payments