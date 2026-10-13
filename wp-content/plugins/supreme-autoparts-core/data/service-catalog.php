<?php
/**
 * Car service catalog. Prices are REAL published reference prices only (see docs/SERVICE-PRICE-SOURCES.md).
 * null = "Quote on request". KES prices: AutoXpress Kenya e-store (2026-10-13).
 * USD ranges: RepairPal Fair Price national estimates (updated 2026-09-03, read 2026-10-13).
 */
if (!defined('ABSPATH')) { exit; }

return [
    'oil-change' => [
        'name' => 'Oil & Filter Change', 'icon' => 'oil', 'group' => 'Maintenance', 'duration' => 45,
        'summary' => 'Drain and refill with the correct grade of engine oil, new oil filter, leak check and service-light reset.',
        'includes' => ['Correct oil grade for your engine', 'New oil filter', 'Drain plug and leak check', 'Fluid top-up check', 'Service light reset'],
        'kes' => null,
        'usd' => ['min' => 160, 'max' => 188, 'note' => 'Average US cost, parts + labour', 'src' => 'repairpal-oil'],
        'parts' => 'oil filter',
    ],
    'brake-pads' => [
        'name' => 'Brake Pad Replacement', 'icon' => 'brake', 'group' => 'Brakes', 'duration' => 90,
        'summary' => 'Replace worn brake pads (per axle), inspect discs, calipers and brake fluid, then road-test.',
        'includes' => ['Pads fitted in pairs (per axle)', 'Disc and caliper inspection', 'Brake fluid check', 'Road test'],
        'kes' => null,
        'usd' => ['min' => 335, 'max' => 394, 'note' => 'Average US cost per axle, parts + labour', 'src' => 'repairpal-brakes'],
        'parts' => 'brake pad',
    ],
    'wheel-alignment' => [
        'name' => '3D Wheel Alignment', 'icon' => 'align', 'group' => 'Tyres & Wheels', 'duration' => 60,
        'summary' => 'Computerised alignment so the car tracks straight and tyres wear evenly.',
        'includes' => ['Computerised 3D measurement', 'Toe adjustment', 'Caster / camber where adjustable', 'Before/after printout'],
        'kes' => ['options' => [['label' => 'Toe', 'price' => 2500], ['label' => 'Caster & camber', 'price' => 4000], ['label' => 'Caster & camber with shims', 'price' => 6000]], 'src' => 'autoxpress-services'],
        'usd' => ['min' => 186, 'max' => 273, 'note' => 'Average US cost', 'src' => 'repairpal-alignment'],
        'parts' => 'tie rod',
    ],
    'wheel-balancing' => [
        'name' => 'Wheel Balancing', 'icon' => 'tyre', 'group' => 'Tyres & Wheels', 'duration' => 45,
        'summary' => 'Dynamic balancing to remove steering vibration at speed. Priced per wheel by rim size.',
        'includes' => ['Dynamic balancing per wheel', 'Wheel weights', 'Tyre pressure set'],
        'kes' => ['options' => [['label' => '12"–14" rim, per wheel', 'price' => 450], ['label' => '15" rim, per wheel', 'price' => 550], ['label' => '16" rim, per wheel', 'price' => 600], ['label' => '17" rim, per wheel', 'price' => 800]], 'src' => 'autoxpress-services'],
        'usd' => null,
        'parts' => 'wheel',
    ],
    'tyre-rotation' => [
        'name' => 'Tyre Rotation', 'icon' => 'tyre', 'group' => 'Tyres & Wheels', 'duration' => 30,
        'summary' => 'Rotate tyres front-to-rear in the correct pattern for even wear, with a tread and pressure check.',
        'includes' => ['Rotation in correct pattern', 'Tread depth check', 'Pressure set', 'Wheel nuts torqued to spec'],
        'kes' => null,
        'usd' => ['min' => 49, 'max' => 72, 'note' => 'Average US cost', 'src' => 'repairpal-rotation'],
        'parts' => 'tire',
    ],
    'ac-service' => [
        'name' => 'AC Gas Refill / Recharge', 'icon' => 'ac', 'group' => 'Air Conditioning', 'duration' => 60,
        'summary' => 'Recover, vacuum and recharge the AC system with refrigerant and oil; check for leaks and cold-air output.',
        'includes' => ['Refrigerant recovery', 'Vacuum + leak test', 'Recharge with gas and oil', 'Vent temperature check'],
        'kes' => ['options' => [['label' => 'Passenger car', 'price' => 5500], ['label' => 'SUV', 'price' => 7500], ['label' => 'SUV (dual vents)', 'price' => 10000]], 'src' => 'autoxpress-services'],
        'usd' => ['min' => 285, 'max' => 369, 'note' => 'Average US cost', 'src' => 'repairpal-ac'],
        'parts' => 'ac compressor',
    ],
    'battery' => [
        'name' => 'Battery Test & Replacement', 'icon' => 'battery', 'group' => 'Electrical', 'duration' => 30,
        'summary' => 'Test battery and charging system; replace with a correctly rated battery if needed.',
        'includes' => ['Battery and alternator test', 'Correctly rated replacement', 'Terminal clean', 'Old battery recycled'],
        'kes' => null,
        'usd' => ['min' => 456, 'max' => 493, 'note' => 'Average US cost, parts + labour', 'src' => 'repairpal-battery'],
        'parts' => 'battery',
    ],
    'diagnostics' => [
        'name' => 'Electronic Diagnostics', 'icon' => 'diag', 'group' => 'Diagnostics', 'duration' => 60,
        'summary' => 'Scan all control units, read fault codes and live data, and get a written repair recommendation.',
        'includes' => ['Full OBD / ECU scan', 'Fault codes + live data', 'Written findings', 'Repair estimate'],
        'kes' => null, 'usd' => null,
        'parts' => 'sensor',
    ],
    'suspension' => [
        'name' => 'Shock Absorbers & Suspension', 'icon' => 'suspension', 'group' => 'Suspension', 'duration' => 120,
        'summary' => 'Inspect and replace shock absorbers, coil springs, bushes and links; alignment check after.',
        'includes' => ['Suspension inspection', 'Shock / spring replacement', 'Bushes and links check', 'Alignment check'],
        'kes' => null, 'usd' => null,
        'parts' => 'shock absorber',
    ],
    'full-service' => [
        'name' => 'Full Service Package', 'icon' => 'service', 'group' => 'Maintenance', 'duration' => 180,
        'summary' => 'Manufacturer-interval service: oil, filters, plugs as due, fluids and a multi-point inspection.',
        'includes' => ['Oil + oil filter', 'Air and cabin filters as due', 'Spark plugs as due', 'Fluids check', 'Multi-point inspection report'],
        'kes' => null, 'usd' => null,
        'parts' => 'air filter',
    ],
];
