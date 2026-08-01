<?php
/**
 * Financial account management for cashboxes, banks, cheques, and e-payments.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Financial_Account {

    private static function t( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public static function all( bool $include_inactive = true ): array {
        global $wpdb;

        $where = $include_inactive ? '1=1' : 'is_active = 1';
        return $wpdb->get_results(
            "SELECT * FROM " . self::t( 'olama_financial_accounts' ) . "
             WHERE {$where}
             ORDER BY type ASC, is_default DESC, account_name ASC"
        ) ?: [];
    }

    public static function get( int $id ): ?object {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_financial_accounts' ) . " WHERE id = %d",
            $id
        ) ) ?: null;
    }

    public static function save( array $data ): int|\WP_Error {
        global $wpdb;

        $id = absint( $data['id'] ?? 0 );
        $type = sanitize_key( $data['type'] ?? '' );
        $valid_types = [ 'cash', 'bank', 'cheque_clearing', 'electronic' ];
        if ( ! in_array( $type, $valid_types, true ) ) {
            return new \WP_Error( 'invalid_account_type', __( 'Invalid financial account type.', 'olama-registration' ) );
        }

        $account_code = strtoupper( sanitize_text_field( $data['account_code'] ?? '' ) );
        $account_name = sanitize_text_field( $data['account_name'] ?? '' );
        if ( $account_code === '' || $account_name === '' ) {
            return new \WP_Error( 'missing_account_data', __( 'Account code and name are required.', 'olama-registration' ) );
        }

        $is_default = ! empty( $data['is_default'] ) ? 1 : 0;
        $payload = [
            'account_code'    => $account_code,
            'account_name'    => $account_name,
            'type'            => $type,
            'currency'        => strtoupper( sanitize_text_field( $data['currency'] ?? 'JOD' ) ) ?: 'JOD',
            'is_default'      => $is_default,
            'is_active'       => ! empty( $data['is_active'] ) ? 1 : 0,
            'opening_balance' => round( (float) ( $data['opening_balance'] ?? 0 ), 2 ),
            'notes'           => sanitize_textarea_field( $data['notes'] ?? '' ) ?: null,
        ];

        if ( $type === 'cash' && $payload['opening_balance'] < 0 ) {
            return new \WP_Error( 'negative_cash_opening_balance', __( 'A cash account cannot have a negative opening balance.', 'olama-registration' ) );
        }

        if ( $id > 0 ) {
            $existing = self::get( $id );
            if ( ! $existing ) {
                return new \WP_Error( 'account_not_found', __( 'Financial account not found.', 'olama-registration' ) );
            }
            $has_activity = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT (
                    (SELECT COUNT(*) FROM " . self::t( 'olama_cash_movements' ) . " WHERE account_id = %d) +
                    (SELECT COUNT(*) FROM " . self::t( 'olama_payments' ) . " WHERE account_id = %d) +
                    (SELECT COUNT(*) FROM " . self::t( 'olama_cash_sessions' ) . " WHERE account_id = %d)
                )",
                $id,
                $id,
                $id
            ) ) > 0;
            if ( $has_activity && (
                $payload['type'] !== (string) $existing->type
                || $payload['currency'] !== (string) $existing->currency
                || abs( $payload['opening_balance'] - (float) $existing->opening_balance ) > 0.009
            ) ) {
                return new \WP_Error(
                    'account_nature_locked',
                    __( 'Account type, currency, and opening balance cannot be changed after financial activity exists.', 'olama-registration' )
                );
            }
        }

        $duplicate = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . self::t( 'olama_financial_accounts' ) . "
             WHERE account_code = %s AND id <> %d LIMIT 1",
            $account_code,
            $id
        ) );
        if ( $duplicate ) {
            return new \WP_Error( 'duplicate_account_code', __( 'This account code is already used.', 'olama-registration' ) );
        }

        $wpdb->query( 'START TRANSACTION' );
        if ( $is_default ) {
            $wpdb->update(
                self::t( 'olama_financial_accounts' ),
                [ 'is_default' => 0 ],
                [ 'type' => $type ]
            );
        }

        if ( $id > 0 ) {
            $result = $wpdb->update( self::t( 'olama_financial_accounts' ), $payload, [ 'id' => $id ] );
            if ( $result === false ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'db_error', $wpdb->last_error );
            }
        } else {
            $payload['created_by'] = get_current_user_id();
            $result = $wpdb->insert( self::t( 'olama_financial_accounts' ), $payload );
            if ( ! $result ) {
                $wpdb->query( 'ROLLBACK' );
                return new \WP_Error( 'db_error', $wpdb->last_error );
            }
            $id = (int) $wpdb->insert_id;
        }

        $wpdb->query( 'COMMIT' );
        self::log_audit( 'financial_account', $id, 'financial_account_saved', null, self::get( $id ) );

        return $id;
    }

    public static function set_active( int $id, bool $active ): true|\WP_Error {
        global $wpdb;

        $account = self::get( $id );
        if ( ! $account ) {
            return new \WP_Error( 'account_not_found', __( 'Financial account not found.', 'olama-registration' ) );
        }

        if ( ! $active && (int) $account->is_default === 1 ) {
            return new \WP_Error( 'default_account_locked', __( 'Default accounts must stay active. Choose another default first.', 'olama-registration' ) );
        }

        $updated = $wpdb->update(
            self::t( 'olama_financial_accounts' ),
            [ 'is_active' => $active ? 1 : 0 ],
            [ 'id' => $id ]
        );
        if ( $updated === false ) {
            return new \WP_Error( 'db_error', $wpdb->last_error );
        }

        self::log_audit( 'financial_account', $id, $active ? 'financial_account_activated' : 'financial_account_deactivated', $account, self::get( $id ) );
        return true;
    }

    public static function type_labels(): array {
        return [
            'cash'             => __( 'صندوق نقدي', 'olama-registration' ),
            'bank'             => __( 'حساب بنك', 'olama-registration' ),
            'cheque_clearing'  => __( 'شيكات تحت التحصيل', 'olama-registration' ),
            'electronic'       => __( 'دفع إلكتروني', 'olama-registration' ),
        ];
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
