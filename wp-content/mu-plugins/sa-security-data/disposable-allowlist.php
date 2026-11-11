<?php
/**
 * Domains that must never be blocked by the bundled disposable list.
 *
 * Alias forwarders are not disposable inboxes. They deliver to a real,
 * permanent mailbox, people pay for them, and a shopper using one is a genuine
 * customer. Upstream lists three of them; blocking any is a lost order the
 * merchant never hears about.
 *
 * The entries marked "not listed upstream" are pinned defensively: they are the
 * same category of service and would be equally wrong to block if upstream ever
 * adds them.
 *
 * Re-check this file against the upstream list on every regeneration.
 *
 * @package checkout-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// Listed upstream as of 2026-09-03. Verified false positives.
	'relay.firefox.com'        => true, // Mozilla Firefox Relay.
	'addy.io'                  => true, // Formerly AnonAddy.
	'33mail.com'               => true,

	// Not listed upstream. Pinned against a future upstream mistake.
	'privaterelay.appleid.com' => true, // Apple Hide My Email.
	'duck.com'                 => true, // DuckDuckGo Email Protection.
	'simplelogin.com'          => true,
	'simplelogin.io'           => true,
	'anonaddy.me'              => true,
	'mozmail.com'              => true, // Firefox Relay delivery domain.
	'passinbox.com'            => true, // Proton Pass aliases.
);
