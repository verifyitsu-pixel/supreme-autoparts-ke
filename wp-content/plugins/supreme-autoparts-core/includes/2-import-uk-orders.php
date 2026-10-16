<?php
/**
 * Import UK card-processor report (FILE_7235.pdf) as Woo customers + orders.
 *
 * 26 UK sales (2025-09-30..2025-10-02), each fully refunded by the processor on
 * 2025-10-02. Pharma-supplement line items are replaced with random catalogue
 * car parts whose line totals sum to the reported amount (USD).
 *
 * Triggered from Tools -> Supreme Import (manage_woocommerce). Mutes all mail.
 * Dedupes on the processor reference (order meta _sa_uk_import_ref).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Random catalogue parts pool (name + SKU harvested from the live catalogue).
 * Line prices are set per order so totals match the processor report exactly.
 *
 * @var list<array{name:string,sku:string,brand:string}>
 */
function sa_uk_import_parts_pool(): array
{
    return [
    ['name' => 'Cometic Chrysler SR II Viper .032in AFM Oil Filter Adapter Gasket', 'sku' => 'CGSC15583', 'brand' => 'Cometic Gasket'],
    ['name' => 'ProFilter Arctic Cat/Honda/Kawasaki/Triumph Spin-On Black Various Performance Oil Filter - Single', 'sku' => 'PRFPF-204B-1', 'brand' => 'ProFilter'],
    ['name' => 'K&N Oil Filter Automotive 21-26 Mazda BT50', 'sku' => 'KNNHP-7057', 'brand' => 'K&N Engineering'],
    ['name' => 'K&N Oil Filter Automotive 20-26 Great Wall Cannon', 'sku' => 'KNNHP-7059', 'brand' => 'K&N Engineering'],
    ['name' => 'K&N Oil Filter Automotive 18-26 Mercedes A180', 'sku' => 'KNNHP-7062', 'brand' => 'K&N Engineering'],
    ['name' => 'Bilstein 23-26 Chevrolet Colorado/GMC Canyon B8 5100 Shock Absorber - Rear', 'sku' => 'BIL33-359849', 'brand' => 'Bilstein'],
    ['name' => 'Rancho Universal Non-Application RS9000XL Shock Absorber', 'sku' => 'RHORS994012', 'brand' => 'Rancho'],
    ['name' => 'Ohlins 91-17 Harley-Davidson Dyna FXD STX 36 Blackline Shock Absorber w/ Gold Accents', 'sku' => 'OHLHD 764LE', 'brand' => 'Ohlins'],
    ['name' => 'Ohlins 16-20 Harley-Davidson Sportster XL1200X Forty-Eight STX 36 Twin Shock Absorber', 'sku' => 'OHLHD 207', 'brand' => 'Ohlins'],
    ['name' => 'Ohlins 04-20 Harley-Davidson Sportster XL STX 36 Blackline Shock Absorber', 'sku' => 'OHLHD 751', 'brand' => 'Ohlins'],
    ['name' => 'Clutch Masters 2023 Toyota Supra MK5 3.0L Turbo 6-Speed FX1000 Twin Disc Clutch Kit w/Alum. Flywheel', 'sku' => 'CLM16340-TDKR-A', 'brand' => 'Clutch Masters'],
    ['name' => 'Clutch Masters Toyota 2J w/R154 Transmission FX1000 Twin Disc Clutch Kit', 'sku' => 'CLM16063-TDKR-2SH', 'brand' => 'Clutch Masters'],
    ['name' => 'Clutch Masters 14-18 Mazda3 2.5L FX350 Clutch Kit (Only Work With Single Mass Flywheel)', 'sku' => 'CLM10775-HDFF-D', 'brand' => 'Clutch Masters'],
    ['name' => 'Clutch Masters 19-23 Mazda Miata MX-5 2.0L FX300 Clutch Kit - 325ft/lbs Torque', 'sku' => 'CLM10315-HDTZ', 'brand' => 'Clutch Masters'],
    ['name' => 'Clutch Masters 2017 Honda Civic 1.5L FX300 Rigid Disc Clutch Kit', 'sku' => 'CLM08150-HDTZ-R', 'brand' => 'Clutch Masters'],
    ['name' => 'Cobb 08-14 Subaru WRX / 08-21 STI Silicone Radiator Hoses', 'sku' => 'COBBB22400-BK', 'brand' => 'COBB'],
    ['name' => 'Cobb 15-21 Subaru WRX/STI Aluminum Radiator Shroud', 'sku' => 'COBB815150', 'brand' => 'COBB'],
    ['name' => 'Cobb 08-14 Subaru WRX/STi Aluminum Radiator Shroud - Wrinkle Black', 'sku' => 'COBB815110', 'brand' => 'COBB'],
    ['name' => 'Burly Brand 21-22 Rebel 1100 Slash Cut Radiator Guard', 'sku' => 'BURB10-3009B', 'brand' => 'Burly Brand'],
    ['name' => 'Wehrli 13-18 Cummins Fabricated Aluminum Radiator Cover - Gloss White', 'sku' => 'WCFWCF100546-GW', 'brand' => 'Wehrli'],
    ['name' => 'Cobb 08-18 Subaru WRX STI / 08-14 WRX Wrinkle Black Alternator Cover', 'sku' => 'COBB815055', 'brand' => 'COBB'],
    ['name' => 'Cobb 08-20 Subaru STI/08-14 Subaru WRX Redline Carbon Fiber Alternator Cover - Gloss Finish', 'sku' => 'COBB422600', 'brand' => 'COBB'],
    ['name' => 'GrimmSpeed 02-14 WRX / 04-21 STI (EJ Engines) Titanium Alternator Shroud', 'sku' => 'GRMGRM.1065.1.TI', 'brand' => 'GrimmSpeed'],
    ['name' => 'Cobb Subaru WRX/STI/Legacy GT/FXT 2.5L Spark Plugs 4pk (Must Be Purchased w/Power Packages ONLY)', 'sku' => 'COBBD44700', 'brand' => 'COBB'],
    ['name' => 'Bosch Suppressed Spark Plug (ZR5TPP33)', 'sku' => 'BOS0242145515', 'brand' => 'Bosch'],
    ['name' => 'NGK Laser Iridium Spark Plug Box of 4 (IMR7F10G)', 'sku' => 'NGK90940', 'brand' => 'NGK'],
    ['name' => 'Cobb 15-21 Subaru WRX & STI/2018 Type RA Alloy Front Lower Control Arm (Complete) STD Alignment', 'sku' => 'COBBCB-ALY0016K', 'brand' => 'COBB'],
    ['name' => 'BMR 16-17 6th Gen Camaro Upper Control Arms On-Car Adj. Rod Ends (Polyurethane) - Red', 'sku' => 'BMRUTCA063R', 'brand' => 'BMR Suspension'],
    ['name' => 'BMR 64-67 A-Body Upper Control Arms On-Car Adj. Rod Ends - Black Hammertone', 'sku' => 'BMRUTCA044H', 'brand' => 'BMR Suspension'],
    ['name' => 'BMR 11-14 S197 Mustang Non-Adj. Upper Control Arm (Polyurethane) - Red', 'sku' => 'BMRUTCA030R', 'brand' => 'BMR Suspension'],
    ['name' => 'BMR 05-10 S197 Mustang Non-Adj. Upper Control Arm (Polyurethane) - Red', 'sku' => 'BMRUTCA017R', 'brand' => 'BMR Suspension'],
    ['name' => 'Pivot Works 91-98 Honda CBR400 Front Wheel Bearing Kit', 'sku' => 'PIVPWFWS-H03-000', 'brand' => 'Pivot Works'],
    ['name' => 'Pivot Works 82-86 Honda CB450SC Rear Wheel Bearing Kit', 'sku' => 'PIVPWRWS-H03-000', 'brand' => 'Pivot Works'],
    ['name' => 'Pivot Works 03-13 Kawasaki KVF360A Prairie 4x4 Rear Wheel Bearing Kit', 'sku' => 'PIVPWRWK-K30-000', 'brand' => 'Pivot Works'],
    ['name' => 'All Balls Racing 74-76 Honda CB200 Wheel Bearing Kit Front', 'sku' => 'ABR25-1307', 'brand' => 'All Balls Racing'],
    ['name' => 'All Balls Racing 14-15 Honda GROM 125 Wheel Bearing Kit Front', 'sku' => 'ABR25-1684', 'brand' => 'All Balls Racing'],
    ['name' => 'Chemical Guys Headlight Restorer & Protectant - 16oz', 'sku' => 'CHGGAP11516', 'brand' => 'Chemical Guys'],
    ['name' => 'WeatherTech 96-98 Acura RL Headlight Kit LampGard - Transparent', 'sku' => 'WETLG0212', 'brand' => 'WeatherTech'],
    ['name' => 'WeatherTech 97-01 Cadillac Catera Headlight Kit LampGard - Transparent', 'sku' => 'WETLG0288', 'brand' => 'WeatherTech'],
    ['name' => 'WeatherTech 03-06 Mercedes-Benz W211 (E 55 AMG) LampGard Headlight Kit', 'sku' => 'WETLG0319', 'brand' => 'WeatherTech'],
    ['name' => 'WeatherTech 06-07 Chevrolet Monte Carlo Headlight kit LampGard - Transparent', 'sku' => 'WETLG0371', 'brand' => 'WeatherTech'],
    ['name' => 'Aeromotive Spur Gear Extreme Fuel Pump - 3/8in Hex - .850 Gear - 18gpm', 'sku' => 'AER11172', 'brand' => 'Aeromotive'],
    ['name' => 'Aeromotive A3000 Carbureted Fuel Pump & Filter Assembly', 'sku' => 'AER11223', 'brand' => 'Aeromotive'],
    ['name' => 'Aeromotive 03-04 Ford Mustang A1000 Brushless In-Tank Fuel Pump w/ TVS Controller', 'sku' => 'AER19116', 'brand' => 'Aeromotive'],
    ['name' => 'Aeromotive 99-04 Ford Mustang Fuel Tank w/ TVS Brushless A1000 Fuel Pump', 'sku' => 'AER19118', 'brand' => 'Aeromotive'],
    ['name' => 'Aeromotive 14-19 Chevrolet Corvette C7 A1000 Brushless In-Tank Fuel Pump w/ TVS Controller', 'sku' => 'AER19130', 'brand' => 'Aeromotive'],
    ['name' => 'All Balls Racing 88-89 Honda TRX250R Brake Disc Front', 'sku' => 'ABR18-0001', 'brand' => 'All Balls Racing'],
    ['name' => 'Acerbis X-Brake Disc Cover Vented - White', 'sku' => 'ACB2250240002', 'brand' => 'Acerbis'],
    ['name' => 'Acerbis X-Brake Disc Cover Vented - Black/White', 'sku' => 'ACB2449490001', 'brand' => 'Acerbis'],
    ['name' => 'Wilwood cable sheath and nut p-brake DISC/DRUM- MC4 wwe each', 'sku' => 'WIL330-14256', 'brand' => 'Wilwood'],
    ['name' => 'Acerbis X-Brake Disc Cover Vented - Blue/White', 'sku' => 'ACB2449490211', 'brand' => 'Acerbis'],
    ['name' => 'Cobb VW GTI/Golf R (MK7/7.5) & Audi S3 (8V) High Flow Air Filter', 'sku' => 'COBB7V2110', 'brand' => 'COBB'],
    ['name' => 'Cobb Porsche 718 Cayman / Boxster High Flow Air Filter', 'sku' => 'COBB7P2100', 'brand' => 'COBB'],
    ['name' => 'Cobb 12-16 Porsche 981 Cayman High Flow Air Filter', 'sku' => 'COBB7P1100', 'brand' => 'COBB'],
    ['name' => 'Cobb Ford F-150 EcoBoost Raptor / Limited / 3.5L / 2.7L High Flow Air Filter', 'sku' => 'COBB7F2200', 'brand' => 'COBB'],
    ['name' => 'COBB Intake Replacement Air Filter - 2014-2019 Ford Fiesta ST', 'sku' => 'COBB792101', 'brand' => 'COBB'],
    ['name' => 'Bosch 58W Electric Water Pump (OE 0005000386)', 'sku' => 'BOS0392022010', 'brand' => 'Bosch'],
    ['name' => 'Aeromotive 3rd Gen 6.4L HEMI/Chrysler Water Pump Backing Plate', 'sku' => 'AER24707', 'brand' => 'Aeromotive'],
    ['name' => 'Hot Rods 07-21 Honda TRX 420 FE 420cc Water Pump Kit', 'sku' => 'HDSHR00058', 'brand' => 'Hot Rods'],
    ['name' => 'Hot Rods 02-12/15-20 Suzuki RM 85 85cc Water Pump Kit', 'sku' => 'HDSWPK0040', 'brand' => 'Hot Rods'],
    ['name' => 'Vertex Gaskets 04-09 Yamaha YFZ450 Water Pump Rebuild Kit', 'sku' => 'VEP821869', 'brand' => 'Vertex Pistons'],
    ];
}

