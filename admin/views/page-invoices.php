<?php
/**
 * Invoices List and Generator View
 */
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

$action = sanitize_text_field( $_GET['action'] ?? '' );
$id     = absint( $_GET['id'] ?? 0 );

$prefilled_family_uid = sanitize_text_field( $_GET['family_uid'] ?? '' );
$prefilled_family_name = '';
$is_family_locked = false;

if ( $prefilled_family_uid ) {
    $family_row = Olama_Reg_Family::get_family( $prefilled_family_uid );
    if ( $family_row ) {
        $prefilled_family_name = $family_row->family_name;
        $is_family_locked = true;
    }
}

// ── PRINT INVOICE MODE ────────────────────────────────────────────────────────
if ( $action === 'print' && $id ) {
    $invoice = Olama_Reg_Billing_Invoice::get_invoice( $id );
    if ( ! $invoice ) {
        wp_die( esc_html__( 'Invoice not found.', 'olama-registration' ) );
    }

    $family = Olama_Reg_Family::get_family( (string) $invoice->family_uid );

    $customer = null;
    if ( ! empty( $invoice->ext_customer_id ) || strpos( (string) $invoice->family_uid, 'CUST-' ) === 0 ) {
        $customer = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_customers WHERE id = %d OR customer_uid = %s LIMIT 1",
            (int) $invoice->ext_customer_id,
            $invoice->family_uid
        ) );
    }

    $payer_name = $customer
        ? (string) $customer->customer_name
        : ( $family ? (string) $family->family_name : (string) $invoice->family_uid );
    
    $student = null;
    if ( $invoice->student_uid ) {
        $student = Olama_Reg_Student::get_student( (string) $invoice->student_uid );
    }

    $year_name = '—';
    if ( class_exists( 'Olama_School_Academic' ) ) {
        $ay = $wpdb->get_row( $wpdb->prepare(
            "SELECT year_name FROM {$wpdb->prefix}olama_academic_years WHERE id = %d",
            $invoice->academic_year_id
        ) );
        if ( $ay ) $year_name = $ay->year_name;
    }

    $payments = [];
    if ( class_exists( 'Olama_Reg_Billing_Payment' ) ) {
        $payments = Olama_Reg_Billing_Payment::get_invoice_payments( $id );
    }
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title><?php echo esc_html( $invoice->invoice_number ); ?></title>
        <style>
            :root {
                --primary: #E8920A;
                --text-main: #1a1a2e;
                --text-muted: #6B7280;
                --border-color: #e2e8f0;
                --bg-light: #fafaf9;
            }
            body { font-family: 'Tajawal', sans-serif; margin: 40px; color: var(--text-main); background: #f8fafc; }
            .print-wrap { background: #fff; max-width: 850px; margin: 0 auto; border: 1px solid var(--border-color); padding: 40px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
            .header-table { width: 100%; border-collapse: collapse; margin-bottom: 40px; border-bottom: 2px solid var(--primary); padding-bottom: 20px; }
            .header-table td { vertical-align: middle; }
            .logo-title { font-size: 28px; font-weight: 900; color: var(--primary); letter-spacing: -0.5px; }
            .invoice-title { font-size: 32px; font-weight: 900; text-align: left; color: var(--text-main); }
            .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 35px; background: var(--bg-light); border-radius: 8px; overflow: hidden; }
            .meta-table td { padding: 12px 16px; border-bottom: 1px solid var(--border-color); font-size: 14px; }
            .label { font-weight: 800; color: var(--text-muted); width: 160px; }
            .items-table { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 35px; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; }
            .items-table th { background: var(--bg-light); color: var(--text-main); font-weight: 800; padding: 14px; text-align: right; border-bottom: 2px solid var(--border-color); }
            .items-table td { padding: 14px; border-bottom: 1px solid var(--border-color); }
            .items-table tr:last-child td { border-bottom: none; }
            .totals-container { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; }
            .totals-table { width: 320px; border-collapse: collapse; background: var(--bg-light); border-radius: 8px; overflow: hidden; border: 1px solid var(--border-color); }
            .totals-table td { padding: 12px 16px; border-bottom: 1px solid var(--border-color); }
            .totals-table tr:last-child td { border-bottom: none; }
            .totals-table tr.grand-total td { font-weight: 900; font-size: 18px; color: #fff; background: var(--primary); border: none; }
            .installments-title, .payments-title { font-size: 18px; font-weight: 800; color: var(--text-main); margin-top: 40px; margin-bottom: 15px; border-right: 4px solid var(--primary); padding-right: 10px; }
            .installments-table, .payments-table { width: 100%; border-collapse: collapse; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; margin-bottom: 30px; }
            .installments-table th, .payments-table th { background: var(--bg-light); padding: 12px; border-bottom: 2px solid var(--border-color); text-align: right; font-weight: 800; }
            .installments-table td, .payments-table td { padding: 12px; border-bottom: 1px solid var(--border-color); }
            .installments-table tr:last-child td, .payments-table tr:last-child td { border-bottom: none; }
            .footer { margin-top: 60px; text-align: center; font-size: 13px; color: var(--text-muted); border-top: 1px solid var(--border-color); padding-top: 20px; }
            @media print {
                body { margin: 0; background: #fff; }
                .print-wrap { border: none; box-shadow: none; padding: 0; max-width: 100%; }
                .no-print { display: none !important; }
            }
        </style>
        <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    </head>
    <body>
        <div class="no-print" style="max-width: 800px; margin: 0 auto 20px; text-align: left;">
            <button onclick="window.print();" style="padding: 10px 20px; font-weight: 700; background: #E8920A; color:#fff; border:none; border-radius:4px; cursor:pointer;">طباعة الفاتورة</button>
        </div>
        <div class="print-wrap">
            <table class="header-table">
                <tr>
                    <td>
                        <?php 
                        $school_settings = get_option('olama_school_settings', []);
                        $school_name = $school_settings['school_name_ar'] ?? 'مدارس أوتاد الإبداع';
                        ?>
                        <div class="logo-title"><?php echo esc_html( $school_name ); ?></div>
                        <div style="font-size: 11px; color:#6B7280; margin-top:4px;">إيصال مطالبة مالية - نسخة لولي الأمر</div>
                    </td>
                    <td style="text-align: left;">
                        <div class="invoice-title">فاتورة رسوم</div>
                        <div style="font-weight: 700; color: #6B7280; margin-top:4px;"><?php echo esc_html( $invoice->invoice_number ); ?></div>
                    </td>
                </tr>
            </table>

            <table class="meta-table">
                <tr>
                    <td class="label"><?php esc_html_e( 'اسم ولي الأمر:', 'olama-registration' ); ?></td>
                    <td><?php echo esc_html( $payer_name ); ?></td>
                    <td class="label">تاريخ الإصدار:</td>
                    <td><?php echo esc_html( $invoice->issue_date ); ?></td>
                </tr>
                <tr>
                    <td class="label">رقم ملف العائلة:</td>
                    <td><strong style="color:#E8920A;"><?php echo esc_html( $invoice->family_uid ); ?></strong></td>
                    <td class="label">تاريخ الاستحقاق:</td>
                    <td><?php echo esc_html( $invoice->due_date ?: '—' ); ?></td>
                </tr>
                <?php if ( $student ): ?>
                    <tr>
                        <td class="label">الطالب المستهدف:</td>
                        <td><?php echo esc_html( $student->student_name ); ?> (<?php echo esc_html( $student->student_uid ); ?>)</td>
                        <td class="label">العام الدراسي:</td>
                        <td><?php echo esc_html( $year_name ); ?></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td class="label">العام الدراسي:</td>
                        <td colspan="3"><?php echo esc_html( $year_name ); ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td class="label">حالة الفاتورة:</td>
                    <td colspan="3">
                        <strong>
                            <?php 
                            echo esc_html( Olama_Reg_Status_Labels::label( $invoice->status, 'invoice' ) );
                            ?>
                        </strong>
                    </td>
                </tr>
            </table>

            <table class="items-table">
                <thead>
                    <tr>
                        <th>البند / الوصف</th>
                        <th style="width: 80px; text-align: center;">الكمية</th>
                        <th style="width: 120px; text-align: left;">سعر الوحدة</th>
                        <th style="width: 120px; text-align: left;">الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $invoice->items as $item ): ?>
                        <tr>
                            <td><?php echo esc_html( $item->description ); ?></td>
                            <td style="text-align: center;"><?php echo esc_html( number_format( $item->quantity, 0 ) ); ?></td>
                            <td style="text-align: left;"><?php echo esc_html( number_format( $item->unit_price, 2 ) ); ?></td>
                            <td style="text-align: left; font-weight: 700;"><?php echo esc_html( number_format( $item->line_total, 2 ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="totals-container">
                <div style="flex:1;">
                    <!-- Space for additional info like QR code or notes if needed -->
                </div>
                <table class="totals-table">
                    <tr>
                        <td class="label">المجموع الفرعي:</td>
                        <td style="text-align: left; font-weight:800;"><?php echo esc_html( number_format( $invoice->subtotal, 2 ) ); ?></td>
                    </tr>
                    <tr>
                        <td class="label">الخصم الممنوح:</td>
                        <td style="text-align: left; color:#dc2626; font-weight:800;">- <?php echo esc_html( number_format( $invoice->discount, 2 ) ); ?></td>
                    </tr>
                    <?php if ( (float) ( $invoice->debit_notes_total ?? 0 ) > 0 ): ?>
                        <tr>
                            <td class="label">إشعارات مدينة:</td>
                            <td style="text-align: left; color:#b45309; font-weight:800;">+ <?php echo esc_html( number_format( $invoice->debit_notes_total, 2 ) ); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ( (float) ( $invoice->credit_notes_total ?? 0 ) > 0 ): ?>
                        <tr>
                            <td class="label">إشعارات دائنة:</td>
                            <td style="text-align: left; color:#1d4ed8; font-weight:800;">- <?php echo esc_html( number_format( $invoice->credit_notes_total, 2 ) ); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr class="grand-total">
                        <td class="label" style="color:#fff;">الإجمالي النهائي:</td>
                        <td style="text-align: left;"><?php echo esc_html( number_format( $invoice->effective_total ?? $invoice->total, 2 ) ); ?></td>
                    </tr>
                    <tr>
                        <td class="label">المبلغ المدفوع:</td>
                        <td style="text-align: left; color:#16a34a; font-weight:800;"><?php echo esc_html( number_format( $invoice->amount_paid, 2 ) ); ?></td>
                    </tr>
                    <tr>
                        <td class="label">المتبقي المستحق:</td>
                        <td style="text-align: left; font-weight:900; color:var(--primary); font-size: 18px;"><?php echo esc_html( number_format( $invoice->balance, 2 ) ); ?></td>
                    </tr>
                </table>
            </div>

            <?php if ( ! empty( $invoice->installments ) ): ?>
                <div class="installments-title">جدول أقساط السداد المتفق عليه</div>
                <table class="installments-table">
                    <thead>
                        <tr>
                            <th style="width: 80px;">رقم القسط</th>
                            <th>تاريخ الاستحقاق</th>
                            <th style="text-align: left; width: 130px;">المبلغ المستحق</th>
                            <th style="text-align: left; width: 130px;">المبلغ المدفوع</th>
                            <th style="width: 100px;">الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $invoice->installments as $inst ): ?>
                            <tr>
                                <td><?php echo esc_html( $inst->installment_no ); ?></td>
                                <td><?php echo esc_html( $inst->due_date ); ?></td>
                                <td style="text-align: left; font-weight: 700;"><?php echo esc_html( number_format( $inst->amount_due, 2 ) ); ?></td>
                                <td style="text-align: left;"><?php echo esc_html( number_format( $inst->amount_paid, 2 ) ); ?></td>
                                <td>
                                    <?php echo esc_html( Olama_Reg_Status_Labels::label( $inst->status, 'installment' ) ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( ! empty( $payments ) ): ?>
                <div class="payments-title">سجل الدفعات السابقة (السندات المرتبطة)</div>
                <table class="payments-table">
                    <thead>
                        <tr>
                            <th style="width: 100px;">رقم السند</th>
                            <th>تاريخ الدفع</th>
                            <th>طريقة الدفع</th>
                            <th style="text-align: left;">المبلغ المدفوع</th>
                            <th>المرجع</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $payments as $pay ): 
                            $method_label = match($pay->method) {
                                'cash' => 'نقدي', 'bank_transfer' => 'حوالة بنكية', 'cheque' => 'شيك', 'online' => 'دفع إلكتروني', 'reversal' => 'عكس سند', default => 'أخرى'
                            };
                            $is_reversal = ($pay->method === 'reversal');
                            $color = $is_reversal ? '#dc2626' : '#16a34a';
                        ?>
                            <tr>
                                <td><strong>#<?php echo esc_html( $pay->id ); ?></strong></td>
                                <td><?php echo esc_html( $pay->payment_date ); ?></td>
                                <td><?php echo esc_html( $method_label ); ?></td>
                                <td style="text-align: left; font-weight: 800; color: <?php echo $color; ?>;">
                                    <?php echo esc_html( number_format( (float) $pay->amount, 2 ) ); ?>
                                </td>
                                <td style="font-size: 13px; color: var(--text-muted);"><?php echo esc_html( $pay->reference ?: '—' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( ! empty( $invoice->notes ) ): ?>
                <div style="margin-top:30px; border-top: 1px solid #eee; padding-top:15px;">
                    <strong style="color:#6B7280; font-size:13px;">ملاحظات إضافية:</strong>
                    <p style="font-size:13px; margin:6px 0; color:#555;"><?php echo nl2br( esc_html( $invoice->notes ) ); ?></p>
                </div>
            <?php endif; ?>

            <div class="footer">
                شكراً لثقتكم بنا وبمدارسنا. لأي استفسارات مالية، يرجى مراجعة قسم الشؤون المالية.
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ── REGULAR LIST / CREATE VIEW ────────────────────────────────────────────────
$filter_status = sanitize_text_field( $_GET['status'] ?? '' );
$filter_year   = absint( $_GET['year_id'] ?? 0 );
$search_q      = sanitize_text_field( $_GET['s'] ?? '' );

$where = "1=1";
$params = [];

if ( $filter_status ) {
    $where .= " AND i.status = %s";
    $params[] = $filter_status;
}
if ( $filter_year ) {
    $where .= " AND i.academic_year_id = %d";
    $params[] = $filter_year;
}
if ( $search_q ) {
    $like = '%' . $wpdb->esc_like( $search_q ) . '%';
    $where .= " AND (i.invoice_number LIKE %s OR i.family_uid LIKE %s OR f.sponsor_full_name LIKE %s OR f.father_name LIKE %s OR c.customer_name LIKE %s OR c.customer_uid LIKE %s OR ft.template_name LIKE %s OR a.agreement_number LIKE %s)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$query = "SELECT i.*, COALESCE(f.sponsor_full_name, f.father_name, f.mother_name, c.customer_name, '') AS father_first_name, '' AS father_family_name,
                 ft.template_name AS fee_template_name,
                 ft.subject_type AS fee_subject_type,
                 ft.subject_value AS fee_subject_value,
                 COALESCE(adj.debit_total, 0) AS debit_notes_total,
                 COALESCE(adj.credit_total, 0) AS credit_notes_total,
                 GREATEST(0, i.total + COALESCE(adj.debit_total, 0) - COALESCE(adj.credit_total, 0)) AS effective_total,
                 a.agreement_number,
                 s.student_name AS direct_student_name,
                 ec.child_name AS direct_child_name
          FROM " . $wpdb->prefix . "olama_invoices i
          LEFT JOIN " . $wpdb->prefix . "olama_core_families f
            ON f.family_uid = i.family_uid
            OR (f.oracle_family_id = COALESCE(NULLIF(i.oracle_family_id, ''), i.family_uid))
          LEFT JOIN " . $wpdb->prefix . "olama_customers c ON c.id = i.ext_customer_id OR c.customer_uid = i.family_uid
          LEFT JOIN " . $wpdb->prefix . "olama_fee_templates ft ON ft.id = i.fee_template_id
          LEFT JOIN " . $wpdb->prefix . "olama_agreements a ON a.id = i.agreement_id
          LEFT JOIN (
              SELECT invoice_id,
                     SUM(CASE WHEN type = 'debit' AND status = 'issued' THEN amount ELSE 0 END) AS debit_total,
                     SUM(CASE WHEN type = 'credit' AND status = 'issued' THEN amount ELSE 0 END) AS credit_total
              FROM " . $wpdb->prefix . "olama_invoice_adjustments
              GROUP BY invoice_id
          ) adj ON adj.invoice_id = i.id
          LEFT JOIN " . $wpdb->prefix . "olama_core_students s
            ON s.student_uid = i.student_uid
            OR (
                (i.student_uid IS NULL OR i.student_uid = '' OR i.student_uid NOT LIKE 'ORA-STU-%')
                AND s.oracle_student_id = COALESCE(NULLIF(i.oracle_student_id, ''), i.student_uid)
                AND (s.family_uid = i.family_uid OR s.oracle_family_id = i.oracle_family_id)
            )
          LEFT JOIN " . $wpdb->prefix . "olama_customer_children ec ON ec.id = i.ext_child_id
          WHERE {$where}
          ORDER BY i.issue_date DESC, i.id DESC";

if ( ! empty( $params ) ) {
    $invoices = $wpdb->get_results( $wpdb->prepare( $query, ...$params ) ) ?: [];
} else {
    $invoices = $wpdb->get_results( $query ) ?: [];
}

foreach ( $invoices as $invoice_row ) {
    if ( empty( $invoice_row->fee_template_name ) && ! empty( $invoice_row->agreement_id ) ) {
        $templates = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT ft.template_name, ft.subject_type, ft.subject_value
             FROM {$wpdb->prefix}olama_agreement_fees af
             LEFT JOIN {$wpdb->prefix}olama_fee_templates ft ON ft.id = CAST(af.fee_category AS UNSIGNED)
             WHERE af.agreement_id = %d
               AND af.fee_category REGEXP '^[0-9]+'
               AND ft.id IS NOT NULL
             ORDER BY ft.template_name ASC",
            (int) $invoice_row->agreement_id
        ) ) ?: [];

        if ( ! empty( $templates ) ) {
            $invoice_row->fee_template_name = implode( '، ', wp_list_pluck( $templates, 'template_name' ) );
            $invoice_row->fee_subject_type  = $templates[0]->subject_type ?? 'agreement';
            $invoice_row->fee_subject_value = $templates[0]->subject_value ?? '';
        }
    }

    $covered_children = [];
    if ( ! empty( $invoice_row->agreement_id ) ) {
        $agreement = $wpdb->get_row( $wpdb->prepare(
            "SELECT payer_type, participant_type FROM {$wpdb->prefix}olama_agreements WHERE id = %d",
            (int) $invoice_row->agreement_id
        ) );

        if ( $agreement && $agreement->payer_type === 'family' ) {
            $covered_children = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT s.student_name
                 FROM {$wpdb->prefix}olama_agreement_fees af
                 LEFT JOIN {$wpdb->prefix}olama_core_students s
                    ON s.student_uid = COALESCE(NULLIF(af.student_uid, ''), NULLIF(af.child_id, ''))
                    OR (
                        NULLIF(af.student_uid, '') IS NULL
                        AND s.oracle_student_id = COALESCE(NULLIF(af.oracle_student_id, ''), NULLIF(af.child_id, ''))
                        AND (s.family_uid = %s OR s.oracle_family_id = %s)
                    )
                 WHERE af.agreement_id = %d
                   AND af.child_id IS NOT NULL
                   AND af.child_id != ''
                   AND s.student_name IS NOT NULL
                 ORDER BY s.student_name ASC",
                (string) $invoice_row->family_uid,
                (string) $invoice_row->oracle_family_id,
                (int) $invoice_row->agreement_id
            ) ) ?: [];
        } else {
            $covered_children = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT ch.child_name
                 FROM {$wpdb->prefix}olama_agreement_fees af
                 LEFT JOIN {$wpdb->prefix}olama_customer_children ch
                    ON ch.id = CAST(af.child_id AS UNSIGNED)
                    OR ch.child_uid = af.child_id
                 WHERE af.agreement_id = %d
                   AND af.child_id IS NOT NULL
                   AND af.child_id != ''
                   AND ch.child_name IS NOT NULL
                 ORDER BY ch.child_name ASC",
                (int) $invoice_row->agreement_id
            ) ) ?: [];
        }
    } elseif ( ! empty( $invoice_row->direct_student_name ) ) {
        $covered_children[] = $invoice_row->direct_student_name;
    } elseif ( ! empty( $invoice_row->direct_child_name ) ) {
        $covered_children[] = $invoice_row->direct_child_name;
    }

    $invoice_row->covered_children_names = array_values( array_unique( array_filter( array_map( static function ( $name ) {
        $parts = preg_split( '/\s+/u', trim( (string) $name ) );
        return $parts[0] ?? '';
    }, $covered_children ) ) ) );
}

