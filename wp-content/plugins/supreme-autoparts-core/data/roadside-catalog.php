<?php
/**
 * Roadside Rescue service types. Prices: ONLY verifiable published Kenyan rates
 * (see docs/ROADSIDE-PRICE-SOURCES.md). Anything without one is quoted on the call.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

return [
    'towing' => [
        'name' => 'Towing', 'icon' => 'truck',
        'summary' => 'Flatbed tow to our Njiru garage, your mechanic or home. Saloons, SUVs, pick-ups and light trucks.',
        'what' => ['Flatbed loading, no dragging on the road', 'Tow to our garage, your mechanic or home', 'Accident and non-starter recovery'],
        'rates' => [
            'source' => 'Galaxy Towing Kenya published rate card (VAT inclusive, same day or night), checked 14 Oct 2026',
            'url' => 'https://www.galaxytowing.co.ke/towing-truck-charges-prices-galaxy-towing-service/',
            'tables' => [
                'Saloons & station wagons' => ['0–10 km' => 6000, '10–20 km' => 9000, '20–30 km' => 12000, '30–40 km' => 15000, '40–50 km' => 17000],
                'SUVs, pick-ups & light trucks (under 2 t)' => ['0–10 km' => 6500, '10–20 km' => 9500, '20–30 km' => 12500, '30–40 km' => 15500, '40–50 km' => 17500],
            ],
        ],
    ],
    'battery' => [
        'name' => 'Battery & jump-start', 'icon' => 'battery',
        'summary' => 'Car will not start? We jump-start it on the spot, test the battery and can bring a new one from our shop.',
        'what' => ['Jump-start with a booster pack', 'Battery and charging test', 'Replacement battery from our shop if needed'],
    ],
    'tyre' => [
        'name' => 'Flat tyre', 'icon' => 'tyre',
        'summary' => 'We fit your spare, or repair a simple puncture so you can drive to a tyre shop.',
        'what' => ['Spare wheel fitted', 'Simple puncture plug where safe', 'Locking wheel nut help'],
    ],
    'fuel' => [
        'name' => 'Fuel delivery', 'icon' => 'fuel',
        'summary' => 'Ran out of fuel? We bring enough petrol or diesel to get you to the nearest station.',
        'what' => ['Petrol or diesel brought to you', 'Fuel charged at the pump price', 'Help to restart the engine'],
    ],
    'lockout' => [
        'name' => 'Lockout', 'icon' => 'key',
        'summary' => 'Keys locked inside? We open the car without damage. Proof of ownership is required.',
        'what' => ['Non-destructive entry', 'Proof of ownership checked on site', 'Lost-key advice'],
    ],
    'winching' => [
        'name' => 'Winching & recovery', 'icon' => 'winch',
        'summary' => 'Stuck in a ditch, mud or sand? We winch the car back to a safe, firm surface.',
        'what' => ['Ditch, mud and sand recovery', 'Off-road and slope recovery', 'Tow afterwards if needed'],
    ],
    'repair' => [
        'name' => 'Minor on-site repair', 'icon' => 'service',
        'summary' => 'A mechanic comes to you for small fixes: hoses, belts, fuses, leaks and quick diagnostics.',
        'what' => ['Quick diagnosis on the spot', 'Hoses, belts, fuses and clamps', 'Parts from our own shop'],
    ],
];
