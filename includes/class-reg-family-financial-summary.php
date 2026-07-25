<?php
/**
 * Olama Registration Family Financial Summary Service.
 *
 * Provides a single source of truth for all computed financial aggregates.
 * Uses a cached snapshot pattern for performance.
 *
 * @package Olama_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Olama_Reg_Family_Financial_Summary {

    /**
     * Resolve any supported family reference to Core's billing UID.
     */
    private static function canonical_family_uid( string $family_reference ): string {
        $family = Olama_Reg_Core_Gateway::family( $family_reference );
        return $family ? (string) $family->family_uid : $family_reference;
    }

    /**
     * Get the full financial summary for a family in a given academic year.
     *
     * @param string $family_uid
     * @param int $academic_year_id
     * @return object
     */
    public static function get_family_summary( string $family_uid, int $academic_year_id = 0 ): object {
        global $wpdb;
        $family_uid = self::canonical_family_uid( $family_uid );
        $table = $wpdb->prefix . 'olama_family_financial_snapshots';

        $snapshot = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE family_uid = %s AND academic_year_id = %d",
            $family_uid,
            $academic_year_id
        ) );

        if ( ! $snapshot ) {
            self::rebuild_snapshot( $family_uid, $academic_year_id );
            $snapshot = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$table} WHERE family_uid = %s AND academic_year_id = %d",
                $family_uid,
                $academic_year_id
            ) );
        }

        // Return snapshot if found, otherwise return a default blank object
        return $snapshot ?: (object) [
            'family_uid'               => $family_uid,
            'academic_year_id'         => $academic_year_id,
            'total_agreements'         => 0,
            'total_fees'               => 0.0,
            'total_discounts'          => 0.0,
            'gross_invoiced'           => 0.0,
            'total_paid'               => 0.0,
            'total_settlements'        => 0.0,
            'total_credit_adjustments' => 0.0,
            'total_debit_adjustments'  => 0.0,
            'net_adjustments'          => 0.0,
            'current_balance'          => 0.0,
            'oracle_balance'           => null,
            'reconciliation_difference'=> null,
            'due_now'                  => 0.0,
            'overdue'                  => 0.0,
            'previous_balance'         => 0.0,
            'unallocated_payments'     => 0.0,
        ];
    }

    /**
     * Get per-student financial breakdown for a family.
     *
     * @param string $family_uid
     * @param int $academic_year_id
     * @return object[]
     */
    public static function get_student_breakdown( string $family_uid, int $academic_year_id = 0 ): array {
        global $wpdb;

        // 1. Get all students associated with the family
        $students = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT ap.student_uid, s.student_name
             FROM {$wpdb->prefix}olama_agreement_participants ap
             LEFT JOIN {$wpdb->prefix}olama_core_students s
                ON s.student_uid = ap.student_uid
                OR (
                    s.oracle_student_id = COALESCE(NULLIF(ap.oracle_student_id, ''), ap.student_uid)
                    AND (
                        s.family_uid = ap.family_uid
                        OR s.oracle_family_id = COALESCE(NULLIF(ap.oracle_family_id, ''), ap.family_uid)
                    )
                )
             WHERE ap.family_uid = %s AND ap.participant_type = 'student' AND ap.student_uid IS NOT NULL AND ap.student_uid != ''",
            $family_uid
        ) ) ?: [];

        $targets = [];
        foreach ( $students as $student ) {
            $targets[] = (object) [
                'uid'  => $student->student_uid,
                'name' => $student->student_name ?: $student->student_uid,
            ];
        }
        // Add unassigned target to capture fees/payments not mapped to any specific child
        $targets[] = (object) [
            'uid'  => '',
            'name' => __( 'رسوم غير مخصصة لطلاب', 'olama-registration' ),
        ];

        $breakdown = [];

        foreach ( $targets as $target ) {
            $student_uid = $target->uid;

            if ( $student_uid !== '' ) {
                $where_child = $wpdb->prepare( "af.child_id = %s", $student_uid );
                $where_alloc_child = $wpdb->prepare( "pa.student_uid = %s", $student_uid );
            } else {
                $where_child = "(af.child_id IS NULL OR af.child_id = '')";
                $where_alloc_child = "(pa.student_uid IS NULL OR pa.student_uid = '')";
            }

            // Count agreements for this student
            $agreement_count = 0;
            if ( $student_uid !== '' ) {
                $agreement_count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(DISTINCT ap.agreement_id)
                     FROM {$wpdb->prefix}olama_agreement_participants ap
                     INNER JOIN {$wpdb->prefix}olama_agreements a ON a.id = ap.agreement_id
                     WHERE ap.student_uid = %s AND a.status != 'cancelled' AND (%d = 0 OR a.academic_year_id = %d)",
                    $student_uid,
                    $academic_year_id,
                    $academic_year_id
                ) );
            }

            // Sum fees specifically assigned to this student / unassigned
            $total_fees = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(af.net_amount), 0)
                 FROM {$wpdb->prefix}olama_agreement_fees af
                 INNER JOIN {$wpdb->prefix}olama_agreements a ON a.id = af.agreement_id
                 WHERE {$where_child} AND a.payer_id = %s AND a.status != 'cancelled' AND af.status != 'cancelled' AND (%d = 0 OR a.academic_year_id = %d)",
                $family_uid,
                $academic_year_id,
                $academic_year_id
            ) );

            // Sum invoiced amount (sum of individual net fee lines that have active invoices)
            $total_invoiced = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(af.net_amount), 0)
                 FROM {$wpdb->prefix}olama_agreement_fees af
                 INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = af.invoice_id
                 INNER JOIN {$wpdb->prefix}olama_agreements a ON a.id = af.agreement_id
                 WHERE {$where_child} AND a.payer_id = %s AND af.status != 'cancelled' AND i.status NOT IN ('draft', 'cancelled')
                   AND (%d = 0 OR i.academic_year_id = %d)",
                $family_uid,
                $academic_year_id,
                $academic_year_id
            ) );

            // Sum paid amount from payment allocations
            $total_paid = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(pa.amount), 0)
                 FROM {$wpdb->prefix}olama_payment_allocations pa
                 INNER JOIN {$wpdb->prefix}olama_payments p ON p.id = pa.payment_id
                 INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = pa.invoice_id
                 WHERE {$where_alloc_child}
                   AND p.family_uid = %s
                   AND pa.type = 'normal'
                   AND (p.status IS NULL OR p.status = '' OR p.status IN ('posted', 'reversed'))
                   AND i.status != 'cancelled'
                   AND (%d = 0 OR i.academic_year_id = %d)",
                $family_uid,
                $academic_year_id,
                $academic_year_id
            ) );

            // Sum overdue amount proportionally based on student's invoice ratio
            $overdue = 0.0;
            $family_invoices = $wpdb->get_results( $wpdb->prepare(
                "SELECT i.id, i.total,
                    (SELECT COALESCE(SUM(ia.amount), 0) FROM {$wpdb->prefix}olama_invoice_adjustments ia WHERE ia.invoice_id = i.id AND ia.status = 'issued' AND ia.type = 'debit') AS debit_total,
                    (SELECT COALESCE(SUM(ia.amount), 0) FROM {$wpdb->prefix}olama_invoice_adjustments ia WHERE ia.invoice_id = i.id AND ia.status = 'issued' AND ia.type = 'credit') AS credit_total,
                    (SELECT COALESCE(SUM(inst.amount_due - inst.amount_paid), 0) FROM {$wpdb->prefix}olama_invoice_installments inst WHERE inst.invoice_id = i.id AND inst.due_date < CURDATE() AND inst.status != 'paid') AS invoice_overdue
                 FROM {$wpdb->prefix}olama_invoices i
                 WHERE i.family_uid = %s AND i.status NOT IN ('draft', 'cancelled')
                   AND (%d = 0 OR i.academic_year_id = %d)",
                $family_uid,
                $academic_year_id,
                $academic_year_id
            ) );

            foreach ( $family_invoices as $inv ) {
                $invoice_overdue = (float) $inv->invoice_overdue;
                if ( $invoice_overdue <= 0.0 ) {
                    continue;
                }

                $effective_total = (float) $inv->total + (float) $inv->debit_total - (float) $inv->credit_total;
                if ( $effective_total <= 0.0 ) {
                    continue;
                }

                // Sum invoiced fees for this target on this invoice
                $target_inv_fees = (float) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COALESCE(SUM(af.net_amount), 0)
                     FROM {$wpdb->prefix}olama_agreement_fees af
                     WHERE af.invoice_id = %d AND af.status != 'cancelled' AND {$where_child}",
                    $inv->id
                ) );

                $ratio = $target_inv_fees / $effective_total;
                $overdue += $ratio * $invoice_overdue;
            }
            $overdue = round( $overdue, 3 );

            $remaining = round( max( 0.0, $total_invoiced - $total_paid ), 3 );

            // Only add unassigned row if there are actual financial values
            if ( $student_uid === '' && $total_fees <= 0.0 && $total_invoiced <= 0.0 && $total_paid <= 0.0 && $overdue <= 0.0 ) {
                continue;
            }

            $breakdown[] = (object) [
                'student_uid'     => $student_uid ?: 'unassigned',
                'student_name'    => $target->name,
                'agreement_count' => $agreement_count,
                'total_fees'      => $total_fees,
                'total_invoiced'  => $total_invoiced,
                'total_paid'      => $total_paid,
                'remaining'       => $remaining,
                'overdue'         => $overdue,
            ];
        }

        return $breakdown;
    }

    /**
     * Get per-agreement financial breakdown for a family.
     *
     * @param string $family_uid
     * @param int $academic_year_id
     * @return object[]
     */
    public static function get_agreement_breakdown( string $family_uid, int $academic_year_id = 0 ): array {
        global $wpdb;

        $agreements = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id AS agreement_id, a.agreement_number, a.activity_type, a.status,
                   (SELECT COALESCE(SUM(net_amount), 0) FROM {$wpdb->prefix}olama_agreement_fees WHERE agreement_id = a.id) AS total_fees,
                   (SELECT COALESCE(SUM(total), 0) FROM {$wpdb->prefix}olama_invoices WHERE agreement_id = a.id AND status NOT IN ('draft', 'cancelled')) AS total_invoiced,
                   (SELECT COALESCE(SUM(p.amount), 0) FROM {$wpdb->prefix}olama_payments p
                    INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = p.invoice_id
                    WHERE i.agreement_id = a.id AND (p.status IS NULL OR p.status = '' OR p.status IN ('posted', 'reversed'))) AS total_paid
            FROM {$wpdb->prefix}olama_agreements a
            WHERE a.payer_type = 'family' AND a.payer_id = %s AND a.status != 'cancelled'
              AND (%d = 0 OR a.academic_year_id = %d)
            ORDER BY a.created_at DESC",
            $family_uid,
            $academic_year_id,
            $academic_year_id
        ) ) ?: [];

        foreach ( $agreements as $agr ) {
            // Fetch student names for this agreement
            $student_names = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT COALESCE(s.student_name, ap.student_uid)
                 FROM {$wpdb->prefix}olama_agreement_participants ap
                 LEFT JOIN {$wpdb->prefix}olama_core_students s
                    ON s.student_uid = ap.student_uid
                    OR (
                        s.oracle_student_id = COALESCE(NULLIF(ap.oracle_student_id, ''), ap.student_uid)
                        AND (
                            s.family_uid = ap.family_uid
                            OR s.oracle_family_id = COALESCE(NULLIF(ap.oracle_family_id, ''), ap.family_uid)
                        )
                    )
                 WHERE ap.agreement_id = %d AND ap.participant_type = 'student'",
                $agr->agreement_id
            ) ) ?: [];

            $agr->students = implode( ', ', $student_names );
            $agr->total_fees = (float) $agr->total_fees;
            $agr->total_invoiced = (float) $agr->total_invoiced;
            $agr->total_paid = (float) $agr->total_paid;
            $agr->remaining = round( max( 0.0, $agr->total_fees - $agr->total_paid ), 3 );
        }

        return $agreements;
    }

    /**
     * Get the total amount due right now (installments with due_date <= today).
     *
     * @param string $family_uid
     * @param int $academic_year_id
     * @return float
     */
    public static function get_due_now( string $family_uid, int $academic_year_id = 0 ): float {
        global $wpdb;

        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(inst.amount_due - inst.amount_paid), 0)
             FROM {$wpdb->prefix}olama_invoice_installments inst
             INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = inst.invoice_id
             WHERE i.family_uid = %s
               AND inst.due_date <= CURDATE()
               AND inst.status != 'paid'
               AND i.status NOT IN ('draft', 'cancelled')
               AND (%d = 0 OR i.academic_year_id = %d)",
            $family_uid,
            $academic_year_id,
            $academic_year_id
        ) );
    }

    /**
     * Get the total overdue amount (installments past due_date, not fully paid).
     *
     * @param string $family_uid
     * @param int $academic_year_id
     * @return float
     */
    public static function get_overdue( string $family_uid, int $academic_year_id = 0 ): float {
        global $wpdb;

        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(inst.amount_due - inst.amount_paid), 0)
             FROM {$wpdb->prefix}olama_invoice_installments inst
             INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = inst.invoice_id
             WHERE i.family_uid = %s
               AND inst.due_date < CURDATE()
               AND inst.status != 'paid'
               AND i.status NOT IN ('draft', 'cancelled')
               AND (%d = 0 OR i.academic_year_id = %d)",
            $family_uid,
            $academic_year_id,
            $academic_year_id
        ) );
    }

    /**
     * Get total unallocated payments.
     * (Payments where total amount > sum of allocations)
     *
     * @param string $family_uid
     * @param int $academic_year_id
     * @return float
     */
    public static function get_unallocated_payments( string $family_uid, int $academic_year_id = 0 ): float {
        global $wpdb;

        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(unallocated), 0) FROM (
                SELECT p.amount - COALESCE(alloc.total_allocated, 0) AS unallocated
                FROM {$wpdb->prefix}olama_payments p
                INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = p.invoice_id
                LEFT JOIN (
                    SELECT payment_id, SUM(amount) AS total_allocated
                    FROM {$wpdb->prefix}olama_payment_allocations
                    WHERE type = 'normal'
                    GROUP BY payment_id
                ) alloc ON alloc.payment_id = p.id
                WHERE p.family_uid = %s
                  AND (p.status IS NULL OR p.status = '' OR p.status IN ('posted', 'reversed'))
                  AND i.status != 'cancelled'
                  AND (%d = 0 OR i.academic_year_id = %d)
            ) AS subquery WHERE unallocated > 0",
            $family_uid,
            $academic_year_id,
            $academic_year_id
        ) );
    }

    /**
     * Rebuild the snapshot cache for a family/year.
     *
     * @param string $family_uid
     * @param int $academic_year_id
     */
    public static function rebuild_snapshot( string $family_uid, int $academic_year_id = 0 ): void {
        global $wpdb;
        $family_uid = self::canonical_family_uid( $family_uid );
        $table = $wpdb->prefix . 'olama_family_financial_snapshots';

        // 1. Calculate agreements count
        $total_agreements = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT a.id)
             FROM {$wpdb->prefix}olama_agreements a
             WHERE a.payer_type = 'family' AND a.payer_id = %s AND a.status != 'cancelled'
               AND (%d = 0 OR a.academic_year_id = %d)",
            $family_uid,
            $academic_year_id,
            $academic_year_id
        ) );

        // 2. Calculate fees & discounts
        $fees_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COALESCE(SUM(af.amount), 0) AS total_fees, COALESCE(SUM(af.discount), 0) AS total_discounts
             FROM {$wpdb->prefix}olama_agreement_fees af
             INNER JOIN {$wpdb->prefix}olama_agreements a ON a.id = af.agreement_id
             WHERE a.payer_type = 'family' AND a.payer_id = %s AND a.status != 'cancelled'
               AND (%d = 0 OR a.academic_year_id = %d)",
            $family_uid,
            $academic_year_id,
            $academic_year_id
        ) );
        $total_fees = (float) ( $fees_row->total_fees ?? 0 );
        $total_discounts = (float) ( $fees_row->total_discounts ?? 0 );

        // 3. Calculate gross invoiced
        $gross_invoiced = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(total), 0) FROM {$wpdb->prefix}olama_invoices
             WHERE family_uid = %s AND status NOT IN ('draft', 'cancelled')
               AND (%d = 0 OR academic_year_id = %d)",
            $family_uid,
            $academic_year_id,
            $academic_year_id
        ) );

        // 4. Calculate total paid (include posted normal payments + posted reversal counterparts)
        $total_paid = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(p.amount), 0) FROM {$wpdb->prefix}olama_payments p
             INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = p.invoice_id
             WHERE p.family_uid = %s
               AND (p.status IS NULL OR p.status = '' OR p.status IN ('posted', 'reversed'))
               AND i.status != 'cancelled'
               AND (%d = 0 OR i.academic_year_id = %d)",
            $family_uid,
            $academic_year_id,
            $academic_year_id
        ) );

        // 5. Calculate total settlements (separate, not subtracted from balance)
        $settlement_clause = '';
        $settlement_params = [$family_uid];
        if ( $academic_year_id > 0 ) {
            $year_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT start_date, end_date FROM {$wpdb->prefix}olama_academic_years WHERE id = %d",
                $academic_year_id
            ) );
            if ( $year_row ) {
                $settlement_clause = " AND settlement_date BETWEEN %s AND %s";
                $settlement_params[] = $year_row->start_date;
                $settlement_params[] = $year_row->end_date;
            }
        }
        $total_settlements = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(settled_amount), 0) FROM {$wpdb->prefix}olama_settlement_receipts
             WHERE family_id = %s AND status = 'settled'{$settlement_clause}",
            ...$settlement_params
        ) );

        // 6. Calculate invoice adjustments
        $adjustments_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN ia.type = 'credit' THEN ia.amount ELSE 0 END), 0) AS credit_total,
                COALESCE(SUM(CASE WHEN ia.type = 'debit' THEN ia.amount ELSE 0 END), 0) AS debit_total
             FROM {$wpdb->prefix}olama_invoice_adjustments ia
             INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = ia.invoice_id
             WHERE i.family_uid = %s
               AND ia.status = 'issued'
               AND i.status != 'cancelled'
               AND (%d = 0 OR i.academic_year_id = %d)",
            $family_uid,
            $academic_year_id,
            $academic_year_id
        ) );
        $total_credit_adjustments = (float) ( $adjustments_row->credit_total ?? 0 );
        $total_debit_adjustments  = (float) ( $adjustments_row->debit_total ?? 0 );
        $net_adjustments          = $total_debit_adjustments - $total_credit_adjustments;

        // 6.5 Calculate previous balance
        $previous_balance = self::get_previous_balance( $family_uid, $academic_year_id );

        // 7. Calculate current balance
        $current_balance = $gross_invoiced + $net_adjustments - $total_paid + $previous_balance;

        // 8. Calculate due now & overdue
        $due_now = self::get_due_now( $family_uid, $academic_year_id ) + $previous_balance;
        $overdue = self::get_overdue( $family_uid, $academic_year_id ) + $previous_balance;

        // 9. Calculate unallocated payments
        $unallocated_payments = self::get_unallocated_payments( $family_uid, $academic_year_id );

        // Oracle is a read-only comparison ledger. It never changes the local
        // operational balance or causes local payments to be counted twice.
        $family = Olama_Reg_Core_Gateway::family( $family_uid );
        $oracle_data = Olama_Reg_Core_Gateway::oracle_financial( $family_uid, $academic_year_id );
        $oracle_summary = $oracle_data['summary'] ?? null;
        $oracle_balance = $oracle_summary
            ? (float) ( is_array( $oracle_summary ) ? ( $oracle_summary['balance'] ?? 0 ) : ( $oracle_summary->balance ?? 0 ) )
            : null;
        $reconciliation_difference = $oracle_balance !== null
            ? $current_balance - $oracle_balance
            : null;
        $study_year = (string) ( $oracle_data['study_year'] ?? '' );

        // MD5 Hash of data inputs for calculation cache integrity
        $hash_input = implode( '|', [
            $total_agreements,
            $total_fees,
            $total_discounts,
            $gross_invoiced,
            $total_paid,
            $total_settlements,
            $net_adjustments,
            $due_now,
            $overdue,
            $previous_balance,
            $unallocated_payments
        ] );
        $hash = md5( $hash_input );

        // Save snapshot to DB
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (
                family_uid, oracle_family_id, academic_year_id, study_year,
                total_agreements, total_fees, total_discounts,
                gross_invoiced, total_paid, total_settlements, total_credit_adjustments,
                total_debit_adjustments, net_adjustments, current_balance, oracle_balance,
                reconciliation_difference, due_now, overdue,
                previous_balance, unallocated_payments, calculated_at, calculation_hash
            ) VALUES (
                %s, %s, %d, %s,
                %d, %f, %f,
                %f, %f, %f, %f,
                %f, %f, %f, %f,
                %f, %f, %f,
                %f, %f, NOW(), %s
            ) ON DUPLICATE KEY UPDATE
                oracle_family_id = VALUES(oracle_family_id),
                study_year = VALUES(study_year),
                total_agreements = VALUES(total_agreements),
                total_fees = VALUES(total_fees),
                total_discounts = VALUES(total_discounts),
                gross_invoiced = VALUES(gross_invoiced),
                total_paid = VALUES(total_paid),
                total_settlements = VALUES(total_settlements),
                total_credit_adjustments = VALUES(total_credit_adjustments),
                total_debit_adjustments = VALUES(total_debit_adjustments),
                net_adjustments = VALUES(net_adjustments),
                current_balance = VALUES(current_balance),
                oracle_balance = VALUES(oracle_balance),
                reconciliation_difference = VALUES(reconciliation_difference),
                due_now = VALUES(due_now),
                overdue = VALUES(overdue),
                previous_balance = VALUES(previous_balance),
                unallocated_payments = VALUES(unallocated_payments),
                calculated_at = NOW(),
                calculation_hash = VALUES(calculation_hash)",
            $family_uid,
            $family ? $family->oracle_family_id : null,
            $academic_year_id,
            $study_year,
            $total_agreements,
            $total_fees,
            $total_discounts,
            $gross_invoiced,
            $total_paid,
            $total_settlements,
            $total_credit_adjustments,
            $total_debit_adjustments,
            $net_adjustments,
            $current_balance,
            $oracle_balance,
            $reconciliation_difference,
            $due_now,
            $overdue,
            $previous_balance,
            $unallocated_payments,
            $hash
        ) );
    }

    /**
     * Get carry-forward previous balance of all academic years prior to the specified one.
     *
     * @param string $family_uid
     * @param int $academic_year_id
     * @return float
     */
    public static function get_previous_balance( string $family_uid, int $academic_year_id ): float {
        global $wpdb;

        if ( ! $academic_year_id ) {
            return 0.0;
        }

        // Get start date of the current target year
        $target_year = $wpdb->get_row( $wpdb->prepare(
            "SELECT start_date FROM {$wpdb->prefix}olama_academic_years WHERE id = %d",
            $academic_year_id
        ) );

        if ( ! $target_year ) {
            return 0.0;
        }

        // Find all academic years with start_date < target start_date
        $prior_years = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}olama_academic_years WHERE start_date < %s",
            $target_year->start_date
        ) );

        if ( empty( $prior_years ) ) {
            return 0.0;
        }

        $previous_balance = 0.0;

        foreach ( $prior_years as $prior_year_id ) {
            $prior_year_id = (int) $prior_year_id;

            // Compute the financial totals for this prior year specifically (non-cumulatively)
            // 1. Gross invoiced for this prior year
            $gross_invoiced = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(total), 0) FROM {$wpdb->prefix}olama_invoices
                 WHERE family_uid = %s AND status NOT IN ('draft', 'cancelled')
                   AND academic_year_id = %d",
                $family_uid,
                $prior_year_id
            ) );

            // 2. Paid for this prior year
            $total_paid = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(p.amount), 0) FROM {$wpdb->prefix}olama_payments p
                 INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = p.invoice_id
                 WHERE p.family_uid = %s
                   AND (p.status IS NULL OR p.status = '' OR p.status IN ('posted', 'reversed'))
                   AND i.status != 'cancelled'
                   AND i.academic_year_id = %d",
                $family_uid,
                $prior_year_id
            ) );

            // 3. Adjustments for this prior year
            $adjustments_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN ia.type = 'credit' THEN ia.amount ELSE 0 END), 0) AS credit_total,
                    COALESCE(SUM(CASE WHEN ia.type = 'debit' THEN ia.amount ELSE 0 END), 0) AS debit_total
                 FROM {$wpdb->prefix}olama_invoice_adjustments ia
                 INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = ia.invoice_id
                 WHERE i.family_uid = %s
                   AND ia.status = 'issued'
                   AND i.status != 'cancelled'
                   AND i.academic_year_id = %d",
                $family_uid,
                $prior_year_id
            ) );
            $total_credit = (float) ( $adjustments_row->credit_total ?? 0 );
            $total_debit  = (float) ( $adjustments_row->debit_total ?? 0 );
            $net_adjustments = $total_debit - $total_credit;

            // Prior year specific balance
            $prior_balance = $gross_invoiced + $net_adjustments - $total_paid;
            $previous_balance += $prior_balance;
        }

        return $previous_balance;
    }

    /**
     * Invalidate the snapshot cache for a family.
     *
     * @param string $family_uid
     * @param int $academic_year_id
     */
    public static function invalidate_snapshot( string $family_uid, int $academic_year_id = 0 ): void {
        global $wpdb;
        $family = Olama_Reg_Core_Gateway::family( $family_uid );
        $family_uid = $family ? (string) $family->family_uid : $family_uid;
        $table = $wpdb->prefix . 'olama_family_financial_snapshots';

        if ( $academic_year_id > 0 ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table}
                 WHERE academic_year_id = %d
                   AND (family_uid = %s OR oracle_family_id = %s)",
                $academic_year_id,
                $family_uid,
                $family ? (string) $family->oracle_family_id : ''
            ) );
        } else {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table}
                 WHERE family_uid = %s OR oracle_family_id = %s",
                $family_uid,
                $family ? (string) $family->oracle_family_id : ''
            ) );
        }
    }
}