// Get years for dropdown
$years = [];
if ( class_exists( 'Olama_School_Academic' ) ) {
    $years = Olama_School_Academic::get_years();
}

$fee_templates = array_values( array_filter(
    Olama_Reg_Billing_Fees::get_templates(),
    static fn( $tpl ) => ( $tpl->subject_type ?? 'general' ) === 'service'
) );
$custom_services = get_option( 'olama_reg_custom_services', ['دوسية', 'نشاط', 'مواصلات', 'امتحان إضافي'] );
?>
<?php
// Quick stats for the banner
$_inv_stats = $wpdb->get_row(
    "SELECT
        COUNT(*) AS total_count,
        COALESCE(SUM(i.total + COALESCE(adj.debit_total, 0) - COALESCE(adj.credit_total, 0)),0) AS total_invoiced,
        COALESCE(SUM(i.amount_paid),0) AS total_paid,
        COALESCE(SUM(i.balance),0) AS total_balance
     FROM {$wpdb->prefix}olama_invoices i
     LEFT JOIN (
         SELECT invoice_id,
                SUM(CASE WHEN type = 'debit' AND status = 'issued' THEN amount ELSE 0 END) AS debit_total,
                SUM(CASE WHEN type = 'credit' AND status = 'issued' THEN amount ELSE 0 END) AS credit_total
         FROM {$wpdb->prefix}olama_invoice_adjustments
         GROUP BY invoice_id
     ) adj ON adj.invoice_id = i.id
     WHERE i.status NOT IN ('cancelled','draft')"
);
?>
<div class="wrap olama-reg-wrap" dir="rtl">

    <div class="olama-reg-page-header">
        <h1>
            <span class="dashicons dashicons-media-text"></span>
            <?php esc_html_e( 'الفواتير والمستحقات المالية', 'olama-registration' ); ?>
        </h1>
        <button class="olama-reg-btn olama-reg-btn--primary" id="olama-reg-open-invoice-modal-btn">
            <span class="dashicons dashicons-plus"></span>
            <?php esc_html_e( 'إصدار فاتورة جديدة', 'olama-registration' ); ?>
        </button>
    </div>

    <!-- ── QUICK STAT CARDS ──────────────────────────────────────── -->
    <div class="olama-reg-metrics-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
        <div class="olama-reg-metric-card olama-reg-metric-card--primary">
            <div class="olama-reg-metric-icon"><span class="dashicons dashicons-media-text"></span></div>
            <div class="olama-reg-metric-content">
                <div class="olama-reg-metric-title"><?php esc_html_e( 'إجمالي الفواتير', 'olama-registration' ); ?></div>
                <div class="olama-reg-metric-value"><?php echo esc_html( number_format( (float)($_inv_stats->total_invoiced ?? 0), 2 ) ); ?></div>
            </div>
        </div>
        <div class="olama-reg-metric-card olama-reg-metric-card--success">
            <div class="olama-reg-metric-icon"><span class="dashicons dashicons-yes-alt"></span></div>
            <div class="olama-reg-metric-content">
                <div class="olama-reg-metric-title"><?php esc_html_e( 'إجمالي المحصل', 'olama-registration' ); ?></div>
                <div class="olama-reg-metric-value"><?php echo esc_html( number_format( (float)($_inv_stats->total_paid ?? 0), 2 ) ); ?></div>
            </div>
        </div>
        <div class="olama-reg-metric-card olama-reg-metric-card--warning">
            <div class="olama-reg-metric-icon"><span class="dashicons dashicons-clock"></span></div>
            <div class="olama-reg-metric-content">
                <div class="olama-reg-metric-title"><?php esc_html_e( 'الذمم المستحقة', 'olama-registration' ); ?></div>
                <div class="olama-reg-metric-value"><?php echo esc_html( number_format( (float)($_inv_stats->total_balance ?? 0), 2 ) ); ?></div>
            </div>
        </div>
        <div class="olama-reg-metric-card olama-reg-metric-card--danger">
            <div class="olama-reg-metric-icon"><span class="dashicons dashicons-warning"></span></div>
            <div class="olama-reg-metric-content">
                <div class="olama-reg-metric-title"><?php esc_html_e( 'عدد الفواتير', 'olama-registration' ); ?></div>
                <div class="olama-reg-metric-value"><?php echo esc_html( (int)($_inv_stats->total_count ?? 0) ); ?></div>
            </div>
        </div>
    </div>

    <!-- Notice area -->
    <div id="olama-reg-notice" class="olama-reg-notice" style="display:none;"></div>

    <!-- ── FILTERS BAR ─────────────────────────────────────────────── -->
    <div class="olama-reg-filter-bar">
        <form method="get" class="olama-reg-filter-form">
            <input type="hidden" name="page" value="olama-registration-invoices">

            <div class="olama-reg-filter-group">
                <label><?php esc_html_e( 'البحث السريع', 'olama-registration' ); ?></label>
                <input type="text" name="s" value="<?php echo esc_attr( $search_q ); ?>"
                       placeholder="<?php esc_attr_e( 'رقم الفاتورة، اسم العائلة...', 'olama-registration' ); ?>"
                       class="olama-reg-filter-input">
            </div>

            <div class="olama-reg-filter-group">
                <label><?php esc_html_e( 'حالة الفاتورة', 'olama-registration' ); ?></label>
                <select name="status" class="olama-reg-filter-input">
                    <option value=""><?php esc_html_e( 'جميع الحالات', 'olama-registration' ); ?></option>
                    <option value="draft"     <?php selected( $filter_status, 'draft' ); ?>><?php esc_html_e( 'مسودة', 'olama-registration' ); ?></option>
                    <option value="issued"    <?php selected( $filter_status, 'issued' ); ?>><?php esc_html_e( 'صادرة', 'olama-registration' ); ?></option>
                    <option value="partial"   <?php selected( $filter_status, 'partial' ); ?>><?php esc_html_e( 'مدفوعة جزئياً', 'olama-registration' ); ?></option>
                    <option value="paid"      <?php selected( $filter_status, 'paid' ); ?>><?php esc_html_e( 'مدفوعة بالكامل', 'olama-registration' ); ?></option>
                    <option value="overdue"   <?php selected( $filter_status, 'overdue' ); ?>><?php esc_html_e( 'متأخرة السداد', 'olama-registration' ); ?></option>
                    <option value="cancelled" <?php selected( $filter_status, 'cancelled' ); ?>><?php esc_html_e( 'ملغاة', 'olama-registration' ); ?></option>
                </select>
            </div>

            <div class="olama-reg-filter-group">
                <label><?php esc_html_e( 'العام الدراسي', 'olama-registration' ); ?></label>
                <select name="year_id" class="olama-reg-filter-input">
                    <option value=""><?php esc_html_e( 'جميع الأعوام', 'olama-registration' ); ?></option>
                    <?php foreach ( $years as $y ): ?>
                        <option value="<?php echo esc_attr( $y->id ); ?>" <?php selected( $filter_year, $y->id ); ?>>
                            <?php echo esc_html( $y->year_name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="olama-reg-filter-group">
                <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e( 'تطبيق التصفية', 'olama-registration' ); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- ── LIST TABLE ─────────────────────────────────────────────── -->
    <div class="olama-reg-section">
        <h3 class="olama-reg-section-title">
            <span class="dashicons dashicons-list-view"></span>
            <?php esc_html_e( 'الفواتير المصدرة', 'olama-registration' ); ?>
        </h3>
        <?php include OLAMA_REG_PATH . 'admin/views/partial-invoices-table.php'; ?>
    </div>

    <!-- ── INVOICE GENERATOR MODAL ─────────────────────────────────── -->
    <div id="olama-reg-invoice-modal" class="olama-reg-modal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(26,26,46,0.4); backdrop-filter:blur(4px);">
        <div class="olama-reg-modal-dialog">
            <div class="olama-reg-modal-header">
                <h2 class="olama-reg-modal-title">
                    <span class="dashicons dashicons-media-text"></span>
                    <?php esc_html_e( 'إصدار فاتورة جديدة', 'olama-registration' ); ?>
                </h2>
                <button type="button" class="olama-reg-modal-close">&times;</button>
            </div>
            
            <form id="olama-reg-invoice-form" style="margin:0;">
                <div class="olama-reg-modal-body">
                    
                    <div class="olama-reg-section">
                        <h3 class="olama-reg-section-title"><?php esc_html_e( 'بيانات المستهدفين والربط', 'olama-registration' ); ?></h3>
                        <div class="olama-reg-grid">
                            <div class="olama-reg-field olama-reg-field--required">
                                <label for="inv_family_uid"><?php esc_html_e( 'رقم ملف العائلة', 'olama-registration' ); ?></label>
                                <select id="inv_family_uid" name="family_uid" style="width:100%;" required <?php disabled($is_family_locked); ?>>
                                    <?php if ( $is_family_locked ): ?>
                                        <option value="<?php echo esc_attr( $prefilled_family_uid ); ?>" selected="selected">
                                            <?php echo esc_html( $prefilled_family_name . ' (' . $prefilled_family_uid . ')' ); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                                <?php if ($is_family_locked): ?>
                                    <input type="hidden" name="family_uid" value="<?php echo esc_attr( $prefilled_family_uid ); ?>">
                                <?php endif; ?>
                            </div>
                            <div class="olama-reg-field">
                                <label for="inv_student_uid"><?php esc_html_e( 'الطالب المستهدف (اختياري)', 'olama-registration' ); ?></label>
                                <select id="inv_student_uid" name="student_uid" style="width:100%;">
                                    <option value=""><?php esc_html_e( 'فاتورة عامة للعائلة', 'olama-registration' ); ?></option>
                                </select>
                            </div>
                            <div class="olama-reg-field olama-reg-field--required">
                                <label for="inv_academic_year_id"><?php esc_html_e( 'العام الدراسي', 'olama-registration' ); ?></label>
                                <select id="inv_academic_year_id" name="academic_year_id" required>
                                    <?php foreach ( $years as $y ): ?>
                                        <option value="<?php echo esc_attr( $y->id ); ?>"><?php echo esc_html( $y->year_name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="olama-reg-section">
                        <h3 class="olama-reg-section-title"><?php esc_html_e( 'النموذج والجدولة الافتراضية', 'olama-registration' ); ?></h3>
                        <div class="olama-reg-grid">
                            <div class="olama-reg-field olama-reg-field--required">
                                <label for="inv_service_type"><?php esc_html_e( 'طبيعة الخدمة', 'olama-registration' ); ?></label>
                                <select id="inv_service_type" name="service_type" required>
                                    <option value=""><?php esc_html_e( '— اختر طبيعة الخدمة —', 'olama-registration' ); ?></option>
                                    <?php foreach ( $custom_services as $service ): ?>
                                        <option value="<?php echo esc_attr( $service ); ?>">
                                            <?php echo esc_html( $service ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="olama-reg-field olama-reg-field--required">
                                <label for="inv_fee_template_id"><?php esc_html_e( 'استيراد بنود نموذج رسوم', 'olama-registration' ); ?></label>
                                <select id="inv_fee_template_id" name="fee_template_id" required>
                                    <option value=""><?php esc_html_e( '— اختر نموذج الرسوم —', 'olama-registration' ); ?></option>
                                    <?php foreach ( $fee_templates as $tpl ): ?>
                                        <option value="<?php echo esc_attr( $tpl->id ); ?>"
                                                data-items="<?php echo esc_attr( wp_json_encode( $tpl->items ) ); ?>"
                                                data-inst="<?php echo esc_attr( $tpl->installments ); ?>"
                                                data-subject-type="<?php echo esc_attr( $tpl->subject_type ?? 'general' ); ?>"
                                                data-subject-value="<?php echo esc_attr( $tpl->subject_value ?? '' ); ?>">
                                            <?php echo esc_html( $tpl->template_name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <input type="hidden" id="inv_installments" name="installments" value="1">
                            <div class="olama-reg-field">
                                <label for="inv_status"><?php esc_html_e( 'حالة الفاتورة عند الإصدار', 'olama-registration' ); ?></label>
                                <select id="inv_status" name="status">
                                    <option value="issued"><?php esc_html_e( 'صادرة (غير مدفوعة)', 'olama-registration' ); ?></option>
                                    <option value="draft"><?php esc_html_e( 'مسودة', 'olama-registration' ); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="olama-reg-section">
                        <h3 class="olama-reg-section-title"><?php esc_html_e( 'بنود الفاتورة المفصلة', 'olama-registration' ); ?></h3>
                        <div style="padding:16px;">
                            <table class="olama-reg-fin-table" id="olama-reg-invoice-items-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'الوصف', 'olama-registration' ); ?></th>
                                        <th style="width:80px;"><?php esc_html_e( 'الكمية', 'olama-registration' ); ?></th>
                                        <th style="width:120px;"><?php esc_html_e( 'سعر الوحدة', 'olama-registration' ); ?></th>
                                        <th style="width:120px;"><?php esc_html_e( 'الإجمالي', 'olama-registration' ); ?></th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="olama-reg-empty-items-row">
                                        <td colspan="5" style="text-align:center; color:#999; padding:15px;">
                                            <?php esc_html_e( 'قم بإضافة بنود لتظهر هنا أو قم باستيراد نموذج رسوم.', 'olama-registration' ); ?>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" class="olama-reg-text--bold"><?php esc_html_e( 'المجموع الفرعي:', 'olama-registration' ); ?></td>
                                        <td colspan="2"><strong id="inv-subtotal-label">0.00</strong></td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" class="olama-reg-text--bold"><?php esc_html_e( 'خصم ممنوح:', 'olama-registration' ); ?></td>
                                        <td colspan="2">
                                            <input type="number" step="0.01" name="discount" id="inv-discount-input" value="0.00" class="olama-reg-input--inline olama-reg-text--danger">
                                        </td>
                                    </tr>
                                    <tr class="olama-reg-row--highlight">
                                        <td colspan="3" class="olama-reg-text--bold"><?php esc_html_e( 'الإجمالي النهائي المستحق:', 'olama-registration' ); ?></td>
                                        <td colspan="2"><strong id="inv-grand-total-label">0.00</strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                            <div style="margin-top:12px;">
                                <button type="button" class="button" id="inv-add-item-row-btn">+ <?php esc_html_e( 'إضافة بند مخصص', 'olama-registration' ); ?></button>
                            </div>
                        </div>
                    </div>

                    <div class="olama-reg-section">
                        <h3 class="olama-reg-section-title"><?php esc_html_e( 'ملاحظات إضافية (تطبع على الفاتورة)', 'olama-registration' ); ?></h3>
                        <div style="padding:14px;">
                            <textarea name="notes" rows="3" style="width:100%; border:1.5px solid #E0C090; border-radius:6px; padding:8px; font-family:inherit;" placeholder="<?php esc_html_e( 'شروط السداد، خصومات التميز، أو تفاصيل أخرى...', 'olama-registration' ); ?>"></textarea>
                        </div>
                    </div>

                </div>
                
                <div class="olama-reg-form-actions">
                    <button type="submit" class="olama-reg-btn olama-reg-btn--primary" id="olama-reg-save-invoice-btn">
                        <?php esc_html_e( 'حفظ وإصدار الفاتورة', 'olama-registration' ); ?>
                    </button>
                    <button type="button" class="button button-large olama-reg-modal-close"><?php esc_html_e( 'إلغاء', 'olama-registration' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── INVOICE DETAILS DRAWER / OVERLAY ────────────────────────── -->
    <div id="olama-reg-invoice-drawer" class="olama-reg-modal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background-color:rgba(26,26,46,0.4); backdrop-filter:blur(4px);">
        <div class="olama-reg-modal-dialog olama-reg-drawer-dialog">
            <div class="olama-reg-modal-header">
                <h2 class="olama-reg-modal-title">
                    <span class="dashicons dashicons-visibility"></span>
                    <?php esc_html_e( 'تفاصيل الفاتورة', 'olama-registration' ); ?>
                    <span id="drawer-invoice-number" style="font-weight:900; color:#ffffff; margin-right:8px;"></span>
                </h2>
                <button type="button" class="olama-reg-drawer-close">&times;</button>
            </div>
            
            <div class="olama-reg-modal-body">
                <!-- Header metrics -->
                <div class="olama-reg-metrics-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 20px; gap:14px;">
                    <div class="olama-reg-metric-card olama-reg-metric-card--primary" style="padding:15px; flex-direction:column; align-items:center; gap:8px;">
                        <div class="olama-reg-metric-title"><?php esc_html_e( 'قيمة الفاتورة', 'olama-registration' ); ?></div>
                        <div id="drawer-total-val" class="olama-reg-metric-value" style="font-size:22px; margin-top:0;">0.00</div>
                    </div>
                    <div class="olama-reg-metric-card" style="background:#fff3f3; border-left:4px solid #ef4444; padding:15px; flex-direction:column; align-items:center; gap:8px;">
                        <div class="olama-reg-metric-title"><?php esc_html_e( 'الخصم الممنوح', 'olama-registration' ); ?></div>
                        <div id="drawer-discount-val" class="olama-reg-metric-value" style="font-size:22px; margin-top:0; color:#ef4444;">0.00</div>
                    </div>
                    <div class="olama-reg-metric-card olama-reg-metric-card--success" style="padding:15px; flex-direction:column; align-items:center; gap:8px;">
                        <div class="olama-reg-metric-title"><?php esc_html_e( 'المجموع المدفوع', 'olama-registration' ); ?></div>
                        <div id="drawer-paid-val" class="olama-reg-metric-value" style="font-size:22px; margin-top:0;">0.00</div>
                    </div>
                    <div class="olama-reg-metric-card olama-reg-metric-card--warning" style="padding:15px; flex-direction:column; align-items:center; gap:8px;">
                        <div class="olama-reg-metric-title"><?php esc_html_e( 'المتبقي المستحق', 'olama-registration' ); ?></div>
                        <div id="drawer-balance-val" class="olama-reg-metric-value" style="font-size:22px; margin-top:0;">0.00</div>
                    </div>
                    <div class="olama-reg-metric-card" style="background:#fff7ed; border-left:4px solid #f59e0b; padding:15px; flex-direction:column; align-items:center; gap:8px;">
                        <div class="olama-reg-metric-title"><?php esc_html_e( 'الإشعار المدين', 'olama-registration' ); ?></div>
                        <div id="drawer-debit-notes-val" class="olama-reg-metric-value" style="font-size:22px; margin-top:0; color:#b45309;">0.00</div>
                    </div>
                    <div class="olama-reg-metric-card" style="background:#eff6ff; border-left:4px solid #2563eb; padding:15px; flex-direction:column; align-items:center; gap:8px;">
                        <div class="olama-reg-metric-title"><?php esc_html_e( 'الإشعار الدائن', 'olama-registration' ); ?></div>
                        <div id="drawer-credit-notes-val" class="olama-reg-metric-value" style="font-size:22px; margin-top:0; color:#1d4ed8;">0.00</div>
                    </div>
                </div>

                <!-- Info grid -->
                <div class="olama-reg-section">
                    <h3 class="olama-reg-section-title"><?php esc_html_e( 'الارتباط والتواريخ', 'olama-registration' ); ?></h3>
                    <div class="olama-reg-section-body" style="font-size:14px; display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div><strong><?php esc_html_e( 'رقم العائلة المربوطة:', 'olama-registration' ); ?></strong> <span id="drawer-family-uid" class="olama-reg-uid-badge"></span></div>
                        <div><strong><?php esc_html_e( 'حالة الفاتورة:', 'olama-registration' ); ?></strong> <span id="drawer-status-badge"></span></div>
                        <div><strong><?php esc_html_e( 'تاريخ الإصدار:', 'olama-registration' ); ?></strong> <span id="drawer-issue-date" style="font-weight:700;"></span></div>
                        <div><strong><?php esc_html_e( 'تاريخ الاستحقاق:', 'olama-registration' ); ?></strong> <span id="drawer-due-date" style="font-weight:700;"></span></div>
                    </div>
                </div>

                <!-- Line items -->
                <div class="olama-reg-section">
                    <h3 class="olama-reg-section-title"><?php esc_html_e( 'البنود والرسوم المفوترة', 'olama-registration' ); ?></h3>
                    <div class="olama-reg-table-wrap">
                        <table class="olama-reg-fin-table" id="drawer-items-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'البند / الرسوم', 'olama-registration' ); ?></th>
                                    <th style="width:70px; text-align:center;"><?php esc_html_e( 'الكمية', 'olama-registration' ); ?></th>
                                    <th style="width:110px;"><?php esc_html_e( 'سعر الوحدة', 'olama-registration' ); ?></th>
                                    <th style="width:110px;"><?php esc_html_e( 'الإجمالي', 'olama-registration' ); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <!-- Installments Timeline -->
                <div class="olama-reg-section">
                    <h3 class="olama-reg-section-title"><?php esc_html_e( 'جدول الأقساط وجدول السداد', 'olama-registration' ); ?></h3>
                    <div class="olama-reg-table-wrap">
                        <table class="olama-reg-fin-table" id="drawer-installments-table">
                            <thead>
                                <tr>
                                    <th style="width:70px;"><?php esc_html_e( 'القسط', 'olama-registration' ); ?></th>
                                    <th><?php esc_html_e( 'تاريخ الاستحقاق', 'olama-registration' ); ?></th>
                                    <th><?php esc_html_e( 'القيمة المطلوبة', 'olama-registration' ); ?></th>
                                    <th><?php esc_html_e( 'القيمة المدفوعة', 'olama-registration' ); ?></th>
                                    <th><?php esc_html_e( 'الحالة', 'olama-registration' ); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <!-- Payments History (سجل الدفعات السابقة) -->
                <div class="olama-reg-section" id="drawer-payments-section" style="display:none;">
                    <h3 class="olama-reg-section-title"><?php esc_html_e( 'سجل الدفعات السابقة (السندات المرتبطة)', 'olama-registration' ); ?></h3>
                    <div class="olama-reg-table-wrap">
                        <table class="olama-reg-fin-table" id="drawer-payments-table">
                            <thead>
                                <tr>
                                    <th style="width:90px;"><?php esc_html_e( 'رقم السند', 'olama-registration' ); ?></th>
                                    <th><?php esc_html_e( 'تاريخ الدفع', 'olama-registration' ); ?></th>
                                    <th><?php esc_html_e( 'طريقة الدفع', 'olama-registration' ); ?></th>
                                    <th><?php esc_html_e( 'المبلغ المدفوع', 'olama-registration' ); ?></th>
                                    <th><?php esc_html_e( 'المرجع', 'olama-registration' ); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

            </div>

            <div class="olama-reg-form-actions" style="justify-content:flex-end;">
                <button type="button" class="olama-reg-btn olama-reg-btn--primary olama-reg-drawer-close"><?php esc_html_e( 'إغلاق النافذة', 'olama-registration' ); ?></button>
            </div>
        </div>
    </div>

</div>
