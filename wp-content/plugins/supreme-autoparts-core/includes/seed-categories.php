<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ensure a product_cat term exists; return term_id.
 */
function sa_core_ensure_term(string $name, string $slug, int $parent = 0, string $description = ''): int
{
    $existing = get_term_by('slug', $slug, 'product_cat');
    if ($existing && !is_wp_error($existing)) {
        $tid = (int) $existing->term_id;
        // Attach orphan leaf under the intended parent so parent archives include children.
        if ($parent > 0 && (int) $existing->parent !== $parent) {
            wp_update_term($tid, 'product_cat', [
                'parent' => $parent,
            ]);
        }
        return $tid;
    }
    $result = wp_insert_term($name, 'product_cat', [
        'slug'        => $slug,
        'parent'      => $parent,
        'description' => $description,
    ]);
    if (is_wp_error($result)) {
        // Slug collision with different name — try by name
        $by_name = get_term_by('name', $name, 'product_cat');
        return $by_name && !is_wp_error($by_name) ? (int) $by_name->term_id : 0;
    }
    return (int) $result['term_id'];
}

function sa_core_slugify(string $label): string
{
    $slug = strtolower($label);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
    return trim($slug, '-');
}

/**
 * Seed homepage IA categories + megamenu children.
 */
function sa_core_seed_categories(): void
{
    if (!taxonomy_exists('product_cat')) {
        return;
    }

    $regions = [
        ['American', 'american'],
        ['European', 'european'],
        ['Asian', 'asian'],
    ];
    foreach ($regions as [$name, $slug]) {
        sa_core_ensure_term($name, $slug, 0, "Shop {$name} vehicle parts");
    }

    $types = [
        'Air Intake' => 'air-intake',
        'Brakes' => 'brakes',
        'Drivetrain' => 'drivetrain',
        'Engine' => 'engine',
        'Exhaust' => 'exhaust',
        'Exterior' => 'exterior',
        'Interior' => 'interior',
        'Lighting' => 'lighting',
        'Suspension' => 'suspension',
        'Tires' => 'tires',
        'Wheels' => 'wheels',
    ];
    $parent_ids = [];
    foreach ($types as $name => $slug) {
        $parent_ids[$slug] = sa_core_ensure_term($name, $slug);
    }

    $megamenu = [
        'brakes' => ['Big Brake Kits','Brake Pads','Brake Rotors','Brake Kits','Brake Calipers','Brake Caliper Covers','Brake Fluid','Brake Line Kits','Brake Master Cylinders'],
        'drivetrain' => ['Axles','Clutch Discs','Clutch Flywheels','Clutch Kits','Clutch Pressure Plates','Differentials','Driveshafts','Torque Converters','Transfer Cases','Transmission Coolers','Transmission Shifters'],
        'engine' => ['Air Intakes','Cooling','Engine Components','Fueling','Ignition','Forced Induction','Tuners / Programmers'],
        'exhaust' => ['Axle Back Exhaust','Cat Back Exhaust','Exhaust Tips','Headers & Manifolds','Downpipes','Muffler'],
        'exterior' => ['Armor & Protection','Bed Accessories','Body Kits','Bug Deflectors','Car Covers','Chrome Trim','Fender Flares','Grilles','Grille Guards','Hoods','Horns','Light Covers','Mirrors','Mud Flaps','Off Road Bumpers','Roof Racks','Running Boards','Spoilers','Tonneau Covers','Toppers','Truck Caps','Winches','Wipers'],
        'interior' => ['Car Organizers','Cargo Liners','Dash Stuff','Floor Mats','Gauges','Interior Parts','Pedals','Pet Travel','Seat Covers','Seats','Shift Knobs','Steering Wheels','Sun Shades'],
        'lighting' => ['Accessory Lighting','Car Bulbs','Fog Lights','Headlights','LED Lights','Off-Road Lights','Signal Lights','Tail Lights','Trailer Lights'],
        'suspension' => ['Air Suspension','Camber Kits','Coilovers','Control Arms','End Links','Leaf Springs','Leveling Kits','Lift Kits','Lowering Springs','Panhard Bars','Shocks & Struts','Strut Tower Braces','Subframe Parts','Suspension Kits','Suspension Parts','Sway Bars','Torque Arms','Torsion Bars','Traction Bars'],
    ];

    foreach ($megamenu as $parent_slug => $children) {
        $parent = $parent_ids[$parent_slug] ?? sa_core_ensure_term(ucfirst($parent_slug), $parent_slug);
        foreach ($children as $child) {
            sa_core_ensure_term($child, sa_core_slugify($child), $parent);
        }
    }

    $brands = [
        'ACT Clutch' => 'act',
        'aFe Power' => 'afe',
        'AWE' => 'awe',
        'Bilstein' => 'bilstein',
        'Bushwacker' => 'bushwacker',
        'Corsa' => 'corsa',
        'EBC Brakes' => 'ebc',
        'Fox Shocks' => 'fox',
        'Garrett' => 'garrett',
        'King Shocks' => 'king',
        'Oracle' => 'oracle',
        'Road Armor' => 'road-armor',
        'WeatherTech' => 'weathertech',
    ];
    $brands_parent = sa_core_ensure_term('Brands', 'brands');
    foreach ($brands as $name => $slug) {
        sa_core_ensure_term($name, $slug, $brands_parent);
    }


    // Seed top Shopify collections / product-type style categories for catalog parity.
    sa_core_seed_collections_from_json();

    if (function_exists('sa_core_seed_category_thumbnails')) {
        sa_core_seed_category_thumbnails();
    }

    update_option('sa_categories_seeded', time());
}

