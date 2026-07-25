<?php
/**
 * Central payment policy checks.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Payment_Policy {

    public static function can_create_payment( object $invoice, string $method = 'cash', ?int $account_id = null ): true|\WP_Error {
        if ( ! self::current_user_can_any( [ 'olama_record_payments' ] ) ) {
            return new \WP_Error( 'unauthorized_payment_action', __( 'You are not allowed to record payments.', 'olama-registration' ) );
        }

        $invoice_policy = Olama_Reg_Billing_Invoice::can_record_payment( $invoice );
        if ( is_wp_error( $invoice_policy ) ) {
            return $invoice_policy;
        }

        $valid_methods = [ 'cash', 'bank_transfer', 'cheque', 'online' ];
        if ( ! in_array( $method, $valid_methods, true ) ) {
            return new \WP_Error( 'invalid_payment_method', __( 'Invalid payment method.', 'olama-registration' ) );
        }

        if ( $account_id ) {
            $account = self::get_account( $account_id );
            if ( ! $account ) {
                return new \WP_Error( 'invalid_account', __( 'Please select an active financial account.', 'olama-registration' ) );
            }

            $expected = [
                'cash'          => [ 'cash' ],
                'bank_transfer' => [ 'bank' ],
                'cheque'        => [ 'cheque_clearing', 'bank' ],
                'online'        => [ 'electronic' ],
            ];
            if ( ! in_array( (string) $account->type, $expected[ $method ], true ) ) {
                return new \WP_Error( 'account_method_mismatch', __( 'The selected account does not match the payment method.', 'olama-registration' ) );
            }
        }

        return true;
    }

    public static function can_view_payment( object $payment ): true|\WP_Error {
        return true;
    }

    public static function can_print_payment( object $payment ): true|\WP_Error {
        return true;
    }

    public static function can_update_payment_admin_fields( object $payment ): true|\WP_Error {
        if ( self::is_reversal_row( $payment ) ) {
            return new \WP_Error( 'payment_locked', __( 'Reversal receipts are locked.', 'olama-registration' ) );
        }

        return true;
    }

    public static function can_update_payment_financial_fields( object $payment ): true|\WP_Error {
        return new \WP_Error(
            'payment_financial_locked',
            __( 'Posted receipt vouchers cannot be financially edited. Please reverse the receipt and issue a new one.', 'olama-registration' )
        );
    }

    public static function can_reverse_payment( object $payment ): true|\WP_Error {
        if ( ! self::current_user_can_any( [ 'olama_reverse_payments' ] ) ) {
            return new \WP_Error( 'unauthorized_payment_action', __( 'You are not allowed to reverse receipts.', 'olama-registration' ) );
        }

        if ( self::is_reversal_row( $payment ) || (float) ( $payment->amount ?? 0 ) <= 0 ) {
            return new \WP_Error( 'already_reversed', __( 'This receipt cannot be reversed.', 'olama-registration' ) );
        }

        $status = (string) ( $payment->status ?? 'posted' );
        if ( $status === 'reversed' ) {
            return new \WP_Error( 'already_reversed', __( 'This receipt has already been reversed.', 'olama-registration' ) );
        }

        if ( ! in_array( $status, [ '', 'posted' ], true ) ) {
            return new \WP_Error( 'payment_not_posted', __( 'Only posted receipts can be reversed.', 'olama-registration' ) );
        }

        return true;
    }

    public static function can_confirm_payment( object $payment ): true|\WP_Error {
        if ( ! self::current_user_can_any( [ 'olama_confirm_bank_payments' ] ) ) {
            return new \WP_Error( 'unauthorized_payment_action', __( 'You are not allowed to confirm pending payments.', 'olama-registration' ) );
        }

        $status = (string) ( $payment->status ?? '' );
        if ( ! in_array( $status, [ 'draft', 'pending_review' ], true ) ) {
            return new \WP_Error( 'payment_not_pending', __( 'Only pending receipts can be confirmed.', 'olama-registration' ) );
        }

        return true;
    }

    public static function can_reject_payment( object $payment ): true|\WP_Error {
        return self::can_confirm_payment( $payment );
    }

    public static function can_open_cash_session( ?int $account_id, ?int $cashier_id ): true|\WP_Error {
        if ( ! self::current_user_can_any( [ 'olama_open_cash_session' ] ) ) {
            return new \WP_Error( 'unauthorized_cash_session_action', __( 'You are not allowed to open cash sessions.', 'olama-registration' ) );
        }

        return true;
    }

    public static function can_close_cash_session( object $session ): true|\WP_Error {
        if ( ! self::current_user_can_any( [ 'olama_close_cash_session' ] ) ) {
            return new \WP_Error( 'unauthorized_cash_session_action', __( 'You are not allowed to close cash sessions.', 'olama-registration' ) );
        }

        if ( (int) ( $session->cashier_id ?? 0 ) !== get_current_user_id()
            && ! self::current_user_can_any( [ 'olama_review_cash_session' ] )
        ) {
            return new \WP_Error( 'not_session_cashier', __( 'Only the cashier or a reviewer can close this cash session.', 'olama-registration' ) );
        }

        return true;
    }

    public static function can_review_cash_session( object $session ): true|\WP_Error {
        if ( ! self::current_user_can_any( [ 'olama_review_cash_session' ] ) ) {
            return new \WP_Error( 'unauthorized_cash_session_action', __( 'You are not allowed to review cash sessions.', 'olama-registration' ) );
        }

        return true;
    }

    public static function can_transfer_between_accounts( ?int $from_account_id, ?int $to_account_id ): true|\WP_Error {
        if ( ! self::current_user_can_any( [ 'olama_transfer_cash_bank' ] ) ) {
            return new \WP_Error( 'unauthorized_transfer_action', __( 'You are not allowed to transfer cash or bank balances.', 'olama-registration' ) );
        }

        return true;
    }

    public static function current_user_can_any( array $caps ): bool {
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        foreach ( $caps as $cap ) {
            if ( current_user_can( $cap ) ) {
                return true;
            }
        }

        return false;
    }

    private static function get_account( int $account_id ): ?object {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_financial_accounts WHERE id = %d AND is_active = 1",
            $account_id
        ) ) ?: null;
    }

    private static function is_reversal_row( object $payment ): bool {
        return (string) ( $payment->method ?? '' ) === 'reversal'
            || (float) ( $payment->amount ?? 0 ) < 0
            || ! empty( $payment->reversed_payment_id );
    }
}
