<?php
/**
 * Method-specific payment detail records.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Payment_Method_Details {

    private static function t( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public static function save_for_payment( int $payment_id, array $data = [] ): true|\WP_Error {
        $payment = self::get_payment( $payment_id );
        if ( ! $payment ) {
            return new \WP_Error( 'payment_not_found', __( 'Payment not found.', 'olama-registration' ) );
        }

        $method = (string) $payment->method;
        if ( $method === 'cheque' ) {
            return self::save_cheque( $payment, $data );
        }
        if ( $method === 'bank_transfer' ) {
            return self::save_bank_transfer( $payment, $data );
        }
        if ( $method === 'online' ) {
            return self::save_epayment( $payment, $data );
        }

        return true;
    }

    public static function confirm_payment( int $payment_id, string $notes = '' ): true|\WP_Error {
        global $wpdb;

        $payment = self::get_payment( $payment_id );
        if ( ! $payment ) {
            return new \WP_Error( 'payment_not_found', __( 'Payment not found.', 'olama-registration' ) );
        }

        $policy = Olama_Reg_Payment_Policy::can_confirm_payment( $payment );
        if ( is_wp_error( $policy ) ) {
            return $policy;
        }

        $wpdb->query( 'START TRANSACTION' );
        $locked_invoice = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status, balance, family_uid FROM " . self::t( 'olama_invoices' ) . " WHERE id = %d FOR UPDATE",
            (int) $payment->invoice_id
        ) );
        if ( ! $locked_invoice ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'invoice_not_found', __( 'Invoice not found.', 'olama-registration' ) );
        }
        if ( in_array( (string) $locked_invoice->status, [ 'cancelled', 'draft' ], true ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'invoice_not_payable', __( 'Payments cannot be confirmed for this invoice in its current status.', 'olama-registration' ) );
        }
        if ( (float) $payment->amount > round( (float) $locked_invoice->balance, 2 ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'overpayment_on_confirm', __( 'This payment is greater than the current invoice balance.', 'olama-registration' ) );
        }

        $locked_payment = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_payments' ) . " WHERE id = %d FOR UPDATE",
            $payment_id
        ) );
        if ( ! $locked_payment || ! in_array( (string) $locked_payment->status, [ 'draft', 'pending_review' ], true ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'payment_not_pending', __( 'Only a pending payment can be confirmed.', 'olama-registration' ) );
        }
        $updated = $wpdb->update(
            self::t( 'olama_payments' ),
            [
                'status'       => 'posted',
                'posted_at'    => current_time( 'mysql' ),
                'confirmed_by' => get_current_user_id(),
                'confirmed_at' => current_time( 'mysql' ),
                'admin_notes'  => sanitize_textarea_field( $notes ) ?: $payment->admin_notes,
            ],
            [ 'id' => $payment_id ]
        );
        if ( $updated === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'db_error', $wpdb->last_error );
        }

        self::mark_detail_status( $payment, 'confirmed' );
        Olama_Reg_Billing_Payment::allocate_to_installments( $payment_id );
        Olama_Reg_Billing_Invoice::recalculate_totals( (int) $payment->invoice_id );

        $movement = Olama_Reg_Cash_Bank_Movement::record_receipt_movement( $payment_id );
        if ( is_wp_error( $movement ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $movement;
        }

        $wpdb->query( 'COMMIT' );
        self::log_audit( 'payment', $payment_id, 'payment_confirmed', $payment, self::get_payment( $payment_id ) );

        if ( class_exists( 'Olama_Reg_Family_Financial_Summary' ) && ! empty( $locked_invoice->family_uid ) ) {
            Olama_Reg_Family_Financial_Summary::invalidate_snapshot( (string) $locked_invoice->family_uid, 0 );
        }

        return true;
    }

    public static function reject_payment( int $payment_id, string $notes = '' ): true|\WP_Error {
        global $wpdb;

        $payment = self::get_payment( $payment_id );
        if ( ! $payment ) {
            return new \WP_Error( 'payment_not_found', __( 'Payment not found.', 'olama-registration' ) );
        }

        $policy = Olama_Reg_Payment_Policy::can_reject_payment( $payment );
        if ( is_wp_error( $policy ) ) {
            return $policy;
        }

        $wpdb->query( 'START TRANSACTION' );
        $updated = $wpdb->update(
            self::t( 'olama_payments' ),
            [
                'status'      => 'cancelled',
                'admin_notes' => sanitize_textarea_field( $notes ) ?: $payment->admin_notes,
            ],
            [ 'id' => $payment_id ]
        );
        if ( $updated === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'db_error', $wpdb->last_error );
        }

        self::mark_detail_status( $payment, 'rejected' );
        $wpdb->query( 'COMMIT' );
        self::log_audit( 'payment', $payment_id, 'payment_rejected', $payment, self::get_payment( $payment_id ) );
        return true;
    }

    public static function transition_cheque( int $cheque_id, string $action, string $notes = '' ): true|\WP_Error {
        global $wpdb;

        $cheque = self::get_cheque( $cheque_id );
        if ( ! $cheque ) {
            return new \WP_Error( 'cheque_not_found', __( 'Cheque not found.', 'olama-registration' ) );
        }
        if ( ! Olama_Reg_Payment_Policy::current_user_can_any( [ 'olama_manage_cheques', 'olama_manage_registration_payments' ] ) ) {
            return new \WP_Error( 'unauthorized_cheque_action', __( 'You are not allowed to manage cheques.', 'olama-registration' ) );
        }

        $payment = self::get_payment( (int) $cheque->payment_id );
        if ( ! $payment ) {
            return new \WP_Error( 'payment_not_found', __( 'Payment not found.', 'olama-registration' ) );
        }

        if ( $action === 'deposit' ) {
            return self::update_cheque_status( $cheque, 'deposited', [ 'deposited_at' => current_time( 'mysql' ) ], $notes );
        }

        if ( $action === 'clear' ) {
            if ( (string) $payment->status === 'pending_review' ) {
                $confirmed = self::confirm_payment( (int) $payment->id, $notes );
                if ( is_wp_error( $confirmed ) ) {
                    return $confirmed;
                }
                $cheque = self::get_cheque( $cheque_id );
            }
            return self::update_cheque_status( $cheque, 'cleared', [ 'cleared_at' => current_time( 'mysql' ) ], $notes );
        }

        if ( $action === 'bounce' ) {
            if ( (string) $payment->status === 'posted' ) {
                $reversal = Olama_Reg_Billing_Payment::reverse( (int) $payment->id, $notes ?: __( 'Cheque bounced.', 'olama-registration' ) );
                if ( is_wp_error( $reversal ) ) {
                    return $reversal;
                }
            } elseif ( in_array( (string) $payment->status, [ 'draft', 'pending_review' ], true ) ) {
                $rejected = self::reject_payment( (int) $payment->id, $notes ?: __( 'Cheque bounced.', 'olama-registration' ) );
                if ( is_wp_error( $rejected ) ) {
                    return $rejected;
                }
            }
            $cheque = self::get_cheque( $cheque_id );
            return self::update_cheque_status( $cheque, 'bounced', [ 'bounced_at' => current_time( 'mysql' ) ], $notes );
        }

        if ( $action === 'cancel' ) {
            if ( ! in_array( (string) $payment->status, [ 'draft', 'pending_review', 'cancelled' ], true ) ) {
                return new \WP_Error( 'posted_cheque_cancel_forbidden', __( 'Posted cheques must be bounced or reversed, not cancelled.', 'olama-registration' ) );
            }
            if ( (string) $payment->status !== 'cancelled' ) {
                $rejected = self::reject_payment( (int) $payment->id, $notes ?: __( 'Cheque cancelled.', 'olama-registration' ) );
                if ( is_wp_error( $rejected ) ) {
                    return $rejected;
                }
            }
            $cheque = self::get_cheque( $cheque_id );
            return self::update_cheque_status( $cheque, 'cancelled', [], $notes );
        }

        return new \WP_Error( 'invalid_cheque_action', __( 'Invalid cheque action.', 'olama-registration' ) );
    }

    public static function get_pending_payments(): array {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT p.*, i.invoice_number, a.account_name, u.display_name AS received_by_name
             FROM " . self::t( 'olama_payments' ) . " p
             LEFT JOIN " . self::t( 'olama_invoices' ) . " i ON i.id = p.invoice_id
             LEFT JOIN " . self::t( 'olama_financial_accounts' ) . " a ON a.id = p.account_id
             LEFT JOIN {$wpdb->users} u ON u.ID = p.received_by
             WHERE p.status = 'pending_review'
             ORDER BY p.payment_date ASC, p.id ASC"
        ) ?: [];
    }

    public static function get_payment_details( int $payment_id, string $method ): ?object {
        global $wpdb;

        $table = null;
        if ( $method === 'cheque' ) {
            $table = self::t( 'olama_cheques' );
        } elseif ( $method === 'bank_transfer' ) {
            $table = self::t( 'olama_bank_transfer_details' );
        } elseif ( $method === 'online' ) {
            $table = self::t( 'olama_epayment_details' );
        }

        if ( ! $table ) {
            return null;
        }

        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE payment_id = %d ORDER BY id DESC LIMIT 1", $payment_id ) ) ?: null;
    }

    private static function save_cheque( object $payment, array $data ): true|\WP_Error {
        global $wpdb;

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . self::t( 'olama_cheques' ) . " WHERE payment_id = %d", (int) $payment->id ) );
        if ( $exists ) return true;

        $result = $wpdb->insert( self::t( 'olama_cheques' ), [
            'payment_id'  => (int) $payment->id,
            'check_no'    => sanitize_text_field( $data['check_no'] ?? $data['cheque_no'] ?? $payment->reference ?? '' ),
            'bank_name'   => sanitize_text_field( $data['bank_name'] ?? '' ) ?: null,
            'branch_name' => sanitize_text_field( $data['branch_name'] ?? '' ) ?: null,
            'check_date'  => self::date_or_null( $data['check_date'] ?? $payment->payment_date ?? '' ),
            'due_date'    => self::date_or_null( $data['due_date'] ?? '' ),
            'amount'      => abs( (float) $payment->amount ),
            'status'      => 'received',
            'notes'       => sanitize_textarea_field( $data['notes'] ?? '' ) ?: null,
        ] );

        return $result ? true : new \WP_Error( 'db_error', $wpdb->last_error );
    }

    private static function save_bank_transfer( object $payment, array $data ): true|\WP_Error {
        global $wpdb;

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . self::t( 'olama_bank_transfer_details' ) . " WHERE payment_id = %d", (int) $payment->id ) );
        if ( $exists ) return true;

        $result = $wpdb->insert( self::t( 'olama_bank_transfer_details' ), [
            'payment_id'          => (int) $payment->id,
            'bank_account_id'     => (int) ( $payment->account_id ?? 0 ) ?: null,
            'transfer_reference'  => sanitize_text_field( $data['transfer_reference'] ?? $payment->reference ?? '' ),
            'transfer_date'       => self::date_or_null( $data['transfer_date'] ?? $payment->payment_date ?? '' ),
            'sender_name'         => sanitize_text_field( $data['sender_name'] ?? '' ) ?: null,
            'attachment_id'       => absint( $data['attachment_id'] ?? 0 ) ?: null,
            'status'              => (string) ( $payment->status ?? 'posted' ) === 'pending_review' ? 'pending_review' : 'confirmed',
            'confirmed_by'        => (string) ( $payment->status ?? 'posted' ) === 'posted' ? get_current_user_id() : null,
            'confirmed_at'        => (string) ( $payment->status ?? 'posted' ) === 'posted' ? current_time( 'mysql' ) : null,
            'notes'               => sanitize_textarea_field( $data['notes'] ?? '' ) ?: null,
        ] );

        return $result ? true : new \WP_Error( 'db_error', $wpdb->last_error );
    }

    private static function save_epayment( object $payment, array $data ): true|\WP_Error {
        global $wpdb;

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . self::t( 'olama_epayment_details' ) . " WHERE payment_id = %d", (int) $payment->id ) );
        if ( $exists ) return true;

        $gross = abs( (float) ( $data['gross_amount'] ?? $payment->amount ) );
        $fee = abs( (float) ( $data['fee_amount'] ?? 0 ) );
        $result = $wpdb->insert( self::t( 'olama_epayment_details' ), [
            'payment_id'        => (int) $payment->id,
            'provider'          => sanitize_text_field( $data['provider'] ?? '' ) ?: null,
            'transaction_id'    => sanitize_text_field( $data['transaction_id'] ?? $payment->reference ?? '' ) ?: null,
            'gateway_reference' => sanitize_text_field( $data['gateway_reference'] ?? $payment->external_reference ?? '' ) ?: null,
            'gross_amount'      => $gross,
            'fee_amount'        => $fee,
            'net_amount'        => round( $gross - $fee, 2 ),
            'status'            => (string) ( $payment->status ?? 'posted' ) === 'pending_review' ? 'pending' : 'confirmed',
            'confirmed_at'      => (string) ( $payment->status ?? 'posted' ) === 'posted' ? current_time( 'mysql' ) : null,
            'raw_payload'       => ! empty( $data['raw_payload'] ) ? wp_json_encode( $data['raw_payload'] ) : null,
        ] );

        return $result ? true : new \WP_Error( 'db_error', $wpdb->last_error );
    }

    private static function mark_detail_status( object $payment, string $status ): void {
        global $wpdb;

        if ( $payment->method === 'bank_transfer' ) {
            $wpdb->update( self::t( 'olama_bank_transfer_details' ), [
                'status'       => $status,
                'confirmed_by' => $status === 'confirmed' ? get_current_user_id() : null,
                'confirmed_at' => $status === 'confirmed' ? current_time( 'mysql' ) : null,
                'rejected_by'  => $status === 'rejected' ? get_current_user_id() : null,
                'rejected_at'  => $status === 'rejected' ? current_time( 'mysql' ) : null,
            ], [ 'payment_id' => (int) $payment->id ] );
        } elseif ( $payment->method === 'online' ) {
            $wpdb->update( self::t( 'olama_epayment_details' ), [
                'status'       => $status === 'rejected' ? 'failed' : 'confirmed',
                'confirmed_at' => $status === 'confirmed' ? current_time( 'mysql' ) : null,
                'failed_at'    => $status === 'rejected' ? current_time( 'mysql' ) : null,
            ], [ 'payment_id' => (int) $payment->id ] );
        } elseif ( $payment->method === 'cheque' ) {
            $wpdb->update( self::t( 'olama_cheques' ), [
                'status'     => $status === 'rejected' ? 'cancelled' : 'cleared',
                'cleared_at' => $status === 'confirmed' ? current_time( 'mysql' ) : null,
            ], [ 'payment_id' => (int) $payment->id ] );
        }
    }

    private static function update_cheque_status( ?object $cheque, string $status, array $extra = [], string $notes = '' ): true|\WP_Error {
        global $wpdb;

        if ( ! $cheque ) {
            return new \WP_Error( 'cheque_not_found', __( 'Cheque not found.', 'olama-registration' ) );
        }

        $payload = array_merge( [
            'status' => $status,
            'notes'  => sanitize_textarea_field( $notes ) ?: $cheque->notes,
        ], $extra );

        $updated = $wpdb->update( self::t( 'olama_cheques' ), $payload, [ 'id' => (int) $cheque->id ] );
        if ( $updated === false ) {
            return new \WP_Error( 'db_error', $wpdb->last_error );
        }

        self::log_audit( 'cheque', (int) $cheque->id, 'cheque_' . $status, $cheque, self::get_cheque( (int) $cheque->id ) );
        return true;
    }

    private static function get_cheque( int $cheque_id ): ?object {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_cheques' ) . " WHERE id = %d",
            $cheque_id
        ) ) ?: null;
    }

    private static function get_payment( int $payment_id ): ?object {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_payments' ) . " WHERE id = %d",
            $payment_id
        ) ) ?: null;
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

    private static function date_or_null( string $date ): ?string {
        $date = sanitize_text_field( $date );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : null;
    }
}
