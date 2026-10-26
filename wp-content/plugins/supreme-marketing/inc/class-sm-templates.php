<?php
/**
 * Email templates: master layout, block renderer and every flow/campaign copy.
 *
 * Copy rules (Supreme Autoparts):
 *  - region-aware: KE zone (Kenya/Africa) gets Nairobi shop, pickup, garage and
 *    roadside copy + KES prices; US zone gets Portland address + USD prices;
 *  - customers see a "Chat on WhatsApp" entry point, never a phone number;
 *  - help line lives in the email body; the footer is logo, business name,
 *    address, policy links and the legally required unsubscribe/preferences;
 *  - no payment-processor or courier names; prices come from the catalog as-is;
 *  - every email carries the sender's physical address and working
 *    unsubscribe + preferences links; consent is recorded.
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class SM_Templates
{
    public const INK = '#111111';
    public const MUTED = '#6B6B6B';
    public const LINE = '#E6E6E6';
    public const AMBER = '#F5A623';
    public const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    public static function logo_url(): string
    {
        return home_url('/wp-content/themes/supreme-autoparts/assets/logo-email.png');
    }

    /** All template keys, for the admin preview list. */
    public static function keys(): array
    {
        return [
            'confirm_optin', 'repermission',
            'welcome_1', 'welcome_2', 'welcome_3', 'welcome_4', 'welcome_5',
            'cart_1', 'cart_2', 'cart_3',
            'checkout_1', 'checkout_2',
            'browse_1',
            'post_1', 'post_2', 'post_3',
            'reorder_1', 'service_1',
            'winback_1', 'winback_2',
            'restock_1',
            'newsletter_1', 'new_product_1', 'cross_sell_1', 'category_1', 'offer_1',
        ];
    }

    /** Marketing templates require an active (opted-in) subscriber; the rest are service-level. */
    public static function marketing_templates(): array
    {
        return [
            'repermission', 'welcome_1', 'welcome_2', 'welcome_3', 'welcome_4', 'welcome_5',
            'browse_1', 'post_3', 'reorder_1', 'service_1', 'winback_1', 'winback_2', 'restock_1',
            'newsletter_1', 'new_product_1', 'cross_sell_1', 'category_1', 'offer_1',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Block renderers (email-safe tables, inline CSS)                     */
    /* ------------------------------------------------------------------ */

    public static function b_p(string $text, string $color = self::INK, string $size = '15px'): string
    {
        return '<p style="margin:0 0 14px 0;font-family:' . self::FONT . ';font-size:' . $size . ';line-height:1.55;color:' . $color . ';">'
            . nl2br(esc_html($text)) . '</p>';
    }

    public static function b_h(string $text): string
    {
        return '<h2 style="margin:0 0 12px 0;font-family:' . self::FONT . ';font-size:20px;line-height:1.3;color:' . self::INK . ';">'
            . esc_html($text) . '</h2>';
    }

    public static function b_bullets(array $items): string
    {
        $lis = '';
        foreach ($items as $i) {
            $lis .= '<li style="margin:0 0 8px 0;font-family:' . self::FONT . ';font-size:15px;line-height:1.5;color:' . self::INK . ';">'
                . esc_html($i) . '</li>';
        }
        return '<ul style="margin:0 0 14px 0;padding:0 0 0 20px;">' . $lis . '</ul>';
    }

    public static function b_button(string $label, string $url): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0 18px 0;"><tr><td style="background:' . self::INK . ';border-radius:6px;">'
            . '<a href="' . esc_url($url) . '" style="display:inline-block;padding:13px 28px;font-family:' . self::FONT . ';font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">'
            . esc_html($label) . '</a></td></tr></table>';
    }

    public static function b_note(string $text): string
    {
        return '<p style="margin:0 0 14px 0;padding:10px 14px;border-left:3px solid ' . self::AMBER . ';background:#FFF9EF;font-family:' . self::FONT . ';font-size:14px;line-height:1.5;color:' . self::INK . ';">'
            . esc_html($text) . '</p>';
    }

    /** Promo code callout: dashed amber box with the code large. sub shown small under it. */
    public static function b_coupon(string $code, string $title, string $sub = ''): string
    {
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 16px 0;"><tr>'
            . '<td align="center" style="border:2px dashed ' . self::AMBER . ';border-radius:10px;background:#FFF9EF;padding:16px 18px;">'
            . '<div style="font-family:' . self::FONT . ';font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:' . self::INK . ';margin:0 0 6px 0;">' . esc_html($title) . '</div>'
            . '<div style="font-family:' . self::FONT . ';font-size:24px;font-weight:800;letter-spacing:.12em;color:' . self::INK . ';">' . esc_html($code) . '</div>';
        if ($sub !== '') {
            $html .= '<div style="font-family:' . self::FONT . ';font-size:12.5px;line-height:1.5;color:' . self::MUTED . ';margin:6px 0 0 0;">' . esc_html($sub) . '</div>';
        }
        return $html . '</td></tr></table>';
    }

    /** Product grid: up to 4 products, 2 columns. Each item: id,name,url,price,image. */
    public static function b_products(array $products): string
    {
        if (!$products) {
            return '';
        }
        $cells = [];
        foreach (array_slice($products, 0, 4) as $p) {
            $img = '';
            if (!empty($p['image'])) {
                $img = '<a href="' . esc_url($p['url']) . '"><img src="' . esc_url($p['image']) . '" width="150" alt="" style="display:block;width:150px;height:150px;object-fit:contain;border:1px solid ' . self::LINE . ';border-radius:6px;margin:0 auto 8px auto;" /></a>';
            }
            $caption = trim((string) ($p['caption'] ?? ''));
            $cap_html = $caption !== ''
                ? '<span style="display:block;font-family:' . self::FONT . ';font-size:12.5px;line-height:1.45;color:' . self::MUTED . ';margin:0 0 6px 0;">' . esc_html($caption) . '</span>'
                : '';
            $cells[] = '<td style="padding:8px;vertical-align:top;width:50%;">'
                . $img
                . '<a href="' . esc_url($p['url']) . '" style="display:block;font-family:' . self::FONT . ';font-size:14px;font-weight:600;color:' . self::INK . ';text-decoration:none;line-height:1.35;margin:0 0 4px 0;">' . esc_html($p['name']) . '</a>'
                . $cap_html
                . '<span style="display:block;font-family:' . self::FONT . ';font-size:14px;color:' . self::MUTED . ';margin:0 0 8px 0;">' . esc_html($p['price']) . '</span>'
                . '<a href="' . esc_url($p['url']) . '" style="display:inline-block;padding:8px 14px;background:' . self::AMBER . ';color:' . self::INK . ';font-family:' . self::FONT . ';font-size:13px;font-weight:600;text-decoration:none;border-radius:5px;">View product</a>'
                . '</td>';
        }
        $rows = '';
        for ($i = 0; $i < count($cells); $i += 2) {
            $rows .= '<tr>' . $cells[$i] . ($cells[$i + 1] ?? '<td style="width:50%;"></td>') . '</tr>';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 10px 0;">' . $rows . '</table>';
    }

    /** Cart contents list. items: name, qty, price, url, image. */
    public static function b_cart(array $items, string $total): string
    {
        $rows = '';
        foreach ($items as $it) {
            $img = trim((string) ($it['image'] ?? ''));
            $url = trim((string) ($it['url'] ?? ''));
            $name_html = esc_html((string) $it['name']) . ' <span style="color:' . self::MUTED . ';">&times; ' . (int) $it['qty'] . '</span>';
            if ($url !== '') {
                $name_html = '<a href="' . esc_url($url) . '" style="color:' . self::INK . ';text-decoration:none;">' . $name_html . '</a>';
            }
            $img_html = '';
            if ($img !== '') {
                $img_html = '<td width="64" style="padding:8px 10px 8px 0;border-bottom:1px solid ' . self::LINE . ';">'
                    . ($url !== '' ? '<a href="' . esc_url($url) . '">' : '')
                    . '<img src="' . esc_url($img) . '" width="56" height="56" alt="" style="display:block;width:56px;height:56px;object-fit:cover;border-radius:8px;background:#F4F4F5;">'
                    . ($url !== '' ? '</a>' : '')
                    . '</td>';
            }
            $rows .= '<tr>'
                . $img_html
                . '<td style="padding:8px 0;border-bottom:1px solid ' . self::LINE . ';font-family:' . self::FONT . ';font-size:14px;color:' . self::INK . ';">'
                . $name_html . '</td>'
                . '<td align="right" style="padding:8px 0;border-bottom:1px solid ' . self::LINE . ';font-family:' . self::FONT . ';font-size:14px;color:' . self::INK . ';white-space:nowrap;">' . esc_html((string) $it['price']) . '</td>'
                . '</tr>';
        }
        $rows .= '<tr><td style="padding:10px 0;font-family:' . self::FONT . ';font-size:15px;font-weight:700;color:' . self::INK . ';">Cart total</td>'
            . '<td align="right" style="padding:10px 0;font-family:' . self::FONT . ';font-size:15px;font-weight:700;color:' . self::INK . ';">' . esc_html($total) . '</td></tr>';
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 12px 0;">' . $rows . '</table>';
    }

    /** Standard help line, lives in the body (never the footer) per store rules. */
    public static function help_line(string $zone): string
    {
        return 'Questions? <a href="' . esc_url(sm_whatsapp_url()) . '" style="color:' . self::INK . ';text-decoration:underline;font-weight:600;">Chat on WhatsApp</a> or reply to this email - a real person answers.';
    }

    public static function b_help(string $zone): string
    {
        return '<p style="margin:18px 0 0 0;padding-top:14px;border-top:1px solid ' . self::LINE . ';font-family:' . self::FONT . ';font-size:13px;line-height:1.5;color:' . self::MUTED . ';">'
            . self::help_line($zone) . '</p>';
    }

    /* ------------------------------------------------------------------ */
    /* Master layout                                                       */
    /* ------------------------------------------------------------------ */

    public static function layout(string $content_html, array $ctx): string
    {
        $email = (string) ($ctx['email'] ?? '');
        $zone = (string) ($ctx['zone'] ?? 'US');
        $unsub = sm_unsubscribe_url($email);
        $prefs = sm_prefs_url($email);
        $biz = sm_business($zone);
        $logo = self::logo_url();
        $home = home_url('/');
        $preheader = esc_html((string) ($ctx['preheader'] ?? ''));
        $link = 'color:' . self::MUTED . ';text-decoration:underline;';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html((string) ($ctx['subject'] ?? '')) . '</title>'
            . '<style>@media (prefers-color-scheme: dark){body,.sm-bg{background:#0d0d0d !important}.sm-card{background:#161616 !important;border-color:#2a2a2a !important}'
            . '.sm-ft{background:#101010 !important}.sm-ft,.sm-ft p,.sm-ft div{color:#a3a3a3 !important}.sm-ft a{color:#d4d4d4 !important}}</style></head>'
            . '<body style="margin:0;padding:0;background:#F4F4F5;">'
            . '<div style="display:none;max-height:0;overflow:hidden;">' . $preheader . '&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="sm-bg" style="background:#F4F4F5;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" class="sm-card" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid ' . self::LINE . ';">'
            // header
            . '<tr><td style="padding:20px 28px;border-bottom:1px solid ' . self::LINE . ';">'
            . '<a href="' . esc_url($home) . '"><img src="' . esc_url($logo) . '" width="150" alt="Supreme Autoparts" style="display:block;width:150px;height:auto;" /></a>'
            . '</td></tr>'
            // body (help line appended here, in the body, never the footer)
            . '<tr><td style="padding:28px;">' . $content_html . self::b_help($zone) . '</td></tr>'
            // footer: logo + business name, address, policy links, unsubscribe/preferences
            . '<tr><td class="sm-ft" style="padding:20px 28px;background:#FAFAFA;border-top:1px solid ' . self::LINE . ';">'
            . '<p style="margin:0 0 8px 0;font-family:' . self::FONT . ';font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:' . self::INK . ';">Supreme Autoparts</p>'
            . '<p style="margin:0 0 10px 0;font-family:' . self::FONT . ';font-size:11.5px;line-height:1.5;color:' . self::MUTED . ';">'
            . '<a href="' . esc_url('https://www.google.com/maps/search/?api=1&query=' . rawurlencode((string) $biz['one_line'])) . '" style="color:' . self::MUTED . ';text-decoration:none;">' . esc_html((string) $biz['one_line']) . '</a></p>'
            . '<p style="margin:0 0 10px 0;font-family:' . self::FONT . ';font-size:11.5px;line-height:1.5;color:' . self::MUTED . ';">'
            . '<a href="' . esc_url(home_url('/privacy-policy/')) . '" style="' . $link . '">Privacy</a> &middot; '
            . '<a href="' . esc_url(home_url('/terms/')) . '" style="' . $link . '">Terms</a> &middot; '
            . '<a href="' . esc_url(home_url('/returns/')) . '" style="' . $link . '">Returns</a></p>'
            . '<p style="margin:0;font-family:' . self::FONT . ';font-size:11.5px;line-height:1.5;color:' . self::MUTED . ';">'
            . 'You received this email because you shopped at or subscribed on supremeautoparts.co.ke. '
            . '<a href="' . esc_url($unsub) . '" style="' . $link . '">Unsubscribe</a> &middot; '
            . '<a href="' . esc_url($prefs) . '" style="' . $link . '">Email preferences</a>'
            . '</p></td></tr>'
            . '</table></td></tr></table>'
            // Opt out of the store mail wrappers: our layout already carries the brand header
            // and a zone-correct footer. These marker strings are what sa-brevo-mail /
            // supreme-autoparts-core check before prepending their header / appending their footer.
            . '<!--sa-email-brand-header--><!--sa-email-footer--><!--/sa-email-footer-->'
            . '</body></html>';
    }

    public static function layout_text(string $text, array $ctx): string
    {
        $email = (string) ($ctx['email'] ?? '');
        $zone = (string) ($ctx['zone'] ?? 'US');
        $biz = sm_business($zone);
        return $text . "\n\nQuestions? Chat on WhatsApp: " . sm_whatsapp_url() . " or reply to this email.\n\n---\nSupreme Autoparts\n"
            . (string) $biz['one_line'] . "\n"
            . 'Unsubscribe: ' . sm_unsubscribe_url($email) . "\n"
            . 'Preferences: ' . sm_prefs_url($email) . "\n";
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                           */
    /* ------------------------------------------------------------------ */

    /** Render blocks to HTML + text. */
    public static function render_blocks(array $blocks, array $ctx): array
    {
        $html = '';
        $text = '';
        foreach ($blocks as $b) {
            if (isset($b['h'])) {
                $html .= self::b_h($b['h']);
                $text .= strtoupper($b['h']) . "\n\n";
            } elseif (isset($b['p'])) {
                $html .= self::b_p($b['p']);
                $text .= $b['p'] . "\n\n";
            } elseif (isset($b['bullets'])) {
                $html .= self::b_bullets($b['bullets']);
                foreach ($b['bullets'] as $i) {
                    $text .= ' - ' . $i . "\n";
                }
                $text .= "\n";
            } elseif (isset($b['button'])) {
                $html .= self::b_button($b['button'][0], $b['button'][1]);
                $text .= $b['button'][0] . ': ' . $b['button'][1] . "\n\n";
            } elseif (isset($b['note'])) {
                $html .= self::b_note($b['note']);
                $text .= 'Note: ' . $b['note'] . "\n\n";
            } elseif (isset($b['coupon'])) {
                $html .= self::b_coupon($b['coupon'][0], $b['coupon'][1], (string) ($b['coupon'][2] ?? ''));
                $text .= $b['coupon'][1] . ': ' . $b['coupon'][0] . "\n" . (string) ($b['coupon'][2] ?? '') . "\n\n";
            } elseif (isset($b['products'])) {
                $html .= self::b_products($b['products']);
                foreach ($b['products'] as $p) {
                    $text .= '* ' . $p['name'] . ' (' . $p['price'] . ') - ' . $p['url'] . "\n";
                }
                $text .= "\n";
            } elseif (isset($b['cart'])) {
                $html .= self::b_cart($b['cart'][0], $b['cart'][1]);
                foreach ($b['cart'][0] as $it) {
                    $text .= '* ' . $it['name'] . ' x' . (int) $it['qty'] . ' - ' . $it['price'] . "\n";
                }
                $text .= 'Cart total: ' . $b['cart'][1] . "\n\n";
            }
        }
        return ['html' => $html, 'text' => $text];
    }

    /**
     * Full render of a template key with context.
     * ctx: email, first_name, zone, plus template-specific data.
     */
    public static function render(string $key, array $ctx): ?array
    {
        $def = self::definition($key, $ctx);
        if ($def === null) {
            return null;
        }
        $greet = 'Hi ' . sm_greet_name(['first_name' => (string) ($ctx['first_name'] ?? '')]) . ',';
        $blocks = array_merge([['p' => $greet]], $def['blocks']);
        $rendered = self::render_blocks($blocks, $ctx);
        $ctx['subject'] = $def['subject'];
        $ctx['preheader'] = $def['preheader'];
        return [
            'subject'   => $def['subject'],
            'preheader' => $def['preheader'],
            'html'      => self::layout($rendered['html'], $ctx),
            'text'      => self::layout_text($rendered['text'], $ctx),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Template definitions (the copy)                                     */
    /* ------------------------------------------------------------------ */

    private static function definition(string $key, array $ctx): ?array
    {
        $shop = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
        $account = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
        $services = home_url('/services/');
        $track = home_url('/track-order/');
        $zone = (string) ($ctx['zone'] ?? 'US');
        $ke = $zone === 'KE';
        $products = is_array($ctx['products'] ?? null) ? $ctx['products'] : [];
        $cart = is_array($ctx['cart'] ?? null) ? $ctx['cart'] : null;
        $order = is_array($ctx['order'] ?? null) ? $ctx['order'] : null;
        $campaign = is_array($ctx['campaign'] ?? null) ? $ctx['campaign'] : [];
        $coupon = is_array($ctx['coupon'] ?? null) ? $ctx['coupon'] : null;
        $category = is_array($ctx['category'] ?? null) ? $ctx['category'] : null;
        $welcome_code = (string) ($ctx['welcome_code'] ?? '');
        $welcome_expires = (string) ($ctx['welcome_expires'] ?? '');

        $shipping_line = $ke
            ? 'Free delivery in Nairobi, fast countrywide delivery, or free pickup at our shop on Kangundo Rd, Njiru'
            : 'Tracked shipping across the US - free on eligible orders';

        switch ($key) {

            case 'confirm_optin':
                return [
                    'subject'   => 'Confirm your Supreme Autoparts subscription',
                    'preheader' => 'One click to confirm you want store emails from us.',
                    'blocks'    => [
                        ['h' => 'Confirm your subscription'],
                        ['p' => 'You asked to hear from Supreme Autoparts by email: new parts, restocks, offers and service reminders.'],
                        ['p' => 'Click below to confirm. If you did not request this, ignore this email and you will not be subscribed.'],
                        ['button' => ['Confirm my subscription', sm_confirm_url((string) ($ctx['email'] ?? ''))]],
                        ['p' => 'We send about one email a week. Every message includes an unsubscribe link.'],
                    ],
                ];

            case 'repermission':
                return [
                    'subject'   => 'Want Supreme Autoparts updates by email?',
                    'preheader' => 'New parts, restocks and offers - only if you want them.',
                    'blocks'    => [
                        ['h' => 'Stay in the loop, your call'],
                        ['p' => 'You have ordered from Supreme Autoparts before. We are setting up store emails: new arrivals, restock notices, offers and service reminders.'],
                        ['p' => 'We will only send them if you say yes. Click below to opt in - if you do nothing, you will not receive marketing emails from us. Your order and account emails are not affected.'],
                        ['button' => ['Yes, keep me posted', sm_confirm_url((string) ($ctx['email'] ?? ''))]],
                    ],
                ];

            case 'welcome_1':
                $blocks = [
                    ['h' => 'Welcome to Supreme Autoparts'],
                    ['p' => 'You drive, we keep you driving. Genuine and quality aftermarket parts for cars, trucks, SUVs and motorcycles - engine, suspension, brakes, exhaust, performance and more - every one checked against the catalog before it ships.'],
                ];
                if ($welcome_code !== '') {
                    $blocks[] = ['coupon' => [$welcome_code, '20% off your first order', ($welcome_expires !== '' ? 'Valid until ' . $welcome_expires . '. ' : '') . 'One use. Applies automatically at checkout when you are signed in.']];
                } else {
                    $blocks[] = ['p' => 'Your first order comes with 20% off: create an account or sign in with Google at checkout and your personal code is issued on the spot.'];
                }
                $blocks[] = ['bullets' => [
                    'Not sure a part fits? Send your year, make, model or VIN and we confirm fitment before you pay',
                    $shipping_line,
                    'Track every order on our site, from confirmed to delivered',
                ]];
                if ($products) {
                    $blocks[] = ['p' => 'Moving fast right now:'];
                    $blocks[] = ['products' => $products];
                }
                $blocks[] = ['button' => ['Shop parts', $shop]];
                return [
                    'subject'   => $welcome_code !== ''
                        ? 'Your 20% first-order code is inside - welcome to Supreme Autoparts'
                        : 'Welcome to Supreme Autoparts - 20% off your first order',
                    'preheader' => 'Genuine and aftermarket parts, fitment checked before you buy.',
                    'blocks'    => $blocks,
                ];

            case 'welcome_2':
                return [
                    'subject'   => 'Finding the right part, first time',
                    'preheader' => 'Part numbers, brands and fitment - how to get it right.',
                    'blocks'    => [
                        ['h' => 'Get the right part, first time'],
                        ['p' => 'The fastest way to the exact part:'],
                        ['bullets' => [
                            'Have the part or OEM number? Search it directly - results match your vehicle',
                            'Browse by category and brand - every category opens its own brand tiles',
                            'Not sure? Send us your year, make, model or VIN and we confirm fitment before you pay',
                            'Save your cars in My Garage and the store flags parts that fit them',
                        ]],
                        ['p' => 'A wrong part costs you days. A quick fitment check costs you nothing.'],
                        ['button' => ['Start browsing', $shop]],
                    ],
                ];

            case 'welcome_3':
                if ($ke) {
                    return [
                        'subject'   => 'More than parts: servicing and roadside rescue',
                        'preheader' => 'Our Nairobi garage fits what we sell - and rescues you when stuck.',
                        'blocks'    => [
                            ['h' => 'The garage behind the store'],
                            ['p' => 'Supreme Autoparts is a working garage in Njiru, Nairobi. When you buy parts from us, we can fit them - and when your car needs more than a part, we service it.'],
                            ['bullets' => [
                                'Car servicing and repairs at our garage on Kangundo Rd, Njiru - book a slot online',
                                'Parts you buy with fitting stay at the garage and go straight on your car',
                                'Roadside Rescue across Nairobi when you are stuck - one tap from the site',
                            ]],
                            ['button' => ['Book a service', $services]],
                        ],
                    ];
                }
                return [
                    'subject'   => 'Genuine vs aftermarket - how we stock both',
                    'preheader' => 'What the difference is and when each makes sense.',
                    'blocks'    => [
                        ['h' => 'Genuine vs aftermarket'],
                        ['p' => 'We sell both, and we label them clearly so you always know what you are buying:'],
                        ['bullets' => [
                            'Genuine (OEM): the exact part your car left the factory with',
                            'Quality aftermarket: same fit and function from established brands, usually for less',
                            'Performance: upgrades for power, braking and handling',
                        ]],
                        ['p' => 'Not sure which way to go for your car and budget? Send us your vehicle details and we will tell you straight.'],
                        ['button' => ['Browse the catalog', $shop]],
                    ],
                ];

            case 'welcome_4':
                $blocks = [
                    ['h' => 'The quick tour'],
                    ['p' => 'The store is organized by category and brand - Tyres, Engine, Suspension, Brakes, Motorcycle and more - with a brand picker inside every category.'],
                    ['bullets' => [
                        'Save up to three vehicles in My Garage and see "Fits your car" badges',
                        'Prices show in your local currency automatically',
                        $shipping_line,
                        'Track every order on our site, from confirmed to delivered',
                    ]],
                ];
                if ($products) {
                    $blocks[] = ['p' => 'Our best sellers - all in stock:'];
                    $blocks[] = ['products' => $products];
                } else {
                    $blocks[] = ['p' => 'Take a look at the current best sellers in the store.'];
                }
                $blocks[] = ['button' => ['Open the store', $shop]];
                return [
                    'subject'   => 'The quick tour: categories, My Garage, tracking',
                    'preheader' => 'How the store is organized and how orders arrive.',
                    'blocks'    => $blocks,
                ];

            case 'welcome_5':
                $grid_themes = [];
                foreach ((array) $products as $gp) {
                    $th = (string) ($gp['theme'] ?? '');
                    if ($th !== '' && !in_array($th, $grid_themes, true)) {
                        $grid_themes[] = $th;
                    }
                }
                $theme_line = implode(' and ', array_slice($grid_themes, 0, 2));

                $blocks = [
                    ['h' => ($theme_line !== '' ? 'For your ' . $theme_line . ' work' : 'Ready when you are')],
                    ['p' => ($theme_line !== ''
                        ? 'A look at the ' . $theme_line . ' side of the catalog - all in stock, and your 20% first-order code is below.'
                        : 'You have seen how we stock, check fitment and deliver. Whenever your car needs something, the store is organized to get you to the right part fast.')],
                ];
                if ($welcome_code !== '') {
                    $blocks[] = ['coupon' => [$welcome_code, 'Your 20% first-order discount', ($welcome_expires !== '' ? 'Valid until ' . $welcome_expires . '. ' : '') . 'One use. Applies automatically at checkout when you are signed in.']];
                } else {
                    $blocks[] = ['p' => 'Remember: sign up or sign in with Google at checkout and your first order gets 20% off.'];
                }
                if ($products) {
                    $blocks[] = ['p' => 'In-stock picks from the catalog:'];
                    $blocks[] = ['products' => $products];
                } else {
                    $blocks[] = ['p' => 'Start with the in-stock picks - the catalog is ready when you are.'];
                }
                $blocks[] = ['button' => [($theme_line !== '' ? 'Shop ' . $theme_line . ' parts' : 'Go to the store'), $shop]];
                $blocks[] = ['p' => 'This is the last email in your welcome series. From here you will hear from us when there is something worth sharing.'];
                return [
                    'subject'   => ($theme_line !== '' ? 'Your 20% first-order code, plus ' . $theme_line . ' picks' : 'Your first order, when you are ready'),
                    'preheader' => 'Everything is set - the parts are waiting.',
                    'blocks'    => $blocks,
                ];

            case 'cart_1':
                return [
                    'subject'   => 'You left something in your cart',
                    'preheader' => 'Your parts are still set aside.',
                    'blocks'    => [
                        ['h' => 'Your cart is waiting'],
                        ['p' => 'You added these parts and stepped away before checkout. They are still in your cart:'],
                        $cart ? ['cart' => [$cart['items'], $cart['total']]] : ['p' => 'Your selected parts are saved in your cart.'],
                        ['button' => ['Return to my cart', (string) ($ctx['cart_url'] ?? home_url('/cart/'))]],
                        ['p' => 'Not 100% sure a part fits? Reply with your year, make, model or VIN and we will confirm before you pay.'],
                    ],
                ];

            case 'cart_2':
                return [
                    'subject'   => 'Still thinking it over? Your cart is saved',
                    'preheader' => 'Fitment help, returns and your saved cart.',
                    'blocks'    => [
                        ['h' => 'A few things worth knowing'],
                        ['p' => 'Your cart is still saved. While you decide:'],
                        ['bullets' => [
                            'We check fitment on request before you pay - send your vehicle details',
                            '15-day returns if a part is not right',
                            $shipping_line,
                        ]],
                        $cart ? ['cart' => [$cart['items'], $cart['total']]] : ['p' => 'Your selected parts are saved in your cart.'],
                        ['button' => ['Complete my order', (string) ($ctx['cart_url'] ?? home_url('/cart/'))]],
                    ],
                ];

            case 'cart_3':
                return [
                    'subject'   => 'Last reminder about your cart',
                    'preheader' => 'We will stop reminding you after this one.',
                    'blocks'    => [
                        ['h' => 'Final reminder'],
                        ['p' => 'This is the last note about the parts in your cart - stock on popular parts moves, and we cannot hold items indefinitely.'],
                        $cart ? ['cart' => [$cart['items'], $cart['total']]] : ['p' => 'Your selected parts are saved in your cart.'],
                        ['button' => ['Check out now', (string) ($ctx['cart_url'] ?? home_url('/cart/'))]],
                        ['p' => 'If you decided not to order, no action needed - you will not hear about this cart again.'],
                    ],
                ];

            case 'checkout_1':
                return [
                    'subject'   => 'Your order is one step from done',
                    'preheader' => 'You started checkout - finish when you are ready.',
                    'blocks'    => [
                        ['h' => 'Almost there'],
                        ['p' => 'You started checkout on supremeautoparts.co.ke and stopped before payment. Everything you entered is saved - picking up where you left off takes under a minute.'],
                        $cart ? ['cart' => [$cart['items'], $cart['total']]] : ['p' => 'Your selected parts are saved at checkout.'],
                        ['button' => ['Finish checkout', (string) ($ctx['cart_url'] ?? home_url('/checkout/'))]],
                        ['p' => $ke
                            ? 'Payment trouble or a question about delivery or pickup? Reply and we will sort it out.'
                            : 'Payment trouble or a question about shipping? Reply and we will sort it out.'],
                    ],
                ];

            case 'checkout_2':
                return [
                    'subject'   => 'Need a hand completing your order?',
                    'preheader' => 'We can help with payment, fitment or delivery questions.',
                    'blocks'    => [
                        ['h' => 'We can help'],
                        ['p' => 'Your checkout is still open. If something got in the way - a payment step, a delivery question, a fitment doubt - tell us and we will fix it with you.'],
                        $cart ? ['cart' => [$cart['items'], $cart['total']]] : ['p' => 'Your selected parts are saved at checkout.'],
                        ['button' => ['Return to checkout', (string) ($ctx['cart_url'] ?? home_url('/checkout/'))]],
                        ['p' => 'You can also reply to this email and a real person will answer.'],
                    ],
                ];

            case 'browse_1':
                return [
                    'subject'   => 'Still looking? Here is what you viewed',
                    'preheader' => 'The parts you checked out, plus fitment help if you want it.',
                    'blocks'    => [
                        ['h' => 'You were looking at these'],
                        ['p' => 'You recently viewed these parts. Each one is in stock and ready to ship:'],
                        ['products' => $products],
                        ['button' => ['Back to the store', $shop]],
                        ['p' => 'Want us to confirm fitment for any of these before you decide? Send your year, make, model or VIN.'],
                    ],
                ];

            case 'post_1':
                $blocks = [
                    ['h' => $ke ? 'Delivery and fitting notes' : 'Delivery notes'],
                    ['p' => ($order ? 'Order ' . $order['number'] . ' is on its way. ' : 'Your order is on its way. ') . 'You can follow it step by step on our tracking page.'],
                ];
                if ($ke) {
                    $blocks[] = ['p' => 'Bought parts that need fitting? Our garage in Njiru can install them - book a slot online and bring the parts, or have them wait for you at the garage.'];
                } else {
                    $blocks[] = ['bullets' => [
                        'Check the part against your old one before installation',
                        'Keep the packaging until the part is fitted and working',
                        'Any issue, reply with your order number and a photo',
                    ]];
                }
                $blocks[] = ['button' => ['Track my order', (string) ($order['url'] ?? $track)]];
                return [
                    'subject'   => 'Your order is on its way - what to know',
                    'preheader' => 'Track it on our site, and fitting help if you need it.',
                    'blocks'    => $blocks,
                ];

            case 'post_2':
                return [
                    'subject'   => 'Everything OK with your order?',
                    'preheader' => 'A quick check-in from Supreme Autoparts.',
                    'blocks'    => [
                        ['h' => 'Checking in'],
                        ['p' => 'Your recent order should have arrived by now. If anything was damaged, missing or not what you expected, tell us and we will make it right.'],
                        ['p' => 'And if you need help with fitment or installation, we are one reply away.'],
                        ['button' => ['Review my order', (string) ($order['url'] ?? $account)]],
                    ],
                ];

            case 'post_3':
                return [
                    'subject'   => 'How did we do? Two lines is enough',
                    'preheader' => 'Your feedback shapes what we stock next.',
                    'blocks'    => [
                        ['h' => 'Two lines, honestly'],
                        ['p' => 'You ordered from Supreme Autoparts recently. How was the experience - the part, the fit, the delivery speed?'],
                        ['p' => 'A short reply to this email is all it takes. It helps us fix what is broken and stock more of what drivers actually need.'],
                        ['button' => ['Leave a review', (string) ($ctx['review_url'] ?? $account)]],
                    ],
                ];

            case 'reorder_1':
                $pname = (string) ($ctx['product_name'] ?? 'your part');
                $blocks = [
                    ['h' => 'Due for a replacement?'],
                    ['p' => 'Based on when you ordered ' . $pname . ', it may be nearing the end of its service life. Replacing wear parts on time is cheaper than the damage a worn one causes.'],
                ];
                if ($products) {
                    $blocks[] = ['p' => 'In stock for the next replacement:'];
                    $blocks[] = ['products' => $products];
                } else {
                    $blocks[] = ['p' => 'Your previous items are ready to order again from your account.'];
                }
                $blocks[] = ['button' => ['Order again', (string) ($ctx['reorder_url'] ?? $account)]];
                return [
                    'subject'   => 'Time to replace ' . $pname . '?',
                    'preheader' => 'Based on when you last ordered, it may be due for a fresh one.',
                    'blocks'    => $blocks,
                ];

            case 'service_1':
                $car = trim((string) ($ctx['car'] ?? ''));
                return [
                    'subject'   => 'Time for your next service' . ($car !== '' ? ', ' . $car . ' owner' : '') . '?',
                    'preheader' => 'Regular servicing is cheaper than repairs. Book your slot online.',
                    'blocks'    => [
                        ['h' => 'Your next service is coming up'],
                        ['p' => 'It has been a while since we serviced your ' . ($car !== '' ? $car : 'car') . ' at the garage. Sticking to the service schedule keeps small issues small - oil, filters, brakes and the safety checks around them.'],
                        ['bullets' => [
                            'Book a slot online - Mon to Sat, confirmed once your booking fee is paid',
                            'Need parts for the service? Order them from the store and we fit them on the day',
                            'Stuck somewhere in Nairobi? Roadside Rescue is one tap away',
                        ]],
                        ['button' => ['Book my service', $services]],
                    ],
                ];

            case 'rotation_1':
                $rot_label = trim((string) ($ctx['rotation_theme_label'] ?? ''));
                $rot_fams  = array_values(array_filter(array_map('strval', (array) ($ctx['rotation_families'] ?? []))));
                $multi     = count($rot_fams) > 1; // grid spans several product families: never describe it as one shelf
                $ai        = is_array($ctx['rotation_ai'] ?? null) ? $ctx['rotation_ai'] : [];
                $headline  = trim((string) ($ai['headline'] ?? ''));
                if ($headline === '') {
                    $headline = $multi
                        ? 'A cross-section of the store'
                        : ($rot_label !== '' ? 'The ' . $rot_label . ' shelf' : 'The catalog, one shelf at a time');
                }
                $intro = trim((string) ($ai['intro'] ?? ''));
                if ($intro === '') {
                    $intro = $multi
                        ? 'A mix across ' . count($rot_fams) . ' product families - ' . implode(', ', $rot_fams) . '. All in stock, priced as listed.'
                        : ($rot_label !== ''
                            ? 'A look at the ' . $rot_label . ' side of the catalog - all in stock, priced as listed.'
                            : 'All in stock, priced as listed.');
                }
                $blocks = [
                    ['h' => $headline],
                    ['p' => $intro],
                ];
                if ($products) {
                    $blocks[] = ['p' => ($multi
                        ? 'In-stock picks across those families:'
                        : ($rot_label !== '' ? 'In-stock picks from the ' . $rot_label . ' shelf:' : 'In-stock picks from the catalog:'))];
                    $blocks[] = ['products' => $products];
                } else {
                    $blocks[] = ['p' => 'Nothing fresh on this shelf right now - the catalog is ready when you are.'];
                }
                $blocks[] = ['button' => [($multi || $rot_label === '' ? 'Browse the catalog' : 'Shop ' . $rot_label), $shop]];
                $subject = trim((string) ($ai['subject'] ?? ''));
                if ($subject === '') {
                    $subject = $multi
                        ? count($rot_fams) . ' product families - in stock at Supreme Autoparts'
                        : ($rot_label !== '' ? $rot_label . ' - in stock at Supreme Autoparts' : 'In stock at Supreme Autoparts');
                }
                return [
                    'subject'   => $subject,
                    'preheader' => 'All in stock, priced as listed.',
                    'blocks'    => $blocks,
                ];

            case 'winback_1':
                $grid_themes = [];
                foreach ((array) $products as $gp) {
                    $th = (string) ($gp['theme'] ?? '');
                    if ($th !== '' && !in_array($th, $grid_themes, true)) {
                        $grid_themes[] = $th;
                    }
                }
                $theme_line = implode(' and ', array_slice($grid_themes, 0, 2));
                $blocks = [
                    ['h' => ($theme_line !== '' ? 'Parts for the next job?' : 'Still there?')],
                    ['p' => 'It has been a while since your last order. Same fitment checks, same delivery, whenever your car needs something.'],
                ];
                if ($products) {
                    $blocks[] = ['p' => SM_Recommend::purchased_product_ids((string) ($ctx['email'] ?? '')) ? 'Recommended from your past orders:' : ($theme_line !== '' ? 'Picked from the ' . $theme_line . ' shelf - all in stock:' : 'Fresh picks from the shop:')];
                    $blocks[] = ['products' => $products];
                } else {
                    $blocks[] = ['p' => 'Browse the catalog whenever you are ready.'];
                }
                $blocks[] = ['button' => ['Browse the catalog', $shop]];
                return [
                    'subject'   => ($theme_line !== '' ? 'Back for ' . $theme_line . ' parts? We have not seen you in a while' : 'We have not seen you in a while'),
                    'preheader' => 'New parts and restocks since your last visit.',
                    'blocks'    => $blocks,
                ];

            case 'winback_2':
                $blocks = [
                    ['h' => 'A fair question'],
                    ['p' => 'You have not ordered in a while, and we do not want to fill an inbox that is not interested. If you still want new-arrival and restock emails, one click keeps them coming.'],
                    ['button' => ['Keep me on the list', sm_prefs_url((string) ($ctx['email'] ?? ''))]],
                    ['p' => 'If we do not hear from you, we will scale these back. You can unsubscribe anytime with the link below.'],
                ];
                if ($products) {
                    $blocks[] = ['p' => SM_Recommend::purchased_product_ids((string) ($ctx['email'] ?? '')) ? 'Recommended from your past orders - all in stock:' : 'Fresh picks from the shop - all in stock:'];
                    $blocks[] = ['products' => $products];
                } else {
                    $blocks[] = ['p' => 'Meanwhile, the best sellers are one click away.'];
                }
                return [
                    'subject'   => 'Should we keep sending these?',
                    'preheader' => 'One click to stay on the list - or do nothing and we will slow down.',
                    'blocks'    => $blocks,
                ];

            case 'restock_1':
                $pname = (string) ($ctx['product_name'] ?? 'A part you bought');
                return [
                    'subject'   => $pname . ' is back in stock',
                    'preheader' => 'Fresh stock, ready to ship.',
                    'blocks'    => [
                        ['h' => 'Back in stock'],
                        ['p' => $pname . ' - which you ordered or viewed before - is back in stock and ready to ship.'],
                        $products ? ['products' => $products] : ['p' => 'See it in the store while stock lasts.'],
                        ['button' => ['View product', (string) ($ctx['product_url'] ?? $shop)]],
                        ['p' => 'Restocks on popular parts move quickly.'],
                    ],
                ];

            case 'newsletter_1':
                return [
                    'subject'   => (string) ($campaign['subject'] ?? 'Supreme Autoparts update'),
                    'preheader' => (string) ($campaign['heading'] ?? 'News from the store.'),
                    'blocks'    => [
                        ['h' => (string) ($campaign['heading'] ?? 'Store update')],
                        ['p' => (string) ($campaign['intro'] ?? '')],
                        $products ? ['products' => $products] : ['p' => 'See the full catalog for current stock.'],
                        ['button' => ['Visit the store', $shop]],
                    ],
                ];

            case 'new_product_1':
                return [
                    'subject'   => (string) ($campaign['subject'] ?? 'New in the store'),
                    'preheader' => 'Just listed and in stock.',
                    'blocks'    => [
                        ['h' => (string) ($campaign['heading'] ?? 'Just listed')],
                        ['p' => (string) ($campaign['intro'] ?? 'New in the Supreme Autoparts store. Like everything we stock, fitment can be confirmed for your vehicle before you buy.')],
                        ['products' => $products],
                        ['button' => ['See what is new', $shop]],
                    ],
                ];

            case 'category_1':
                $catname = (string) ($category['name'] ?? 'Top picks');
                $caturl = (string) ($category['url'] ?? $shop);
                return [
                    'subject'   => (string) ($campaign['subject'] ?? ($catname . ' at Supreme Autoparts')),
                    'preheader' => (string) ($campaign['heading'] ?? ('The ' . $catname . ' range, in stock now.')),
                    'blocks'    => [
                        ['h' => (string) ($campaign['heading'] ?? $catname)],
                        ['p' => (string) ($campaign['intro'] ?? ('A look at our ' . $catname . ' range - in stock and ready to ship, with fitment checks on request.'))],
                        ['products' => $products],
                        ['button' => ['Browse ' . $catname, $caturl]],
                    ],
                ];

            case 'offer_1':
                $blocks = [
                    ['h' => (string) ($campaign['heading'] ?? 'This week\'s offer')],
                    ['p' => (string) ($campaign['intro'] ?? 'A limited-time offer from Supreme Autoparts, for a selected group of customers.')],
                ];
                if ($coupon) {
                    $sub = [];
                    if (!empty($coupon['expires'])) {
                        $sub[] = 'Valid until ' . $coupon['expires'];
                    }
                    $sub[] = 'One use per customer';
                    if (!empty($coupon['free_shipping'])) {
                        $sub[] = 'includes free shipping';
                    }
                    $blocks[] = ['coupon' => [(string) $coupon['code'], (string) ($coupon['label'] ?? 'Your offer code'), implode('. ', $sub) . '.']];
                }
                if ($products) {
                    $blocks[] = ['products' => $products];
                }
                $blocks[] = ['button' => ['Shop the offer', $category ? (string) ($category['url'] ?? $shop) : $shop]];
                return [
                    'subject'   => (string) ($campaign['subject'] ?? 'An offer for you from Supreme Autoparts'),
                    'preheader' => (string) ($campaign['heading'] ?? 'Limited time, selected customers.'),
                    'blocks'    => $blocks,
                ];

            case 'cross_sell_1':
                return [
                    'subject'   => (string) ($campaign['subject'] ?? 'Picked for your car'),
                    'preheader' => 'Based on your past orders.',
                    'blocks'    => [
                        ['h' => (string) ($campaign['heading'] ?? 'Picked for you')],
                        ['p' => (string) ($campaign['intro'] ?? 'Based on your past orders, these parts are the closest match to what your car already runs:')],
                        ['products' => $products],
                        ['button' => ['Browse the store', $shop]],
                        ['p' => 'Every listing can be fitment-checked for your vehicle before you buy - just ask.'],
                    ],
                ];
        }

        return null;
    }

    /** Sample context for admin previews. */
    public static function sample_context(string $key): array
    {
        $zone = isset($_GET['sm_zone']) && sanitize_key((string) $_GET['sm_zone']) === 'ke' ? 'KE' : 'US';
        $products = [];
        if (function_exists('wc_get_products')) {
            $items = sm_marketing_eligible(SM_Recommend::best_sellers(60)); // owner-banned items never featured
            $items = sm_marketing_pool_backfill($items, 4, 60); // deep performance pool
            $items = sm_diverse_products($items, 4);
sm_reset_caption_dedup();
                            foreach ($items as $p) {
                $products[] = sm_product_payload($p, $zone);
            }
        }
        $rot_label = '';
        if ($key === 'rotation_1' && function_exists('sm_rotation_pick')) {
            $rot = sm_rotation_pick('', 4); // preview: no recipient, no reservations
            if ($rot['products']) {
                $products = [];
                sm_reset_caption_dedup();
                foreach ($rot['products'] as $p) {
                    $products[] = sm_product_payload($p, $zone);
                }
            }
            $rot_label = (string) ($rot['theme_label'] ?? '');
            $rot_fams  = (array) ($rot['families'] ?? []);
        }
        if (!$products) {
            $products = [
                ['id' => 0, 'name' => 'Brake Pad Set - Toyota Corolla', 'url' => home_url('/shop/'), 'price' => '$45.00', 'image' => ''],
                ['id' => 0, 'name' => 'Oil Filter - Mazda CX-5', 'url' => home_url('/shop/'), 'price' => '$12.00', 'image' => ''],
                ['id' => 0, 'name' => 'Front Shock Absorber - Subaru Forester', 'url' => home_url('/shop/'), 'price' => '$89.00', 'image' => ''],
                ['id' => 0, 'name' => 'Wiper Blade Set (24"/18")', 'url' => home_url('/shop/'), 'price' => '$18.00', 'image' => ''],
            ];
        }
        return [
            'email'           => 'driver@example.com',
            'first_name'      => 'Brian',
            'rotation_theme_label' => $rot_label,
            'rotation_families' => $rot_fams ?? [],
            'zone'            => $zone,
            'products'        => $products,
            'product_name'    => $products[0]['name'] ?? 'Oil Filter',
            'product_url'     => $products[0]['url'] ?? home_url('/shop/'),
            'cart_url'        => home_url('/cart/'),
            'reorder_url'     => home_url('/my-account/'),
            'welcome_code'    => 'SUP20-AB12CD',
            'welcome_expires' => gmdate('j M Y', time() + 14 * DAY_IN_SECONDS),
            'car'             => 'Mazda CX-5',
            'cart'            => [
                'items' => [
                    ['name' => $products[0]['name'] ?? 'Brake Pad Set', 'qty' => 1, 'price' => $products[0]['price'] ?? '$45.00', 'image' => $products[0]['image'] ?? '', 'url' => $products[0]['url'] ?? home_url('/shop/')],
                    ['name' => $products[1]['name'] ?? 'Oil Filter', 'qty' => 2, 'price' => $products[1]['price'] ?? '$24.00', 'image' => $products[1]['image'] ?? '', 'url' => $products[1]['url'] ?? home_url('/shop/')],
                ],
                'total' => sm_money(753.0, $zone),
            ],
            'order'           => ['number' => '#1042', 'url' => home_url('/my-account/')],
            'category'        => ['name' => 'Brakes', 'url' => home_url('/product-category/brakes/')],
            'coupon'          => ['code' => 'BRAKES15', 'label' => '15% off this week', 'expires' => gmdate('j M Y', time() + 7 * DAY_IN_SECONDS), 'free_shipping' => false],
            'campaign'        => [
                'subject' => 'This week at Supreme Autoparts',
                'heading' => 'Store update',
                'intro'   => 'New arrivals, restocks and offers from the past week, all in stock and ready to ship.',
            ],
        ];
    }
}