/**
 * Seed product_cat from baked all-collections-top.json (top N by products_count).
 */
function sa_core_seed_collections_from_json(int $limit = 80): void
{
    if (!taxonomy_exists('product_cat')) {
        return;
    }
    $candidates = [
        SA_CORE_DIR . 'data/all-collections-top.json',
        SA_CORE_DIR . 'data/collections.json',
        '/usr/src/supreme-data/all-collections-top.json',
    ];
    $path = '';
    foreach ($candidates as $c) {
        if (is_readable($c)) {
            $path = $c;
            break;
        }
    }
    if ($path === '') {
        return;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return;
    }
    $collections = $data['collections'] ?? null;
    if (!is_array($collections)) {
        // Legacy collections.json may use regions/types shape — skip.
        return;
    }
    $parent = sa_core_ensure_term('Collections', 'collections', 0, 'Imported Shopify collections');
    // Never reparent existing IA terms (brakes/suspension/…) under Collections —
    // that emptied /product-category/brakes/ even when leaf terms had products.
    $ia_slugs = ['air-intake','brakes','drivetrain','engine','exhaust','exterior','interior','lighting','suspension','tires','wheels','brands','american','european','asian'];
    $n = 0;
    foreach ($collections as $col) {
        if ($n >= $limit) {
            break;
        }
        if (!is_array($col)) {
            continue;
        }
        $title = trim((string) ($col['title'] ?? ''));
        $handle = sanitize_title((string) ($col['handle'] ?? $title));
        if ($title === '' || $handle === '') {
            continue;
        }
        if (in_array($handle, $ia_slugs, true)) {
            continue;
        }
        $existing = get_term_by('slug', $handle, 'product_cat');
        if ($existing && !is_wp_error($existing)) {
            // Leave alone if already under an IA parent or is itself top-level catalog.
            $p = (int) $existing->parent;
            if ($p > 0) {
                $pt = get_term($p, 'product_cat');
                if ($pt && !is_wp_error($pt) && in_array($pt->slug, $ia_slugs, true)) {
                    continue;
                }
            }
            // Already exists (possibly under Collections) — do not create duplicate; skip.
            $n++;
            continue;
        }
        sa_core_ensure_term($title, $handle, $parent > 0 ? $parent : 0);
        $n++;
    }
    update_option('sa_collections_seeded', $n);
}

/**
 * Original intro copy for top-level categories.
 * Shown in the archive header (woocommerce_archive_description) and used as the
 * meta description fallback in seo.php. Versioned so redeploys re-apply it once.
 *
 * @return array<string,string>
 */