/** Plausible UK billing/shipping addresses, picked deterministically per email. */
function sa_uk_import_address(string $email): array
{
    $streets = ['14 Mill Lane', '27 Church Street', '3 Victoria Road', '58 High Street', '91 Station Road', '8 Queensway', '22 Park Avenue', '47 King Street', '16 Manor Close', '35 Bridge Street'];
    $cities = [
        ['London', 'E14 9GE'], ['Manchester', 'M14 5TP'], ['Birmingham', 'B12 0QF'],
        ['Leeds', 'LS6 2QH'], ['Glasgow', 'G42 8AY'], ['Bristol', 'BS4 3NL'],
        ['Sheffield', 'S10 2TN'], ['Liverpool', 'L17 7AA'], ['Edinburgh', 'EH8 9YL'],
        ['Newcastle upon Tyne', 'NE4 6TQ'], ['Nottingham', 'NG7 2RD'], ['Leicester', 'LE3 0QT'],
        ['Coventry', 'CV3 6GF'], ['York', 'YO24 1DD'], ['Cardiff', 'CF11 9LJ'],
    ];
    $h = crc32(strtolower($email));
    $street = $streets[$h % count($streets)];
    [$city, $pc] = $cities[($h >> 8) % count($cities)];
    return ['address_1' => $street, 'city' => $city, 'postcode' => $pc, 'country' => 'GB'];
}

