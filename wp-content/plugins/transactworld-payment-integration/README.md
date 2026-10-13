=== TransactWorld Payment Integration ===
Contributors: TransactWorld
Requires at least: 2.0
Tested up to: 6.8
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Integrates TransactWorld payment processing into WooCommerce, supporting secure Visa, Mastercard, and other payments with real-time reconciliation.

== Description ==
The TransactWorld Payment Integration plugin enables WooCommerce stores to process payments securely via the TransactWorld API. It supports Visa, Mastercard, and other major card networks, with features like PCI DSS-compliant transactions, real-time order reconciliation, and sandbox/live mode configurations. Suitable for businesses of all sizes, the plugin ensures accurate order management and secure payment processing.

== Features ==
- Accept payments via Visa, Mastercard, and other major card networks.
- PCI DSS-compliant transaction processing.
- Real-time reconciliation to update order transaction status.
- Sandbox and live mode configurations.
- Supports Merchant ID, Partner ID, Working Key, and Store IP validations.
- Transaction query and reconciliation functionality.

== Installation ==
1. Download the plugin zip file from the WordPress.org repository.
2. In your WordPress admin dashboard, go to **Plugins > Add New > Upload Plugin**.
3. Choose the plugin zip file and click **Install Now**.
4. Once installed, click **Activate Plugin**.

== Configuration ==
1. Go to **WooCommerce > Settings > Payments** tab.
2. Locate **TransactWorld Pay** and click **Manage**.
3. Configure the following:
   - **Title**: Payment title (e.g., TransactWorld Pay).
   - **Description**: Payment method description (e.g., Pay securely by Credit or Internet Banking).
   - **Merchant ID**: Your TransactWorld Merchant ID.
   - **Working Key**: Secret key issued by TransactWorld.
   - **Partner Name (Optional)**: Required for TransactWorld partner program accounts.
   - **Partner ID (Optional)**: Required for partner program accounts.
   - **Store Language**: Preferred language (e.g., English). Contact TransactWorld for additional languages.
   - **IP Address**: Server IP for transaction validation.
   - **Live Mode Activation**: Yes for live transactions, No for sandbox mode.
   - **Live Mode URL**: URL for live transaction processing.
   - **Test Mode URL**: URL for sandbox testing.
   - **Query URL**: URL for reconciliation queries.

== Payment Workflow ==
- Customer selects **TransactWorld Pay** at checkout.
- Redirects to TransactWorld’s secure payment page.
- Customer enters card details and completes payment.
- On success, the WooCommerce order status updates automatically.
- Use sandbox mode to test the payment flow before going live.

== Reconciliation ==
1. In the WordPress admin panel, go to **Reconciliation**.
2. View the table showing:
   - Order Number
   - Order Description
   - Order Title
   - Tracking ID
   - Order Status
3. Click **Recon** to fetch updated transaction status from TransactWorld.
4. The table updates order records based on API data, ensuring synchronization.

== Testing in Sandbox Mode ==
1. Set **Live Mode Activation** to **No**.
2. Use the **Test Mode URL** provided by TransactWorld.
3. Obtain sandbox credentials from your TransactWorld merchant dashboard.
4. Place a test order to verify the payment flow.

== Security ==
Transactions are processed via TransactWorld’s PCI DSS-compliant servers. No cardholder data is stored on your WordPress server.

== Frequently Asked Questions ==
= How do I get sandbox credentials? =
Contact TransactWorld or check your merchant dashboard for sandbox credentials.

= What if I get an "Invalid Merchant ID" error? =
Verify that the Merchant ID matches the one provided by TransactWorld.

= Why is the payment not processing? =
Ensure sandbox credentials are used in Test Mode and live credentials in Live Mode.

= How do I contact support? =
Email support@transactworld.com or visit https://transactworld.com/plugin-docs.

== Screenshots ==
1. Configuration settings in WooCommerce Payments tab.
2. Reconciliation table showing order details.
3. Checkout page with TransactWorld Pay option.

== Changelog ==
= 1.0.0 =
* Initial release with support for Visa, Mastercard, and reconciliation features.

== Upgrade Notice ==
= 1.0.0 =
Initial release. No upgrades available yet.

== Additional Notes ==
- **PHP Requirements**: Requires PHP 7.4 or higher.
- **Support**: Email support@transactworld.com or visit https://transactworld.com/support.
- **Bug Reports**: Submit issues at https://transactworld.com/report-bug.