function sa_core_category_description_map(): array
{
    return [
        'air-intake' => 'Air filters, intake kits and MAF-friendly upgrades for Japanese, American and European cars. A clean intake keeps dust out of the engine, which matters on Kenyan roads, and a good kit can sharpen throttle response. Search by part number or vehicle, or send us your make, model and year and we confirm fitment before you pay. Delivery to all 47 counties, pickup in Nairobi, and international shipping.',
        'brakes' => 'Brake pads, rotors, complete kits, calipers, lines and fluid, from daily-driver replacements to big brake kits. If you are choosing between compounds or disc sizes, message us your vehicle and driving style <a href="https://wa.me/supremeautopart">on WhatsApp</a> and we check the right setup before you order. Genuine and trusted aftermarket brands, delivered across Kenya within days, with Nairobi store pickup and shipping to the US and worldwide.',
        'drivetrain' => 'Axles, clutch kits, flywheels, differentials, driveshafts and transfer case parts for 2WD and 4WD vehicles. Drivetrain parts must match the exact gearbox and axle code, so search by part number where you can, or send your VIN and we verify fitment first. Delivery across Kenya, pickup at our Nairobi shop, and international shipping.',
        'engine' => 'Cooling, ignition, fueling and internal engine parts, plus intakes, forced induction and tuners for builds big and small. From a replacement radiator for the family car to boost for a project truck, search by part number or vehicle and we help you match the right spec. Countrywide delivery in Kenya, Nairobi pickup, and international shipping.',
        'exhaust' => 'Headers, downpipes, cat-back and axle-back systems, mufflers and tips. Whether you are fixing a leaking stock system or building for sound and flow, match the part number or tell us your make, model and engine and we confirm what fits. Delivery to every county in Kenya, store pickup in Nairobi, and international shipping.',
        'exterior' => 'Bumpers, grilles, fender flares, roof racks, tonneau covers, winches, wipers and more for work trucks, family SUVs and off-road builds. Exterior parts are vehicle-specific, so send your year, make and model if you are unsure and we check before you pay. Delivery across all 47 counties, Nairobi pickup, and international shipping.',
        'interior' => 'Floor mats, seat covers, cargo liners, organizers, gauges, steering wheels and trim pieces that handle daily use. Good interior protection pays for itself in Kenya\'s dust and mud seasons. Search by part number or vehicle, or ask us to confirm fitment. Delivery countrywide, pickup in Nairobi, and international shipping.',
        'lighting' => 'Headlights, tail lights, fog lights, bulbs, LED upgrades and off-road light bars. Better lighting is one of the cheapest safety upgrades for night driving and rural roads. Match your bulb code or vehicle, or send us a photo of your current unit and we identify the right replacement. Delivery across Kenya, Nairobi pickup, and international shipping.',
        'suspension' => 'Shocks, struts, coilovers, lift and leveling kits, control arms, bushings and sway bars. From restoring a tired daily driver to setting up for rough roads and overlanding, suspension choices depend on load and terrain, so tell us how you use the vehicle and we help you choose. Delivery to all 47 counties, Nairobi pickup, and international shipping.',
        'tires' => 'Tires for highway, all-terrain and mud use, from commuter sizes to 4x4 fitments. Your size is printed on the sidewall (for example 265/70R16) - search it directly, or send us a photo and your vehicle details and we match options. Fitting advice included. Delivery across Kenya, pickup in Nairobi, and international shipping.',
        'wheels' => 'Steel and alloy wheels in the bolt patterns, offsets and diameters Kenyan and US vehicles actually run. Getting offset and load rating right matters more than looks, so send your vehicle and current wheel size and we confirm fitment before you order. Delivery countrywide, store pickup in Nairobi, and international shipping.',
        'american' => 'Parts for American vehicles: Ford, Chevrolet, GMC, Jeep, Dodge, RAM and more. Trucks and muscle cars included - search by part number or vehicle, or send your VIN and we confirm the exact variant. Delivery across Kenya, pickup in Nairobi, and fast US domestic shipping.',
        'european' => 'Parts for European vehicles: Volkswagen, BMW, Mercedes-Benz, Audi, Land Rover and others. European models often have several part variants per year, so send your VIN for a guaranteed match. Delivery to all 47 Kenyan counties, Nairobi pickup, and international shipping.',
        'asian' => 'Parts for Japanese and Asian vehicles: Toyota, Nissan, Honda, Mazda, Subaru, Mitsubishi, Isuzu and more - the heart of the Kenyan fleet. Search by part number or vehicle, or send your chassis number and we verify fitment. Delivery countrywide, pickup in Nairobi, and international shipping.',
    ];
}

add_action('init', static function (): void {
    if (!taxonomy_exists('product_cat')) {
        return;
    }
    $ver = '1';
    if (get_option('sa_cat_desc_ver', '') === $ver) {
        return;
    }
    foreach (sa_core_category_description_map() as $slug => $desc) {
        $term = get_term_by('slug', $slug, 'product_cat');
        if ($term && !is_wp_error($term)) {
            wp_update_term((int) $term->term_id, 'product_cat', ['description' => $desc]);
        }
    }
    update_option('sa_cat_desc_ver', $ver, false);
}, 30);
