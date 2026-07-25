<?php
/**
 * Agreement accounting workflow: agreement -> invoice -> due schedule -> receipts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Olama_Reg_Agreement_Invoice {

    public const DEFAULT_INSTALLMENTS = 8;

    private static function t( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public static function get_first_payment_amount( ?object $agreement = null ): float {
        $amount = max( 0, (float) get_option( 'olama_reg_agreement_first_payment_amount', 0 ) );
        return round( (float) apply_filters( 'olama_reg_agreement_first_payment_amount', $amount, $agreement ), 2 );
    }

    public static function calculate_installment_amounts( float $total, int $count, float $first_payment ): array {
        $total = max( 0, round( $total, 2 ) );
        $count = min( self::DEFAULT_INSTALLMENTS, max( 1, $count ) );
        $first_payment = min( $total, max( 0, round( $first_payment, 2 ) ) );
        $distributed_balance = max( 0, round( $total - $first_payment, 2 ) );
        $base = round( $distributed_balance / $count, 2 );
        $amounts = $first_payment > 0 ? [ $first_payment ] : [];
        $allocated = 0.0;

        for ( $i = 1; $i <= $count; $i++ ) {
            $monthly_share = ( $i === $count )
                ? round( $distributed_balance - $allocated, 2 )
                : $base;
            $allocated = round( $allocated + $monthly_share, 2 );
            $amount = round( $monthly_share, 2 );

            if ( $amount > 0 ) {
                $amounts[] = $amount;
            }
        }

        return $amounts;
    }

    public static function calculate_installment_due_dates( int $school_year, int $count ): array {
        $count = min( self::DEFAULT_INSTALLMENTS, max( 1, $count ) );
        $dates = [];

        for ( $index = 0; $index < $count; $index++ ) {
            $date = new \DateTime( sprintf( '%04d-09-01', $school_year ) );
            if ( $index > 0 ) {
                $date->modify( '+' . $index . ' months' );
            }
            $date->modify( 'last day of this month' );
            $dates[] = $date->format( 'Y-m-d' );
        }

        return $dates;
    }

    public static function get_due_schedule( int $agreement_id ): array {
        global $wpdb;

        $schedule = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_invoice_installments' ) . "
             WHERE agreement_id = %d
             ORDER BY installment_no ASC, id ASC",
            $agreement_id
        ) ) ?: [];

        $has_first_payment = self::get_first_payment_amount() > 0 && ! empty( $schedule );
        foreach ( $schedule as $index => $line ) {
            $line->is_first_payment = $has_first_payment && $index === 0;
            $line->display_installment_no = $line->is_first_payment
                ? __( 'الدفعة الأولى', 'olama-registration' )
                : (int) $line->installment_no - ( $has_first_payment ? 1 : 0 );
        }

        return $schedule;
    }

    public static function save_due_schedule( int $agreement_id, array $lines, int $invoice_id = 0, bool $skip_lock_check = false ): bool|\WP_Error {
        global $wpdb;

        $agreement = Olama_Reg_Agreement::get( $agreement_id );
        if ( ! $agreement ) {
            return new \WP_Error( 'not_found', __( 'العقد غير موجود.', 'olama-registration' ) );
        }

        if ( $skip_lock_check ) {
            $existing_schedule = self::get_due_schedule( $agreement_id );
            $has_allocated_payments = array_sum( array_map(
                static fn( $line ) => (float) ( $line->amount_paid ?? 0 ),
                $existing_schedule
            ) ) > 0.009;

            if ( $has_allocated_payments ) {
                $requested_dates = array_values( array_filter( array_map(
                    static fn( $line ) => self::sanitize_date( (string) ( $line['due_date'] ?? '' ) ),
                    $lines
                ) ) );

                return self::redistribute_unpaid_balance(
                    $agreement_id,
                    $requested_dates ? min( $requested_dates ) : current_time( 'Y-m-d' ),
                    count( $lines )
                );
            }
        }

        if ( ! $skip_lock_check ) {
            if ( class_exists( 'Olama_Reg_Agreement_Policy' ) ) {
                $allowed = Olama_Reg_Agreement_Policy::can_reschedule_installments( $agreement_id );
                if ( is_wp_error( $allowed ) ) {
                    return $allowed;
                }

                if ( $invoice_id <= 0 ) {
                    $invoice_id = Olama_Reg_Agreement_Policy::get_linked_invoice_id( $agreement_id );
                }
            } else {
                $paid_total = (float) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COALESCE(SUM(amount_paid), 0) FROM " . self::t( 'olama_invoices' ) . " WHERE agreement_id = %d AND status != 'cancelled'",
                    $agreement_id
                ) );
                if ( $paid_total > 0 ) {
                    return new \WP_Error( 'schedule_locked', __( 'لا يمكن تعديل توزيع الاستحقاق بعد تسجيل مدفوعات على الفاتورة.', 'olama-registration' ) );
                }
            }
        }

        if ( $invoice_id <= 0 ) {
            $invoice_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM " . self::t( 'olama_invoices' ) . " WHERE agreement_id = %d AND status != 'cancelled' ORDER BY id ASC LIMIT 1",
                $agreement_id
            ) );
        }

        $clean = [];
        $no = 1;
        foreach ( $lines as $line ) {
            $due_date = self::sanitize_date( $line['due_date'] ?? '' );
            $amount = round( (float) ( $line['amount'] ?? $line['amount_due'] ?? 0 ), 2 );

            if ( ! $due_date || $amount <= 0 ) {
                continue;
            }

            $clean[] = [
                'installment_no' => $no++,
                'due_date'       => $due_date,
                'amount_due'     => $amount,
            ];
        }

        $fixed_first_payment = min(
            round( (float) $agreement->total_amount, 2 ),
            self::get_first_payment_amount( $agreement )
        );
        if ( $fixed_first_payment > 0 ) {
            $registration_date = self::registration_date( $agreement );
            $fixed_line = [
                'installment_no' => 1,
                'due_date'       => $registration_date,
                'amount_due'     => $fixed_first_payment,
            ];

            if ( empty( $clean ) ) {
                $clean[] = $fixed_line;
            } else {
                $clean[0] = $fixed_line;
            }
        }

        if ( empty( $clean ) ) {
            $start = self::sanitize_date( $agreement->start_date ?: current_time( 'Y-m-d' ) );
            $clean[] = [
                'installment_no' => 1,
                'due_date'       => $start ?: current_time( 'Y-m-d' ),
                'amount_due'     => round( (float) $agreement->total_amount, 2 ),
            ];
        }

        $wpdb->delete( self::t( 'olama_invoice_installments' ), [ 'agreement_id' => $agreement_id ] );

        foreach ( $clean as $line ) {
            $wpdb->insert( self::t( 'olama_invoice_installments' ), [
                'invoice_id'      => $invoice_id,
                'agreement_id'    => $agreement_id,
                'installment_no'  => $line['installment_no'],
                'due_date'        => $line['due_date'],
                'amount_due'      => $line['amount_due'],
                'amount_paid'     => 0.00,
                'status'          => self::initial_due_status( $line['due_date'] ),
            ] );
        }

        return true;
    }

    public static function generate_default_due_schedule( int $agreement_id, int $count = 0, bool $skip_lock_check = false ): bool|\WP_Error {
        $agreement = Olama_Reg_Agreement::get( $agreement_id );
        if ( ! $agreement ) {
            return new \WP_Error( 'not_found', __( 'العقد غير موجود.', 'olama-registration' ) );
        }

        $existing_schedule = self::get_due_schedule( $agreement_id );
        $has_allocated_payments = array_sum( array_map(
            static fn( $line ) => (float) ( $line->amount_paid ?? 0 ),
            $existing_schedule
        ) ) > 0.009;

        if ( $skip_lock_check && $has_allocated_payments ) {
            return self::redistribute_unpaid_balance(
                $agreement_id,
                current_time( 'Y-m-d' ),
                $count
            );
        }

        if ( $count <= 0 ) {
            $count = (int) apply_filters( 'olama_reg_agreement_default_installments', self::DEFAULT_INSTALLMENTS, $agreement );
        }
        $count = max( 1, $count );

        $total = round( (float) $agreement->total_amount, 2 );
        $start = self::sanitize_date( $agreement->start_date ?: current_time( 'Y-m-d' ) );
        $end = self::sanitize_date( $agreement->end_date ?: '' );
        if ( ! $end ) {
            $end = self::sanitize_date( self::get_active_academic_year_end_date() ?: '' );
        }
        if ( ! $end ) {
            $end = $start;
        }

        $registration_dt = new \DateTime( self::registration_date( $agreement ) );
        $academic_year_id = (int) ( $agreement->academic_year_id ?? 0 );
        $academic_start = self::sanitize_date( self::get_academic_year_start_date( $academic_year_id ) );
        $academic_end = self::sanitize_date( self::get_academic_year_end_date( $academic_year_id ) );
        $school_year = (int) substr( $academic_start ?: $registration_dt->format( 'Y-m-d' ), 0, 4 );
        if ( $academic_end && (int) substr( $academic_end, 5, 2 ) <= 8 ) {
            $school_year = (int) substr( $academic_end, 0, 4 ) - 1;
        }
        $installment_dates = self::calculate_installment_due_dates( $school_year, $count );

        // The first payment is a separate fixed line. The configured count is
        // the number of monthly installments that follow it.
        $first_payment = self::get_first_payment_amount( $agreement );
        $amounts = self::calculate_installment_amounts(
            $total,
            $count,
            $first_payment
        );
        $has_first_payment = min( $total, $first_payment ) > 0;
        $lines = [];

        foreach ( $amounts as $index => $amount ) {
            if ( $has_first_payment && $index === 0 ) {
                $date = clone $registration_dt;
            } else {
                $monthly_index = $index - ( $has_first_payment ? 1 : 0 );
                $date = new \DateTime( $installment_dates[ $monthly_index ] );
            }
            $lines[] = [
                'due_date' => $date->format( 'Y-m-d' ),
                'amount'   => $amount,
            ];
        }

        return self::save_due_schedule( $agreement_id, $lines, 0, $skip_lock_check );
    }

    /**
     * Preserve paid installments and redistribute only the outstanding
     * agreement balance over the remaining installments and contract period.
     *
     * Existing rows that contain allocations are updated in place so payment
     * allocation references remain valid.
     */
    public static function redistribute_unpaid_balance(
        int $agreement_id,
        string $effective_date = '',
        int $total_installments = 0
    ): bool|\WP_Error {
        global $wpdb;

        $agreement = Olama_Reg_Agreement::get( $agreement_id );
        if ( ! $agreement ) {
            return new \WP_Error( 'not_found', __( 'العقد غير موجود.', 'olama-registration' ) );
        }

        $schedule = self::get_due_schedule( $agreement_id );
        if ( empty( $schedule ) ) {
            return self::generate_default_due_schedule(
                $agreement_id,
                $total_installments > 0 ? $total_installments : self::DEFAULT_INSTALLMENTS,
                true
            );
        }

        $invoice_id = (int) ( $schedule[0]->invoice_id ?? 0 );
        if ( $invoice_id <= 0 && class_exists( 'Olama_Reg_Agreement_Policy' ) ) {
            $invoice_id = Olama_Reg_Agreement_Policy::get_linked_invoice_id( $agreement_id );
        }

        $paid_total = round( array_sum( array_map(
            static fn( $line ) => (float) ( $line->amount_paid ?? 0 ),
            $schedule
        ) ), 2 );
        $agreement_total = round( (float) $agreement->total_amount, 2 );

        if ( $agreement_total + 0.009 < $paid_total ) {
            return new \WP_Error(
                'agreement_total_below_paid',
                __( 'لا يمكن إعادة توزيع الاستحقاقات لأن قيمة العقد الجديدة أقل من المبلغ المدفوع.', 'olama-registration' )
            );
        }

        $settled = [];
        $partial = [];
        $unpaid = [];

        foreach ( $schedule as $line ) {
            $due = round( (float) $line->amount_due, 2 );
            $paid = round( (float) $line->amount_paid, 2 );

            if ( $due > 0 && $paid + 0.009 >= $due ) {
                $settled[] = $line;
            } elseif ( $paid > 0 ) {
                $partial[] = $line;
            } else {
                $unpaid[] = $line;
            }
        }

        $outstanding = max( 0.0, round( $agreement_total - $paid_total, 2 ) );
        $requested_total = $total_installments > 0 ? $total_installments : count( $schedule );
        $remaining_count = $outstanding > 0
            ? max( 1, $requested_total - count( $settled ), count( $partial ) )
            : count( $partial );

        $remaining_rows = array_merge( $partial, $unpaid );
        $kept_rows = array_slice( $remaining_rows, 0, $remaining_count );
        $removed_rows = array_slice( $remaining_rows, $remaining_count );

        foreach ( $removed_rows as $line ) {
            if ( (float) $line->amount_paid > 0 ) {
                return new \WP_Error(
                    'paid_installment_removal_blocked',
                    __( 'لا يمكن حذف قسط مرتبط بدفعة أثناء إعادة التوزيع.', 'olama-registration' )
                );
            }

            if ( false === $wpdb->delete(
                self::t( 'olama_invoice_installments' ),
                [ 'id' => (int) $line->id ],
                [ '%d' ]
            ) ) {
                return new \WP_Error( 'installment_delete_failed', __( 'تعذر تحديث عدد الأقساط المتبقية.', 'olama-registration' ) );
            }
        }

        while ( count( $kept_rows ) < $remaining_count ) {
            $inserted = $wpdb->insert(
                self::t( 'olama_invoice_installments' ),
                [
                    'invoice_id'     => $invoice_id ?: null,
                    'agreement_id'   => $agreement_id,
                    'installment_no' => count( $schedule ) + count( $kept_rows ) + 1,
                    'due_date'       => current_time( 'Y-m-d' ),
                    'amount_due'     => 0.00,
                    'amount_paid'    => 0.00,
                    'status'         => 'unpaid',
                ],
                [ '%d', '%d', '%d', '%s', '%f', '%f', '%s' ]
            );

            if ( ! $inserted ) {
                return new \WP_Error( 'installment_insert_failed', __( 'تعذر إنشاء قسط متبقٍ جديد.', 'olama-registration' ) );
            }

            $kept_rows[] = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM " . self::t( 'olama_invoice_installments' ) . " WHERE id = %d",
                (int) $wpdb->insert_id
            ) );
        }

        if ( $remaining_count > 0 ) {
            $anchor = self::sanitize_date( $effective_date ?: current_time( 'Y-m-d' ) );
            $anchor = $anchor ?: current_time( 'Y-m-d' );
            $future_dates = array_values( array_filter( array_map(
                static fn( $line ) => self::sanitize_date( (string) ( $line->due_date ?? '' ) ),
                $kept_rows
            ), static fn( $date ) => $date !== '' && $date >= $anchor ) );

            $period_start = $future_dates ? min( $future_dates ) : $anchor;
            $period_end = self::sanitize_date( (string) ( $agreement->end_date ?? '' ) );
            if ( ! $period_end ) {
                $period_end = self::sanitize_date( self::get_active_academic_year_end_date() ?: '' );
            }
            if ( ! $period_end || $period_end < $period_start ) {
                $period_end = $period_start;
            }

            $start_dt = new \DateTime( $period_start );
            $end_dt = new \DateTime( $period_end );
            $days_span = max( 0, (int) $start_dt->diff( $end_dt )->days );
            $outstanding_qirsh = (int) round( $outstanding * 100 );
            $base_qirsh = intdiv( $outstanding_qirsh, $remaining_count );
            $allocated_qirsh = 0;

            foreach ( $kept_rows as $index => $line ) {
                $date = clone $start_dt;
                if ( $remaining_count > 1 && $days_span > 0 ) {
                    $offset = (int) round( ( $days_span * $index ) / ( $remaining_count - 1 ) );
                    if ( $offset > 0 ) {
                        $date->modify( '+' . $offset . ' days' );
                    }
                }

                $share_qirsh = ( $index === $remaining_count - 1 )
                    ? $outstanding_qirsh - $allocated_qirsh
                    : $base_qirsh;
                $allocated_qirsh += $share_qirsh;

                $paid = round( (float) ( $line->amount_paid ?? 0 ), 2 );
                $new_due = round( $paid + ( $share_qirsh / 100 ), 2 );
                $new_status = $share_qirsh <= 0
                    ? 'paid'
                    : ( $paid > 0 ? 'partially_paid' : self::initial_due_status( $date->format( 'Y-m-d' ) ) );

                $updated = $wpdb->update(
                    self::t( 'olama_invoice_installments' ),
                    [
                        'invoice_id'  => $invoice_id ?: null,
                        'due_date'    => $date->format( 'Y-m-d' ),
                        'amount_due'  => $new_due,
                        'status'      => $new_status,
                    ],
                    [ 'id' => (int) $line->id ],
                    [ '%d', '%s', '%f', '%s' ],
                    [ '%d' ]
                );

                if ( false === $updated ) {
                    return new \WP_Error( 'installment_update_failed', __( 'تعذر إعادة توزيع الأقساط غير المدفوعة.', 'olama-registration' ) );
                }
            }
        }

        $final_rows = array_merge( $settled, $kept_rows );
        foreach ( $final_rows as $index => $line ) {
            $wpdb->update(
                self::t( 'olama_invoice_installments' ),
                [ 'installment_no' => $index + 1 ],
                [ 'id' => (int) $line->id ],
                [ '%d' ],
                [ '%d' ]
            );
        }

        return true;
    }

    public static function validate_completion( int $agreement_id ): true|\WP_Error {
        global $wpdb;

        $agreement = Olama_Reg_Agreement::get( $agreement_id );
        if ( ! $agreement ) {
            return new \WP_Error( 'not_found', __( 'العقد غير موجود.', 'olama-registration' ) );
        }

        if ( $agreement->status === 'cancelled' ) {
            return new \WP_Error( 'agreement_cancelled', __( 'لا يمكن إكمال عقد ملغى.', 'olama-registration' ) );
        }

        if ( $agreement->status === 'completed' ) {
            return new \WP_Error( 'agreement_already_completed', __( 'تم إكمال هذا العقد مسبقاً.', 'olama-registration' ) );
        }

        $errors = [];
        if ( empty( $agreement->payer_id ) ) {
            $errors[] = __( 'يجب اختيار الجهة الدافعة.', 'olama-registration' );
        }
        if ( empty( $agreement->activity_type ) ) {
            $errors[] = __( 'يجب اختيار طبيعة العقد.', 'olama-registration' );
        }
        $template = ! empty( $agreement->template_id )
            ? Olama_Reg_Agreement_Templates::get( (int) $agreement->template_id )
            : null;
        if ( ! $template || ! (int) $template->is_active || trim( (string) $template->contract_content ) === '' ) {
            $errors[] = __( 'يجب اختيار نموذج عقد مفعل يحتوي على نص العقد.', 'olama-registration' );
        } elseif ( $template->template_key === 'kindergarten-registration' ) {
            $participant_ids = array_values( array_filter( array_map(
                'strval',
                (array) ( $agreement->participant_ids_array ?? [] )
            ) ) );
            if ( count( array_unique( $participant_ids ) ) !== 1 ) {
                $errors[] = __( 'نموذج تسجيل الروضة يتطلب طالباً واحداً فقط في كل عقد.', 'olama-registration' );
            }
        }
        if ( empty( $agreement->start_date ) ) {
            $errors[] = __( 'تاريخ بداية العقد مطلوب.', 'olama-registration' );
        }
        if ( empty( $agreement->end_date ) ) {
            $errors[] = __( 'تاريخ نهاية العقد مطلوب.', 'olama-registration' );
        }
        if ( ! empty( $agreement->start_date ) && ! empty( $agreement->end_date ) && $agreement->end_date < $agreement->start_date ) {
            $errors[] = __( 'تاريخ نهاية العقد لا يمكن أن يكون قبل تاريخ البداية.', 'olama-registration' );
        }

        $fees = Olama_Reg_Agreement_Fees::get_by_agreement( $agreement_id );
        if ( empty( $fees ) ) {
            $errors[] = __( 'يجب إضافة بند رسوم واحد على الأقل.', 'olama-registration' );
        }
        if ( round( (float) $agreement->total_amount, 2 ) <= 0 ) {
            $errors[] = __( 'صافي العقد يجب أن يكون أكبر من صفر.', 'olama-registration' );
        }

        $schedule = self::get_due_schedule( $agreement_id );
        if ( empty( $schedule ) ) {
            $errors[] = __( 'يجب إنشاء توزيع الاستحقاق قبل إكمال العقد.', 'olama-registration' );
        } else {
            $due_total = round( array_sum( array_map( static fn( $line ) => (float) $line->amount_due, $schedule ) ), 2 );
            $agreement_total = round( (float) $agreement->total_amount, 2 );
            if ( abs( $due_total - $agreement_total ) > 0.009 ) {
                $errors[] = __( 'مجموع الاستحقاقات لا يساوي صافي العقد. يرجى تعديل توزيع الاستحقاق قبل الحفظ.', 'olama-registration' );
            }
        }

        if ( $errors ) {
            return new \WP_Error( 'completion_validation_failed', implode( "\n", $errors ) );
        }

        return true;
    }

    public static function complete_agreement( int $agreement_id ): bool|\WP_Error {
        global $wpdb;

        $validation = self::validate_completion( $agreement_id );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $wpdb->query( 'START TRANSACTION' );
        $snapshot = Olama_Reg_Contract_Renderer::snapshot( $agreement_id );
        if ( is_wp_error( $snapshot ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $snapshot;
        }

        $invoice_id = self::generate_invoice( $agreement_id );

        if ( is_wp_error( $invoice_id ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $invoice_id;
        }

        $wpdb->update(
            self::t( 'olama_invoice_installments' ),
            [ 'invoice_id' => (int) $invoice_id ],
            [ 'agreement_id' => $agreement_id ],
            [ '%d' ],
            [ '%d' ]
        );

        $fees = Olama_Reg_Agreement_Fees::get_by_agreement( $agreement_id );
        foreach ( $fees as $fee ) {
            Olama_Reg_Agreement_Fees::mark_invoiced( (int) $fee->id, (int) $invoice_id );
        }

        Olama_Reg_Agreement::update( $agreement_id, [ 'status' => 'completed' ] );
        $wpdb->query( 'COMMIT' );

        return true;
    }

    public static function generate_invoice( int $agreement_id, array $fee_ids = [] ): int|\WP_Error {
        global $wpdb;

        $agreement = Olama_Reg_Agreement::get( $agreement_id );
        if ( ! $agreement ) {
            return new \WP_Error( 'not_found', __( 'العقد غير موجود.', 'olama-registration' ) );
        }

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_invoices' ) . " WHERE agreement_id = %d AND status != 'cancelled' ORDER BY id ASC LIMIT 1",
            $agreement_id
        ) );

        if ( $existing && (float) $existing->amount_paid > 0 ) {
            return new \WP_Error( 'invoice_has_payments', __( 'لا يمكن تعديل فاتورة مرتبطة بمدفوعات. يلزم إجراء تسوية أو إشعار دائن.', 'olama-registration' ) );
        }

        $fees = Olama_Reg_Agreement_Fees::get_by_agreement( $agreement_id );
        if ( empty( $fees ) ) {
            return new \WP_Error( 'no_fees', __( 'لا توجد رسوم صالحة للفوترة.', 'olama-registration' ) );
        }

        $items = [];
        foreach ( $fees as $fee ) {
            $items[] = [
                'agreement_fee_id' => (int) $fee->id,
                'student_uid'      => (string) ( $fee->student_uid ?? '' ),
                'fee_category'     => (string) $fee->fee_category,
                'description'      => $fee->label ?: __( 'رسوم عقد', 'olama-registration' ),
                'quantity'         => 1,
                'unit_price'       => (float) $fee->net_amount,
            ];
        }

        $year_id = (int) ( $agreement->academic_year_id ?? 0 );
        if ( ! $year_id && class_exists( 'Olama_School_Academic' ) ) {
            $active_year = Olama_School_Academic::get_active_year();
            if ( $active_year ) {
                $year_id = (int) $active_year->id;
            }
        }
        if ( ! $year_id ) {
            return new \WP_Error( 'missing_year', __( 'لا يوجد عام دراسي محدد للعقد.', 'olama-registration' ) );
        }

        $invoice_data = [
            'payer_type'           => $agreement->payer_type,
            'academic_year_id'    => $year_id,
            'issue_date'          => current_time( 'Y-m-d' ),
            'status'              => 'issued',
            'notes'               => sprintf( __( 'فاتورة من العقد رقم: %s', 'olama-registration' ), $agreement->agreement_number ),
            'items'               => $items,
            'discount'            => 0,
            'linked_agreement_id' => $agreement->id,
            'agreement_id'        => $agreement->id,
        ];

        if ( $agreement->payer_type === 'customer' ) {
            $invoice_data['customer_id'] = absint( $agreement->customer_id ?: $agreement->payer_id );
            $invoice_data['ext_customer_id'] = $invoice_data['customer_id'];
            if ( $agreement->participant_type === 'child' ) {
                $invoice_data['ext_child_id'] = absint( $agreement->participant_id );
            }
        } else {
            $invoice_data['family_uid'] = (string) ( $agreement->family_uid ?: $agreement->payer_id );
        }

        if ( $existing ) {
            $updated = Olama_Reg_Billing_Invoice::update( (int) $existing->id, $invoice_data );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
            return (int) $existing->id;
        }

        return Olama_Reg_Billing_Invoice::create( $invoice_data );
    }

    public static function cancel_agreement( int $agreement_id ): bool|\WP_Error {
        global $wpdb;

        if ( class_exists( 'Olama_Reg_Agreement_Policy' ) ) {
            $allowed = Olama_Reg_Agreement_Policy::can_cancel_agreement( $agreement_id );
            if ( is_wp_error( $allowed ) ) {
                return $allowed;
            }
        }

        $invoice = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::t( 'olama_invoices' ) . " WHERE agreement_id = %d AND status != 'cancelled' ORDER BY id ASC LIMIT 1",
            $agreement_id
        ) );

        if ( $invoice && (float) $invoice->amount_paid > 0 ) {
            return new \WP_Error( 'has_payments', __( 'لا يمكن إلغاء العقد مباشرة لوجود مدفوعات مرتبطة بالفاتورة.', 'olama-registration' ) );
        }

        if ( $invoice ) {
            $cancelled = Olama_Reg_Billing_Invoice::cancel( (int) $invoice->id );
            if ( is_wp_error( $cancelled ) ) {
                return $cancelled;
            }
        }

        return Olama_Reg_Agreement::update( $agreement_id, [ 'status' => 'cancelled' ] );
    }

    public static function get_academic_year_end_date( int $academic_year_id = 0 ): string {
        global $wpdb;

        if ( $academic_year_id > 0 ) {
            $academic_year = class_exists( 'Olama_School_Academic' ) && method_exists( 'Olama_School_Academic', 'get_year' )
                ? Olama_School_Academic::get_year( $academic_year_id )
                : $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}olama_academic_years WHERE id = %d LIMIT 1",
                    $academic_year_id
                ) );
        } else {
            $academic_year = class_exists( 'Olama_School_Academic' )
                ? Olama_School_Academic::get_active_year()
                : $wpdb->get_row(
                    "SELECT * FROM {$wpdb->prefix}olama_academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1"
                );
        }

        if ( ! $academic_year || empty( $academic_year->id ) ) {
            return '';
        }

        foreach ( [ 'end_date', 'year_end_date', 'date_end' ] as $field ) {
            if ( ! empty( $academic_year->{$field} ) ) {
                return self::sanitize_date( $academic_year->{$field} );
            }
        }

        $columns = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}olama_academic_years", 0 );
        foreach ( [ 'end_date', 'year_end_date', 'date_end' ] as $field ) {
            if ( in_array( $field, (array) $columns, true ) ) {
                $date = $wpdb->get_var( $wpdb->prepare(
                    "SELECT {$field} FROM {$wpdb->prefix}olama_academic_years WHERE id = %d",
                    (int) $academic_year->id
                ) );
                if ( $date ) {
                    return self::sanitize_date( $date );
                }
            }
        }

        return '';
    }

    public static function get_academic_year_start_date( int $academic_year_id = 0 ): string {
        global $wpdb;

        if ( $academic_year_id > 0 ) {
            $date = $wpdb->get_var( $wpdb->prepare(
                "SELECT start_date FROM {$wpdb->prefix}olama_academic_years WHERE id = %d LIMIT 1",
                $academic_year_id
            ) );
        } else {
            $date = $wpdb->get_var(
                "SELECT start_date FROM {$wpdb->prefix}olama_academic_years
                 WHERE is_active = 1 ORDER BY id DESC LIMIT 1"
            );
        }

        return self::sanitize_date( (string) $date );
    }

    public static function get_active_academic_year_end_date(): string {
        return self::get_academic_year_end_date();
    }

    private static function initial_due_status( string $due_date ): string {
        return ( $due_date < current_time( 'Y-m-d' ) ) ? 'overdue' : 'unpaid';
    }

    private static function registration_date( object $agreement ): string {
        $created_date = self::sanitize_date( substr( (string) ( $agreement->created_at ?? '' ), 0, 10 ) );
        $start_date = self::sanitize_date( (string) ( $agreement->start_date ?? '' ) );
        return $created_date ?: ( $start_date ?: current_time( 'Y-m-d' ) );
    }

    private static function sanitize_date( string $val ): string {
        $raw = sanitize_text_field( $val );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
    }
}
