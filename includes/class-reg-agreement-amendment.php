<?php
/**
 * Controlled agreement amendment workflow.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Olama_Reg_Agreement_Amendment {

    private static function t( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public static function generate_amendment_number(): string {
        global $wpdb;

        $year = current_time( 'Y' );
        $prefix = 'AMD-' . $year . '-';
        $last = $wpdb->get_var( $wpdb->prepare(
            "SELECT amendment_no FROM " . self::t( 'olama_agreement_amendments' ) . "
             WHERE amendment_no LIKE %s
             ORDER BY id DESC
             LIMIT 1",
            $prefix . '%'
        ) );

        $next = 1;
        if ( $last && preg_match( '/(\d+)$/', (string) $last, $m ) ) {
            $next = (int) $m[1] + 1;
        }

        return $prefix . str_pad( (string) $next, 5, '0', STR_PAD_LEFT );
    }

    public static function create( int $agreement_id, array $data ): int|\WP_Error {
        global $wpdb;

        if ( class_exists( 'Olama_Reg_Agreement_Policy' ) ) {
            $allowed = Olama_Reg_Agreement_Policy::can_create_amendment( $agreement_id );
            if ( is_wp_error( $allowed ) ) {
                return $allowed;
            }
        }

        $agreement = class_exists( 'Olama_Reg_Agreement' ) ? Olama_Reg_Agreement::get( $agreement_id ) : null;
        if ( ! $agreement ) {
            return new \WP_Error( 'agreement_not_found', __( 'العقد غير موجود.', 'olama-registration' ) );
        }

        $reason = trim( sanitize_textarea_field( $data['reason'] ?? '' ) );
        if ( $reason === '' ) {
            return new \WP_Error( 'reason_required', __( 'سبب التعديل مطلوب.', 'olama-registration' ) );
        }

        $old_total = isset( $data['old_total'] ) ? round( (float) $data['old_total'], 3 ) : round( (float) $agreement->total_amount, 3 );
        $new_total = isset( $data['new_total'] ) ? round( (float) $data['new_total'], 3 ) : $old_total;
        $diff = round( $new_total - $old_total, 3 );
        $invoice_id = class_exists( 'Olama_Reg_Agreement_Policy' ) ? Olama_Reg_Agreement_Policy::get_linked_invoice_id( $agreement_id ) : self::get_invoice_id( $agreement_id );
        $before = self::snapshot_agreement( $agreement_id );
        $after = $data['after_snapshot'] ?? $before;
        if ( isset( $after['agreement'] ) && is_array( $after['agreement'] ) ) {
            $after['agreement']['total_amount'] = $new_total;
        }

        $inserted = $wpdb->insert(
            self::t( 'olama_agreement_amendments' ),
            [
                'agreement_id'      => $agreement_id,
                'invoice_id'        => $invoice_id ?: null,
                'amendment_no'      => self::generate_amendment_number(),
                'amendment_type'    => sanitize_key( $data['amendment_type'] ?? 'correction_error' ),
                'status'            => 'draft',
                'effective_date'    => self::sanitize_date( $data['effective_date'] ?? current_time( 'Y-m-d' ) ),
                'old_total'         => $old_total,
                'new_total'         => $new_total,
                'difference_amount' => $diff,
                'reason'            => $reason,
                'admin_notes'       => sanitize_textarea_field( $data['admin_notes'] ?? $data['notes'] ?? '' ),
                'before_snapshot'   => wp_json_encode( $before ),
                'after_snapshot'    => wp_json_encode( $after ),
                'created_by'        => get_current_user_id(),
                'created_at'        => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        if ( ! $inserted ) {
            return new \WP_Error( 'amendment_create_failed', __( 'تعذر إنشاء مسودة تعديل العقد.', 'olama-registration' ) );
        }

        $amendment_id = (int) $wpdb->insert_id;
        self::write_audit( 'agreement_amendment_created', $amendment_id, null, self::get( $amendment_id ) );

        foreach ( (array) ( $data['lines'] ?? [] ) as $line ) {
            self::add_line( $amendment_id, $agreement_id, $invoice_id, $line );
        }

        return $amendment_id;
    }

    public static function create_draft( int $agreement_id, array $args = [] ): int|\WP_Error {
        return self::create( $agreement_id, $args );
    }

    public static function approve( int $amendment_id ): bool|\WP_Error {
        $amendment = self::get( $amendment_id );
        if ( ! $amendment ) {
            return new \WP_Error( 'amendment_not_found', __( 'تعديل العقد غير موجود.', 'olama-registration' ) );
        }
        if ( ! in_array( $amendment->status, [ 'draft', 'pending_approval' ], true ) ) {
            return new \WP_Error( 'invalid_status', __( 'يمكن اعتماد مسودة تعديل فقط.', 'olama-registration' ) );
        }

        return self::set_status( $amendment_id, 'approved', [
            'approved_by' => get_current_user_id(),
            'approved_at' => current_time( 'mysql' ),
        ], 'agreement_amendment_approved' );
    }

    public static function reject( int $amendment_id, string $reason ): bool|\WP_Error {
        $amendment = self::get( $amendment_id );
        if ( ! $amendment ) {
            return new \WP_Error( 'amendment_not_found', __( 'تعديل العقد غير موجود.', 'olama-registration' ) );
        }
        if ( in_array( $amendment->status, [ 'posted', 'cancelled' ], true ) ) {
            return new \WP_Error( 'immutable_amendment', __( 'لا يمكن رفض تعديل مرحل أو ملغى.', 'olama-registration' ) );
        }

        return self::set_status( $amendment_id, 'rejected', [
            'admin_notes' => trim( (string) $amendment->admin_notes . "\n" . sanitize_textarea_field( $reason ) ),
        ], 'agreement_amendment_rejected' );
    }

    public static function cancel( int $amendment_id, string $reason ): bool|\WP_Error {
        $amendment = self::get( $amendment_id );
        if ( ! $amendment ) {
            return new \WP_Error( 'amendment_not_found', __( 'تعديل العقد غير موجود.', 'olama-registration' ) );
        }
        if ( $amendment->status === 'posted' ) {
            return new \WP_Error( 'posted_immutable', __( 'لا يمكن إلغاء تعديل تم ترحيله.', 'olama-registration' ) );
        }

        return self::set_status( $amendment_id, 'cancelled', [
            'cancelled_by' => get_current_user_id(),
            'cancelled_at' => current_time( 'mysql' ),
            'admin_notes'  => trim( (string) $amendment->admin_notes . "\n" . sanitize_textarea_field( $reason ) ),
        ], 'agreement_amendment_cancelled' );
    }

    public static function post( int $amendment_id ): bool|\WP_Error {
        global $wpdb;

        $amendment = self::get( $amendment_id );
        if ( ! $amendment ) {
            return new \WP_Error( 'amendment_not_found', __( 'تعديل العقد غير موجود.', 'olama-registration' ) );
        }
        if ( ! in_array( $amendment->status, [ 'approved' ], true ) ) {
            return new \WP_Error( 'amendment_not_approved', __( 'يجب اعتماد التعديل قبل ترحيله.', 'olama-registration' ) );
        }
        if ( trim( (string) $amendment->reason ) === '' ) {
            return new \WP_Error( 'reason_required', __( 'سبب التعديل مطلوب قبل الترحيل.', 'olama-registration' ) );
        }

        $invoice_id = class_exists( 'Olama_Reg_Agreement_Policy' )
            ? Olama_Reg_Agreement_Policy::get_linked_invoice_id( (int) $amendment->agreement_id )
            : (int) $amendment->invoice_id;
        if ( $invoice_id <= 0 ) {
            return new \WP_Error( 'invoice_not_found', __( 'لا توجد فاتورة مرتبطة بالعقد.', 'olama-registration' ) );
        }

        $amendment->invoice_id = $invoice_id;
        $diff = round( (float) $amendment->difference_amount, 3 );
        $amendment_type = (string) $amendment->amendment_type;
        $wpdb->query( 'START TRANSACTION' );

        $adjustment_id = 0;
        if ( $diff > 0 ) {
            $adjustment_id = self::create_debit_adjustment( $amendment_id );
        } elseif ( $diff < 0 ) {
            $adjustment_id = self::create_credit_adjustment( $amendment_id );
        }

        if ( is_wp_error( $adjustment_id ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $adjustment_id;
        }

        $agreement_updated = class_exists( 'Olama_Reg_Agreement' )
            ? Olama_Reg_Agreement::update( (int) $amendment->agreement_id, [ 'total_amount' => round( (float) $amendment->new_total, 3 ) ] )
            : $wpdb->update(
                self::t( 'olama_agreements' ),
                [
                    'total_amount' => round( (float) $amendment->new_total, 3 ),
                    'updated_at'   => current_time( 'mysql' ),
                ],
                [ 'id' => (int) $amendment->agreement_id ],
                [ '%f', '%s' ],
                [ '%d' ]
            );

        if ( ! $agreement_updated ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'agreement_update_failed', __( 'تعذر تحديث إجمالي العقد بعد الترحيل.', 'olama-registration' ) );
        }

        if ( $diff != 0 ) {
            // For add_fee amendments, the fee is already added; just link it to invoice and mark as invoiced
            if ( $amendment_type === 'add_fee' ) {
                $lines = self::get_lines( $amendment_id );
                foreach ( $lines as $line ) {
                    if ( $line->line_type === 'add_fee' && ! empty( $line->related_fee_id ) ) {
                        $wpdb->update(
                            $wpdb->prefix . 'olama_agreement_fees',
                            [
                                'invoice_id'  => $invoice_id,
                                'paid_status' => 'invoiced',
                            ],
                            [ 'id' => (int) $line->related_fee_id ]
                        );
                    }
                }
            } else {
                $first_child_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT child_id FROM " . $wpdb->prefix . "olama_agreement_fees WHERE agreement_id = %d AND child_id IS NOT NULL AND child_id != '' LIMIT 1",
                    (int) $amendment->agreement_id
                ) );

                $fee_category = 'amendment';
                $label = sprintf( __( 'تعديل مالي #%s: %s', 'olama-registration' ), $amendment->amendment_no, $amendment->reason );

                $inserted_fee = $wpdb->insert(
                    $wpdb->prefix . 'olama_agreement_fees',
                    [
                        'agreement_id' => (int) $amendment->agreement_id,
                        'child_id'     => $first_child_id ?: null,
                        'fee_category' => $fee_category,
                        'label'        => $label,
                        'amount'       => $diff,
                        'discount'     => 0,
                        'net_amount'   => $diff,
                        'due_date'     => $amendment->effective_date,
                        'invoice_id'   => $invoice_id ?: null,
                        'paid_status'  => 'invoiced',
                        'sort_order'   => 99,
                    ],
                    [ '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%d', '%s', '%d' ]
                );

                if ( false === $inserted_fee ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new \WP_Error( 'fee_insert_failed', __( 'تعذر إضافة بند التعديل المالي لرسوم العقد.', 'olama-registration' ) );
                }

                if ( class_exists( 'Olama_Reg_Agreement' ) ) {
                    Olama_Reg_Agreement::recalculate_total( (int) $amendment->agreement_id );
                }
            }
        }

        if ( class_exists( 'Olama_Reg_Agreement_Invoice' ) ) {
            $redistributed = Olama_Reg_Agreement_Invoice::redistribute_unpaid_balance(
                (int) $amendment->agreement_id,
                (string) $amendment->effective_date
            );
            if ( is_wp_error( $redistributed ) ) {
                $wpdb->query( 'ROLLBACK' );
                return $redistributed;
            }

            self::write_audit(
                'agreement_installments_rescheduled',
                $amendment_id,
                null,
                Olama_Reg_Agreement_Invoice::get_due_schedule( (int) $amendment->agreement_id )
            );
        }

        $updates = [
            'status'    => 'posted',
            'posted_by' => get_current_user_id(),
            'posted_at' => current_time( 'mysql' ),
        ];
        if ( $diff > 0 ) {
            $updates['debit_adjustment_id'] = (int) $adjustment_id;
        } elseif ( $diff < 0 ) {
            $updates['credit_adjustment_id'] = (int) $adjustment_id;
        }

        $updated = $wpdb->update( self::t( 'olama_agreement_amendments' ), $updates, [ 'id' => $amendment_id ] );
        if ( $updated === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new \WP_Error( 'post_failed', __( 'تعذر ترحيل تعديل العقد.', 'olama-registration' ) );
        }

        self::write_audit( 'agreement_amendment_posted', $amendment_id, $amendment, self::get( $amendment_id ) );
        self::write_audit( $diff >= 0 ? 'agreement_amount_increased' : 'agreement_amount_decreased', (int) $amendment->agreement_id, null, self::get( $amendment_id ) );
        $wpdb->query( 'COMMIT' );

        $payer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT payer_id FROM " . self::t( 'olama_agreements' ) . " WHERE id = %d AND payer_type = 'family'",
            (int) $amendment->agreement_id
        ));
        if ( class_exists( 'Olama_Reg_Family_Financial_Summary' ) && ! empty( $payer_id ) ) {
            Olama_Reg_Family_Financial_Summary::invalidate_snapshot( $payer_id, 0 );
        }

        return true;
    }

    public static function calculate_difference( int $agreement_id, float $new_total ): array {
        $agreement = class_exists( 'Olama_Reg_Agreement' ) ? Olama_Reg_Agreement::get( $agreement_id ) : null;
        $old_total = $agreement ? round( (float) $agreement->total_amount, 3 ) : 0.0;
        $new_total = round( $new_total, 3 );
        $difference = round( $new_total - $old_total, 3 );

        return [
            'old_total' => $old_total,
            'new_total' => $new_total,
            'difference_amount' => $difference,
            'direction' => $difference > 0 ? 'debit' : ( $difference < 0 ? 'credit' : 'none' ),
        ];
    }

    public static function create_debit_adjustment( int $amendment_id ): int|\WP_Error {
        $amendment = self::get( $amendment_id );
        if ( ! $amendment || (float) $amendment->difference_amount <= 0 ) {
            return new \WP_Error( 'invalid_debit_amendment', __( 'لا توجد زيادة مالية صالحة لإنشاء إشعار مدين.', 'olama-registration' ) );
        }

        return self::create_invoice_adjustment( $amendment, 'debit', abs( (float) $amendment->difference_amount ) );
    }

    public static function create_credit_adjustment( int $amendment_id ): int|\WP_Error {
        $amendment = self::get( $amendment_id );
        if ( ! $amendment || (float) $amendment->difference_amount >= 0 ) {
            return new \WP_Error( 'invalid_credit_amendment', __( 'لا يوجد تخفيض مالي صالح لإنشاء إشعار دائن.', 'olama-registration' ) );
        }

        return self::create_invoice_adjustment( $amendment, 'credit', abs( (float) $amendment->difference_amount ) );
    }

    public static function reschedule_unpaid_installments( int $amendment_id, array $schedule ): bool|\WP_Error {
        $amendment = self::get( $amendment_id );
        if ( ! $amendment ) {
            return new \WP_Error( 'amendment_not_found', __( 'تعديل العقد غير موجود.', 'olama-registration' ) );
        }
        if ( ! class_exists( 'Olama_Reg_Agreement_Invoice' ) ) {
            return new \WP_Error( 'missing_invoice_module', __( 'وحدة فواتير العقود غير محملة.', 'olama-registration' ) );
        }

        $result = Olama_Reg_Agreement_Invoice::save_due_schedule( (int) $amendment->agreement_id, $schedule, (int) $amendment->invoice_id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        self::write_audit( 'agreement_installments_rescheduled', $amendment_id, null, $schedule );
        return true;
    }

    public static function get( int $amendment_id ): ?object {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_agreement_amendments' ) . " WHERE id = %d",
            $amendment_id
        ) );

        return $row ?: null;
    }

    public static function get_lines( int $amendment_id ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_agreement_amendment_lines' ) . "
             WHERE amendment_id = %d
             ORDER BY id ASC",
            $amendment_id
        ) ) ?: [];
    }

    public static function get_by_agreement( int $agreement_id ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_agreement_amendments' ) . "
             WHERE agreement_id = %d
             ORDER BY id DESC",
            $agreement_id
        ) ) ?: [];
    }

    public static function snapshot_agreement( int $agreement_id ): array {
        global $wpdb;

        $agreement = class_exists( 'Olama_Reg_Agreement' ) ? Olama_Reg_Agreement::get( $agreement_id ) : null;
        $invoice_id = class_exists( 'Olama_Reg_Agreement_Policy' ) ? Olama_Reg_Agreement_Policy::get_linked_invoice_id( $agreement_id ) : self::get_invoice_id( $agreement_id );

        return [
            'agreement'    => $agreement ? self::object_to_array( $agreement ) : null,
            'fees'         => class_exists( 'Olama_Reg_Agreement_Fees' ) ? array_map( [ self::class, 'object_to_array' ], Olama_Reg_Agreement_Fees::get_by_agreement( $agreement_id ) ) : [],
            'clauses'      => class_exists( 'Olama_Reg_Agreement_Clauses' ) ? array_map( [ self::class, 'object_to_array' ], Olama_Reg_Agreement_Clauses::get_by_agreement( $agreement_id ) ) : [],
            'installments' => $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM " . self::t( 'olama_invoice_installments' ) . "
                 WHERE agreement_id = %d
                 ORDER BY installment_no ASC, id ASC",
                $agreement_id
            ), ARRAY_A ) ?: [],
            'invoice'      => $invoice_id > 0 ? $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM " . self::t( 'olama_invoices' ) . " WHERE id = %d",
                $invoice_id
            ), ARRAY_A ) : null,
        ];
    }

    private static function create_invoice_adjustment( object $amendment, string $type, float $amount ): int|\WP_Error {
        if ( ! class_exists( 'Olama_Reg_Billing_Invoice' ) ) {
            return new \WP_Error( 'missing_invoice_module', __( 'وحدة الفواتير غير محملة.', 'olama-registration' ) );
        }

        $result = Olama_Reg_Billing_Invoice::create_adjustment(
            (int) $amendment->invoice_id,
            $type,
            $amount,
            (string) $amendment->reason,
            (string) $amendment->admin_notes
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        self::write_audit(
            $type === 'debit' ? 'agreement_debit_created' : 'agreement_credit_created',
            (int) $amendment->id,
            null,
            [
                'adjustment_id' => (int) $result,
                'invoice_id'    => (int) $amendment->invoice_id,
                'type'          => $type,
                'amount'        => round( $amount, 3 ),
            ]
        );
        return (int) $result;
    }

    public static function add_line( int $amendment_id, int $agreement_id, int $invoice_id, array $line ): int|false {
        global $wpdb;

        $old_amount = round( (float) ( $line['old_amount'] ?? 0 ), 3 );
        $new_amount = round( (float) ( $line['new_amount'] ?? $old_amount ), 3 );

        $inserted = $wpdb->insert(
            self::t( 'olama_agreement_amendment_lines' ),
            [
                'amendment_id'      => $amendment_id,
                'agreement_id'      => $agreement_id,
                'invoice_id'        => $invoice_id ?: null,
                'line_type'         => sanitize_key( $line['line_type'] ?? 'fee_line_change' ),
                'related_fee_id'    => isset( $line['related_fee_id'] ) ? absint( $line['related_fee_id'] ) : null,
                'student_id'        => sanitize_text_field( $line['student_id'] ?? '' ),
                'description'       => sanitize_text_field( $line['description'] ?? '' ),
                'old_amount'        => $old_amount,
                'new_amount'        => $new_amount,
                'difference_amount' => round( $new_amount - $old_amount, 3 ),
                'before_state'      => isset( $line['before_state'] ) ? wp_json_encode( $line['before_state'] ) : null,
                'after_state'       => isset( $line['after_state'] ) ? wp_json_encode( $line['after_state'] ) : null,
                'created_at'        => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s' ]
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    private static function set_status( int $amendment_id, string $status, array $extra, string $audit_action ): bool|\WP_Error {
        global $wpdb;

        $before = self::get( $amendment_id );
        $data = array_merge( [ 'status' => $status ], $extra );
        $updated = $wpdb->update( self::t( 'olama_agreement_amendments' ), $data, [ 'id' => $amendment_id ] );

        if ( $updated === false ) {
            return new \WP_Error( 'status_update_failed', __( 'تعذر تحديث حالة تعديل العقد.', 'olama-registration' ) );
        }

        self::write_audit( $audit_action, $amendment_id, $before, self::get( $amendment_id ) );
        return true;
    }

    private static function write_audit( string $action, int $entity_id, mixed $before, mixed $after ): void {
        global $wpdb;

        $wpdb->insert(
            self::t( 'olama_billing_audit' ),
            [
                'entity_type'  => 'agreement_amendment',
                'entity_id'    => $entity_id,
                'action'       => $action,
                'actor_id'     => get_current_user_id(),
                'before_state' => $before === null ? null : wp_json_encode( self::object_to_array( $before ) ),
                'after_state'  => $after === null ? null : wp_json_encode( self::object_to_array( $after ) ),
                'ip_address'   => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
                'created_at'   => current_time( 'mysql' ),
            ]
        );
    }

    private static function get_invoice_id( int $agreement_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . self::t( 'olama_invoices' ) . " WHERE agreement_id = %d AND status != 'cancelled' ORDER BY id ASC LIMIT 1",
            $agreement_id
        ) );
    }

    private static function sanitize_date( string $date ): string {
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : current_time( 'Y-m-d' );
    }

    private static function object_to_array( mixed $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }

        if ( is_scalar( $value ) || $value === null ) {
            return [ 'value' => $value ];
        }

        $normalized = json_decode( wp_json_encode( $value ), true );
        if ( is_array( $normalized ) ) {
            return $normalized;
        }

        return [ 'value' => $normalized ];
    }

    public static function sync_posted_amendment_fees( int $agreement_id ): void {
        global $wpdb;

        $amendments = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_agreement_amendments' ) . "
             WHERE agreement_id = %d AND status = 'posted'",
            $agreement_id
        ) );

        if ( empty( $amendments ) ) {
            return;
        }

        $fees_table = $wpdb->prefix . 'olama_agreement_fees';

        $existing_fees = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, label FROM {$fees_table}
             WHERE agreement_id = %d AND fee_category = 'amendment'",
            $agreement_id
        ) );

        $existing_labels = wp_list_pluck( $existing_fees, 'label' );
        $needs_recalculate = false;

        foreach ( $amendments as $amendment ) {
            if ( $amendment->amendment_type === 'add_fee' ) {
                continue;
            }

            $found = false;
            foreach ( $existing_labels as $label ) {
                if ( strpos( (string) $label, $amendment->amendment_no ) !== false ) {
                    $found = true;
                    break;
                }
            }

            if ( ! $found ) {
                $diff = round( (float) $amendment->difference_amount, 3 );
                if ( $diff != 0 ) {
                    $first_child_id = $wpdb->get_var( $wpdb->prepare(
                        "SELECT child_id FROM {$fees_table} WHERE agreement_id = %d AND child_id IS NOT NULL AND child_id != '' LIMIT 1",
                        $agreement_id
                    ) );

                    $label = sprintf( __( 'تعديل مالي #%s: %s', 'olama-registration' ), $amendment->amendment_no, $amendment->reason );

                    $inserted = $wpdb->insert(
                        $fees_table,
                        [
                            'agreement_id' => $agreement_id,
                            'child_id'     => $first_child_id ?: null,
                            'fee_category' => 'amendment',
                            'label'        => $label,
                            'amount'       => $diff,
                            'discount'     => 0,
                            'net_amount'   => $diff,
                            'due_date'     => $amendment->effective_date,
                            'invoice_id'   => $amendment->invoice_id ?: null,
                            'paid_status'  => 'invoiced',
                            'sort_order'   => 99,
                        ],
                        [ '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%d', '%s', '%d' ]
                    );

                    if ( $inserted ) {
                        $needs_recalculate = true;
                    }
                }
            }
        }

        if ( $needs_recalculate && class_exists( 'Olama_Reg_Agreement' ) ) {
            Olama_Reg_Agreement::recalculate_total( $agreement_id );
        }
    }
}
