<?php
/**
 * Safe repair helpers for legacy receipt data.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Receipt_Repair {

    private static function t( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public static function preview(): array {
        global $wpdb;

        return [
            'missing_payment_no' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . self::t( 'olama_payments' ) . "
                 WHERE payment_no IS NULL OR payment_no = ''"
            ),
            'missing_account' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . self::t( 'olama_payments' ) . "
                 WHERE amount > 0 AND method != 'reversal' AND (account_id IS NULL OR account_id = 0)"
            ),
            'missing_movements' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . self::t( 'olama_payments' ) . " p
                 LEFT JOIN " . self::t( 'olama_cash_bank_movements' ) . " m
                   ON m.source_type = 'payment' AND m.source_id = p.id AND m.movement_type = 'receipt'
                 WHERE p.amount > 0
                   AND p.method != 'reversal'
                   AND (p.status IS NULL OR p.status = '' OR p.status = 'posted')
                   AND m.id IS NULL"
            ),
            'mismatched_movements' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . self::t( 'olama_cash_bank_movements' ) . " m
                 LEFT JOIN " . self::t( 'olama_payments' ) . " p ON p.id = m.source_id
                 WHERE m.source_type = 'payment'
                   AND m.movement_type = 'receipt'
                   AND (
                       p.id IS NULL
                       OR p.amount <= 0
                       OR p.method = 'reversal'
                       OR COALESCE(NULLIF(p.status, ''), 'posted') != 'posted'
                       OR (p.account_id IS NOT NULL AND p.account_id > 0 AND m.account_id != p.account_id)
                       OR NOT (m.cash_session_id <=> p.cash_session_id)
                       OR m.direction != 'in'
                       OR ROUND(m.amount, 2) != ROUND(p.amount, 2)
                       OR m.movement_date != p.payment_date
                       OR m.status != 'posted'
                   )"
            ),
        ];
    }

    public static function run( array $options ): array|\WP_Error {
        global $wpdb;

        $stats = [ 'payment_numbers' => 0, 'accounts' => 0, 'movements' => 0, 'movement_conflicts' => 0 ];

        $wpdb->query( 'START TRANSACTION' );

        if ( ! empty( $options['payment_numbers'] ) ) {
            $ids = $wpdb->get_col(
                "SELECT id FROM " . self::t( 'olama_payments' ) . "
                 WHERE payment_no IS NULL OR payment_no = ''
                 ORDER BY payment_date ASC, id ASC"
            );
            foreach ( $ids as $id ) {
                $number = self::generate_receipt_no_for_payment( (int) $id );
                $updated = $wpdb->update(
                    self::t( 'olama_payments' ),
                    [ 'payment_no' => $number ],
                    [ 'id' => (int) $id ]
                );
                if ( $updated === false ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new \WP_Error( 'db_error', $wpdb->last_error );
                }
                $stats['payment_numbers']++;
            }
        }

        if ( ! empty( $options['accounts'] ) ) {
            $rows = $wpdb->get_results(
                "SELECT id, method FROM " . self::t( 'olama_payments' ) . "
                 WHERE amount > 0 AND method != 'reversal' AND (account_id IS NULL OR account_id = 0)"
            ) ?: [];
            foreach ( $rows as $row ) {
                $account_id = Olama_Reg_Cash_Bank_Movement::get_default_account_id_for_method( (string) $row->method );
                if ( ! $account_id ) {
                    continue;
                }
                $updated = $wpdb->update(
                    self::t( 'olama_payments' ),
                    [ 'account_id' => $account_id ],
                    [ 'id' => (int) $row->id ]
                );
                if ( $updated === false ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new \WP_Error( 'db_error', $wpdb->last_error );
                }
                $stats['accounts']++;
            }
        }

        if ( ! empty( $options['movements'] ) ) {
            /*
             * A partial legacy reset could delete payments while retaining their
             * immutable ledger movements. MySQL may then reuse the payment IDs,
             * making an old movement look like the movement of a new receipt.
             * Preserve the historical movement under a legacy source namespace
             * before creating the correct movement for the current payment.
             */
            $conflicts = $wpdb->get_results(
                "SELECT m.id
                 FROM " . self::t( 'olama_cash_bank_movements' ) . " m
                 LEFT JOIN " . self::t( 'olama_payments' ) . " p ON p.id = m.source_id
                 WHERE m.source_type = 'payment'
                   AND m.movement_type = 'receipt'
                   AND (
                       p.id IS NULL
                       OR p.amount <= 0
                       OR p.method = 'reversal'
                       OR COALESCE(NULLIF(p.status, ''), 'posted') != 'posted'
                       OR (p.account_id IS NOT NULL AND p.account_id > 0 AND m.account_id != p.account_id)
                       OR NOT (m.cash_session_id <=> p.cash_session_id)
                       OR m.direction != 'in'
                       OR ROUND(m.amount, 2) != ROUND(p.amount, 2)
                       OR m.movement_date != p.payment_date
                       OR m.status != 'posted'
                   )
                 ORDER BY m.id ASC"
            ) ?: [];

            foreach ( $conflicts as $conflict ) {
                $updated = $wpdb->update(
                    self::t( 'olama_cash_bank_movements' ),
                    [ 'source_type' => 'legacy_payment_' . (int) $conflict->id ],
                    [ 'id' => (int) $conflict->id ]
                );
                if ( $updated === false ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new \WP_Error( 'db_error', $wpdb->last_error );
                }
                $stats['movement_conflicts']++;
            }

            $ids = $wpdb->get_col(
                "SELECT p.id FROM " . self::t( 'olama_payments' ) . " p
                 LEFT JOIN " . self::t( 'olama_cash_bank_movements' ) . " m
                   ON m.source_type = 'payment' AND m.source_id = p.id AND m.movement_type = 'receipt'
                 WHERE p.amount > 0
                   AND p.method != 'reversal'
                   AND (p.status IS NULL OR p.status = '' OR p.status = 'posted')
                   AND m.id IS NULL
                 ORDER BY p.payment_date ASC, p.id ASC"
            );
            foreach ( $ids as $id ) {
                $movement = Olama_Reg_Cash_Bank_Movement::record_receipt_movement( (int) $id );
                if ( is_wp_error( $movement ) ) {
                    $wpdb->query( 'ROLLBACK' );
                    return $movement;
                }
                $stats['movements']++;
            }
        }

        $wpdb->query( 'COMMIT' );
        self::log_audit( 'receipt_repair', 0, 'receipt_repair_run', null, (object) $stats );

        return $stats;
    }

    private static function generate_receipt_no_for_payment( int $payment_id ): string {
        global $wpdb;

        $date = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT payment_date FROM " . self::t( 'olama_payments' ) . " WHERE id = %d",
            $payment_id
        ) );
        $year = preg_match( '/^(\d{4})-/', $date, $matches ) ? $matches[1] : current_time( 'Y' );
        $base = 'REC-' . $year . '-';
        $latest = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT payment_no FROM " . self::t( 'olama_payments' ) . "
             WHERE payment_no LIKE %s
             ORDER BY payment_no DESC
             LIMIT 1",
            $wpdb->esc_like( $base ) . '%'
        ) );

        $next = 1;
        if ( preg_match( '/^' . preg_quote( $base, '/' ) . '(\d+)$/', $latest, $matches ) ) {
            $next = (int) $matches[1] + 1;
        }

        return $base . str_pad( (string) $next, 5, '0', STR_PAD_LEFT );
    }

    private static function log_audit( string $entity_type, int $entity_id, string $action, ?object $before, ?object $after ): void {
        global $wpdb;

        $wpdb->insert( self::t( 'olama_billing_audit' ), [
            'entity_type'  => $entity_type,
            'entity_id'    => $entity_id,
            'action'       => $action,
            'actor_id'     => get_current_user_id(),
            'before_state' => $before ? wp_json_encode( $before ) : null,
            'after_state'  => $after ? wp_json_encode( $after ) : null,
            'ip_address'   => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
        ] );
    }
}
