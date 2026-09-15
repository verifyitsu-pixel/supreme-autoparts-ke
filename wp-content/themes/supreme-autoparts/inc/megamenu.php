<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deep megamenu IA mirroring supreme-mods.com.
 *
 * @return array<string, array{label:string,slug:string,columns:array<int,string>}>
 */
function sa_megamenu_tree(): array
{
    return [
        'brakes' => [
            'label'   => 'Brakes',
            'slug'    => 'brakes',
            'columns' => [
                'Big Brake Kits', 'Brake Pads', 'Brake Rotors', 'Brake Kits',
                'Brake Calipers', 'Brake Caliper Covers', 'Brake Fluid',
                'Brake Line Kits', 'Brake Master Cylinders',
            ],
        ],
        'drivetrain' => [
            'label'   => 'Drivetrain',
            'slug'    => 'drivetrain',
            'columns' => [
                'Axles', 'Clutch Discs', 'Clutch Flywheels', 'Clutch Kits',
                'Clutch Pressure Plates', 'Differentials', 'Driveshafts',
                'Torque Converters', 'Transfer Cases', 'Transmission Coolers',
                'Transmission Shifters',
            ],
        ],
        'engine' => [
            'label'   => 'Engine',
            'slug'    => 'engine',
            'columns' => [
                'Air Intakes', 'Cooling', 'Engine Components', 'Fueling',
                'Ignition', 'Forced Induction', 'Tuners / Programmers',
            ],
        ],
        'exhaust' => [
            'label'   => 'Exhaust',
            'slug'    => 'exhaust',
            'columns' => [
                'Axle Back Exhaust', 'Cat Back Exhaust', 'Exhaust Tips',
                'Headers & Manifolds', 'Downpipes', 'Muffler',
            ],
        ],
        'exterior' => [
            'label'   => 'Exterior',
            'slug'    => 'exterior',
            'columns' => [
                'Armor & Protection', 'Bed Accessories', 'Body Kits', 'Bug Deflectors',
                'Car Covers', 'Chrome Trim', 'Fender Flares', 'Grilles', 'Grille Guards',
                'Hoods', 'Horns', 'Light Covers', 'Mirrors', 'Mud Flaps', 'Off Road Bumpers',
                'Roof Racks', 'Running Boards', 'Spoilers', 'Tonneau Covers', 'Toppers',
                'Truck Caps', 'Winches', 'Wipers',
            ],
        ],
        'interior' => [
            'label'   => 'Interior',
            'slug'    => 'interior',
            'columns' => [
                'Car Organizers', 'Cargo Liners', 'Dash Stuff', 'Floor Mats', 'Gauges',
                'Interior Parts', 'Pedals', 'Pet Travel', 'Seat Covers', 'Seats',
                'Shift Knobs', 'Steering Wheels', 'Sun Shades',
            ],
        ],
        'lighting' => [
            'label'   => 'Lighting',
            'slug'    => 'lighting',
            'columns' => [
                'Accessory Lighting', 'Car Bulbs', 'Fog Lights', 'Headlights',
                'LED Lights', 'Off-Road Lights', 'Signal Lights', 'Tail Lights',
                'Trailer Lights',
            ],
        ],
        'suspension' => [
            'label'   => 'Suspension',
            'slug'    => 'suspension',
            'columns' => [
                'Air Suspension', 'Camber Kits', 'Coilovers', 'Control Arms', 'End Links',
                'Leaf Springs', 'Leveling Kits', 'Lift Kits', 'Lowering Springs',
                'Panhard Bars', 'Shocks & Struts', 'Strut Tower Braces', 'Subframe Parts',
                'Suspension Kits', 'Suspension Parts', 'Sway Bars', 'Torque Arms',
                'Torsion Bars', 'Traction Bars',
            ],
        ],
    ];
}

function sa_slugify(string $label): string
{
    $slug = strtolower($label);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
    return trim($slug, '-');
}
