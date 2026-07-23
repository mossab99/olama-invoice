<?php
/**
 * Payment recording and allocation against invoice installments.
 *
 * Tables used:
 *   {prefix}olama_payments
 *   {prefix}olama_invoice_installments
 *   {prefix}olama_invoices
 *   {prefix}olama_billing_audit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Billing_Payment {

    private static function t( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    // ── Record a payment ──────────────────────────────────────────────────────

    /**
     * Record a new payment and allocate it to installments.
     *
     * @param array $data {
     *   invoice_id, installment_id (optional), family_uid,
     *   payment_date, amount, method, reference, notes
     * }
     * @return int|WP_Error
     */
    public static function record( array $data ): int|\WP_Error {
        global $wpdb;

        $invoice_id = absint( $data['invoice_id'] ?? 0 );
        if ( ! $invoice_id ) {
            return new \WP_Error( 'missing_invoice', __( 'Invoice ID is required.', 'olama-registration' ) );
        }

        $invoice = Olama_Reg_Billing_Invoice::get_invoice( $invoice_id );
        if ( ! $invoice ) {
            return new \WP_Error( 'not_found', __( 'Invoice not found.', 'olama-registration' ) );
        }

        $amount = round( (float) ( $data['amount'] ?? 0 ), 2 );
        if ( $amount <= 0 ) {
            return new \WP_Error( 'invalid_amount', __( 'Payment amount must be greater than zero.', 'olama-registration' ) );
        }
        if ( $amount > round( (float) $invoice->balance, 2 ) ) {
            return new \WP_Error( 'overpayment', __( 'لا يمكن تسجيل دفعة أكبر من الرصيد المتبقي على الفاتورة.', 'olama-registration' ) );
        }

        $family_uid = (string) ( $invoice->family_uid ?? '' );

        $payment_date = '';
        if ( ! empty( $data['payment_date'] ) ) {
            $raw = sanitize_text_field( $data['payment_date'] );
            $payment_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : date( 'Y-m-d' );
        } else {
            $payment_date = date( 'Y-m-d' );
        }

        $valid_methods = [ 'cash', 'bank_transfer', 'cheque', 'online' ];
        $method = sanitize_text_field( $data['method'] ?? 'cash' );
        if ( ! in_array( $method, $valid_methods, true ) ) $method = 'cash';

        $account_id = absint( $data['account_id'] ?? 0 ) ?: Olama_Reg_Cash_Bank_Movement::get_default_account_id_for_method( $method );

        $policy = Olama_Reg_Payment_Policy::can_create_payment( $invoice, $method, $account_id ?: null );
        if ( is_wp_error( $policy ) ) {
            return $policy;
        }

        $default_status = 'posted';
        if ( $method === 'bank_transfer' && get_option( 'olama_bank_transfer_immediate_posting', '1' ) !== '1' ) {
            $default_status = 'pending_review';
        } elseif ( $method === 'online' && get_option( 'olama_epayment_immediate_posting', '1' ) !== '1' ) {
            $default_status = 'pending_review';
        } elseif ( $method === 'cheque' && get_option( 'olama_cheque_financial_effect', 'on_receive' ) === 'on_clear' ) {
            $default_status = 'pending_review';
        }

        $status = sanitize_key( $data['status'] ?? $default_status );
        if ( ! in_array( $status, [ 'draft', 'pending_review', 'posted', 'reversed', 'failed', 'cancelled' ], true ) ) {
            $status = $default_status;
        }
        if ( $method === 'cash' ) $status = 'posted';

        if ( ! $account_id ) {
            return new \WP_Error( 'missing_account', __( 'Please configure a default financial account before recording receipts.', 'olama-registration' ) );
        }

        $cash_session_id = absint( $data['cash_session_id'] ?? 0 ) ?: null;
        if ( $method === 'cash' && $cash_session_id ) {
            $session = Olama_Reg_Cash_Session::get( $cash_session_id );
            if ( ! $session || (string) $session->status !== 'open' ) {
                return new \WP_Error( 'cash_session_not_open', __( 'Cash receipts can only be linked to an open cash session.', 'olama-registration' ) );
            }
            if ( (int) $session->account_id !== (int) $account_id ) {
                return new \WP_Error( 'cash_session_account_mismatch', __( 'The selected cash session does not match the receipt account.', 'olama-registration' ) );
            }
            if ( (int) $session->cashier_id !== get_current_user_id() ) {
                return new \WP_Error( 'cash_session_cashier_mismatch', __( 'Cash receipts must be recorded in your own open cash session.', 'olama-registration' ) );
            }
            if ( $session->session_date !== $payment_date ) {
                return new \WP_Error( 'cash_session_date_mismatch', __( 'Cash receipt date must match the selected cash session date.', 'olama-registration' ) );
            }
        }

        $payload = [
            'payment_no'     => null,
            'account_id'     => $account_id ?: null,
            'cash_session_id'=> $cash_session_id,
            'invoice_id'     => $invoice_id,
            'installment_id' => absint( $data['installment_id'] ?? 0 ) ?: null,
            'payer_type'     => (string) ( $invoice->payer_type ?? ( $family_uid ? 'family' : 'customer' ) ),
            'family_uid'     => $family_uid,
            'oracle_family_id' => $invoice->oracle_family_id ?? null,
            'customer_id'    => isset( $invoice->customer_id ) ? (int) $invoice->customer_id : null,
            'payment_date'   => $payment_date,
            'amount'         => $amount,
            'method'         => $method,
            'status'         => $status,
            'oracle_sync_status' => 'not_required',
            'reference'      => sanitize_text_field( $data['reference'] ?? '' ) ?: null,
            'external_reference' => sanitize_text_field( $data['external_reference'] ?? '' ) ?: null,
            'received_by'    => get_current_user_id(),
            'notes'          => sanitize_textarea_field( $data['notes'] ?? '' ) ?: null,
            'admin_notes'    => sanitize_textarea_field( $data['admin_notes'] ?? '' ) ?: null,
            'posted_at'      => $status === 'posted' ? current_time( 'mysql' ) : null,
        ];

        // ── Transaction: insert payment + update totals ────────────────────────
        $wpdb->query( 'START TRANSACTION' );
        if ( ! self::acquire_number_lock() ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'number_lock_failed', __( 'Could not reserve a receipt number. Please try again.', 'olama-registration' ) );
        }

        $payload['payment_no'] = self::generate_document_no( 'REC', 'payment_no' );

        $result = $wpdb->insert( self::t( 'olama_payments' ), $payload );

        if ( ! $result ) {
            self::release_number_lock();
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'db_error', $wpdb->last_error );
        }

        $payment_id = (int) $wpdb->insert_id;

        $session_link = Olama_Reg_Cash_Session::attach_payment_to_open_session( $payment_id );
        if ( is_wp_error( $session_link ) ) {
            self::release_number_lock();
            $wpdb->query( 'ROLLBACK' );
            return $session_link;
        }

        $method_details = Olama_Reg_Payment_Method_Details::save_for_payment( $payment_id, $data );
        if ( is_wp_error( $method_details ) ) {
            self::release_number_lock();
            $wpdb->query( 'ROLLBACK' );
            return $method_details;
        }

        if ( $status === 'posted' ) {
            // Allocate to installments
            self::allocate_to_installments( $payment_id );

            // Recalculate invoice totals (also handles status auto-update)
            Olama_Reg_Billing_Invoice::recalculate_totals( $invoice_id );

            $movement = Olama_Reg_Cash_Bank_Movement::record_receipt_movement( $payment_id );
            if ( is_wp_error( $movement ) ) {
                self::release_number_lock();
                $wpdb->query( 'ROLLBACK' );
                return $movement;
            }
        }

        $wpdb->query( 'COMMIT' );
        self::release_number_lock();

        // Audit
        self::log_audit( 'payment', $payment_id, 'payment_created', null,
            self::get_payment_row( $payment_id ) );

        if ( class_exists( 'Olama_Reg_Family_Financial_Summary' ) && ! empty( $family_uid ) ) {
            Olama_Reg_Family_Financial_Summary::invalidate_snapshot( $family_uid, 0 );
        }

        return $payment_id;
    }

    /**
     * Reverse a payment by inserting a negative counterpart and recalculating installments.
     */
    public static function reverse( int $id, string $notes = '' ): int|\WP_Error {
        global $wpdb;

        $payment = self::get_payment_row( $id );
        if ( ! $payment ) {
            return new \WP_Error( 'not_found', __( 'Payment not found.', 'olama-registration' ) );
        }

        $policy = self::can_reverse_payment( $payment );
        if ( is_wp_error( $policy ) ) {
            return $policy;
        }

        // Check if already reversed
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . self::t( 'olama_payments' ) . " WHERE reference = %s",
            'REVERSAL-' . $id
        ) );
        if ( $exists ) {
            return new \WP_Error( 'already_reversed', __( 'السند معكوس مسبقاً.', 'olama-registration' ) );
        }

        $reversal_cash_session_id = null;
        if ( (string) $payment->method === 'cash' ) {
            $reversal_date = current_time( 'Y-m-d' );
            $account_id    = isset( $payment->account_id ) ? (int) $payment->account_id : 0;
            if ( ! $account_id ) {
                $account_id = Olama_Reg_Cash_Bank_Movement::get_default_account_id_for_method( 'cash' );
            }

            if ( $account_id ) {
                $open_session = Olama_Reg_Cash_Session::get_open_session( $account_id, get_current_user_id(), $reversal_date );
                if ( $open_session ) {
                    $reversal_cash_session_id = (int) $open_session->id;
                } elseif ( get_option( 'olama_require_cash_session', '0' ) === '1' ) {
                    return new \WP_Error( 'missing_cash_session', __( 'Cash receipt reversals require an open cash session for today.', 'olama-registration' ) );
                }
            }
        }

        $payload = [
            'payment_no'     => null,
            'account_id'     => isset( $payment->account_id ) ? (int) $payment->account_id : null,
            'cash_session_id'=> $reversal_cash_session_id,
            'invoice_id'     => $payment->invoice_id,
            'installment_id' => $payment->installment_id,
            'payer_type'     => $payment->payer_type,
            'family_uid'     => $payment->family_uid,
            'oracle_family_id' => $payment->oracle_family_id,
            'customer_id'    => $payment->customer_id,
            'payment_date'   => date( 'Y-m-d' ),
            'amount'         => -1 * (float) $payment->amount,
            'method'         => 'reversal',
            'status'         => 'posted',
            'oracle_sync_status' => 'not_required',
            'reference'      => 'REVERSAL-' . $id,
            'reversed_payment_id' => $id,
            'received_by'    => get_current_user_id(),
            'posted_at'      => current_time( 'mysql' ),
            'notes'          => sanitize_textarea_field( $notes ) ?: __( 'عكس سند قبض رقم', 'olama-registration' ) . ' #' . $id,
        ];

        $wpdb->query( 'START TRANSACTION' );
        if ( ! self::acquire_number_lock() ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'number_lock_failed', __( 'Could not reserve a receipt number. Please try again.', 'olama-registration' ) );
        }

        $payload['payment_no'] = self::generate_document_no( 'REC', 'payment_no' );

        $result = $wpdb->insert( self::t( 'olama_payments' ), $payload );
        if ( ! $result ) {
            self::release_number_lock();
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'db_error', $wpdb->last_error );
        }
        $new_payment_id = (int) $wpdb->insert_id;

        self::reverse_allocations( $id, $new_payment_id );

        $wpdb->update(
            self::t( 'olama_payments' ),
            [
                'status'      => 'reversed',
                'reversed_by' => get_current_user_id(),
                'reversed_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ]
        );

        // Recalculate invoice totals
        Olama_Reg_Billing_Invoice::recalculate_totals( (int) $payment->invoice_id );

        $movement = Olama_Reg_Cash_Bank_Movement::record_reversal_movement( $new_payment_id, $id );
        if ( is_wp_error( $movement ) ) {
            self::release_number_lock();
            $wpdb->query( 'ROLLBACK' );
            return $movement;
        }

        $wpdb->query( 'COMMIT' );
        self::release_number_lock();

        self::log_audit( 'payment', $id, 'payment_reversed', $payment, self::get_payment_row( $id ) );

        if ( class_exists( 'Olama_Reg_Family_Financial_Summary' ) && $payment && ! empty( $payment->family_uid ) ) {
            Olama_Reg_Family_Financial_Summary::invalidate_snapshot( $payment->family_uid, 0 );
        }

        return $new_payment_id;
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    public static function get_invoice_payments( int $invoice_id ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, u.display_name AS received_by_name
             FROM " . self::t( 'olama_payments' ) . " p
             LEFT JOIN {$wpdb->users} u ON u.ID = p.received_by
             WHERE p.invoice_id = %d
             ORDER BY p.payment_date ASC, p.id ASC",
            $invoice_id
        ) ) ?: [];
    }

    public static function get_family_payments( string $family_uid, int $year_id ): array {
        global $wpdb;

        $params      = [ $family_uid ];
        $year_clause = '';
        if ( $year_id > 0 ) {
            $year_clause = ' AND i.academic_year_id = %d';
            $params[]    = $year_id;
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, i.invoice_number, u.display_name AS received_by_name
             FROM " . self::t( 'olama_payments' ) . " p
             LEFT JOIN " . self::t( 'olama_invoices' ) . " i ON i.id = p.invoice_id
             LEFT JOIN {$wpdb->users} u ON u.ID = p.received_by
             WHERE p.family_uid = %s {$year_clause}
             ORDER BY p.payment_date DESC, p.id DESC",
            ...$params
        ) ) ?: [];
    }

    // ── Installment allocation ────────────────────────────────────────────────

    /**
     * Allocate a payment to the oldest unpaid installments first.
     * Carries over remainder to subsequent installments.
     */
    public static function allocate_to_installments( int $payment_id ): void {
        global $wpdb;

        $payment = self::get_payment_row( $payment_id );
        if ( ! $payment ) return;

        $remaining = (float) $payment->amount;
        if ( $remaining <= 0 ) return;

        $details = self::resolve_allocation_details( (int) $payment->invoice_id );

        // Get unpaid/partial installments ordered oldest first
        $installments = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_invoice_installments' ) . "
             WHERE invoice_id = %d
               AND status IN ('pending','unpaid','partial','partially_paid','overdue')
             ORDER BY installment_no ASC",
            (int) $payment->invoice_id
        ) );

        if ( empty( $installments ) ) {
            $wpdb->insert( self::t( 'olama_payment_allocations' ), [
                'payment_id'      => $payment_id,
                'invoice_id'      => (int) $payment->invoice_id,
                'installment_id'  => null,
                'amount'          => round( $remaining, 2 ),
                'allocation_date' => $payment->payment_date ?: date( 'Y-m-d' ),
                'type'            => 'normal',
                'student_uid'     => $details['student_uid'],
                'fee_category'    => $details['fee_category'],
                'created_by'      => get_current_user_id(),
            ] );
            return;
        }

        foreach ( $installments as $inst ) {
            if ( $remaining <= 0 ) break;

            $outstanding = (float) $inst->amount_due - (float) $inst->amount_paid;
            if ( $outstanding <= 0 ) continue;

            $apply = min( $remaining, $outstanding );
            $new_paid = round( (float) $inst->amount_paid + $apply, 2 );
            $remaining = round( $remaining - $apply, 2 );

            $new_status = 'partially_paid';
            if ( $new_paid >= (float) $inst->amount_due ) {
                $new_status = 'paid';
            } elseif ( ! empty( $inst->due_date ) && $inst->due_date < date( 'Y-m-d' ) ) {
                $new_status = 'overdue';
            }

            $wpdb->update(
                self::t( 'olama_invoice_installments' ),
                [
                    'amount_paid' => $new_paid,
                    'status'      => $new_status,
                ],
                [ 'id' => (int) $inst->id ]
            );

            $wpdb->insert( self::t( 'olama_payment_allocations' ), [
                'payment_id'      => $payment_id,
                'invoice_id'      => (int) $payment->invoice_id,
                'installment_id'  => (int) $inst->id,
                'amount'          => round( $apply, 2 ),
                'allocation_date' => $payment->payment_date ?: date( 'Y-m-d' ),
                'type'            => 'normal',
                'student_uid'     => $details['student_uid'],
                'fee_category'    => $details['fee_category'],
                'created_by'      => get_current_user_id(),
            ] );
        }
    }

    /**
     * Completely recalculates all installment allocations for an invoice.
     * Used when a payment is reversed to ensure accuracy.
     */
    public static function reallocate_all_installments( int $invoice_id ): void {
        global $wpdb;

        // 1. Reset all installments amount_paid = 0 and status based on due_date
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::t( 'olama_invoice_installments' ) . " 
             SET amount_paid = 0, 
                 status = IF(due_date < CURDATE(), 'overdue', 'unpaid')
             WHERE invoice_id = %d",
            $invoice_id
        ) );

        // 2. Get net sum of all payments for this invoice
        $net_paid = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(amount)
             FROM " . self::t( 'olama_payments' ) . "
             WHERE invoice_id = %d
               AND (status IS NULL OR status = '' OR status IN ('posted','reversed'))",
            $invoice_id
        ) );

        if ( $net_paid <= 0 ) return;

        // 3. Re-apply to installments in order
        $installments = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_invoice_installments' ) . "
             WHERE invoice_id = %d
             ORDER BY installment_no ASC",
            $invoice_id
        ) );

        $remaining = $net_paid;
        foreach ( $installments as $inst ) {
            if ( $remaining <= 0 ) break;

            $outstanding = (float) $inst->amount_due;
            if ( $outstanding <= 0 ) continue;

            $apply = min( $remaining, $outstanding );
            $new_paid = $apply;
            $remaining = round( $remaining - $apply, 2 );

            $new_status = 'partially_paid';
            if ( $new_paid >= (float) $inst->amount_due ) {
                $new_status = 'paid';
            } elseif ( ! empty( $inst->due_date ) && $inst->due_date < date( 'Y-m-d' ) ) {
                $new_status = 'overdue';
            }

            $wpdb->update(
                self::t( 'olama_invoice_installments' ),
                [
                    'amount_paid' => $new_paid,
                    'status'      => $new_status,
                ],
                [ 'id' => (int) $inst->id ]
            );
        }
    }

    public static function get_payment_allocations( int $payment_id ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_payment_allocations' ) . "
             WHERE payment_id = %d
             ORDER BY id ASC",
            $payment_id
        ) ) ?: [];
    }

    public static function can_reverse_payment( object $payment ): true|\WP_Error {
        global $wpdb;

        $policy = Olama_Reg_Payment_Policy::can_reverse_payment( $payment );
        if ( is_wp_error( $policy ) ) {
            return $policy;
        }

        if ( (float) $payment->amount <= 0 || $payment->method === 'reversal' ) {
            return new \WP_Error( 'already_reversed', __( 'لا يمكن عكس هذا السند.', 'olama-registration' ) );
        }

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . self::t( 'olama_payments' ) . " WHERE reference = %s OR reversed_payment_id = %d",
            'REVERSAL-' . (int) $payment->id,
            (int) $payment->id
        ) );
        if ( $exists ) {
            return new \WP_Error( 'already_reversed', __( 'السند معكوس مسبقاً.', 'olama-registration' ) );
        }

        return true;
    }

    public static function get_receipt_number( object $payment ): string {
        $payment_no = trim( (string) ( $payment->payment_no ?? '' ) );
        if ( $payment_no !== '' ) {
            return $payment_no;
        }

        return '#' . (int) ( $payment->id ?? 0 );
    }

    public static function get_status_label( object|string $payment ): string {
        $status = is_object( $payment ) ? (string) ( $payment->status ?? 'posted' ) : (string) $payment;
        return Olama_Reg_Status_Labels::label( $status, 'payment' );
    }

    private static function reverse_allocations( int $original_payment_id, int $reversal_payment_id ): void {
        global $wpdb;

        $original = self::get_payment_row( $original_payment_id );
        if ( ! $original ) return;

        $allocations = self::get_payment_allocations( $original_payment_id );
        if ( empty( $allocations ) ) {
            self::reallocate_all_installments( (int) $original->invoice_id );
            return;
        }

        foreach ( $allocations as $allocation ) {
            $amount = round( -1 * (float) $allocation->amount, 2 );

            if ( ! empty( $allocation->installment_id ) ) {
                $inst = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM " . self::t( 'olama_invoice_installments' ) . " WHERE id = %d",
                    (int) $allocation->installment_id
                ) );
                if ( $inst ) {
                    $new_paid = max( 0, round( (float) $inst->amount_paid + $amount, 2 ) );
                    $status = 'unpaid';
                    if ( $new_paid >= (float) $inst->amount_due ) {
                        $status = 'paid';
                    } elseif ( $new_paid > 0 ) {
                        $status = 'partially_paid';
                    } elseif ( ! empty( $inst->due_date ) && $inst->due_date < date( 'Y-m-d' ) ) {
                        $status = 'overdue';
                    }

                    $wpdb->update(
                        self::t( 'olama_invoice_installments' ),
                        [
                            'amount_paid' => $new_paid,
                            'status'      => $status,
                        ],
                        [ 'id' => (int) $inst->id ]
                    );
                }
            }

            $wpdb->insert( self::t( 'olama_payment_allocations' ), [
                'payment_id'                => $reversal_payment_id,
                'invoice_id'                => (int) $allocation->invoice_id,
                'installment_id'            => $allocation->installment_id ? (int) $allocation->installment_id : null,
                'amount'                    => $amount,
                'allocation_date'           => date( 'Y-m-d' ),
                'type'                      => 'reversal',
                'student_uid'               => $allocation->student_uid ?? null,
                'fee_category'              => $allocation->fee_category ?? null,
                'reversed_allocation_id'    => (int) $allocation->id,
                'created_by'                => get_current_user_id(),
            ] );
        }
    }

    // ── Receipt data ──────────────────────────────────────────────────────────

    /**
     * Build an array of receipt data for printing / display.
     */
    public static function generate_receipt_data( int $payment_id ): array {
        global $wpdb;

        $payment = self::get_payment_row( $payment_id );
        if ( ! $payment ) return [];

        $invoice = Olama_Reg_Billing_Invoice::get_invoice( (int) $payment->invoice_id );

        // Enrolled family profiles are read from Olama Core.
        $family = $payment->family_uid
            ? Olama_Reg_Family::get_family( (string) $payment->family_uid )
            : null;

        // Fallback: look up external customer when family_uid is a CUST- code
        $ext_customer_name = null;
        if ( ! $family && $payment->family_uid ) {
            // Check invoice for ext_customer_id first
            $ext_id = $invoice ? (int) ( $invoice->ext_customer_id ?? 0 ) : 0;

            // If not on invoice, try to find by customer_uid
            if ( ! $ext_id ) {
                $ext_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s LIMIT 1",
                    $payment->family_uid
                ) );
            }

            if ( $ext_id ) {
                $ext_customer_name = $wpdb->get_var( $wpdb->prepare(
                    "SELECT customer_name FROM {$wpdb->prefix}olama_customers WHERE id = %d",
                    $ext_id
                ) );
            }
        }

        $received_by_name = '';
        if ( $payment->received_by ) {
            $user = get_userdata( (int) $payment->received_by );
            $received_by_name = $user ? $user->display_name : '';
        }

        $account = null;
        if ( ! empty( $payment->account_id ) ) {
            $account = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM " . self::t( 'olama_financial_accounts' ) . " WHERE id = %d",
                (int) $payment->account_id
            ) );
        }

        $cash_session = null;
        if ( ! empty( $payment->cash_session_id ) ) {
            $cash_session = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM " . self::t( 'olama_cash_sessions' ) . " WHERE id = %d",
                (int) $payment->cash_session_id
            ) );
        }

        $method_details = Olama_Reg_Payment_Method_Details::get_payment_details( (int) $payment->id, (string) $payment->method );

        return [
            'payment'           => $payment,
            'invoice'           => $invoice,
            'family'            => $family,
            'account'           => $account,
            'cash_session'      => $cash_session,
            'method_details'    => $method_details,
            'ext_customer_name' => $ext_customer_name,
            'received_by_name'  => $received_by_name,
            'generated_at'      => current_time( 'mysql' ),
        ];
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private static function get_payment_row( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_payments' ) . " WHERE id = %d",
            $id
        ) ) ?: null;
    }

    private static function acquire_number_lock(): bool {
        global $wpdb;
        $locked = $wpdb->get_var( "SELECT GET_LOCK('olama_reg_payment_number', 5)" );

        return (string) $locked === '1';
    }

    private static function release_number_lock(): void {
        global $wpdb;
        $wpdb->get_var( "SELECT RELEASE_LOCK('olama_reg_payment_number')" );
    }

    private static function generate_document_no( string $prefix, string $column ): string {
        global $wpdb;

        $year = current_time( 'Y' );
        $base = $prefix . '-' . $year . '-';
        $latest = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT {$column}
             FROM " . self::t( 'olama_payments' ) . "
             WHERE {$column} LIKE %s
             ORDER BY {$column} DESC
             LIMIT 1",
            $wpdb->esc_like( $base ) . '%'
        ) );

        $next = 1;
        if ( preg_match( '/^' . preg_quote( $base, '/' ) . '(\d+)$/', $latest, $matches ) ) {
            $next = (int) $matches[1] + 1;
        }

        return $base . str_pad( (string) $next, 5, '0', STR_PAD_LEFT );
    }

    private static function log_audit(
        string  $entity_type,
        int     $entity_id,
        string  $action,
        ?object $before,
        ?object $after
    ): void {
        global $wpdb;

        $wpdb->insert( self::t( 'olama_billing_audit' ), [
            'entity_type'  => sanitize_text_field( $entity_type ),
            'entity_id'    => $entity_id,
            'action'       => sanitize_text_field( $action ),
            'actor_id'     => get_current_user_id(),
            'before_state' => $before ? wp_json_encode( $before ) : null,
            'after_state'  => $after  ? wp_json_encode( $after )  : null,
            'ip_address'   => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
        ] );
    }

    private static function resolve_allocation_details( int $invoice_id ): array {
        global $wpdb;
        
        $student_uid = null;
        $fee_category = null;
        
        // 1. Check invoice table first
        $invoice = $wpdb->get_row( $wpdb->prepare(
            "SELECT student_uid, ext_child_id, agreement_id 
             FROM {$wpdb->prefix}olama_invoices 
             WHERE id = %d",
            $invoice_id
        ) );
        
        if ( $invoice ) {
            if ( ! empty( $invoice->student_uid ) ) {
                $student_uid = $invoice->student_uid;
            } elseif ( ! empty( $invoice->ext_child_id ) ) {
                $student_uid = (string) $invoice->ext_child_id;
            }
        }
        
        // 2. Query agreement_fees for fee category and student fallback
        $fee = $wpdb->get_row( $wpdb->prepare(
            "SELECT child_id, fee_category FROM {$wpdb->prefix}olama_agreement_fees 
             WHERE invoice_id = %d LIMIT 1",
            $invoice_id
        ) );
        
        if ( $fee ) {
            $fee_category = $fee->fee_category;
            if ( empty( $student_uid ) && ! empty( $fee->child_id ) ) {
                $student_uid = $fee->child_id;
            }
        }
        
        // 3. Fallback: look at invoice items or default to general
        if ( empty( $fee_category ) ) {
            $item_desc = $wpdb->get_var( $wpdb->prepare(
                "SELECT description FROM {$wpdb->prefix}olama_invoice_items 
                 WHERE invoice_id = %d LIMIT 1",
                $invoice_id
            ) );
            if ( $item_desc ) {
                $fee_category = $item_desc;
            } else {
                $fee_category = 'general';
            }
        }
        
        return [
            'student_uid'  => $student_uid,
            'fee_category' => $fee_category
        ];
    }
}
