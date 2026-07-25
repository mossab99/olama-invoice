<?php
/**
 * Settlement Receipts Logic
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Settlement {

    private static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'olama_settlement_receipts';
    }

    /**
     * Create a new Settlement Receipt
     *
     * @param array $data
     * @return int|WP_Error
     */
    public static function create_receipt( array $data ) {
        global $wpdb;
        $table = self::get_table_name();

        $family_id        = sanitize_text_field( $data['family_id'] ?? '' );
        $student_id       = sanitize_text_field( $data['student_id'] ?? '' );
        $payment_category = sanitize_text_field( $data['payment_category'] ?? '' );
        $original_amount  = (float) ( $data['original_amount'] ?? 0 );
        $payment_method   = sanitize_text_field( $data['payment_method'] ?? 'cash' );
        $notes            = sanitize_textarea_field( $data['notes'] ?? '' );

        if ( ! $family_id || ! $payment_category || $original_amount <= 0 ) {
            return new WP_Error( 'invalid_data', __( 'Family, Category, and positive amount are required.', 'olama-registration' ) );
        }

        $current_user_id = get_current_user_id();

        // Generate a receipt number: SR-YYYYMMDD-XXXX
        $date_prefix = date('Ymd');
        $random_suffix = strtoupper( substr( uniqid(), -4 ) );
        $receipt_number = "SR-{$date_prefix}-{$random_suffix}";

        $insert_data = [
            'receipt_number'    => $receipt_number,
            'family_id'         => $family_id,
            'student_id'        => $student_id ?: null,
            'payment_category'  => $payment_category,
            'original_amount'   => $original_amount,
            'settled_amount'    => 0,
            'remaining_balance' => $original_amount,
            'payment_method'    => $payment_method,
            'status'            => 'pending_settlement',
            'notes'             => $notes,
            'created_by'        => $current_user_id,
            'updated_by'        => $current_user_id,
        ];

        $format = [
            '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%d', '%d'
        ];

        $inserted = $wpdb->insert( $table, $insert_data, $format );

        if ( ! $inserted ) {
            return new WP_Error( 'db_error', __( 'Could not insert settlement receipt.', 'olama-registration' ) );
        }

        return $wpdb->insert_id;
    }

    /**
     * Settle an existing receipt
     *
     * @param int $id
     * @param string $oracle_receipt_number
     * @param string $settlement_notes
     * @return bool|WP_Error
     */
    public static function settle_receipt( int $id, string $oracle_receipt_number, string $settlement_notes = '' ) {
        global $wpdb;
        $table = self::get_table_name();

        $receipt = self::get_receipt( $id );
        if ( ! $receipt ) {
            return new WP_Error( 'not_found', __( 'Receipt not found.', 'olama-registration' ) );
        }

        if ( $receipt->status !== 'pending_settlement' ) {
            return new WP_Error( 'invalid_status', __( 'Receipt is not in a pending state.', 'olama-registration' ) );
        }

        if ( empty( $oracle_receipt_number ) ) {
            return new WP_Error( 'invalid_data', __( 'Oracle receipt number is required.', 'olama-registration' ) );
        }

        $current_user_id = get_current_user_id();

        $update_data = [
            'oracle_receipt_number' => sanitize_text_field( $oracle_receipt_number ),
            'settled_amount'        => $receipt->original_amount,
            'remaining_balance'     => 0,
            'status'                => 'settled',
            'settlement_date'       => date('Y-m-d'),
            'settled_by'            => $current_user_id,
            'updated_by'            => $current_user_id,
        ];

        if ( ! empty( $settlement_notes ) ) {
            $update_data['notes'] = $receipt->notes . "\n--- Settlement Notes ---\n" . sanitize_textarea_field( $settlement_notes );
        }

        $format = [ '%s', '%f', '%f', '%s', '%s', '%d', '%d' ];
        if ( isset($update_data['notes']) ) {
            $format[] = '%s';
        }

        $updated = $wpdb->update( $table, $update_data, [ 'id' => $id ], $format, [ '%d' ] );

        if ( $updated === false ) {
            return new WP_Error( 'db_error', __( 'Could not update receipt.', 'olama-registration' ) );
        }

        return true;
    }

    /**
     * Cancel a receipt
     *
     * @param int $id
     * @return bool|WP_Error
     */
    public static function cancel_receipt( int $id ) {
        global $wpdb;
        $table = self::get_table_name();

        $receipt = self::get_receipt( $id );
        if ( ! $receipt ) {
            return new WP_Error( 'not_found', __( 'Receipt not found.', 'olama-registration' ) );
        }

        if ( $receipt->status === 'settled' ) {
            return new WP_Error( 'invalid_status', __( 'Cannot cancel a settled receipt.', 'olama-registration' ) );
        }

        $current_user_id = get_current_user_id();

        $updated = $wpdb->update(
            $table,
            [
                'status'       => 'cancelled',
                'cancelled_by' => $current_user_id,
                'updated_by'   => $current_user_id,
            ],
            [ 'id' => $id ],
            [ '%s', '%d', '%d' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            return new WP_Error( 'db_error', __( 'Could not cancel receipt.', 'olama-registration' ) );
        }

        return true;
    }

    /**
     * Get a specific receipt
     *
     * @param int $id
     * @return object|null
     */
    public static function get_receipt( int $id ) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    /**
     * Get receipts with filters
     *
     * @param array $args
     * @return array
     */
    public static function get_receipts( array $args = [] ): array {
        global $wpdb;
        $table = self::get_table_name();

        $where = [ '1=1' ];
        $values = [];

        if ( ! empty( $args['status'] ) ) {
            $where[] = "r.status = %s";
            $values[] = $args['status'];
        }

        if ( ! empty( $args['family_id'] ) ) {
            $where[] = "r.family_id = %s";
            $values[] = $args['family_id'];
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[] = "r.created_at >= %s";
            $values[] = $args['date_from'] . ' 00:00:00';
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[] = "r.created_at <= %s";
            $values[] = $args['date_to'] . ' 23:59:59';
        }

        $where_sql = implode( ' AND ', $where );
        
        $order_by = "ORDER BY r.id DESC";

        $sql = "SELECT r.*, COALESCE(f.sponsor_full_name, f.father_name, f.mother_name, r.family_id) AS father_first_name, '' AS father_family_name
                FROM {$table} r
                LEFT JOIN {$wpdb->prefix}olama_core_families f
                    ON f.family_uid = COALESCE(NULLIF(r.family_uid, ''), r.family_id)
                    OR f.oracle_family_id = COALESCE(NULLIF(r.oracle_family_id, ''), r.family_id)
                WHERE {$where_sql} {$order_by}";

        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare( $sql, ...$values );
        }

        return $wpdb->get_results( $sql );
    }
}