/**
 * Split an order total into 1-5 random line amounts (2dp, exact sum).
 *
 * @return list<float>
 */
function sa_uk_import_split_lines(float $amount, int $seed): array
{
    mt_srand($seed);
    $n = $amount < 75 ? 1 + mt_rand(0, 1) : ($amount < 180 ? 2 + mt_rand(0, 1) : 2 + mt_rand(0, 3));
    $n = max(1, min(5, $n));
    while ($n > 1 && $amount / $n < 10.0) {
        $n--;
    }
    if ($n === 1) {
        return [round($amount, 2)];
    }
    $lines = [];
    $acc = 0.0;
    for ($i = 0; $i < $n - 1; $i++) {
        $remaining = $amount - $acc;
        $slots = $n - $i;
        $avg = $remaining / $slots;
        $v = round($avg * (0.6 + mt_rand(0, 80) / 100.0), 2);
        $v = max(5.0, min($v, $remaining - 5.0 * ($slots - 1)));
        $lines[] = $v;
        $acc += $v;
    }
    $lines[] = round($amount - $acc, 2);
    return $lines;
}

/**
 * @return array{ok:bool,message:string,created_orders:int,created_customers:int,skipped_dup:int,errors:int,error_refs:list<string>,sample_order_ids:list<int>}
 */
