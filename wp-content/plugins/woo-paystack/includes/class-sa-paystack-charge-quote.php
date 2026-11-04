<?php
/**
 * Supreme: server-locked charge quotes for Paystack.
 *
 * Store orders stay in their own currency (USD). This account settles in KES,
 * so at payment time the exact charge is quoted server-side, locked onto the
 * order for that transaction reference, sent to Paystack, and later matched
 * during verification. Nothing is relabelled: the quote records the order
 * currency/total, the rate, and the charge currency/amount, and verification
 * only accepts the exact locked figures for the exact reference.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SA_Paystack_Charge_Quote {

	const META_KEY         = '_sa_paystack_charge_quotes';
	const DISPLAY_META_KEY = '_sa_paystack_display_quote';
	const MAX_KEPT         = 5;
	// How long the customer-visible quote stays authoritative for payment.
	const DISPLAY_TTL      = 1800;

	/**
	 * Currency this Paystack account charges and settles in.
	 */
	public static function settlement_currency(): string {
		$currency = strtoupper( (string) apply_filters( 'sa_paystack_settlement_currency', 'KES' ) );
		return preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'KES';
	}

	public static function decimals_for( string $currency ): int {
		if ( class_exists( 'SA_FX_Rates' ) ) {
			return SA_FX_Rates::decimals_for( $currency );
		}
		return 2;
	}

	public static function minor( float $amount ): int {
		return (int) round( $amount * 100 );
	}

	/**
	 * Build the quote for charging this order in the settlement currency.
	 * Returns null when no truthful quote exists (no rate, bad totals), which
	 * must fail the payment attempt closed rather than relabel anything.
	 */
	public static function build_quote( $order, string $txn_ref ): ?array {
		$order_currency = strtoupper( (string) $order->get_currency() );
		$settlement     = self::settlement_currency();
		$total          = (float) $order->get_total();
		if ( '' === $order_currency || ! is_finite( $total ) || $total <= 0 ) {
			return null;
		}
		$rate = 1.0;
		if ( $order_currency !== $settlement ) {
			if ( ! class_exists( 'SA_FX_Rates' ) ) {
				return null;
			}
			$rate = SA_FX_Rates::rate_for( $settlement );
			if ( ! is_numeric( $rate ) || ! is_finite( (float) $rate ) || (float) $rate <= 0 ) {
				return null;
			}
			$rate = (float) $rate;
		}
		$charge = round( $total * $rate, self::decimals_for( $settlement ) );
		if ( ! is_finite( $charge ) || $charge <= 0 ) {
			return null;
		}
		return array(
			'reference'       => $txn_ref,
			'order_currency'  => $order_currency,
			'order_total'     => $total,
			'charge_currency' => $settlement,
			'charge_amount'   => $charge,
			'rate'            => $rate,
			'quoted_at'       => time(),
		);
	}

	/**
	 * Quote from an explicit rate, used when reusing the customer-visible quote.
	 */
	public static function build_quote_at_rate( $order, string $txn_ref, float $rate ): ?array {
		$order_currency = strtoupper( (string) $order->get_currency() );
		$settlement     = self::settlement_currency();
		$total          = (float) $order->get_total();
		if ( '' === $order_currency || ! is_finite( $total ) || $total <= 0 || ! is_finite( $rate ) || $rate <= 0 ) {
			return null;
		}
		$charge = round( $total * $rate, self::decimals_for( $settlement ) );
		if ( ! is_finite( $charge ) || $charge <= 0 ) {
			return null;
		}
		return array(
			'reference'       => $txn_ref,
			'order_currency'  => $order_currency,
			'order_total'     => $total,
			'charge_currency' => $settlement,
			'charge_amount'   => $charge,
			'rate'            => $rate,
			'quoted_at'       => time(),
		);
	}

	/**
	 * Read the customer-visible display quote stored on the order, when it still
	 * describes the current order and is fresh enough to honor.
	 */
	public static function display_quote_for_order( $order ): ?array {
		$display = $order->get_meta( self::DISPLAY_META_KEY );
		if ( ! is_array( $display ) ) {
			return null;
		}
		if ( strtoupper( (string) ( $display['order_currency'] ?? '' ) ) !== strtoupper( (string) $order->get_currency() ) ) {
			return null;
		}
		if ( abs( (float) ( $display['order_total'] ?? -1 ) - (float) $order->get_total() ) >= 0.005 ) {
			return null;
		}
		if ( (int) ( $display['quoted_at'] ?? 0 ) < time() - self::DISPLAY_TTL ) {
			return null;
		}
		if ( (float) ( $display['rate'] ?? 0 ) <= 0 || (float) ( $display['charge_amount'] ?? 0 ) <= 0 ) {
			return null;
		}
		return $display;
	}

	/**
	 * Persist the exact quote used to initialize a transaction, keyed by its
	 * reference, so later verification matches what was actually initialized.
	 *
	 * When a customer-visible display quote exists for this order, its rate is
	 * reused so the charge equals the figure the customer saw. If that figure
	 * is stale and a fresh quote would charge a different amount, this returns
	 * null: the payment must stop and the page must show the updated charge
	 * instead of silently charging a new figure.
	 */
	public static function lock_for_order( $order, string $txn_ref ): ?array {
		$display = self::display_quote_for_order( $order );
		if ( is_array( $display ) ) {
			$quote = self::build_quote_at_rate( $order, $txn_ref, (float) $display['rate'] );
			if ( ! is_array( $quote ) ) {
				return null;
			}
			if ( self::minor( (float) $quote['charge_amount'] ) !== self::minor( (float) $display['charge_amount'] ) ) {
				return null;
			}
		} else {
			$had_display = is_array( $order->get_meta( self::DISPLAY_META_KEY ) );
			$quote       = self::build_quote( $order, $txn_ref );
			if ( ! is_array( $quote ) ) {
				return null;
			}
			if ( $had_display ) {
				$old = $order->get_meta( self::DISPLAY_META_KEY );
				if ( abs( (float) ( $old['order_total'] ?? -1 ) - (float) $order->get_total() ) < 0.005
					&& self::minor( (float) ( $old['charge_amount'] ?? -1 ) ) !== self::minor( (float) $quote['charge_amount'] ) ) {
					// Stale displayed figure for the same total: re-display, do not silently charge the new one.
					return null;
				}
			}
		}
		$quotes = $order->get_meta( self::META_KEY );
		$quotes = is_array( $quotes ) ? $quotes : array();
		$quotes[ $txn_ref ] = $quote;
		uasort(
			$quotes,
			static function ( $a, $b ) {
				return ( (int) ( $a['quoted_at'] ?? 0 ) ) <=> ( (int) ( $b['quoted_at'] ?? 0 ) );
			}
		);
		while ( count( $quotes ) > self::MAX_KEPT ) {
			array_shift( $quotes );
		}
		$order->update_meta_data( self::META_KEY, $quotes );
		return $quote;
	}

	public static function quote_for_reference( $order, string $txn_ref ): ?array {
		$quotes = $order->get_meta( self::META_KEY );
		if ( ! is_array( $quotes ) || ! isset( $quotes[ $txn_ref ] ) || ! is_array( $quotes[ $txn_ref ] ) ) {
			return null;
		}
		return $quotes[ $txn_ref ];
	}

	/**
	 * What the customer is expected to be charged for this reference: the
	 * locked quote when it still describes the current order, otherwise the
	 * order itself (same-currency payments behave exactly as before).
	 */
	public static function expected_charge( $order, string $txn_ref ): array {
		$quote = self::quote_for_reference( $order, $txn_ref );
		if ( is_array( $quote )
			&& strtoupper( (string) ( $quote['order_currency'] ?? '' ) ) === strtoupper( (string) $order->get_currency() )
			&& abs( (float) ( $quote['order_total'] ?? -1 ) - (float) $order->get_total() ) < 0.005
			&& (float) ( $quote['charge_amount'] ?? 0 ) > 0
			&& '' !== (string) ( $quote['charge_currency'] ?? '' ) ) {
			$converted = strtoupper( (string) $quote['charge_currency'] ) !== strtoupper( (string) $quote['order_currency'] );
			return array(
				'amount'   => (float) $quote['charge_amount'],
				'currency' => strtoupper( (string) $quote['charge_currency'] ),
				'quote'    => $converted ? $quote : null,
			);
		}
		return array(
			'amount'   => (float) $order->get_total(),
			'currency' => strtoupper( (string) $order->get_currency() ),
			'quote'    => null,
		);
	}

	/**
	 * Fail-closed settlement match for verification choke points: paid figures
	 * must equal the locked quote (or, without one, the order itself).
	 */
	public static function matches_transaction( $order, string $txn_ref, $amount_minor, string $currency ): bool {
		if ( ! is_numeric( $amount_minor ) ) {
			return false;
		}
		$expected = self::expected_charge( $order, $txn_ref );
		if ( $expected['amount'] <= 0 || '' === $expected['currency'] ) {
			return false;
		}
		if ( strtoupper( $currency ) !== $expected['currency'] ) {
			return false;
		}
		return (int) $amount_minor === self::minor( (float) $expected['amount'] );
	}
}
