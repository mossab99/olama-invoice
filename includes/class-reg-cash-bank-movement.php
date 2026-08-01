<?php
/**
 * Immutable cash/bank movement ledger helpers.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Cash_Bank_Movement {

    private static function t( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public static function record_receipt_movement( int $payment_id ): int|\WP_Error {
        $payment = self::get_payment( $payment_id );
        if ( ! $payment ) {
            return new \WP_Error( 'payment_not_found', __( 'Payment not found.', 'olama-registration' ) );
        }

        if ( (string) ( $payment->status ?? 'posted' ) !== 'posted' || (float) $payment->amount <= 0 ) {
            return new \WP_Error( 'payment_not_posted', __( 'Only posted positive payments create receipt movements.', 'olama-registration' ) );
        }

        $account_id = (int) ( $payment->account_id ?? 0 );
        if ( ! $account_id ) {
            $account_id = self::get_default_account_id_for_method( (string) $payment->method );
        }

        if ( ! $account_id ) {
            return new \WP_Error( 'missing_account', __( 'No financial account is available for this receipt.', 'olama-registration' ) );
        }

        return self::record_movement( [
            'account_id'      => $account_id,
            'cash_session_id' => (int) ( $payment->cash_session_id ?? 0 ) ?: null,
            'movement_type'   => 'receipt',
            'source_type'     => 'payment',
            'source_id'       => $payment_id,
            'direction'       => 'in',
            'amount'          => (float) $payment->amount,
            'movement_date'   => $payment->payment_date ?: current_time( 'Y-m-d' ),
            'created_by'      => (int) ( $payment->received_by ?? get_current_user_id() ),
            'notes'           => self::payment_note( $payment ),
        ] );
    }

    public static function record_reversal_movement( int $reversal_payment_id, int $original_payment_id ): int|\WP_Error {
        $reversal = self::get_payment( $reversal_payment_id );
        $original = self::get_payment( $original_payment_id );
        if ( ! $reversal || ! $original ) {
            return new \WP_Error( 'payment_not_found', __( 'Payment not found.', 'olama-registration' ) );
        }

        $account_id = (int) ( $reversal->account_id ?? $original->account_id ?? 0 );
        if ( ! $account_id ) {
            $account_id = self::get_default_account_id_for_method( (string) $original->method );
        }

        if ( ! $account_id ) {
            return new \WP_Error( 'missing_account', __( 'No financial account is available for this reversal.', 'olama-registration' ) );
        }

        return self::record_movement( [
            'account_id'      => $account_id,
            'cash_session_id' => (int) ( $reversal->cash_session_id ?? 0 ) ?: null,
            'movement_type'   => 'receipt_reversal',
            'source_type'     => 'payment',
            'source_id'       => $reversal_payment_id,
            'direction'       => 'out',
            'amount'          => abs( (float) $reversal->amount ),
            'movement_date'   => $reversal->payment_date ?: current_time( 'Y-m-d' ),
            'created_by'      => (int) ( $reversal->received_by ?? get_current_user_id() ),
            'notes'           => self::payment_note( $reversal ),
        ] );
    }

    /**
     * Reconcile an approved physical cash count with the account ledger.
     *
     * The movement is intentionally not linked back to the cash session totals:
     * it records the approved overage/shortage after the session calculation has
     * been frozen, so it becomes the ledger reference for the next session.
     */
    public static function record_cash_session_variance(
        int $session_id,
        int $account_id,
        float $difference,
        string $phase,
        string $movement_date,
        string $notes = ''
    ): int|\WP_Error {
        $difference = round( $difference, 2 );
        $phase = $phase === 'opening' ? 'opening' : 'closing';
        if ( ! $session_id || ! $account_id || abs( $difference ) < 0.01 ) {
            return new \WP_Error( 'invalid_cash_variance', __( 'The cash-session variance is invalid.', 'olama-registration' ) );
        }

        $is_overage = $difference > 0;

        return self::record_movement( [
            'account_id'      => $account_id,
            'cash_session_id' => null,
            'movement_type'   => 'cash_' . $phase . '_' . ( $is_overage ? 'overage' : 'shortage' ),
            'source_type'     => 'cash_session',
            'source_id'       => $session_id,
            'direction'       => $is_overage ? 'in' : 'out',
            'amount'          => abs( $difference ),
            'movement_date'   => $movement_date,
            'created_by'      => get_current_user_id(),
            'notes'           => $notes,
        ] );
    }

    public static function record_transfer( int $from_account_id, int $to_account_id, float $amount, array $meta = [] ): int|\WP_Error {
        global $wpdb;

        $policy = Olama_Reg_Payment_Policy::can_transfer_between_accounts( $from_account_id, $to_account_id );
        if ( is_wp_error( $policy ) ) {
            return $policy;
        }

        $from_account = self::get_account( $from_account_id );
        $to_account   = self::get_account( $to_account_id );

        if ( ! $from_account || ! $to_account ) {
            return new \WP_Error( 'invalid_account', __( 'Please choose two active financial accounts.', 'olama-registration' ) );
        }

        if ( $from_account_id === $to_account_id ) {
            return new \WP_Error( 'same_account_transfer', __( 'Source and destination accounts must be different.', 'olama-registration' ) );
        }

        $amount = round( abs( $amount ), 2 );
        if ( $amount <= 0 ) {
            return new \WP_Error( 'invalid_transfer_amount', __( 'Transfer amount must be greater than zero.', 'olama-registration' ) );
        }

        $current_balance = self::get_account_balance( $from_account_id );
        if ( $current_balance < $amount ) {
            return new \WP_Error( 'insufficient_funds', __( 'The source account does not have enough balance for this transfer.', 'olama-registration' ) );
        }

        self::maybe_create_transfer_table();

        $transfer_date = sanitize_text_field( $meta['transfer_date'] ?? current_time( 'Y-m-d' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $transfer_date ) ) {
            $transfer_date = current_time( 'Y-m-d' );
        }

        $payload = [
            'transfer_no'      => self::generate_transfer_no(),
            'from_account_id'  => $from_account_id,
            'to_account_id'    => $to_account_id,
            'amount'           => $amount,
            'transfer_date'    => $transfer_date,
            'reference'        => sanitize_text_field( $meta['reference'] ?? '' ) ?: null,
            'notes'            => sanitize_textarea_field( $meta['notes'] ?? '' ) ?: null,
            'status'           => 'posted',
            'created_by'       => get_current_user_id(),
        ];

        $wpdb->query( 'START TRANSACTION' );

        // Lock both accounts in a stable order, then recheck the source
        // balance while locked to prevent concurrent transfers overdrawing it.
        $lock_ids = [ $from_account_id, $to_account_id ];
        sort( $lock_ids, SORT_NUMERIC );
        foreach ( $lock_ids as $lock_id ) {
            $locked = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM " . self::t( 'olama_financial_accounts' ) . " WHERE id = %d FOR UPDATE",
                $lock_id
            ) );
            if ( ! $locked ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'invalid_account', __( 'Please choose two active financial accounts.', 'olama-registration' ) );
            }
        }
        if ( self::get_account_balance( $from_account_id ) < $amount ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'insufficient_funds', __( 'The source account does not have enough balance for this transfer.', 'olama-registration' ) );
        }

        $inserted = $wpdb->insert( self::t( 'olama_account_transfers' ), $payload );
        if ( ! $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'db_error', $wpdb->last_error );
        }

        $transfer_id = (int) $wpdb->insert_id;
        $notes = self::transfer_note( (object) $payload, $from_account, $to_account );
        $from_cash_session_id = null;
        $to_cash_session_id = null;

        if ( (string) $from_account->type === 'cash' ) {
            $session = Olama_Reg_Cash_Session::get_open_session( $from_account_id, get_current_user_id() );
            $from_cash_session_id = $session ? (int) $session->id : null;
        }
        if ( (string) $to_account->type === 'cash' ) {
            $session = Olama_Reg_Cash_Session::get_open_session( $to_account_id, get_current_user_id() );
            $to_cash_session_id = $session ? (int) $session->id : null;
        }

        if ( get_option( 'olama_require_cash_session', '0' ) === '1' ) {
            if ( (string) $from_account->type === 'cash' && ! $from_cash_session_id ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'missing_cash_session', __( 'A source cash session must be open before transferring cash.', 'olama-registration' ) );
            }
            if ( (string) $to_account->type === 'cash' && ! $to_cash_session_id ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'missing_cash_session', __( 'A destination cash session must be open before receiving transferred cash.', 'olama-registration' ) );
            }
        }

        $out = self::record_movement( [
            'account_id'      => $from_account_id,
            'cash_session_id' => $from_cash_session_id,
            'movement_type'   => 'transfer_out',
            'source_type'     => 'account_transfer',
            'source_id'       => $transfer_id,
            'direction'       => 'out',
            'amount'          => $amount,
            'movement_date'   => $transfer_date,
            'created_by'      => get_current_user_id(),
            'notes'           => $notes,
        ] );
        if ( is_wp_error( $out ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $out;
        }

        $in = self::record_movement( [
            'account_id'      => $to_account_id,
            'cash_session_id' => $to_cash_session_id,
            'movement_type'   => 'transfer_in',
            'source_type'     => 'account_transfer',
            'source_id'       => $transfer_id,
            'direction'       => 'in',
            'amount'          => $amount,
            'movement_date'   => $transfer_date,
            'created_by'      => get_current_user_id(),
            'notes'           => $notes,
        ] );
        if ( is_wp_error( $in ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $in;
        }

        $wpdb->query( 'COMMIT' );

        return $transfer_id;
    }

    public static function get_transfers( int $limit = 20 ): array {
        global $wpdb;

        self::maybe_create_transfer_table();

        $limit = max( 1, min( 100, $limit ) );
        return $wpdb->get_results(
            "SELECT t.*,
                    fa.account_code AS from_account_code,
                    fa.account_name AS from_account_name,
                    ta.account_code AS to_account_code,
                    ta.account_name AS to_account_name,
                    u.display_name AS created_by_name
             FROM " . self::t( 'olama_account_transfers' ) . " t
             LEFT JOIN " . self::t( 'olama_financial_accounts' ) . " fa ON fa.id = t.from_account_id
             LEFT JOIN " . self::t( 'olama_financial_accounts' ) . " ta ON ta.id = t.to_account_id
             LEFT JOIN {$wpdb->users} u ON u.ID = t.created_by
             ORDER BY t.transfer_date DESC, t.id DESC
             LIMIT {$limit}"
        ) ?: [];
    }

    public static function get_account_balance( int $account_id, ?string $date_to = null ): float {
        global $wpdb;

        $account = $wpdb->get_row( $wpdb->prepare(
            "SELECT type, opening_balance FROM " . self::t( 'olama_financial_accounts' ) . " WHERE id = %d",
            $account_id
        ) );
        if ( ! $account ) {
            return 0.0;
        }

        /*
         * An open cash session is the authoritative physical inventory for a
         * cashbox. Its opening count replaces the historical ledger baseline;
         * adding both would count the same cash twice.
         */
        if ( (string) $account->type === 'cash' ) {
            $session_inventory = self::get_open_cash_inventory_balance( $account_id, $date_to );
            if ( $session_inventory !== null ) {
                return $session_inventory;
            }
        }

        $where = 'account_id = %d AND status = %s';
        $params = [ $account_id, 'posted' ];
        if ( $date_to ) {
            $where .= ' AND movement_date <= %s';
            $params[] = $date_to;
        }

        $net = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0)
             FROM " . self::t( 'olama_cash_bank_movements' ) . "
             WHERE {$where}",
            ...$params
        ) );

        return round( (float) $account->opening_balance + $net, 2 );
    }

    /**
     * Return the physical inventory held by currently open cash sessions.
     *
     * Null means no applicable open session, so callers should fall back to
     * the immutable account-movement ledger.
     */
    public static function get_open_cash_inventory_balance( int $account_id, ?string $date_to = null ): ?float {
        global $wpdb;

        $where = 's.account_id = %d AND s.status = %s';
        $params = [ $account_id, 'open' ];
        if ( $date_to ) {
            $where .= ' AND s.session_date <= %s';
            $params[] = sanitize_text_field( $date_to );
        }

        $session_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::t( 'olama_cash_sessions' ) . " s WHERE {$where}",
            ...$params
        ) );
        if ( $session_count === 0 ) {
            return null;
        }

        $inventory = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(session_totals.expected_balance), 0)
             FROM (
                 SELECT
                     s.id,
                     s.opening_balance
                         + COALESCE(SUM(
                             CASE
                                 WHEN m.direction = 'in' THEN m.amount
                                 WHEN m.direction = 'out' THEN -m.amount
                                 ELSE 0
                             END
                         ), 0) AS expected_balance
                 FROM " . self::t( 'olama_cash_sessions' ) . " s
                 LEFT JOIN " . self::t( 'olama_cash_bank_movements' ) . " m
                        ON m.cash_session_id = s.id
                       AND m.status = 'posted'
                 WHERE {$where}
                 GROUP BY s.id, s.opening_balance
             ) session_totals",
            ...$params
        ) );

        return round( (float) $inventory, 2 );
    }

    public static function get_movements( array $filters = [] ): array {
        global $wpdb;

        $where = [ '1=1' ];
        $params = [];
        if ( ! empty( $filters['account_id'] ) ) {
            $where[] = 'm.account_id = %d';
            $params[] = absint( $filters['account_id'] );
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where[] = 'm.movement_date >= %s';
            $params[] = sanitize_text_field( $filters['date_from'] );
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[] = 'm.movement_date <= %s';
            $params[] = sanitize_text_field( $filters['date_to'] );
        }

        $sql = "SELECT m.*, a.account_code, a.account_name, a.type AS account_type
                FROM " . self::t( 'olama_cash_bank_movements' ) . " m
                LEFT JOIN " . self::t( 'olama_financial_accounts' ) . " a ON a.id = m.account_id
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY m.movement_date DESC, m.id DESC";

        return $params ? ( $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) ?: [] ) : ( $wpdb->get_results( $sql ) ?: [] );
    }

    public static function get_default_account_id_for_method( string $method ): int {
        $map = [
            'cash'          => 'cash',
            'bank_transfer' => 'bank',
            'cheque'        => 'cheque_clearing',
            'online'        => 'electronic',
        ];

        return self::get_default_account_id( $map[ $method ] ?? 'cash' );
    }

    public static function get_default_account_id( string $type ): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . self::t( 'olama_financial_accounts' ) . "
             WHERE type = %s AND is_active = 1
             ORDER BY is_default DESC, id ASC
             LIMIT 1",
            $type
        ) );
    }

    private static function record_movement( array $data ): int|\WP_Error {
        global $wpdb;

        $payload = [
            'account_id'      => (int) $data['account_id'],
            'cash_session_id' => $data['cash_session_id'] ?? null,
            'movement_type'   => sanitize_key( $data['movement_type'] ),
            'source_type'     => sanitize_key( $data['source_type'] ),
            'source_id'       => (int) $data['source_id'],
            'direction'       => $data['direction'] === 'out' ? 'out' : 'in',
            'amount'          => round( abs( (float) $data['amount'] ), 2 ),
            'movement_date'   => sanitize_text_field( $data['movement_date'] ?? current_time( 'Y-m-d' ) ),
            'status'          => sanitize_key( $data['status'] ?? 'posted' ),
            'created_by'      => (int) ( $data['created_by'] ?? get_current_user_id() ),
            'notes'           => sanitize_textarea_field( $data['notes'] ?? '' ) ?: null,
        ];

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_cash_bank_movements' ) . "
             WHERE source_type = %s AND source_id = %d AND movement_type = %s",
            $payload['source_type'],
            $payload['source_id'],
            $payload['movement_type']
        ) );
        if ( $existing ) {
            $same_event =
                (int) $existing->account_id === $payload['account_id']
                && (int) ( $existing->cash_session_id ?? 0 ) === (int) ( $payload['cash_session_id'] ?? 0 )
                && (string) $existing->direction === $payload['direction']
                && round( (float) $existing->amount, 2 ) === $payload['amount']
                && (string) $existing->movement_date === $payload['movement_date']
                && (string) $existing->status === $payload['status'];

            if ( $same_event ) {
                return (int) $existing->id;
            }

            return new \WP_Error(
                'movement_source_collision',
                __( 'تعذر تسجيل الحركة لأن معرف السند مرتبط بحركة مالية قديمة مختلفة. يرجى تشغيل إصلاح حركات السندات.', 'olama-registration' )
            );
        }

        if ( ! self::acquire_number_lock() ) {
            return new \WP_Error( 'number_lock_failed', __( 'Could not reserve a movement number. Please try again.', 'olama-registration' ) );
        }

        $payload = [ 'movement_no' => self::generate_movement_no() ] + $payload;

        $result = $wpdb->insert( self::t( 'olama_cash_bank_movements' ), $payload );
        self::release_number_lock();

        if ( ! $result ) {
            return new \WP_Error( 'db_error', $wpdb->last_error );
        }

        return (int) $wpdb->insert_id;
    }

    private static function get_payment( int $payment_id ): ?object {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_payments' ) . " WHERE id = %d",
            $payment_id
        ) ) ?: null;
    }

    private static function get_account( int $account_id ): ?object {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_financial_accounts' ) . " WHERE id = %d AND is_active = 1",
            $account_id
        ) ) ?: null;
    }

    private static function payment_note( object $payment ): string {
        $receipt_no = method_exists( 'Olama_Reg_Billing_Payment', 'get_receipt_number' )
            ? Olama_Reg_Billing_Payment::get_receipt_number( $payment )
            : '#' . (int) $payment->id;

        return sprintf( 'Receipt %s / invoice %d', $receipt_no, (int) $payment->invoice_id );
    }

    private static function transfer_note( object $transfer, object $from_account, object $to_account ): string {
        return sprintf(
            'Internal transfer %s from %s to %s',
            (string) ( $transfer->transfer_no ?? '' ),
            (string) ( $from_account->account_code ?? '' ),
            (string) ( $to_account->account_code ?? '' )
        );
    }

    private static function maybe_create_transfer_table(): void {
        global $wpdb;

        static $ready = false;
        if ( $ready ) {
            return;
        }

        $table = self::t( 'olama_account_transfers' );
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists === $table ) {
            $ready = true;
            return;
        }

        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            transfer_no varchar(50) NOT NULL,
            from_account_id bigint(20) UNSIGNED NOT NULL,
            to_account_id bigint(20) UNSIGNED NOT NULL,
            amount decimal(12,2) NOT NULL DEFAULT 0.00,
            transfer_date date DEFAULT NULL,
            reference varchar(190) DEFAULT NULL,
            status varchar(30) NOT NULL DEFAULT 'posted',
            notes text DEFAULT NULL,
            created_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY transfer_no (transfer_no),
            KEY from_account_id (from_account_id),
            KEY to_account_id (to_account_id),
            KEY transfer_date (transfer_date)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        $ready = true;
    }

    private static function generate_transfer_no(): string {
        global $wpdb;

        self::maybe_create_transfer_table();

        $year = current_time( 'Y' );
        $base = 'TRF-' . $year . '-';
        $latest = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT transfer_no
             FROM " . self::t( 'olama_account_transfers' ) . "
             WHERE transfer_no LIKE %s
             ORDER BY transfer_no DESC
             LIMIT 1",
            $wpdb->esc_like( $base ) . '%'
        ) );

        $next = 1;
        if ( preg_match( '/^' . preg_quote( $base, '/' ) . '(\d+)$/', $latest, $matches ) ) {
            $next = (int) $matches[1] + 1;
        }

        return $base . str_pad( (string) $next, 5, '0', STR_PAD_LEFT );
    }

    private static function acquire_number_lock(): bool {
        global $wpdb;
        $locked = $wpdb->get_var( "SELECT GET_LOCK('olama_reg_movement_number', 5)" );

        return (string) $locked === '1';
    }

    private static function release_number_lock(): void {
        global $wpdb;
        $wpdb->get_var( "SELECT RELEASE_LOCK('olama_reg_movement_number')" );
    }

    private static function generate_movement_no(): string {
        global $wpdb;

        $year = current_time( 'Y' );
        $base = 'MOV-' . $year . '-';
        $latest = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT movement_no
             FROM " . self::t( 'olama_cash_bank_movements' ) . "
             WHERE movement_no LIKE %s
             ORDER BY movement_no DESC
             LIMIT 1",
            $wpdb->esc_like( $base ) . '%'
        ) );

        $next = 1;
        if ( preg_match( '/^' . preg_quote( $base, '/' ) . '(\d+)$/', $latest, $matches ) ) {
            $next = (int) $matches[1] + 1;
        }

        return $base . str_pad( (string) $next, 5, '0', STR_PAD_LEFT );
    }
}