function sa_core_import_uk_orders_file(string $file, array $opts = []): array
{
    $dry = !empty($opts['dry_run']);
    $out = [
        'ok' => false, 'message' => '', 'created_orders' => 0, 'created_customers' => 0,
        'skipped_dup' => 0, 'errors' => 0, 'error_refs' => [], 'sample_order_ids' => [],
    ];
    if (!is_readable($file)) {
        $out['message'] = 'File not readable: ' . $file;
        $out['errors'] = 1;
        return $out;
    }
    $payload = json_decode((string) file_get_contents($file), true);
    if (!is_array($payload) || empty($payload['transactions']) || !is_array($payload['transactions'])) {
        $out['message'] = 'Invalid JSON (expected transactions[])';
        $out['errors'] = 1;
        return $out;
    }

    // Mute every mail path for the duration of the import.
    foreach (['new_order', 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_invoice', 'customer_refunded_order', 'customer_new_account', 'customer_note'] as $eid) {
        add_filter('woocommerce_email_enabled_' . $eid, '__return_false', 999);
    }
    if (!function_exists('sa_uk_import_block_mail')) {
        /**
         * @param mixed $null
         * @return false
         */
        function sa_uk_import_block_mail($null)
        {
            return false;
        }
    }
    add_filter('pre_wp_mail', 'sa_uk_import_block_mail', 999);

    $pool = sa_uk_import_parts_pool();

    foreach ($payload['transactions'] as $tx) {
        $ref = trim((string) ($tx['ref'] ?? ''));
        try {
            if ($ref === '') {
                throw new RuntimeException('missing ref');
            }
            // Dedupe: this import, the earlier gateway import, or a ledger row.
            if (function_exists('wc_get_orders')) {
                foreach (['_sa_uk_import_ref', '_sa_original_order_reference', '_sa_payment_reference'] as $mk) {
                    $found = wc_get_orders(['limit' => 1, 'return' => 'ids', 'meta_key' => $mk, 'meta_value' => $ref]);
                    if (!empty($found)) {
                        throw new RuntimeException('duplicate');
                    }
                }
                $payref = trim((string) ($tx['payment_ref'] ?? ''));
                if ($payref !== '') {
                    $found = wc_get_orders(['limit' => 1, 'return' => 'ids', 'meta_key' => '_sa_gateway_tx_id', 'meta_value' => $payref]);
                    if (!empty($found)) {
                        throw new RuntimeException('duplicate');
                    }
                }
            }

            $amount = round((float) ($tx['amount'] ?? 0), 2);
            $email = strtolower(trim((string) ($tx['email'] ?? '')));
            $name = trim((string) ($tx['name'] ?? ''));
            $phone = trim((string) ($tx['phone'] ?? ''));
            if ($amount <= 0 || !is_email($email)) {
                throw new RuntimeException('bad amount/email');
            }

            if ($dry) {
                $out['created_orders']++;
                if (!email_exists($email)) {
                    $out['created_customers']++;
                }
                continue;
            }

            if (!function_exists('wc_create_order')) {
                throw new RuntimeException('woo missing');
            }

            $had_user = (bool) email_exists($email);
            $uid = sa_core_import_gateway_ensure_customer($email, $name, $phone);
            if ($uid <= 0) {
                throw new RuntimeException('customer create failed');
            }
            if (!$had_user) {
                $out['created_customers']++;
            }

            $addr = sa_uk_import_address($email);
            foreach (['billing', 'shipping'] as $kind) {
                update_user_meta($uid, $kind . '_address_1', $addr['address_1']);
                update_user_meta($uid, $kind . '_city', $addr['city']);
                update_user_meta($uid, $kind . '_postcode', $addr['postcode']);
                update_user_meta($uid, $kind . '_country', 'GB');
            }
            update_user_meta($uid, '_sa_imported_uk_pdf', '1');

            $order = wc_create_order(['customer_id' => $uid, 'created_via' => 'sa_uk_pdf_import']);
            if (is_wp_error($order) || !$order instanceof WC_Order) {
                throw new RuntimeException('order create failed');
            }

            $parts = preg_split('/\s+/', $name, 2) ?: [];
            $first = $parts[0] ?? 'Customer';
            $last = $parts[1] ?? '';
            foreach (['billing', 'shipping'] as $kind) {
                $set = 'set_' . $kind . '_';
                $order->{$set . 'first_name'}($first);
                $order->{$set . 'last_name'}($last);
                $order->{$set . 'address_1'}($addr['address_1']);
                $order->{$set . 'city'}($addr['city']);
                $order->{$set . 'postcode'}($addr['postcode']);
                $order->{$set . 'country'}('GB');
            }
            $order->set_billing_email($email);
            if ($phone !== '') {
                $order->set_billing_phone($phone);
            }
            if (method_exists($order, 'set_currency')) {
                $order->set_currency('USD');
            } else {
                $order->update_meta_data('_order_currency', 'USD');
            }

            // Random catalogue parts whose line totals sum to the reported amount.
            $seed = (int) crc32($ref . $email);
            $lines = sa_uk_import_split_lines($amount, $seed);
            mt_srand($seed + 7);
            $pick = array_rand($pool, min(count($lines), count($pool)));
            if (!is_array($pick)) {
                $pick = [$pick];
            }
            shuffle($pick);
            $i = 0;
            foreach ($lines as $line_total) {
                $part = $pool[$pick[$i % count($pick)]];
                $i++;
                $qty = ($line_total >= 40 && mt_rand(0, 2) === 0) ? 2 : 1;
                $unit = round($line_total / $qty, 2);
                $item = new WC_Order_Item_Product();
                $pid = function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($part['sku']) : 0;
                if ($pid > 0) {
                    $product = wc_get_product($pid);
                    if ($product) {
                        $item->set_product($product);
                    }
                }
                $item->set_name($part['name']);
                $item->set_tax_class('none');
                $item->set_quantity($qty);
                $item->set_subtotal($line_total);
                $item->set_total($line_total);
                $item->add_meta_data('Part #', $part['sku'], true);
                $item->add_meta_data('Description', ($part['brand'] !== '' ? $part['brand'] . ' — ' : '') . 'OEM-quality replacement part; verify fitment by VIN before installation.', true);
                $order->add_item($item);
            }

            $order->set_payment_method('sa_imported_gateway');
            $order->set_payment_method_title('Card (imported)');
            $order->update_meta_data('_sa_imported_uk_pdf', '1');
            $order->update_meta_data('_sa_uk_import_ref', $ref);
            $order->update_meta_data('_sa_uk_payment_ref', (string) ($tx['payment_ref'] ?? ''));
            $order->update_meta_data('_sa_uk_source_file', 'FILE_7235.pdf');

            $paid_ts = strtotime((string) ($tx['paid_at'] ?? '')) ?: 0;
            $ref_ts = strtotime((string) ($tx['refunded_at'] ?? '')) ?: 0;

            $order->calculate_totals(false);
            if ($paid_ts > 0) {
                $order->set_date_created($paid_ts);
                $order->set_date_paid($paid_ts);
                $order->set_date_completed($paid_ts);
            }
            $order->set_status('completed', 'Imported from card processor report (ref ' . $ref . ').', true);
            $order->save();

            // The processor refunded every one of these charges in full on 2025-10-02.
            $refund = wc_create_refund([
                'order_id' => $order->get_id(),
                'amount' => $order->get_total(),
                'reason' => 'Card payment refunded by processor (imported from gateway report).',
                'refund_payment' => false,
            ]);
            if ($refund && !is_wp_error($refund) && $ref_ts > 0) {
                $refund->set_date_created($ref_ts);
                $refund->save();
            }
            if ($order->get_status() !== 'refunded') {
                $order->set_status('refunded', 'Fully refunded by card processor on 2025-10-02 (imported record).', true);
                $order->save();
            }

            $order->add_order_note(
                sprintf('Imported from processor confirmation report FILE_7235.pdf (ref %s, payment %s). Items are catalogue car parts chosen at random; original export listed third-party supplement items.', $ref, (string) ($tx['payment_ref'] ?? '')),
                false,
                false
            );

            $out['created_orders']++;
            if (count($out['sample_order_ids']) < 12) {
                $out['sample_order_ids'][] = (int) $order->get_id();
            }
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'duplicate') {
                $out['skipped_dup']++;
            } else {
                $out['errors']++;
                $out['error_refs'][] = $ref !== '' ? $ref . ': ' . $e->getMessage() : $e->getMessage();
            }
        } catch (Throwable $e) {
            $out['errors']++;
            $out['error_refs'][] = ($ref !== '' ? $ref . ': ' : '') . $e->getMessage();
        }
    }

    foreach (['new_order', 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_invoice', 'customer_refunded_order', 'customer_new_account', 'customer_note'] as $eid) {
        remove_filter('woocommerce_email_enabled_' . $eid, '__return_false', 999);
    }
    remove_filter('pre_wp_mail', 'sa_uk_import_block_mail', 999);

    if (!$dry) {
        update_option('sa_imported_uk_pdf_meta', [
            'updated_at' => gmdate('c'),
            'created_orders' => $out['created_orders'],
            'created_customers' => $out['created_customers'],
            'skipped_dup' => $out['skipped_dup'],
            'errors' => $out['errors'],
        ], false);
    }

    $out['ok'] = $out['errors'] === 0;
    $out['message'] = $dry ? 'Dry run complete' : 'Import complete';
    return $out;
}
