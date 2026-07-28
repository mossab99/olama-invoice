<?php
/**
 * Billing reports and analytics class.
 *
 * Tables used:
 *   {prefix}olama_invoices
 *   {prefix}olama_payments
 *   {prefix}olama_invoice_installments
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Billing_Reports {

    private static function t( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    /**
     * Get overall key metrics for a given academic year.
     */
    public static function get_summary_metrics( int $year_id ): array {
        global $wpdb;

        $params = [];
        $where  = "status NOT IN ('draft', 'cancelled')";
        if ( $year_id > 0 ) {
            $where .= " AND academic_year_id = %d";
            $params[] = $year_id;
        }

        $query = "SELECT 
            COALESCE(SUM(i.total + COALESCE(adj.debit_total, 0) - COALESCE(adj.credit_total, 0)), 0) AS total_invoiced,
            COALESCE(SUM(i.amount_paid), 0) AS total_collected,
            COALESCE(SUM(i.balance), 0) AS total_receivables,
            COALESCE(SUM(i.discount + COALESCE(fee_discounts.contract_discount, 0)), 0) AS total_discount
            FROM " . self::t( 'olama_invoices' ) . " i
            LEFT JOIN (
                SELECT invoice_id,
                       SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) AS debit_total,
                       SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) AS credit_total
                FROM " . self::t( 'olama_invoice_adjustments' ) . "
                WHERE status = 'issued'
                GROUP BY invoice_id
            ) adj ON adj.invoice_id = i.id
            LEFT JOIN (
                SELECT items.invoice_id,
                       SUM(COALESCE(fees.discount, 0) * COALESCE(items.quantity, 1)) AS contract_discount
                FROM " . self::t( 'olama_invoice_items' ) . " items
                INNER JOIN " . self::t( 'olama_agreement_fees' ) . " fees
                        ON fees.id = items.agreement_fee_id
                GROUP BY items.invoice_id
            ) fee_discounts ON fee_discounts.invoice_id = i.id
            WHERE " . str_replace( [ 'status', 'academic_year_id' ], [ 'i.status', 'i.academic_year_id' ], $where );

        if ( ! empty( $params ) ) {
            $row = $wpdb->get_row( $wpdb->prepare( $query, ...$params ) );
        } else {
            $row = $wpdb->get_row( $query );
        }

        $aging = self::get_aging_receivables( $year_id );

        return [
            'total_invoiced'    => (float) ( $row->total_invoiced ?? 0 ),
            'total_collected'   => (float) ( $row->total_collected ?? 0 ),
            'total_receivables' => (float) ( $row->total_receivables ?? 0 ),
            'total_discount'    => (float) ( $row->total_discount ?? 0 ),
            'total_overdue'     => array_sum( $aging ),
        ];
    }

    /**
     * Get monthly collections breakdown.
     */
    public static function get_monthly_collections( int $year_id ): array {
        global $wpdb;

		$params = [];
		$join = "";
		$where = "(p.status IS NULL OR p.status = '' OR p.status IN ('posted','reversed'))";

		if ( $year_id > 0 ) {
			$join = " LEFT JOIN " . self::t( 'olama_invoices' ) . " i ON i.id = p.invoice_id";
			$where .= " AND i.academic_year_id = %d";
			$params[] = $year_id;
		}

        $query = "SELECT 
            DATE_FORMAT(p.payment_date, '%Y-%m') AS month_label,
            SUM(p.amount) AS amount
            FROM " . self::t( 'olama_payments' ) . " p
            {$join}
            WHERE {$where}
            GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
            ORDER BY month_label ASC";

        if ( ! empty( $params ) ) {
            $results = $wpdb->get_results( $wpdb->prepare( $query, ...$params ) );
        } else {
            $results = $wpdb->get_results( $query );
        }

        $data = [];
        foreach ( (array) $results as $r ) {
            $data[] = [
                'month'  => $r->month_label,
                'amount' => (float) $r->amount,
            ];
        }

        return $data;
    }

    /**
     * Get collection breakdown by payment method.
     */
    public static function get_payment_method_breakdown( int $year_id ): array {
        global $wpdb;

		$params = [];
		$join = " LEFT JOIN " . self::t( 'olama_payments' ) . " orig ON orig.id = p.reversed_payment_id";
		$where = "(p.status IS NULL OR p.status = '' OR p.status IN ('posted','reversed'))";

		if ( $year_id > 0 ) {
			$join .= " LEFT JOIN " . self::t( 'olama_invoices' ) . " i ON i.id = p.invoice_id";
			$where .= " AND i.academic_year_id = %d";
			$params[] = $year_id;
		}

        $query = "SELECT 
            COALESCE(
                NULLIF(
                    CASE
                        WHEN p.method = 'reversal' OR p.amount < 0 THEN orig.method
                        ELSE p.method
                    END,
                    ''
                ),
                'other'
            ) AS method,
            SUM(p.amount) AS total_amount,
            COUNT(*) AS transaction_count
            FROM " . self::t( 'olama_payments' ) . " p
            {$join}
            WHERE {$where}
            GROUP BY method";

        if ( ! empty( $params ) ) {
            $results = $wpdb->get_results( $wpdb->prepare( $query, ...$params ) );
        } else {
            $results = $wpdb->get_results( $query );
        }

        $data = [];
        foreach ( (array) $results as $r ) {
            $data[] = [
                'method' => $r->method,
                'amount' => (float) $r->total_amount,
                'count'  => (int) $r->transaction_count,
            ];
        }

        return $data;
    }

    /**
     * Get aging receivables: 0-30, 31-60, 61-90, 91+ days overdue.
     */
    public static function get_aging_receivables( int $year_id = 0 ): array {
        global $wpdb;

        $today = esc_sql( current_time( 'Y-m-d' ) );
        $params = [];
        $installment_year = '';
        $invoice_year = '';
        if ( $year_id > 0 ) {
            $installment_year = ' AND i.academic_year_id = %d';
            $invoice_year = ' AND i.academic_year_id = %d';
            $params[] = $year_id;
            $params[] = $year_id;
        }

        $query = "SELECT
            SUM(CASE WHEN DATEDIFF('{$today}', debt.due_date) BETWEEN 1 AND 30 THEN debt.remaining ELSE 0 END) AS band_30,
            SUM(CASE WHEN DATEDIFF('{$today}', debt.due_date) BETWEEN 31 AND 60 THEN debt.remaining ELSE 0 END) AS band_60,
            SUM(CASE WHEN DATEDIFF('{$today}', debt.due_date) BETWEEN 61 AND 90 THEN debt.remaining ELSE 0 END) AS band_90,
            SUM(CASE WHEN DATEDIFF('{$today}', debt.due_date) > 90 THEN debt.remaining ELSE 0 END) AS band_90_plus
            FROM (
                SELECT inst.due_date,
                       GREATEST(inst.amount_due - inst.amount_paid, 0) AS remaining
                FROM " . self::t( 'olama_invoice_installments' ) . " inst
                INNER JOIN " . self::t( 'olama_invoices' ) . " i ON i.id = inst.invoice_id
                WHERE i.status NOT IN ('paid','draft','cancelled')
                  AND inst.status != 'cancelled'
                  AND (inst.amount_due - inst.amount_paid) > 0
                  AND inst.due_date < '{$today}'
                  {$installment_year}

                UNION ALL

                SELECT i.due_date,
                       GREATEST(i.balance, 0) AS remaining
                FROM " . self::t( 'olama_invoices' ) . " i
                WHERE i.status NOT IN ('paid','draft','cancelled')
                  AND i.balance > 0
                  AND i.due_date IS NOT NULL
                  AND i.due_date < '{$today}'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM " . self::t( 'olama_invoice_installments' ) . " inst
                      WHERE inst.invoice_id = i.id
                        AND inst.status != 'cancelled'
                  )
                  {$invoice_year}
            ) debt";

        if ( ! empty( $params ) ) {
            $row = $wpdb->get_row( $wpdb->prepare( $query, ...$params ) );
        } else {
            $row = $wpdb->get_row( $query );
        }

        return [
            'band_30'      => (float) ( $row->band_30 ?? 0 ),
            'band_60'      => (float) ( $row->band_60 ?? 0 ),
            'band_90'      => (float) ( $row->band_90 ?? 0 ),
            'band_90_plus' => (float) ( $row->band_90_plus ?? 0 ),
        ];
    }

    public static function get_method_labels(): array {
        return [
            'cash'          => __( 'نقدي', 'olama-registration' ),
            'cheque'        => __( 'شيك بنكي', 'olama-registration' ),
            'online'        => __( 'دفع إلكتروني', 'olama-registration' ),
            'bank_transfer' => __( 'تحويل بنكي', 'olama-registration' ),
        ];
    }

    public static function get_available_payment_methods(): array {
        global $wpdb;

        $rows = $wpdb->get_col(
            "SELECT DISTINCT method FROM " . self::t( 'olama_payments' ) . "
             WHERE method IS NOT NULL AND method != ''
             ORDER BY method ASC"
        ) ?: [];

        $labels = self::get_method_labels();
        $methods = [];
        foreach ( $rows as $method ) {
            $methods[ $method ] = $labels[ $method ] ?? $method;
        }

        foreach ( $labels as $method => $label ) {
            if ( ! isset( $methods[ $method ] ) ) {
                $methods[ $method ] = $label;
            }
        }

        return $methods;
    }

    public static function get_cashier_options(): array {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT DISTINCT u.ID, u.display_name
             FROM " . self::t( 'olama_payments' ) . " p
             INNER JOIN {$wpdb->users} u ON u.ID = p.received_by
             WHERE p.received_by > 0
             ORDER BY u.display_name ASC"
        ) ?: [];
    }

    public static function get_cash_register_report( array $filters = [] ): array {
        global $wpdb;

        $year_id     = absint( $filters['year_id'] ?? 0 );
        $date_from   = self::sanitize_report_date( $filters['date_from'] ?? '' );
        $date_to     = self::sanitize_report_date( $filters['date_to'] ?? '' );
        $method      = sanitize_text_field( $filters['method'] ?? '' );
        $cashier_id  = absint( $filters['cashier_id'] ?? 0 );

		$where  = [ "p.amount > 0", "p.method != 'reversal'", "(p.status IS NULL OR p.status = '' OR p.status = 'posted')" ];
        $params = [];

        if ( $year_id > 0 ) {
            $where[]  = "i.academic_year_id = %d";
            $params[] = $year_id;
        }
        if ( $date_from ) {
            $where[]  = "p.payment_date >= %s";
            $params[] = $date_from;
        }
        if ( $date_to ) {
            $where[]  = "p.payment_date <= %s";
            $params[] = $date_to;
        }
        if ( $method !== '' ) {
            $where[]  = "p.method = %s";
            $params[] = $method;
        }
        if ( $cashier_id > 0 ) {
            $where[]  = "p.received_by = %d";
            $params[] = $cashier_id;
        }

        $where_sql = implode( ' AND ', $where );

        $query = "SELECT
                p.id,
                p.payment_date,
                p.method,
                p.amount,
                p.reference,
                p.notes,
                p.received_by,
                cs.session_no,
                i.invoice_number,
                i.student_uid,
                i.academic_year_id,
                COALESCE(s.student_name, ec.child_name, f.sponsor_full_name, f.father_name, f.mother_name, c.customer_name, p.family_uid) AS student_name,
                COALESCE(i.student_uid, ec.child_uid, p.family_uid) AS student_identifier,
                u.display_name AS received_by_name
            FROM " . self::t( 'olama_payments' ) . " p
            LEFT JOIN " . self::t( 'olama_invoices' ) . " i ON i.id = p.invoice_id
            LEFT JOIN " . olama_core()->read_models()->table( 'students' ) . " s
                ON s.student_uid = i.student_uid
                OR (
                    s.oracle_student_id = COALESCE(NULLIF(i.oracle_student_id, ''), i.student_uid)
                    AND (
                        s.family_uid = i.family_uid
                        OR s.oracle_family_id = COALESCE(NULLIF(i.oracle_family_id, ''), i.family_uid)
                    )
                )
            LEFT JOIN " . olama_core()->read_models()->table( 'families' ) . " f
                ON f.family_uid = p.family_uid
                OR f.oracle_family_id = COALESCE(NULLIF(p.oracle_family_id, ''), p.family_uid)
            LEFT JOIN " . self::t( 'olama_customers' ) . " c ON c.customer_uid = p.family_uid
            LEFT JOIN " . self::t( 'olama_customer_children' ) . " ec ON ec.id = i.ext_child_id
            LEFT JOIN " . self::t( 'olama_cash_sessions' ) . " cs ON cs.id = p.cash_session_id
            LEFT JOIN {$wpdb->users} u ON u.ID = p.received_by
            WHERE {$where_sql}
            ORDER BY p.payment_date ASC, p.id ASC";

        $rows = $params
            ? ( $wpdb->get_results( $wpdb->prepare( $query, ...$params ) ) ?: [] )
            : ( $wpdb->get_results( $query ) ?: [] );

        $summary = [
            'cash'              => 0.0,
            'cheque'            => 0.0,
            'online'            => 0.0,
            'bank_transfer'     => 0.0,
            'other'             => 0.0,
            'total'             => 0.0,
            'transaction_count' => count( $rows ),
        ];

        foreach ( $rows as $row ) {
            $amount = (float) $row->amount;
            $method_key = (string) $row->method;
            if ( isset( $summary[ $method_key ] ) ) {
                $summary[ $method_key ] += $amount;
            } else {
                $summary['other'] += $amount;
            }
            $summary['total'] += $amount;
        }

        return [
            'rows'    => $rows,
            'summary' => $summary,
            'filters' => [
                'year_id'    => $year_id,
                'date_from'  => $date_from,
                'date_to'    => $date_to,
                'method'     => $method,
                'cashier_id' => $cashier_id,
            ],
        ];
    }

    public static function get_financial_account_options(): array {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT id, account_code, account_name, type
             FROM " . self::t( 'olama_financial_accounts' ) . "
             WHERE is_active = 1
             ORDER BY type ASC, is_default DESC, account_name ASC"
        ) ?: [];
    }

    public static function get_account_ledger_report( array $filters = [] ): array {
        global $wpdb;

        $account_id = absint( $filters['account_id'] ?? 0 );
        $date_from  = self::sanitize_report_date( $filters['date_from'] ?? '' );
        $date_to    = self::sanitize_report_date( $filters['date_to'] ?? '' );

        $where = [ '1=1' ];
        $params = [];
        if ( $account_id > 0 ) {
            $where[] = 'm.account_id = %d';
            $params[] = $account_id;
        }
        if ( $date_from ) {
            $where[] = 'm.movement_date >= %s';
            $params[] = $date_from;
        }
        if ( $date_to ) {
            $where[] = 'm.movement_date <= %s';
            $params[] = $date_to;
        }

        $query = "SELECT
                m.*,
                a.account_code,
                a.account_name,
                a.type AS account_type,
                a.opening_balance,
                p.payment_no,
                p.invoice_id
            FROM " . self::t( 'olama_cash_bank_movements' ) . " m
            LEFT JOIN " . self::t( 'olama_financial_accounts' ) . " a ON a.id = m.account_id
            LEFT JOIN " . self::t( 'olama_payments' ) . " p ON m.source_type = 'payment' AND p.id = m.source_id
            WHERE " . implode( ' AND ', $where ) . "
            ORDER BY m.movement_date ASC, m.id ASC";

        $rows = $params
            ? ( $wpdb->get_results( $wpdb->prepare( $query, ...$params ) ) ?: [] )
            : ( $wpdb->get_results( $query ) ?: [] );

        $running = [];
        $opening_by_account = [];
        if ( $date_from ) {
            $opening_where  = [ 'm.movement_date < %s' ];
            $opening_params = [ $date_from ];
            if ( $account_id > 0 ) {
                $opening_where[]  = 'a.id = %d';
                $opening_params[] = $account_id;
            }

            $opening_sql = "SELECT
                    a.id AS account_id,
                    (COALESCE(a.opening_balance, 0) + COALESCE(SUM(CASE WHEN m.direction = 'out' THEN -1 * m.amount ELSE m.amount END), 0)) AS opening_balance
                FROM " . self::t( 'olama_financial_accounts' ) . " a
                LEFT JOIN " . self::t( 'olama_cash_bank_movements' ) . " m
                    ON m.account_id = a.id AND " . implode( ' AND ', $opening_where ) . "
                GROUP BY a.id";

            $opening_rows = $wpdb->get_results( $wpdb->prepare( $opening_sql, ...$opening_params ) ) ?: [];
            foreach ( $opening_rows as $opening_row ) {
                $opening_by_account[ (int) $opening_row->account_id ] = (float) $opening_row->opening_balance;
            }
        }

        foreach ( $rows as $row ) {
            $aid = (int) $row->account_id;
            if ( ! isset( $running[ $aid ] ) ) {
                $running[ $aid ] = array_key_exists( $aid, $opening_by_account )
                    ? (float) $opening_by_account[ $aid ]
                    : (float) ( $row->opening_balance ?? 0 );
            }
            $running[ $aid ] += (string) $row->direction === 'out' ? -1 * (float) $row->amount : (float) $row->amount;
            $row->running_balance = round( $running[ $aid ], 2 );
        }

        return [
            'rows'    => $rows,
            'filters' => [
                'account_id' => $account_id,
                'date_from'  => $date_from,
                'date_to'    => $date_to,
            ],
        ];
    }

    public static function get_receipts_report( array $filters = [] ): array {
        global $wpdb;

        $date_from  = self::sanitize_report_date( $filters['date_from'] ?? '' );
        $date_to    = self::sanitize_report_date( $filters['date_to'] ?? '' );
        $method     = sanitize_text_field( $filters['method'] ?? '' );
        $status     = sanitize_key( $filters['status'] ?? '' );
        $account_id = absint( $filters['account_id'] ?? 0 );
        if ( $status === 'pending' ) {
            $status = 'pending_review';
        }

        $where = [ '1=1' ];
        $params = [];
        if ( $date_from ) {
            $where[] = 'p.payment_date >= %s';
            $params[] = $date_from;
        }
        if ( $date_to ) {
            $where[] = 'p.payment_date <= %s';
            $params[] = $date_to;
        }
        if ( $method !== '' ) {
            $where[] = 'p.method = %s';
            $params[] = $method;
        }
        if ( $status !== '' ) {
            $where[] = 'p.status = %s';
            $params[] = $status;
        }
        if ( $account_id > 0 ) {
            $where[] = 'p.account_id = %d';
            $params[] = $account_id;
        }

        $query = "SELECT p.*, i.invoice_number, a.account_name, cs.session_no, u.display_name AS received_by_name
            FROM " . self::t( 'olama_payments' ) . " p
            LEFT JOIN " . self::t( 'olama_invoices' ) . " i ON i.id = p.invoice_id
            LEFT JOIN " . self::t( 'olama_financial_accounts' ) . " a ON a.id = p.account_id
            LEFT JOIN " . self::t( 'olama_cash_sessions' ) . " cs ON cs.id = p.cash_session_id
            LEFT JOIN {$wpdb->users} u ON u.ID = p.received_by
            WHERE " . implode( ' AND ', $where ) . "
            ORDER BY p.payment_date DESC, p.id DESC";

        $rows = $params ? ( $wpdb->get_results( $wpdb->prepare( $query, ...$params ) ) ?: [] ) : ( $wpdb->get_results( $query ) ?: [] );

        return [
            'rows'    => $rows,
            'filters' => compact( 'date_from', 'date_to', 'method', 'status', 'account_id' ),
        ];
    }

    public static function get_allocation_report( array $filters = [] ): array {
        global $wpdb;

        $date_from = self::sanitize_report_date( $filters['date_from'] ?? '' );
        $date_to   = self::sanitize_report_date( $filters['date_to'] ?? '' );

        $where = [ '1=1' ];
        $params = [];
        if ( $date_from ) {
            $where[] = 'pa.allocation_date >= %s';
            $params[] = $date_from;
        }
        if ( $date_to ) {
            $where[] = 'pa.allocation_date <= %s';
            $params[] = $date_to;
        }

        $query = "SELECT pa.*, pa.amount AS amount_allocated, p.payment_no, p.method, p.status AS payment_status,
                i.invoice_number, inst.installment_no, inst.due_date, inst.amount_due
            FROM " . self::t( 'olama_payment_allocations' ) . " pa
            LEFT JOIN " . self::t( 'olama_payments' ) . " p ON p.id = pa.payment_id
            LEFT JOIN " . self::t( 'olama_invoices' ) . " i ON i.id = pa.invoice_id
            LEFT JOIN " . self::t( 'olama_invoice_installments' ) . " inst ON inst.id = pa.installment_id
            WHERE " . implode( ' AND ', $where ) . "
            ORDER BY pa.allocation_date DESC, pa.id DESC";

        $rows = $params ? ( $wpdb->get_results( $wpdb->prepare( $query, ...$params ) ) ?: [] ) : ( $wpdb->get_results( $query ) ?: [] );

        return [
            'rows'    => $rows,
            'filters' => compact( 'date_from', 'date_to' ),
        ];
    }

    public static function get_cheques_report( array $filters = [] ): array {
        global $wpdb;

        $status = sanitize_key( $filters['status'] ?? '' );
        $where = [ '1=1' ];
        $params = [];
        if ( $status !== '' ) {
            $where[] = 'c.status = %s';
            $params[] = $status;
        }

        $query = "SELECT c.*, c.check_no AS cheque_no, p.payment_no, p.payment_date, p.reference, p.status AS payment_status,
                i.invoice_number, a.account_name
            FROM " . self::t( 'olama_cheques' ) . " c
            LEFT JOIN " . self::t( 'olama_payments' ) . " p ON p.id = c.payment_id
            LEFT JOIN " . self::t( 'olama_invoices' ) . " i ON i.id = p.invoice_id
            LEFT JOIN " . self::t( 'olama_financial_accounts' ) . " a ON a.id = p.account_id
            WHERE " . implode( ' AND ', $where ) . "
            ORDER BY c.due_date ASC, c.id DESC";

        $rows = $params ? ( $wpdb->get_results( $wpdb->prepare( $query, ...$params ) ) ?: [] ) : ( $wpdb->get_results( $query ) ?: [] );

        return [
            'rows'    => $rows,
            'filters' => [ 'status' => $status ],
        ];
    }

    /**
     * Return balances for contract families only.
     *
     * Olama Core remains the source of family names and phone numbers. Financial
     * values are calculated from this module's posted invoice activity.
     */
    public static function get_family_balances_report( array $filters = [] ): array {
        global $wpdb;

        $year_id = absint( $filters['year_id'] ?? 0 );
        $search  = sanitize_text_field( $filters['search'] ?? '' );

        $agreement_year = $year_id > 0
            ? $wpdb->prepare( ' AND a.academic_year_id = %d', $year_id )
            : '';
        $invoice_year = $year_id > 0
            ? $wpdb->prepare( ' AND i.academic_year_id = %d', $year_id )
            : '';

        $agreement_scope = "SELECT
                a.payer_id AS family_ref,
                MAX(NULLIF(a.oracle_family_id, '')) AS oracle_family_id
            FROM " . self::t( 'olama_agreements' ) . " a
            WHERE a.payer_type = 'family'
              AND a.payer_id IS NOT NULL
              AND a.payer_id <> ''
              AND COALESCE(a.status, '') <> 'cancelled'
              {$agreement_year}
            GROUP BY a.payer_id";

        $invoice_totals = "SELECT
                COALESCE(NULLIF(i.oracle_family_id, ''), i.family_uid) AS family_ref,
                SUM(CAST(i.total AS DECIMAL(18,2))) AS invoice_debit
            FROM " . self::t( 'olama_invoices' ) . " i
            WHERE i.status NOT IN ('draft', 'cancelled')
              AND COALESCE(NULLIF(i.oracle_family_id, ''), i.family_uid) IS NOT NULL
              {$invoice_year}
            GROUP BY COALESCE(NULLIF(i.oracle_family_id, ''), i.family_uid)";

        $adjustment_totals = "SELECT
                COALESCE(NULLIF(i.oracle_family_id, ''), i.family_uid) AS family_ref,
                SUM(CASE WHEN adj.type = 'debit' THEN CAST(adj.amount AS DECIMAL(18,2)) ELSE 0 END) AS adjustment_debit,
                SUM(CASE WHEN adj.type = 'credit' THEN CAST(adj.amount AS DECIMAL(18,2)) ELSE 0 END) AS adjustment_credit
            FROM " . self::t( 'olama_invoice_adjustments' ) . " adj
            INNER JOIN " . self::t( 'olama_invoices' ) . " i ON i.id = adj.invoice_id
            WHERE adj.status = 'issued'
              AND i.status NOT IN ('draft', 'cancelled')
              {$invoice_year}
            GROUP BY COALESCE(NULLIF(i.oracle_family_id, ''), i.family_uid)";

        $payment_totals = "SELECT
                COALESCE(NULLIF(i.oracle_family_id, ''), i.family_uid) AS family_ref,
                SUM(CASE
                    WHEN p.method = 'reversal' OR p.amount < 0 OR p.reversed_payment_id IS NOT NULL
                    THEN ABS(CAST(p.amount AS DECIMAL(18,2))) ELSE 0 END) AS payment_debit,
                SUM(CASE
                    WHEN p.method = 'reversal' OR p.amount < 0 OR p.reversed_payment_id IS NOT NULL
                    THEN 0 ELSE ABS(CAST(p.amount AS DECIMAL(18,2))) END) AS payment_credit
            FROM " . self::t( 'olama_payments' ) . " p
            INNER JOIN " . self::t( 'olama_invoices' ) . " i ON i.id = p.invoice_id
            WHERE i.status NOT IN ('draft', 'cancelled')
              AND (p.status IS NULL OR p.status = '' OR p.status IN ('posted', 'reversed'))
              {$invoice_year}
            GROUP BY COALESCE(NULLIF(i.oracle_family_id, ''), i.family_uid)";

        $search_sql    = '';
        $search_params = [];
        if ( $search !== '' ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $search_sql = ' WHERE (
                scope.family_ref LIKE %s
                OR scope.oracle_family_id LIKE %s
                OR f.oracle_family_id LIKE %s
                OR f.sponsor_full_name LIKE %s
                OR f.father_name LIKE %s
                OR f.mother_name LIKE %s
            )';
            $search_params = array_fill( 0, 6, $like );
        }

        $query = "SELECT
                scope.family_ref,
                COALESCE(NULLIF(f.oracle_family_id, ''), NULLIF(scope.oracle_family_id, ''), scope.family_ref) AS family_number,
                COALESCE(
                    NULLIF(f.sponsor_full_name, ''),
                    NULLIF(f.father_name, ''),
                    NULLIF(f.mother_name, ''),
                    scope.family_ref
                ) AS family_name,
                COALESCE(f.father_mobile, '') AS father_mobile,
                COALESCE(f.mother_mobile, '') AS mother_mobile,
                COALESCE(inv.invoice_debit, 0) AS invoice_debit,
                COALESCE(adj.adjustment_debit, 0) AS adjustment_debit,
                COALESCE(adj.adjustment_credit, 0) AS adjustment_credit,
                COALESCE(pay.payment_debit, 0) AS payment_debit,
                COALESCE(pay.payment_credit, 0) AS payment_credit
            FROM ({$agreement_scope}) scope
            LEFT JOIN " . olama_core()->read_models()->table( 'families' ) . " f
                ON f.oracle_family_id = scope.family_ref
                OR f.family_uid = scope.family_ref
                OR (
                    scope.oracle_family_id IS NOT NULL
                    AND (
                        f.oracle_family_id = scope.oracle_family_id
                        OR f.family_uid = scope.oracle_family_id
                    )
                )
            LEFT JOIN ({$invoice_totals}) inv
                ON inv.family_ref = scope.family_ref OR inv.family_ref = scope.oracle_family_id
            LEFT JOIN ({$adjustment_totals}) adj
                ON adj.family_ref = scope.family_ref OR adj.family_ref = scope.oracle_family_id
            LEFT JOIN ({$payment_totals}) pay
                ON pay.family_ref = scope.family_ref OR pay.family_ref = scope.oracle_family_id
            {$search_sql}
            ORDER BY CAST(COALESCE(NULLIF(f.oracle_family_id, ''), scope.family_ref) AS UNSIGNED), family_name";

        $rows = $search_params
            ? ( $wpdb->get_results( $wpdb->prepare( $query, ...$search_params ) ) ?: [] )
            : ( $wpdb->get_results( $query ) ?: [] );

        $summary = [
            'family_count' => count( $rows ),
            'total_debit'  => 0.0,
            'total_credit' => 0.0,
            'balance'      => 0.0,
        ];

        foreach ( $rows as $row ) {
            $total_debit  = (float) $row->invoice_debit + (float) $row->adjustment_debit + (float) $row->payment_debit;
            $total_credit = (float) $row->adjustment_credit + (float) $row->payment_credit;
            $net_balance  = round( $total_debit - $total_credit, 2 );

            $row->total_debit  = round( $total_debit, 2 );
            $row->total_credit = round( $total_credit, 2 );
            $row->balance      = $net_balance;

            $summary['total_debit']  += $row->total_debit;
            $summary['total_credit'] += $row->total_credit;
        }

        $summary['total_debit']  = round( $summary['total_debit'], 2 );
        $summary['total_credit'] = round( $summary['total_credit'], 2 );
        $summary['balance']      = round( $summary['total_debit'] - $summary['total_credit'], 2 );

        return [
            'rows'    => $rows,
            'summary' => $summary,
            'filters' => [
                'year_id' => $year_id,
                'search'  => $search,
            ],
        ];
    }

    public static function get_family_statement_report( array $filters = [] ): array {
        global $wpdb;

        $entity_type = sanitize_key( $filters['entity_type'] ?? 'family' );
        if ( ! in_array( $entity_type, [ 'family', 'external' ], true ) ) {
            $entity_type = 'family';
        }

        $uid         = sanitize_text_field( $filters['uid'] ?? '' );
        $year_id   = absint( $filters['year_id'] ?? 0 );
        $student_uid = ! empty( $filters['student_uid'] ) ? sanitize_text_field( $filters['student_uid'] ) : '';
        $date_from = self::sanitize_report_date( $filters['date_from'] ?? '' );
        $date_to   = self::sanitize_report_date( $filters['date_to'] ?? '' );

        $entity = [
            'type' => $entity_type,
            'uid'  => $uid,
            'name' => '',
        ];

        $customer_id = 0;
        $family_refs  = $uid !== '' ? [ $uid ] : [];
        $balance_uid  = $uid;
        if ( $uid !== '' ) {
            if ( $entity_type === 'family' ) {
                $entity_row = Olama_Reg_Family::get_family( $uid );
                if ( $entity_row ) {
                    $entity['name'] = (string) $entity_row->family_name;
                    $balance_uid    = (string) ( $entity_row->oracle_family_id ?? $entity_row->family_uid ?? $uid );
                    $family_refs    = array_values( array_unique( array_filter( [
                        $uid,
                        (string) ( $entity_row->family_uid ?? '' ),
                        (string) ( $entity_row->oracle_family_id ?? '' ),
                        (string) ( $entity_row->core_family_uid ?? '' ),
                    ] ) ) );
                }
            } else {
                $entity_row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id, customer_uid, customer_name
                         FROM " . self::t( 'olama_customers' ) . "
                         WHERE customer_uid = %s
                         LIMIT 1",
                        $uid
                    )
                );
                if ( $entity_row ) {
                    $customer_id    = (int) $entity_row->id;
                    $entity['name'] = (string) $entity_row->customer_name;
                }
            }
        }

        if ($student_uid !== '' && $entity_type === 'family') {
            $student = Olama_Reg_Student::get_student( $student_uid );
            if ( $student ) {
                $entity['name'] .= ' - ' . $student->student_name;
            }
        }

        $conditions = [ "i.status NOT IN ('draft', 'cancelled')" ];
        $params     = [];

        if ( $uid !== '' ) {
            if ( $entity_type === 'external' ) {
                $conditions[] = '(i.ext_customer_id = %d OR i.family_uid = %s)';
                $params[]     = $customer_id > 0 ? $customer_id : -1;
                $params[]     = $uid;
            } else {
                $family_conditions = [];
                foreach ( $family_refs as $family_ref ) {
                    $family_conditions[] = 'i.family_uid = %s';
                    $params[]            = $family_ref;
                    $family_conditions[] = 'i.oracle_family_id = %s';
                    $params[]            = $family_ref;
                }
                $conditions[] = '(' . implode( ' OR ', $family_conditions ) . ')';
            }
        }

        if ( $year_id > 0 ) {
            $conditions[] = 'i.academic_year_id = %d';
            $params[]     = $year_id;
        }

        $where_sql = implode( ' AND ', $conditions );

        $student_where_i = '';
        $student_params_i = [];
        $student_where_p = '';
        $student_params_p = [];
        if ( $student_uid !== '' ) {
            $student_where_i = ' AND i.student_uid = %s';
            $student_params_i[] = $student_uid;
            $student_where_p = ' AND (i.student_uid = %s OR p.id IN (SELECT DISTINCT payment_id FROM ' . self::t( 'olama_payment_allocations' ) . ' WHERE student_uid = %s))';
            $student_params_p[] = $student_uid;
            $student_params_p[] = $student_uid;
        }

        $invoice_sql = "SELECT
                i.issue_date AS movement_date,
                i.created_at AS created_at,
                'invoice' AS entry_type,
                CASE
                    WHEN i.agreement_id IS NOT NULL AND i.agreement_id > 0 THEN 'agreement'
                    ELSE 'service'
                END AS entry_subtype,
                i.invoice_number AS reference_no,
                COALESCE(i.notes, '') AS details,
                CAST(i.total AS DECIMAL(18,2)) AS debit_amount,
                0.00 AS credit_amount,
                i.id AS sort_id
            FROM " . self::t( 'olama_invoices' ) . " i
            WHERE {$where_sql}{$student_where_i}";

        $adjustment_sql = "SELECT
                DATE(adj.created_at) AS movement_date,
                adj.created_at AS created_at,
                adj.type AS entry_type,
                '' AS entry_subtype,
                CONCAT(COALESCE(i.invoice_number, '#'), ' / ', COALESCE(adj.adjustment_no, CONCAT('#', adj.id))) AS reference_no,
                COALESCE(adj.reason, adj.notes, '') AS details,
                CASE WHEN adj.type = 'debit' THEN CAST(adj.amount AS DECIMAL(18,2)) ELSE 0.00 END AS debit_amount,
                CASE WHEN adj.type = 'credit' THEN CAST(adj.amount AS DECIMAL(18,2)) ELSE 0.00 END AS credit_amount,
                adj.id AS sort_id
            FROM " . self::t( 'olama_invoice_adjustments' ) . " adj
            INNER JOIN " . self::t( 'olama_invoices' ) . " i ON i.id = adj.invoice_id
            WHERE adj.status = 'issued' AND {$where_sql}{$student_where_i}";

        $payment_sql = "SELECT
                p.payment_date AS movement_date,
                p.created_at AS created_at,
                CASE
                    WHEN p.method = 'reversal' OR p.amount < 0 OR p.reversed_payment_id IS NOT NULL THEN 'payment_reversal'
                    ELSE 'payment'
                END AS entry_type,
                CASE
                    WHEN p.method = 'reversal' THEN ''
                    ELSE COALESCE(p.method, '')
                END AS entry_subtype,
                COALESCE(p.payment_no, CONCAT('#', p.id)) AS reference_no,
                COALESCE(p.notes, p.reference, '') AS details,
                CASE
                    WHEN p.method = 'reversal' OR p.amount < 0 OR p.reversed_payment_id IS NOT NULL THEN ABS(CAST(p.amount AS DECIMAL(18,2)))
                    ELSE 0.00
                END AS debit_amount,
                CASE
                    WHEN p.method = 'reversal' OR p.amount < 0 OR p.reversed_payment_id IS NOT NULL THEN 0.00
                    ELSE ABS(CAST(p.amount AS DECIMAL(18,2)))
                END AS credit_amount,
                p.id AS sort_id
            FROM " . self::t( 'olama_payments' ) . " p
            INNER JOIN " . self::t( 'olama_invoices' ) . " i ON i.id = p.invoice_id
            WHERE {$where_sql}{$student_where_p}
			  AND (p.status IS NULL OR p.status = '' OR p.status IN ('posted','reversed'))";

        $union_sql = "{$invoice_sql} UNION ALL {$adjustment_sql} UNION ALL {$payment_sql}";
        $outer_sql = "SELECT *
            FROM ({$union_sql}) statement_rows";

        $range_conditions = [];
        $range_params     = [];
        if ( $date_from ) {
            $range_conditions[] = 'movement_date >= %s';
            $range_params[]     = $date_from;
        }
        if ( $date_to ) {
            $range_conditions[] = 'movement_date <= %s';
            $range_params[]     = $date_to;
        }
        if ( $range_conditions ) {
            $outer_sql .= ' WHERE ' . implode( ' AND ', $range_conditions );
        }
        $outer_sql .= ' ORDER BY movement_date ASC, created_at ASC, sort_id ASC';

        $query_params = array_merge(
            array_merge( $params, $student_params_i ),
            array_merge( $params, $student_params_i ),
            array_merge( $params, $student_params_p ),
            $range_params
        );
        $rows = $query_params
            ? ( $wpdb->get_results( $wpdb->prepare( $outer_sql, ...$query_params ) ) ?: [] )
            : ( $wpdb->get_results( $outer_sql ) ?: [] );

        $opening_balance = 0.0;
        if ( $entity_type === 'family' && $year_id > 0 && class_exists( 'Olama_Reg_Family_Financial_Summary' ) ) {
            $opening_balance = Olama_Reg_Family_Financial_Summary::get_previous_balance( $balance_uid, $year_id );
        }

        if ( $date_from ) {
            $opening_sql = "SELECT
                    COALESCE(SUM(debit_amount), 0) AS total_debit,
                    COALESCE(SUM(credit_amount), 0) AS total_credit
                FROM ({$union_sql}) statement_opening
                WHERE movement_date < %s";
            
            $opening_params = array_merge(
                array_merge( $params, $student_params_i ),
                array_merge( $params, $student_params_i ),
                array_merge( $params, $student_params_p )
            );
            $opening_params[] = $date_from;

            $opening_row = $opening_params
                ? $wpdb->get_row( $wpdb->prepare( $opening_sql, ...$opening_params ) )
                : $wpdb->get_row( $opening_sql );

            if ( $opening_row ) {
                $opening_balance += round(
                    (float) ( $opening_row->total_debit ?? 0 ) - (float) ( $opening_row->total_credit ?? 0 ),
                    2
                );
            }
        }

        $running_balance = $opening_balance;
        $total_debit     = 0.0;
        $total_credit    = 0.0;

        foreach ( $rows as $row ) {
            $row->debit_amount  = round( (float) $row->debit_amount, 2 );
            $row->credit_amount = round( (float) $row->credit_amount, 2 );
            $total_debit       += $row->debit_amount;
            $total_credit      += $row->credit_amount;
            $running_balance   += $row->debit_amount - $row->credit_amount;
            $row->running_balance = round( $running_balance, 2 );
        }

        return [
            'entity'  => $entity,
            'rows'    => $rows,
            'summary' => [
                'opening_balance' => $opening_balance,
                'total_debit'     => round( $total_debit, 2 ),
                'total_credit'    => round( $total_credit, 2 ),
                'closing_balance' => round( $running_balance, 2 ),
            ],
            'filters' => [
                'entity_type' => $entity_type,
                'uid'         => $uid,
                'year_id'     => $year_id,
                'student_uid' => $student_uid,
                'date_from'   => $date_from,
                'date_to'     => $date_to,
            ],
        ];
    }

    public static function format_statement_entry_type( string $entry_type, string $entry_subtype = '' ): string {
        $base_labels = [
            'invoice'          => __( 'فاتورة', 'olama-registration' ),
            'payment'          => __( 'دفعة', 'olama-registration' ),
            'payment_reversal' => __( 'عكس دفعة', 'olama-registration' ),
            'credit'           => __( 'إشعار دائن', 'olama-registration' ),
            'debit'            => __( 'إشعار مدين', 'olama-registration' ),
        ];
        $base = $base_labels[ $entry_type ] ?? $entry_type;

        $subtype_labels = [];
        if ( $entry_type === 'invoice' ) {
            $subtype_labels = [
                'agreement' => __( 'عقد', 'olama-registration' ),
                'service'   => __( 'خدمات إضافية', 'olama-registration' ),
            ];
        } elseif ( in_array( $entry_type, [ 'payment', 'payment_reversal' ], true ) ) {
            $subtype_labels = [
                'cash'          => __( 'نقدي', 'olama-registration' ),
                'bank_transfer' => __( 'تحويل بنكي', 'olama-registration' ),
                'online'        => __( 'دفع إلكتروني', 'olama-registration' ),
                'electronic'    => __( 'دفع إلكتروني', 'olama-registration' ),
                'card'          => __( 'بطاقة', 'olama-registration' ),
                'cheque'        => __( 'شيك', 'olama-registration' ),
                'check'         => __( 'شيك', 'olama-registration' ),
            ];
        }

        $subtype = $subtype_labels[ $entry_subtype ] ?? '';
        return $subtype !== '' ? $base . ' - ' . $subtype : $base;
    }

    private static function sanitize_report_date( string $date ): string {
        $date = sanitize_text_field( $date );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
    }
}
