<?php
/**
 * Billing Reports and Analytics Dashboard.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'olama_reg_reports_fix_mojibake_text' ) ) {
function olama_reg_reports_fix_mojibake_text( string $text ): string {
    if ( ! preg_match( '/[\x{0637}\x{0638}\x{0622}\x{00E2}]/u', $text ) ) {
        return $text;
    }

    if ( false && (
        strpos( $text, 'ط' ) === false &&
        strpos( $text, 'ظ' ) === false &&
        strpos( $text, 'آ' ) === false &&
        strpos( $text, 'â' ) === false
    ) ) {
        return $text;
    }

    $bytes = @iconv( 'UTF-8', 'Windows-1256//IGNORE', $text );
    $is_valid_utf8 = is_string( $bytes ) && (
        function_exists( 'mb_check_encoding' )
            ? mb_check_encoding( $bytes, 'UTF-8' )
            : (bool) preg_match( '//u', $bytes )
    );

    if ( ! is_string( $bytes ) || $bytes === '' || ! $is_valid_utf8 ) {
        return $text;
    }

    return $bytes;
}
}

if ( ! function_exists( 'olama_reg_reports_fix_mojibake_output' ) ) {
function olama_reg_reports_fix_mojibake_output( string $html ): string {
    $html = preg_replace_callback(
        '/>([^<]+)</u',
        static function ( array $matches ): string {
            return '>' . olama_reg_reports_fix_mojibake_text( $matches[1] ) . '<';
        },
        $html
    );

    return preg_replace_callback(
        '/\b(placeholder|title|aria-label)="([^"]*)"/u',
        static function ( array $matches ): string {
            return $matches[1] . '="' . esc_attr( olama_reg_reports_fix_mojibake_text( $matches[2] ) ) . '"';
        },
        $html ?? ''
    );
}
}

ob_start( 'olama_reg_reports_fix_mojibake_output' );

$active_tab = sanitize_key( $_GET['report_tab'] ?? 'dashboard' );
$action     = sanitize_text_field( $_GET['action'] ?? '' );

$year_id = absint( $_GET['year_id'] ?? 0 );
if ( ! $year_id && class_exists( 'Olama_School_Academic' ) ) {
    $active_y = Olama_School_Academic::get_active_year();
    if ( $active_y ) {
        $year_id = (int) $active_y->id;
    }
}

$years = [];
if ( class_exists( 'Olama_School_Academic' ) ) {
    $years = Olama_School_Academic::get_years();
}

$method_labels = Olama_Reg_Billing_Reports::get_method_labels();
$available_methods = Olama_Reg_Billing_Reports::get_available_payment_methods();
$cashiers = Olama_Reg_Billing_Reports::get_cashier_options();
$financial_accounts = Olama_Reg_Billing_Reports::get_financial_account_options();

$cash_filters = [
    'year_id'    => $year_id,
    'date_from'  => sanitize_text_field( $_GET['date_from'] ?? '' ),
    'date_to'    => sanitize_text_field( $_GET['date_to'] ?? '' ),
    'method'     => sanitize_text_field( $_GET['method'] ?? '' ),
    'cashier_id' => absint( $_GET['cashier_id'] ?? 0 ),
];
$cash_report = Olama_Reg_Billing_Reports::get_cash_register_report( $cash_filters );
$cash_rows = $cash_report['rows'];
$cash_summary = $cash_report['summary'];

$ledger_filters = [
    'account_id' => absint( $_GET['account_id'] ?? 0 ),
    'date_from'  => sanitize_text_field( $_GET['date_from'] ?? '' ),
    'date_to'    => sanitize_text_field( $_GET['date_to'] ?? '' ),
];
$ledger_report = Olama_Reg_Billing_Reports::get_account_ledger_report( $ledger_filters );
$ledger_rows = $ledger_report['rows'];

$receipt_report = Olama_Reg_Billing_Reports::get_receipts_report( [
    'date_from'  => sanitize_text_field( $_GET['date_from'] ?? '' ),
    'date_to'    => sanitize_text_field( $_GET['date_to'] ?? '' ),
    'method'     => sanitize_text_field( $_GET['method'] ?? '' ),
    'status'     => sanitize_key( $_GET['status'] ?? '' ),
    'account_id' => absint( $_GET['account_id'] ?? 0 ),
] );
$receipt_rows = $receipt_report['rows'];

$allocation_report = Olama_Reg_Billing_Reports::get_allocation_report( [
    'date_from' => sanitize_text_field( $_GET['date_from'] ?? '' ),
    'date_to'   => sanitize_text_field( $_GET['date_to'] ?? '' ),
] );
$allocation_rows = $allocation_report['rows'];

$statement_report = Olama_Reg_Billing_Reports::get_family_statement_report( [
    'entity_type' => sanitize_key( $_GET['entity_type'] ?? 'family' ),
    'uid'         => sanitize_text_field( $_GET['uid'] ?? '' ),
    'year_id'     => $year_id,
    'student_uid' => sanitize_text_field( $_GET['student_uid'] ?? '' ),
    'date_from'   => sanitize_text_field( $_GET['date_from'] ?? '' ),
    'date_to'     => sanitize_text_field( $_GET['date_to'] ?? '' ),
] );
$statement_rows    = $statement_report['rows'];
$statement_summary = $statement_report['summary'];
$statement_entity  = $statement_report['entity'];

$family_balances_report = Olama_Reg_Billing_Reports::get_family_balances_report( [
    'year_id' => $year_id,
    'search'  => sanitize_text_field( $_GET['family_search'] ?? '' ),
] );
$family_balance_rows    = $family_balances_report['rows'];
$family_balance_summary = $family_balances_report['summary'];

$cheque_report = Olama_Reg_Billing_Reports::get_cheques_report( [
    'status' => sanitize_key( $_GET['cheque_status'] ?? '' ),
] );
$cheque_rows = $cheque_report['rows'];

$money = static function ( $amount ): string {
    return number_format( (float) $amount, 2 );
};

$year_label = __( 'ط¬ظ…ظٹط¹ ط§ظ„ط£ط¹ظˆط§ظ… ط§ظ„ط¯ط±ط§ط³ظٹط©', 'olama-registration' );
foreach ( $years as $y ) {
    if ( (int) $y->id === (int) $year_id ) {
        $year_label = $y->year_name;
        break;
    }
}

$build_url = static function ( array $args = [] ): string {
    return esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) );
};

if ( $action === 'print_family_statement' ) {
    $school_settings = get_option( 'olama_school_settings', [] );
    $school_name     = $school_settings['school_name_ar'] ?? get_bloginfo( 'name' );
    $entity_name     = ! empty( $statement_entity['name'] ) ? $statement_entity['name'] : $statement_entity['uid'];
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title><?php esc_html_e( 'كشف الحساب', 'olama-registration' ); ?></title>
        <style>
            body { font-family:Tajawal, Arial, sans-serif; color:#172033; margin:28px; direction:rtl; }
            .no-print { margin-bottom:16px; text-align:left; }
            button { background:#6f42ff; color:#fff; border:0; border-radius:6px; padding:9px 18px; font-weight:700; cursor:pointer; }
            h1 { margin:0 0 4px; font-size:24px; }
            .meta { color:#64748b; margin-bottom:18px; line-height:1.8; }
            .summary { display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; margin:18px 0; }
            .box { border:1px solid #d8dee9; background:#f8fafc; padding:10px; border-radius:6px; }
            .box strong { display:block; font-size:17px; margin-top:4px; direction:ltr; }
            .debit { color:#c62828; }
            .credit { color:#17823b; }
            table { width:100%; border-collapse:collapse; font-size:12px; }
            th, td { border:1px solid #d8dee9; padding:8px; text-align:right; vertical-align:middle; }
            th { background:#1A1A2E; color:#fff; }
            .amount { direction:ltr; text-align:left; }
            @media print {
                .no-print { display:none; }
                body { margin:0; }
                @page { size:A4 landscape; margin:12mm; }
            }
        </style>
    </head>
    <body>
        <div class="no-print"><button type="button" onclick="window.print();"><?php esc_html_e( 'طباعة كشف الحساب', 'olama-registration' ); ?></button></div>
        <h1><?php esc_html_e( 'كشف الحساب', 'olama-registration' ); ?></h1>
        <div class="meta">
            <?php echo esc_html( $school_name ); ?> |
            <?php echo esc_html( $entity_name ); ?>
            <?php if ( ! empty( $statement_entity['uid'] ) ) : ?>
                <span dir="ltr">(<?php echo esc_html( $statement_entity['uid'] ); ?>)</span>
            <?php endif; ?> |
            <?php esc_html_e( 'العام الدراسي:', 'olama-registration' ); ?> <?php echo esc_html( $year_label ); ?> |
            <?php esc_html_e( 'تاريخ الطباعة:', 'olama-registration' ); ?> <?php echo esc_html( wp_date( 'Y-m-d H:i' ) ); ?>
        </div>
        <div class="summary">
            <div class="box"><?php esc_html_e( 'الرصيد الافتتاحي', 'olama-registration' ); ?><strong><?php echo esc_html( $money( $statement_summary['opening_balance'] ?? 0 ) ); ?></strong></div>
            <div class="box"><?php esc_html_e( 'إجمالي المدين', 'olama-registration' ); ?><strong class="debit"><?php echo esc_html( $money( $statement_summary['total_debit'] ?? 0 ) ); ?></strong></div>
            <div class="box"><?php esc_html_e( 'إجمالي الدائن', 'olama-registration' ); ?><strong class="credit"><?php echo esc_html( $money( $statement_summary['total_credit'] ?? 0 ) ); ?></strong></div>
            <div class="box"><?php esc_html_e( 'الرصيد الختامي', 'olama-registration' ); ?><strong><?php echo esc_html( $money( $statement_summary['closing_balance'] ?? 0 ) ); ?></strong></div>
        </div>
        <table>
            <thead><tr>
                <th><?php esc_html_e( 'التاريخ', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'النوع', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'المرجع', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'البيان', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'مدين', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'دائن', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'الرصيد', 'olama-registration' ); ?></th>
            </tr></thead>
            <tbody>
                <?php if ( empty( $statement_rows ) ) : ?>
                    <tr><td colspan="7"><?php esc_html_e( 'لا توجد حركات على كشف الحساب.', 'olama-registration' ); ?></td></tr>
                <?php else : foreach ( $statement_rows as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row->movement_date ?: '—' ); ?></td>
                        <td><?php echo esc_html( Olama_Reg_Billing_Reports::format_statement_entry_type( (string) $row->entry_type, (string) ( $row->entry_subtype ?? '' ) ) ); ?></td>
                        <td><strong><?php echo esc_html( $row->reference_no ?: '—' ); ?></strong></td>
                        <td><?php echo esc_html( $row->details ?: '—' ); ?></td>
                        <td class="amount debit"><?php echo $row->debit_amount > 0 ? esc_html( $money( $row->debit_amount ) ) : '—'; ?></td>
                        <td class="amount credit"><?php echo $row->credit_amount > 0 ? esc_html( $money( $row->credit_amount ) ) : '—'; ?></td>
                        <td class="amount"><strong><?php echo esc_html( $money( $row->running_balance ) ); ?></strong></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

$export_args = [
    'page'       => 'olama-registration-reports',
    'report_tab' => 'cash_register',
    'year_id'    => $year_id,
    'date_from'  => $cash_report['filters']['date_from'],
    'date_to'    => $cash_report['filters']['date_to'],
    'method'     => $cash_report['filters']['method'],
    'cashier_id' => $cash_report['filters']['cashier_id'],
];

if ( $action === 'export_cash_register_excel' ) {
    nocache_headers();
    header( 'Content-Type: application/vnd.ms-excel; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="cash-register-report-' . date( 'Y-m-d' ) . '.xls"' );
    echo "\xEF\xBB\xBF";
    ?>
    <html lang="ar" dir="rtl">
    <head><meta charset="UTF-8"></head>
    <body>
    <table border="1">
        <thead>
            <tr>
                <th><?php esc_html_e( 'ط±ظ‚ظ… ط§ظ„ط³ظ†ط¯', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'ط§ط³ظ… ط§ظ„ط·ط§ظ„ط¨', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'ط±ظ‚ظ… ط§ظ„ط·ط§ظ„ط¨', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'طھط§ط±ظٹط® ط§ظ„ظ‚ط¨ط¶', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'ط·ط±ظٹظ‚ط© ط§ظ„ط¯ظپط¹', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'ط§ظ„ظ…ط¨ظ„ط؛ ط§ظ„ظ…ظ‚ط¨ظˆط¶', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'ط±ظ‚ظ… ط§ظ„ظ…ط±ط¬ط¹', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'ظ…ظ„ط§ط­ط¸ط§طھ', 'olama-registration' ); ?></th>
                <th><?php esc_html_e( 'ط§ظ„ظ…ط³طھظ„ظ…', 'olama-registration' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $cash_rows as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( '#' . $row->id ); ?></td>
                    <td><?php echo esc_html( $row->student_name ?: 'â€”' ); ?></td>
                    <td><?php echo esc_html( $row->student_identifier ?: 'â€”' ); ?></td>
                    <td><?php echo esc_html( $row->payment_date ); ?></td>
                    <td><?php echo esc_html( $method_labels[ $row->method ] ?? $row->method ); ?></td>
                    <td><?php echo esc_html( $money( $row->amount ) ); ?></td>
                    <td><?php echo esc_html( $row->reference ?: 'â€”' ); ?></td>
                    <td><?php echo esc_html( $row->notes ?: 'â€”' ); ?></td>
                    <td><?php echo esc_html( $row->received_by_name ?: 'â€”' ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </body>
    </html>
    <?php
    exit;
}

if ( $action === 'print_family_balances' ) {
    $school_settings = get_option( 'olama_school_settings', [] );
    $school_name     = $school_settings['school_name_ar'] ?? get_bloginfo( 'name' );
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>كشف أرصدة العائلات</title>
        <style>
            body { font-family:Tajawal, Arial, sans-serif; color:#172033; margin:28px; direction:rtl; }
            .no-print { margin-bottom:16px; text-align:left; }
            button { background:#E8920A; color:#fff; border:0; border-radius:6px; padding:9px 18px; font-weight:700; cursor:pointer; }
            h1 { margin:0 0 4px; font-size:24px; }
            .meta { color:#64748b; margin-bottom:18px; }
            .summary { display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; margin:18px 0; }
            .box { border:1px solid #E0C090; background:#FFF8E7; padding:10px; border-radius:6px; }
            .box strong { display:block; font-size:17px; margin-top:4px; }
            table { width:100%; border-collapse:collapse; font-size:12px; }
            th, td { border:1px solid #d8dee9; padding:8px; text-align:right; vertical-align:middle; }
            th { background:#1A1A2E; color:#fff; }
            .amount { direction:ltr; text-align:left; font-weight:700; }
            .debit { color:#c62828; }
            .credit { color:#17823b; }
            @media print {
                .no-print { display:none; }
                body { margin:0; }
                @page { size:A4 landscape; margin:12mm; }
            }
        </style>
    </head>
    <body>
        <div class="no-print"><button onclick="window.print();">طباعة</button></div>
        <h1>كشف أرصدة العائلات</h1>
        <div class="meta">
            <?php echo esc_html( $school_name ); ?> |
            العام الدراسي: <?php echo esc_html( $year_label ); ?> |
            تاريخ الطباعة: <?php echo esc_html( wp_date( 'Y-m-d' ) ); ?>
        </div>
        <div class="summary">
            <div class="box">عدد العائلات<strong><?php echo esc_html( (int) $family_balance_summary['family_count'] ); ?></strong></div>
            <div class="box">مجموع الحركات المدينة<strong class="debit"><?php echo esc_html( $money( $family_balance_summary['total_debit'] ) ); ?></strong></div>
            <div class="box">مجموع الحركات الدائنة<strong class="credit"><?php echo esc_html( $money( $family_balance_summary['total_credit'] ) ); ?></strong></div>
            <div class="box">الرصيد<strong><?php echo esc_html( $money( $family_balance_summary['balance'] ) ); ?></strong></div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>رقم العائلة</th>
                    <th>اسم العائلة / ولي الأمر</th>
                    <th>مجموع الحركات المدينة</th>
                    <th>مجموع الحركات الدائنة</th>
                    <th>الرصيد</th>
                    <th>هاتف الأب</th>
                    <th>هاتف الأم</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $family_balance_rows ) ) : ?>
                    <tr><td colspan="7">لا توجد عائلات لديها عقود مطابقة.</td></tr>
                <?php else : foreach ( $family_balance_rows as $row ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $row->family_number ); ?></strong></td>
                        <td><?php echo esc_html( $row->family_name ); ?></td>
                        <td class="amount debit"><?php echo esc_html( $money( $row->total_debit ) ); ?></td>
                        <td class="amount credit"><?php echo esc_html( $money( $row->total_credit ) ); ?></td>
                        <td class="amount"><?php echo esc_html( $money( $row->balance ) ); ?></td>
                        <td dir="ltr"><?php echo esc_html( $row->father_mobile ?: '—' ); ?></td>
                        <td dir="ltr"><?php echo esc_html( $row->mother_mobile ?: '—' ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

if ( $action === 'print_cash_register' || $action === 'export_cash_register_pdf' ) {
    $school_settings = get_option( 'olama_school_settings', [] );
    $school_name = $school_settings['school_name_ar'] ?? get_bloginfo( 'name' );
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title><?php esc_html_e( 'ط¬ط±ط¯ ط§ظ„طµظ†ط¯ظˆظ‚', 'olama-registration' ); ?></title>
        <style>
            body { font-family: Tajawal, Arial, sans-serif; color:#1a1a2e; margin:28px; direction:rtl; }
            .no-print { margin-bottom:16px; text-align:left; }
            button { background:#E8920A; color:#fff; border:0; border-radius:6px; padding:9px 18px; font-weight:700; cursor:pointer; }
            h1 { margin:0 0 4px; font-size:24px; }
            .meta { color:#64748b; margin-bottom:18px; }
            .summary { display:grid; grid-template-columns:repeat(5, 1fr); gap:8px; margin:18px 0; }
            .box { border:1px solid #E0C090; background:#FFF8E7; padding:10px; border-radius:6px; }
            .box strong { display:block; font-size:17px; margin-top:4px; }
            table { width:100%; border-collapse:collapse; font-size:12px; }
            th, td { border:1px solid #d8dee9; padding:7px; text-align:right; vertical-align:top; }
            th { background:#1A1A2E; color:#fff; }
            @media print { .no-print { display:none; } body { margin:0; } }
        </style>
    </head>
    <body>
        <div class="no-print">
            <button onclick="window.print();"><?php echo $action === 'export_cash_register_pdf' ? esc_html__( 'ط­ظپط¸ / ط·ط¨ط§ط¹ط© PDF', 'olama-registration' ) : esc_html__( 'ط·ط¨ط§ط¹ط©', 'olama-registration' ); ?></button>
        </div>
        <h1><?php esc_html_e( 'ط¬ط±ط¯ ط§ظ„طµظ†ط¯ظˆظ‚', 'olama-registration' ); ?></h1>
        <div class="meta">
            <?php echo esc_html( $school_name ); ?> |
            <?php esc_html_e( 'ط§ظ„ط¹ط§ظ… ط§ظ„ط¯ط±ط§ط³ظٹ:', 'olama-registration' ); ?> <?php echo esc_html( $year_label ); ?> |
            <?php esc_html_e( 'ظ…ظ†:', 'olama-registration' ); ?> <?php echo esc_html( $cash_report['filters']['date_from'] ?: 'â€”' ); ?> |
            <?php esc_html_e( 'ط¥ظ„ظ‰:', 'olama-registration' ); ?> <?php echo esc_html( $cash_report['filters']['date_to'] ?: 'â€”' ); ?>
        </div>
        <div class="summary">
            <div class="box"><?php esc_html_e( 'ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ظ†ظ‚ط¯ظٹ', 'olama-registration' ); ?><strong><?php echo esc_html( $money( $cash_summary['cash'] ) ); ?></strong></div>
            <div class="box"><?php esc_html_e( 'ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ط´ظٹظƒط§طھ', 'olama-registration' ); ?><strong><?php echo esc_html( $money( $cash_summary['cheque'] ) ); ?></strong></div>
            <div class="box"><?php esc_html_e( 'ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ط¯ظپط¹ ط§ظ„ط¥ظ„ظƒطھط±ظˆظ†ظٹ', 'olama-registration' ); ?><strong><?php echo esc_html( $money( $cash_summary['online'] ) ); ?></strong></div>
            <div class="box"><?php esc_html_e( 'ط§ظ„ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ط¹ط§ظ…', 'olama-registration' ); ?><strong><?php echo esc_html( $money( $cash_summary['total'] ) ); ?></strong></div>
            <div class="box"><?php esc_html_e( 'ط¹ط¯ط¯ ط§ظ„ط­ط±ظƒط§طھ', 'olama-registration' ); ?><strong><?php echo esc_html( (int) $cash_summary['transaction_count'] ); ?></strong></div>
        </div>
        <?php olama_reg_render_cash_register_table( $cash_rows, $method_labels, $money ); ?>
        <?php if ( $action === 'export_cash_register_pdf' ) : ?>
            <script>window.addEventListener('load', function(){ window.print(); });</script>
        <?php endif; ?>
    </body>
    </html>
    <?php
    exit;
}

$metrics = Olama_Reg_Billing_Reports::get_summary_metrics( $year_id );
$monthly = Olama_Reg_Billing_Reports::get_monthly_collections( $year_id );
$methods = Olama_Reg_Billing_Reports::get_payment_method_breakdown( $year_id );
$aging   = Olama_Reg_Billing_Reports::get_aging_receivables( $year_id );

if ( ! function_exists( 'olama_reg_render_cash_register_table' ) ) {
function olama_reg_render_cash_register_table( array $rows, array $method_labels, callable $money ): void {
    ?>
    <div class="olama-reg-table-wrap">
        <table class="olama-reg-fin-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ط±ظ‚ظ… ط§ظ„ط³ظ†ط¯', 'olama-registration' ); ?></th>
                    <th><?php esc_html_e( 'ط§ط³ظ… ط§ظ„ط·ط§ظ„ط¨', 'olama-registration' ); ?></th>
                    <th><?php esc_html_e( 'ط±ظ‚ظ… ط§ظ„ط·ط§ظ„ط¨', 'olama-registration' ); ?></th>
                    <th><?php esc_html_e( 'طھط§ط±ظٹط® ط§ظ„ظ‚ط¨ط¶', 'olama-registration' ); ?></th>
                    <th><?php esc_html_e( 'ط·ط±ظٹظ‚ط© ط§ظ„ط¯ظپط¹', 'olama-registration' ); ?></th>
                    <th><?php esc_html_e( 'ط§ظ„ظ…ط¨ظ„ط؛ ط§ظ„ظ…ظ‚ط¨ظˆط¶', 'olama-registration' ); ?></th>
                    <th><?php esc_html_e( 'ط±ظ‚ظ… ط§ظ„ظ…ط±ط¬ط¹', 'olama-registration' ); ?></th>
                    <th><?php esc_html_e( 'ظ…ظ„ط§ط­ط¸ط§طھ', 'olama-registration' ); ?></th>
                    <th><?php esc_html_e( 'ط§ظ„ظ…ط³طھظ„ظ…', 'olama-registration' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr>
                        <td colspan="9" class="olama-reg-empty-state">
                            <?php esc_html_e( 'ظ„ط§ طھظˆط¬ط¯ ط­ط±ظƒط§طھ ظ‚ط¨ط¶ ظ…ط·ط§ط¨ظ‚ط© ظ„ظ„ظپظ„ط§طھط± ط§ظ„ظ…ط­ط¯ط¯ط©.', 'olama-registration' ); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><strong>#<?php echo esc_html( $row->id ); ?></strong></td>
                            <td><?php echo esc_html( $row->student_name ?: 'â€”' ); ?></td>
                            <td><span class="olama-reg-uid-badge"><?php echo esc_html( $row->student_identifier ?: 'â€”' ); ?></span></td>
                            <td><?php echo esc_html( $row->payment_date ); ?></td>
                            <td><?php echo esc_html( $method_labels[ $row->method ] ?? $row->method ); ?></td>
                            <td class="olama-reg-text--success"><?php echo esc_html( $money( $row->amount ) ); ?></td>
                            <td><?php echo esc_html( $row->reference ?: 'â€”' ); ?></td>
                            <td><?php echo esc_html( $row->notes ?: 'â€”' ); ?></td>
                            <td><?php echo esc_html( $row->received_by_name ?: 'â€”' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
}

if ( ! function_exists( 'olama_reg_render_statement_type_label' ) ) {
function olama_reg_render_statement_type_label( string $type, string $subtype = '' ): string {
    return Olama_Reg_Billing_Reports::format_statement_entry_type( $type, $subtype );
}
}

if ( ! function_exists( 'olama_reg_statement_text' ) ) {
function olama_reg_statement_text( string $key ): string {
    $labels = [
        'tab'              => "\u{0643}\u{0634}\u{0641} \u{0627}\u{0644}\u{062D}\u{0633}\u{0627}\u{0628}",
        'filter_title'     => "\u{0641}\u{0644}\u{0627}\u{062A}\u{0631} \u{0643}\u{0634}\u{0641} \u{0627}\u{0644}\u{062D}\u{0633}\u{0627}\u{0628}",
        'entity_type'      => "\u{0646}\u{0648}\u{0639} \u{0627}\u{0644}\u{062C}\u{0647}\u{0629}",
        'family'           => "\u{0639}\u{0627}\u{0626}\u{0644}\u{0629}",
        'external'         => "\u{0639}\u{0645}\u{064A}\u{0644} \u{062E}\u{0627}\u{0631}\u{062C}\u{064A}",
        'uid_label'        => "\u{0631}\u{0642}\u{0645} \u{0627}\u{0644}\u{0645}\u{0644}\u{0641} / \u{0627}\u{0644}\u{0639}\u{0645}\u{064A}\u{0644}",
        'uid_placeholder'  => "\u{0645}\u{062B}\u{0627}\u{0644}: 634 \u{0623}\u{0648} CUST-0001",
        'year'             => "\u{0627}\u{0644}\u{0639}\u{0627}\u{0645} \u{0627}\u{0644}\u{062F}\u{0631}\u{0627}\u{0633}\u{064A}",
        'all_years'        => "\u{062C}\u{0645}\u{064A}\u{0639} \u{0627}\u{0644}\u{0623}\u{0639}\u{0648}\u{0627}\u{0645} \u{0627}\u{0644}\u{062F}\u{0631}\u{0627}\u{0633}\u{064A}\u{0629}",
        'from_date'        => "\u{0645}\u{0646} \u{062A}\u{0627}\u{0631}\u{064A}\u{062E}",
        'to_date'          => "\u{0625}\u{0644}\u{0649} \u{062A}\u{0627}\u{0631}\u{064A}\u{062E}",
        'show'             => "\u{0639}\u{0631}\u{0636} \u{0627}\u{0644}\u{0643}\u{0634}\u{0641}",
        'opening'          => "\u{0627}\u{0644}\u{0631}\u{0635}\u{064A}\u{062F} \u{0627}\u{0644}\u{0627}\u{0641}\u{062A}\u{062A}\u{0627}\u{062D}\u{064A}",
        'total_debit'      => "\u{0625}\u{062C}\u{0645}\u{0627}\u{0644}\u{064A} \u{0627}\u{0644}\u{0645}\u{062F}\u{064A}\u{0646}",
        'total_credit'     => "\u{0625}\u{062C}\u{0645}\u{0627}\u{0644}\u{064A} \u{0627}\u{0644}\u{062F}\u{0627}\u{0626}\u{0646}",
        'closing'          => "\u{0627}\u{0644}\u{0631}\u{0635}\u{064A}\u{062F} \u{0627}\u{0644}\u{062E}\u{062A}\u{0627}\u{0645}\u{064A}",
        'date'             => "\u{0627}\u{0644}\u{062A}\u{0627}\u{0631}\u{064A}\u{062E}",
        'type'             => "\u{0627}\u{0644}\u{0646}\u{0648}\u{0639}",
        'reference'        => "\u{0627}\u{0644}\u{0645}\u{0631}\u{062C}\u{0639}",
        'details'          => "\u{0627}\u{0644}\u{0628}\u{064A}\u{0627}\u{0646}",
        'debit'            => "\u{0645}\u{062F}\u{064A}\u{0646}",
        'credit'           => "\u{062F}\u{0627}\u{0626}\u{0646}",
        'balance'          => "\u{0627}\u{0644}\u{0631}\u{0635}\u{064A}\u{062F}",
        'empty'            => "\u{0644}\u{0627} \u{062A}\u{0648}\u{062C}\u{062F} \u{062D}\u{0631}\u{0643}\u{0627}\u{062A} \u{0644}\u{0644}\u{062C}\u{0647}\u{0629} \u{0627}\u{0644}\u{0645}\u{062D}\u{062F}\u{062F}\u{0629}.",
        'invoice'          => "\u{0641}\u{0627}\u{062A}\u{0648}\u{0631}\u{0629}",
        'payment'          => "\u{062F}\u{0641}\u{0639}\u{0629}",
        'payment_reversal' => "\u{0639}\u{0643}\u{0633} \u{062F}\u{0641}\u{0639}\u{0629}",
        'credit_note'      => "\u{0625}\u{0634}\u{0639}\u{0627}\u{0631} \u{062F}\u{0627}\u{0626}\u{0646}",
        'debit_note'       => "\u{0625}\u{0634}\u{0639}\u{0627}\u{0631} \u{0645}\u{062F}\u{064A}\u{0646}",
    ];

    if ( $key === 'credit' ) {
        return $labels['credit_note'];
    }
    if ( $key === 'debit' ) {
        return $labels['debit_note'];
    }

    return $labels[ $key ] ?? $key;
}
}
?>
<div class="wrap olama-reg-wrap" dir="rtl">
    <div class="olama-reg-page-header">
        <h1>
            <span class="dashicons dashicons-chart-bar"></span>
            <?php esc_html_e( 'ط§ظ„طھظ‚ط§ط±ظٹط± ط§ظ„ظ…ط§ظ„ظٹط© ظˆط§ظ„طھط­ظ„ظٹظ„ط§طھ', 'olama-registration' ); ?>
        </h1>
        <?php if ( $year_id ) : ?>
            <span class="olama-reg-badge olama-reg-badge--info" style="padding:8px 18px; font-size:13px;">
                <span class="dashicons dashicons-calendar-alt"></span>
                <?php echo esc_html( $year_label ); ?>
            </span>
        <?php endif; ?>
    </div>

    <div id="olama-reg-notice" class="olama-reg-notice" style="display:none;"></div>

    <div class="olama-reg-report-tabs no-print">
        <a class="<?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>" href="<?php echo $build_url( [ 'page' => 'olama-registration-reports', 'report_tab' => 'dashboard', 'year_id' => $year_id ] ); ?>">
            <span class="dashicons dashicons-chart-bar"></span>
            <?php esc_html_e( 'ظ„ظˆط­ط© ط§ظ„طھظ‚ط§ط±ظٹط±', 'olama-registration' ); ?>
        </a>
        <a class="<?php echo $active_tab === 'cash_register' ? 'active' : ''; ?>" href="<?php echo $build_url( [ 'page' => 'olama-registration-reports', 'report_tab' => 'cash_register', 'year_id' => $year_id ] ); ?>">
            <span class="dashicons dashicons-portfolio"></span>
            <?php esc_html_e( 'ط¬ط±ط¯ ط§ظ„طµظ†ط¯ظˆظ‚', 'olama-registration' ); ?>
        </a>
        <a class="<?php echo $active_tab === 'account_ledger' ? 'active' : ''; ?>" href="<?php echo $build_url( [ 'page' => 'olama-registration-reports', 'report_tab' => 'account_ledger', 'year_id' => $year_id ] ); ?>">
            <span class="dashicons dashicons-list-view"></span>
            <?php esc_html_e( 'ظƒط´ظپ ط­ط±ظƒط© ط§ظ„ط­ط³ط§ط¨ط§طھ', 'olama-registration' ); ?>
        </a>
        <a class="<?php echo $active_tab === 'receipts' ? 'active' : ''; ?>" href="<?php echo $build_url( [ 'page' => 'olama-registration-reports', 'report_tab' => 'receipts', 'year_id' => $year_id ] ); ?>">
            <span class="dashicons dashicons-media-document"></span>
            <?php esc_html_e( 'طھظ‚ط±ظٹط± ط§ظ„ط¥ظٹطµط§ظ„ط§طھ', 'olama-registration' ); ?>
        </a>
        <a class="<?php echo $active_tab === 'allocations' ? 'active' : ''; ?>" href="<?php echo $build_url( [ 'page' => 'olama-registration-reports', 'report_tab' => 'allocations', 'year_id' => $year_id ] ); ?>">
            <span class="dashicons dashicons-networking"></span>
            <?php esc_html_e( 'طھط®طµظٹطµط§طھ ط§ظ„ط¯ظپط¹ط§طھ', 'olama-registration' ); ?>
        </a>
        <a class="<?php echo $active_tab === 'family_statement' ? 'active' : ''; ?>" href="<?php echo $build_url( [ 'page' => 'olama-registration-reports', 'report_tab' => 'family_statement', 'year_id' => $year_id ] ); ?>">
            <span class="dashicons dashicons-media-spreadsheet"></span>
            <?php echo esc_html( olama_reg_statement_text( 'tab' ) ); ?>
        </a>
        <a class="<?php echo $active_tab === 'family_balances' ? 'active' : ''; ?>" href="<?php echo $build_url( [ 'page' => 'olama-registration-reports', 'report_tab' => 'family_balances', 'year_id' => $year_id ] ); ?>">
            <span class="dashicons dashicons-groups"></span>
            كشف أرصدة العائلات
        </a>
        <a class="<?php echo $active_tab === 'cheques' ? 'active' : ''; ?>" href="<?php echo $build_url( [ 'page' => 'olama-registration-reports', 'report_tab' => 'cheques', 'year_id' => $year_id ] ); ?>">
            <span class="dashicons dashicons-tickets-alt"></span>
            <?php esc_html_e( 'طھظ‚ط±ظٹط± ط§ظ„ط´ظٹظƒط§طھ', 'olama-registration' ); ?>
        </a>
    </div>

    <?php if ( $active_tab === 'cash_register' ) : ?>
        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-filter"></span>
                <?php esc_html_e( 'ظپظ„ط§طھط± ط¬ط±ط¯ ط§ظ„طµظ†ط¯ظˆظ‚', 'olama-registration' ); ?>
            </h3>
            <form method="get" class="olama-reg-grid olama-reg-grid--compact">
                <input type="hidden" name="page" value="olama-registration-reports">
                <input type="hidden" name="report_tab" value="cash_register">
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ط§ظ„ط¹ط§ظ… ط§ظ„ط¯ط±ط§ط³ظٹ', 'olama-registration' ); ?></label>
                    <select name="year_id">
                        <option value="0"><?php esc_html_e( 'ط¬ظ…ظٹط¹ ط§ظ„ط£ط¹ظˆط§ظ… ط§ظ„ط¯ط±ط§ط³ظٹط©', 'olama-registration' ); ?></option>
                        <?php foreach ( $years as $y ) : ?>
                            <option value="<?php echo esc_attr( $y->id ); ?>" <?php selected( $year_id, $y->id ); ?>><?php echo esc_html( $y->year_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ظ…ظ† طھط§ط±ظٹط®', 'olama-registration' ); ?></label>
                    <input type="date" name="date_from" value="<?php echo esc_attr( $cash_report['filters']['date_from'] ); ?>">
                </div>
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ط¥ظ„ظ‰ طھط§ط±ظٹط®', 'olama-registration' ); ?></label>
                    <input type="date" name="date_to" value="<?php echo esc_attr( $cash_report['filters']['date_to'] ); ?>">
                </div>
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ط·ط±ظٹظ‚ط© ط§ظ„ط¯ظپط¹', 'olama-registration' ); ?></label>
                    <select name="method">
                        <option value=""><?php esc_html_e( 'ط¬ظ…ظٹط¹ ط·ط±ظ‚ ط§ظ„ط¯ظپط¹', 'olama-registration' ); ?></option>
                        <?php foreach ( $available_methods as $method_key => $method_label ) : ?>
                            <option value="<?php echo esc_attr( $method_key ); ?>" <?php selected( $cash_report['filters']['method'], $method_key ); ?>><?php echo esc_html( $method_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ط£ظ…ظٹظ† ط§ظ„طµظ†ط¯ظˆظ‚', 'olama-registration' ); ?></label>
                    <select name="cashier_id">
                        <option value="0"><?php esc_html_e( 'ط¬ظ…ظٹط¹ ط§ظ„ظ…ط³طھط®ط¯ظ…ظٹظ†', 'olama-registration' ); ?></option>
                        <?php foreach ( $cashiers as $cashier ) : ?>
                            <option value="<?php echo esc_attr( $cashier->ID ); ?>" <?php selected( $cash_report['filters']['cashier_id'], $cashier->ID ); ?>><?php echo esc_html( $cashier->display_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="olama-reg-field" style="justify-content:flex-end;">
                    <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                        <span class="dashicons dashicons-search"></span>
                        <?php esc_html_e( 'ط¹ط±ط¶ ط§ظ„طھظ‚ط±ظٹط±', 'olama-registration' ); ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="olama-reg-metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
            <div class="olama-reg-metric-card olama-reg-metric-card--success">
                <div class="olama-reg-metric-icon"><span class="dashicons dashicons-money-alt"></span></div>
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ظ†ظ‚ط¯ظٹ', 'olama-registration' ); ?></div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( $money( $cash_summary['cash'] ) ); ?></div>
                </div>
            </div>
            <div class="olama-reg-metric-card olama-reg-metric-card--warning">
                <div class="olama-reg-metric-icon"><span class="dashicons dashicons-media-spreadsheet"></span></div>
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ط´ظٹظƒط§طھ', 'olama-registration' ); ?></div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( $money( $cash_summary['cheque'] ) ); ?></div>
                </div>
            </div>
            <div class="olama-reg-metric-card olama-reg-metric-card--primary">
                <div class="olama-reg-metric-icon"><span class="dashicons dashicons-smartphone"></span></div>
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ط¯ظپط¹ ط§ظ„ط¥ظ„ظƒطھط±ظˆظ†ظٹ', 'olama-registration' ); ?></div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( $money( $cash_summary['online'] ) ); ?></div>
                </div>
            </div>
            <div class="olama-reg-metric-card">
                <div class="olama-reg-metric-icon"><span class="dashicons dashicons-bank"></span></div>
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„طھط­ظˆظٹظ„ط§طھ', 'olama-registration' ); ?></div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( $money( $cash_summary['bank_transfer'] ) ); ?></div>
                </div>
            </div>
            <div class="olama-reg-metric-card olama-reg-metric-card--success">
                <div class="olama-reg-metric-icon"><span class="dashicons dashicons-chart-line"></span></div>
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ظ…ظ‚ط¨ظˆط¶ط§طھ', 'olama-registration' ); ?></div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( $money( $cash_summary['total'] ) ); ?></div>
                </div>
            </div>
            <div class="olama-reg-metric-card">
                <div class="olama-reg-metric-icon"><span class="dashicons dashicons-list-view"></span></div>
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'ط¹ط¯ط¯ ط§ظ„ط­ط±ظƒط§طھ', 'olama-registration' ); ?></div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( (int) $cash_summary['transaction_count'] ); ?></div>
                </div>
            </div>
        </div>

        <div class="olama-reg-fin-toolbar no-print">
            <strong><?php esc_html_e( 'طھظپط§طµظٹظ„ ط­ط±ظƒط§طھ ط§ظ„ظ‚ط¨ط¶', 'olama-registration' ); ?></strong>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a class="olama-reg-btn olama-reg-btn--secondary" href="<?php echo $build_url( array_merge( $export_args, [ 'action' => 'export_cash_register_excel' ] ) ); ?>">
                    <span class="dashicons dashicons-media-spreadsheet"></span>
                    <?php esc_html_e( 'Excel', 'olama-registration' ); ?>
                </a>
                <a class="olama-reg-btn olama-reg-btn--secondary" target="_blank" href="<?php echo $build_url( array_merge( $export_args, [ 'action' => 'export_cash_register_pdf' ] ) ); ?>">
                    <span class="dashicons dashicons-pdf"></span>
                    <?php esc_html_e( 'PDF', 'olama-registration' ); ?>
                </a>
                <a class="olama-reg-btn olama-reg-btn--secondary" target="_blank" href="<?php echo $build_url( array_merge( $export_args, [ 'action' => 'print_cash_register' ] ) ); ?>">
                    <span class="dashicons dashicons-printer"></span>
                    <?php esc_html_e( 'ط·ط¨ط§ط¹ط©', 'olama-registration' ); ?>
                </a>
            </div>
        </div>

        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-portfolio"></span>
                <?php esc_html_e( 'ط¬ط±ط¯ ط§ظ„طµظ†ط¯ظˆظ‚', 'olama-registration' ); ?>
            </h3>
            <?php olama_reg_render_cash_register_table( $cash_rows, $method_labels, $money ); ?>
        </div>
    <?php elseif ( $active_tab === 'account_ledger' ) : ?>
        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-filter"></span>
                <?php esc_html_e( 'ظپظ„ط§طھط± ظƒط´ظپ ط­ط±ظƒط© ط§ظ„ط­ط³ط§ط¨ط§طھ', 'olama-registration' ); ?>
            </h3>
            <form method="get" class="olama-reg-grid olama-reg-grid--compact">
                <input type="hidden" name="page" value="olama-registration-reports">
                <input type="hidden" name="report_tab" value="account_ledger">
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ط§ظ„ط­ط³ط§ط¨ ط§ظ„ظ…ط§ظ„ظٹ', 'olama-registration' ); ?></label>
                    <select name="account_id">
                        <option value="0"><?php esc_html_e( 'ط¬ظ…ظٹط¹ ط§ظ„ط­ط³ط§ط¨ط§طھ', 'olama-registration' ); ?></option>
                        <?php foreach ( $financial_accounts as $account ) : ?>
                            <option value="<?php echo esc_attr( $account->id ); ?>" <?php selected( $ledger_report['filters']['account_id'], $account->id ); ?>>
                                <?php echo esc_html( $account->account_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ظ…ظ† طھط§ط±ظٹط®', 'olama-registration' ); ?></label>
                    <input type="date" name="date_from" value="<?php echo esc_attr( $ledger_report['filters']['date_from'] ); ?>">
                </div>
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ط¥ظ„ظ‰ طھط§ط±ظٹط®', 'olama-registration' ); ?></label>
                    <input type="date" name="date_to" value="<?php echo esc_attr( $ledger_report['filters']['date_to'] ); ?>">
                </div>
                <div class="olama-reg-field" style="justify-content:flex-end;">
                    <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                        <span class="dashicons dashicons-search"></span>
                        <?php esc_html_e( 'ط¹ط±ط¶ ط§ظ„ظƒط´ظپ', 'olama-registration' ); ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-list-view"></span>
                <?php esc_html_e( 'ظƒط´ظپ ط­ط±ظƒط© ط§ظ„ط­ط³ط§ط¨ط§طھ ط§ظ„ظ…ط§ظ„ظٹط©', 'olama-registration' ); ?>
            </h3>
            <div class="olama-reg-table-wrap">
                <table class="olama-reg-fin-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'ط±ظ‚ظ… ط§ظ„ط­ط±ظƒط©', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„طھط§ط±ظٹط®', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ط­ط³ط§ط¨', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ظ†ظˆط¹ ط§ظ„ط­ط±ظƒط©', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ظ…طµط¯ط±', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ظˆط§ط±ط¯', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'طµط§ط¯ط±', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ط±طµظٹط¯', 'olama-registration' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $ledger_rows ) ) : ?>
                            <tr><td colspan="8" class="olama-reg-empty-state"><?php esc_html_e( 'ظ„ط§ طھظˆط¬ط¯ ط­ط±ظƒط§طھ ظ…ط§ظ„ظٹط© ظ…ط·ط§ط¨ظ‚ط©.', 'olama-registration' ); ?></td></tr>
                        <?php else : foreach ( $ledger_rows as $row ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $row->movement_no ); ?></strong></td>
                                <td><?php echo esc_html( $row->movement_date ); ?></td>
                                <td><?php echo esc_html( $row->account_name ?: 'â€”' ); ?></td>
                                <td><?php echo esc_html( $row->movement_type ); ?></td>
                                <td><?php echo esc_html( $row->payment_no ?: '#' . (int) $row->source_id ); ?></td>
                                <td class="olama-reg-text--success"><?php echo $row->direction === 'in' ? esc_html( $money( $row->amount ) ) : 'â€”'; ?></td>
                                <td style="color:#c62828;"><?php echo $row->direction === 'out' ? esc_html( $money( $row->amount ) ) : 'â€”'; ?></td>
                                <td><strong><?php echo esc_html( $money( $row->running_balance ) ); ?></strong></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ( $active_tab === 'family_balances' ) : ?>
        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-filter"></span>
                فلاتر كشف أرصدة العائلات
            </h3>
            <form method="get" class="olama-reg-grid olama-reg-grid--compact">
                <input type="hidden" name="page" value="olama-registration-reports">
                <input type="hidden" name="report_tab" value="family_balances">
                <div class="olama-reg-field">
                    <label>العام الدراسي</label>
                    <select name="year_id">
                        <option value="0">جميع الأعوام الدراسية</option>
                        <?php foreach ( $years as $y ) : ?>
                            <option value="<?php echo esc_attr( $y->id ); ?>" <?php selected( $year_id, $y->id ); ?>><?php echo esc_html( $y->year_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="olama-reg-field">
                    <label for="olama-family-balance-search">بحث عن عائلة</label>
                    <input
                        type="search"
                        id="olama-family-balance-search"
                        name="family_search"
                        value="<?php echo esc_attr( $family_balances_report['filters']['search'] ); ?>"
                        placeholder="رقم العائلة أو اسم ولي الأمر"
                    >
                </div>
                <div class="olama-reg-field" style="justify-content:flex-end;">
                    <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                        <span class="dashicons dashicons-search"></span>
                        عرض التقرير
                    </button>
                </div>
                <div class="olama-reg-field" style="justify-content:flex-end;">
                    <a
                        class="olama-reg-btn olama-reg-btn--secondary"
                        target="_blank"
                        href="<?php echo $build_url( [
                            'page'          => 'olama-registration-reports',
                            'report_tab'    => 'family_balances',
                            'action'        => 'print_family_balances',
                            'year_id'       => $year_id,
                            'family_search' => $family_balances_report['filters']['search'],
                        ] ); ?>"
                    >
                        <span class="dashicons dashicons-printer"></span>
                        طباعة التقرير
                    </a>
                </div>
            </form>
            <p style="margin:14px 0 0; color:#64748b;">
                يعرض التقرير فقط العائلات التي لديها عقد مسجل وغير ملغى في العام الدراسي المحدد.
            </p>
        </div>

        <div class="olama-reg-metrics-grid" style="grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));">
            <div class="olama-reg-metric-card olama-reg-metric-card--primary">
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title">عدد العائلات</div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( (int) $family_balance_summary['family_count'] ); ?></div>
                </div>
            </div>
            <div class="olama-reg-metric-card olama-reg-metric-card--danger">
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title">مجموع الحركات المدينة</div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( $money( $family_balance_summary['total_debit'] ) ); ?></div>
                </div>
            </div>
            <div class="olama-reg-metric-card olama-reg-metric-card--success">
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title">مجموع الحركات الدائنة</div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( $money( $family_balance_summary['total_credit'] ) ); ?></div>
                </div>
            </div>
            <div class="olama-reg-metric-card olama-reg-metric-card--warning">
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title">الرصيد</div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( $money( $family_balance_summary['balance'] ) ); ?></div>
                </div>
            </div>
        </div>

        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-groups"></span>
                كشف أرصدة العائلات
            </h3>
            <div class="olama-reg-table-wrap">
                <table class="olama-reg-fin-table">
                    <thead>
                        <tr>
                            <th>رقم العائلة</th>
                            <th>اسم العائلة / ولي الأمر</th>
                            <th>مجموع الحركات المدينة</th>
                            <th>مجموع الحركات الدائنة</th>
                            <th>الرصيد</th>
                            <th>هاتف الأب</th>
                            <th>هاتف الأم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $family_balance_rows ) ) : ?>
                            <tr>
                                <td colspan="7" class="olama-reg-empty-state">
                                    لا توجد عائلات لديها عقود مطابقة للفلاتر المحددة.
                                </td>
                            </tr>
                        <?php else : foreach ( $family_balance_rows as $row ) : ?>
                            <tr>
                                <td><span class="olama-reg-uid-badge"><?php echo esc_html( $row->family_number ); ?></span></td>
                                <td><strong><?php echo esc_html( $row->family_name ); ?></strong></td>
                                <td style="color:#c62828;" dir="ltr"><strong><?php echo esc_html( $money( $row->total_debit ) ); ?></strong></td>
                                <td class="olama-reg-text--success" dir="ltr"><strong><?php echo esc_html( $money( $row->total_credit ) ); ?></strong></td>
                                <td dir="ltr"><strong><?php echo esc_html( $money( $row->balance ) ); ?></strong></td>
                                <td dir="ltr"><?php echo esc_html( $row->father_mobile ?: '—' ); ?></td>
                                <td dir="ltr"><?php echo esc_html( $row->mother_mobile ?: '—' ); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ( $active_tab === 'family_statement' ) : ?>
        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-filter"></span>
                <?php echo esc_html( olama_reg_statement_text( 'filter_title' ) ); ?>
            </h3>
            <form method="get" class="olama-reg-grid olama-reg-grid--compact">
                <input type="hidden" name="page" value="olama-registration-reports">
                <input type="hidden" name="report_tab" value="family_statement">
                <div class="olama-reg-field">
                    <label><?php echo esc_html( olama_reg_statement_text( 'entity_type' ) ); ?></label>
                    <select name="entity_type" id="olama-reg-statement-entity-type">
                        <option value="family" <?php selected( $statement_report['filters']['entity_type'], 'family' ); ?>><?php echo esc_html( olama_reg_statement_text( 'family' ) ); ?></option>
                        <option value="external" <?php selected( $statement_report['filters']['entity_type'], 'external' ); ?>><?php echo esc_html( olama_reg_statement_text( 'external' ) ); ?></option>
                    </select>
                </div>
                <div class="olama-reg-field" id="olama-reg-statement-family-field">
                    <label for="olama-reg-statement-family"><?php esc_html_e( 'رقم العائلة', 'olama-registration' ); ?></label>
                    <select
                        name="uid"
                        id="olama-reg-statement-family"
                        style="width:100%;"
                        data-placeholder="<?php esc_attr_e( 'ابحث برقم العائلة أو اسم ولي الأمر', 'olama-registration' ); ?>"
                    >
                        <?php if ( $statement_report['filters']['entity_type'] === 'family' && $statement_report['filters']['uid'] !== '' ) : ?>
                            <option value="<?php echo esc_attr( $statement_report['filters']['uid'] ); ?>" selected>
                                <?php
                                echo esc_html(
                                    $statement_report['filters']['uid']
                                    . ( ! empty( $statement_entity['name'] ) ? ' — ' . $statement_entity['name'] : '' )
                                );
                                ?>
                            </option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="olama-reg-field" id="olama-reg-statement-external-field" hidden>
                    <label for="olama-reg-statement-external"><?php echo esc_html( olama_reg_statement_text( 'uid_label' ) ); ?></label>
                    <input
                        type="text"
                        name="uid"
                        id="olama-reg-statement-external"
                        value="<?php echo $statement_report['filters']['entity_type'] === 'external' ? esc_attr( $statement_report['filters']['uid'] ) : ''; ?>"
                        placeholder="<?php echo esc_attr( olama_reg_statement_text( 'uid_placeholder' ) ); ?>"
                        disabled
                    >
                </div>
                <div class="olama-reg-field">
                    <label><?php echo esc_html( olama_reg_statement_text( 'year' ) ); ?></label>
                    <select name="year_id">
                        <option value="0"><?php echo esc_html( olama_reg_statement_text( 'all_years' ) ); ?></option>
                        <?php foreach ( $years as $y ) : ?>
                            <option value="<?php echo esc_attr( $y->id ); ?>" <?php selected( $year_id, $y->id ); ?>><?php echo esc_html( $y->year_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php
                $show_student_dropdown = false;
                $family_students = [];
                if ( $statement_report['filters']['entity_type'] === 'family' && ! empty( $statement_report['filters']['uid'] ) ) {
                    $family_students = Olama_Reg_Student::get_family_students(
                        (string) $statement_report['filters']['uid'],
                        $year_id
                    );
                    if ( ! empty( $family_students ) ) {
                        $show_student_dropdown = true;
                    }
                }
                if ( $show_student_dropdown ) :
                ?>
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'الطالب (اختياري)', 'olama-registration' ); ?></label>
                    <select name="student_uid">
                        <option value=""><?php esc_html_e( 'جميع الطلاب', 'olama-registration' ); ?></option>
                        <?php foreach ( $family_students as $fs ) : ?>
                            <option value="<?php echo esc_attr( $fs->student_uid ); ?>" <?php selected( $statement_report['filters']['student_uid'], $fs->student_uid ); ?>><?php echo esc_html( $fs->student_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="olama-reg-field">
                    <label><?php echo esc_html( olama_reg_statement_text( 'from_date' ) ); ?></label>
                    <input type="date" name="date_from" value="<?php echo esc_attr( $statement_report['filters']['date_from'] ); ?>">
                </div>
                <div class="olama-reg-field">
                    <label><?php echo esc_html( olama_reg_statement_text( 'to_date' ) ); ?></label>
                    <input type="date" name="date_to" value="<?php echo esc_attr( $statement_report['filters']['date_to'] ); ?>">
                </div>
                <div class="olama-reg-field" style="justify-content:flex-end;">
                    <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                        <span class="dashicons dashicons-search"></span>
                        <?php echo esc_html( olama_reg_statement_text( 'show' ) ); ?>
                    </button>
                </div>
                <?php if ( $statement_report['filters']['uid'] !== '' ) : ?>
                <div class="olama-reg-field" style="justify-content:flex-end;">
                    <a
                        class="olama-reg-btn olama-reg-btn--secondary"
                        target="_blank"
                        href="<?php echo $build_url( [
                            'page'        => 'olama-registration-reports',
                            'report_tab'  => 'family_statement',
                            'action'      => 'print_family_statement',
                            'entity_type' => $statement_report['filters']['entity_type'],
                            'uid'         => $statement_report['filters']['uid'],
                            'year_id'     => $statement_report['filters']['year_id'],
                            'student_uid' => $statement_report['filters']['student_uid'],
                            'date_from'   => $statement_report['filters']['date_from'],
                            'date_to'     => $statement_report['filters']['date_to'],
                        ] ); ?>"
                    >
                        <span class="dashicons dashicons-printer"></span>
                        <?php esc_html_e( 'طباعة كشف الحساب', 'olama-registration' ); ?>
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <div class="olama-reg-metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
            <div class="olama-reg-metric-card olama-reg-metric-card--primary"><div class="olama-reg-metric-content"><div class="olama-reg-metric-title"><?php echo esc_html( olama_reg_statement_text( 'opening' ) ); ?></div><div class="olama-reg-metric-value"><?php echo esc_html( $money( $statement_summary['opening_balance'] ?? 0 ) ); ?></div></div></div>
            <div class="olama-reg-metric-card olama-reg-metric-card--danger"><div class="olama-reg-metric-content"><div class="olama-reg-metric-title"><?php echo esc_html( olama_reg_statement_text( 'total_debit' ) ); ?></div><div class="olama-reg-metric-value"><?php echo esc_html( $money( $statement_summary['total_debit'] ?? 0 ) ); ?></div></div></div>
            <div class="olama-reg-metric-card olama-reg-metric-card--success"><div class="olama-reg-metric-content"><div class="olama-reg-metric-title"><?php echo esc_html( olama_reg_statement_text( 'total_credit' ) ); ?></div><div class="olama-reg-metric-value"><?php echo esc_html( $money( $statement_summary['total_credit'] ?? 0 ) ); ?></div></div></div>
            <div class="olama-reg-metric-card olama-reg-metric-card--warning"><div class="olama-reg-metric-content"><div class="olama-reg-metric-title"><?php echo esc_html( olama_reg_statement_text( 'closing' ) ); ?></div><div class="olama-reg-metric-value"><?php echo esc_html( $money( $statement_summary['closing_balance'] ?? 0 ) ); ?></div></div></div>
        </div>

        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-media-spreadsheet"></span>
                <?php echo esc_html( ! empty( $statement_entity['name'] ) ? $statement_entity['name'] . ' - ' . olama_reg_statement_text( 'tab' ) : olama_reg_statement_text( 'tab' ) ); ?>
            </h3>
            <div class="olama-reg-table-wrap">
                <table class="olama-reg-fin-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html( olama_reg_statement_text( 'date' ) ); ?></th>
                            <th><?php echo esc_html( olama_reg_statement_text( 'type' ) ); ?></th>
                            <th><?php echo esc_html( olama_reg_statement_text( 'reference' ) ); ?></th>
                            <th><?php echo esc_html( olama_reg_statement_text( 'details' ) ); ?></th>
                            <th><?php echo esc_html( olama_reg_statement_text( 'debit' ) ); ?></th>
                            <th><?php echo esc_html( olama_reg_statement_text( 'credit' ) ); ?></th>
                            <th><?php echo esc_html( olama_reg_statement_text( 'balance' ) ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $statement_rows ) ) : ?>
                            <tr><td colspan="7" class="olama-reg-empty-state"><?php echo esc_html( olama_reg_statement_text( 'empty' ) ); ?></td></tr>
                        <?php else : foreach ( $statement_rows as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row->movement_date ?: 'â€”' ); ?></td>
                                <td><?php echo esc_html( olama_reg_render_statement_type_label( $row->entry_type, (string) ( $row->entry_subtype ?? '' ) ) ); ?></td>
                                <td><strong><?php echo esc_html( $row->reference_no ?: 'â€”' ); ?></strong></td>
                                <td><?php echo esc_html( $row->details ?: 'â€”' ); ?></td>
                                <td style="color:#c62828;" dir="ltr"><?php echo $row->debit_amount > 0 ? esc_html( $money( $row->debit_amount ) ) : 'â€”'; ?></td>
                                <td class="olama-reg-text--success" dir="ltr"><?php echo $row->credit_amount > 0 ? esc_html( $money( $row->credit_amount ) ) : 'â€”'; ?></td>
                                <td dir="ltr"><strong><?php echo esc_html( $money( $row->running_balance ) ); ?></strong></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ( $active_tab === 'receipts' ) : ?>
        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-filter"></span>
                <?php esc_html_e( 'ظپظ„ط§طھط± طھظ‚ط±ظٹط± ط§ظ„ط¥ظٹطµط§ظ„ط§طھ', 'olama-registration' ); ?>
            </h3>
            <form method="get" class="olama-reg-grid olama-reg-grid--compact">
                <input type="hidden" name="page" value="olama-registration-reports">
                <input type="hidden" name="report_tab" value="receipts">
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ظ…ظ† طھط§ط±ظٹط®', 'olama-registration' ); ?></label>
                    <input type="date" name="date_from" value="<?php echo esc_attr( $receipt_report['filters']['date_from'] ); ?>">
                </div>
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ط¥ظ„ظ‰ طھط§ط±ظٹط®', 'olama-registration' ); ?></label>
                    <input type="date" name="date_to" value="<?php echo esc_attr( $receipt_report['filters']['date_to'] ); ?>">
                </div>
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ط·ط±ظٹظ‚ط© ط§ظ„ط¯ظپط¹', 'olama-registration' ); ?></label>
                    <select name="method">
                        <option value=""><?php esc_html_e( 'ط¬ظ…ظٹط¹ ط§ظ„ط·ط±ظ‚', 'olama-registration' ); ?></option>
                        <?php foreach ( $available_methods as $method_key => $method_label ) : ?>
                            <option value="<?php echo esc_attr( $method_key ); ?>" <?php selected( $receipt_report['filters']['method'], $method_key ); ?>><?php echo esc_html( $method_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ط§ظ„ط­ط§ظ„ط©', 'olama-registration' ); ?></label>
                    <select name="status">
                        <option value=""><?php esc_html_e( 'ط¬ظ…ظٹط¹ ط§ظ„ط­ط§ظ„ط§طھ', 'olama-registration' ); ?></option>
                        <?php foreach ( [ 'posted', 'pending_review', 'reversed', 'cancelled' ] as $status_key ) : ?>
                            <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $receipt_report['filters']['status'], $status_key ); ?>><?php echo esc_html( Olama_Reg_Billing_Payment::get_status_label( $status_key ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ط§ظ„ط­ط³ط§ط¨ ط§ظ„ظ…ط§ظ„ظٹ', 'olama-registration' ); ?></label>
                    <select name="account_id">
                        <option value="0"><?php esc_html_e( 'ط¬ظ…ظٹط¹ ط§ظ„ط­ط³ط§ط¨ط§طھ', 'olama-registration' ); ?></option>
                        <?php foreach ( $financial_accounts as $account ) : ?>
                            <option value="<?php echo esc_attr( $account->id ); ?>" <?php selected( $receipt_report['filters']['account_id'], $account->id ); ?>><?php echo esc_html( $account->account_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="olama-reg-field" style="justify-content:flex-end;">
                    <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                        <span class="dashicons dashicons-search"></span>
                        <?php esc_html_e( 'ط¹ط±ط¶ ط§ظ„طھظ‚ط±ظٹط±', 'olama-registration' ); ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-media-document"></span>
                <?php esc_html_e( 'طھظ‚ط±ظٹط± ط§ظ„ط¥ظٹطµط§ظ„ط§طھ', 'olama-registration' ); ?>
            </h3>
            <div class="olama-reg-table-wrap">
                <table class="olama-reg-fin-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'ط±ظ‚ظ… ط§ظ„ط¥ظٹطµط§ظ„', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„طھط§ط±ظٹط®', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ظپط§طھظˆط±ط©', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط·ط±ظٹظ‚ط© ط§ظ„ط¯ظپط¹', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ط­ط§ظ„ط©', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ط­ط³ط§ط¨', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط¬ظ„ط³ط© ط§ظ„طµظ†ط¯ظˆظ‚', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ظ…ط¨ظ„ط؛', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ظ…ط³طھط®ط¯ظ…', 'olama-registration' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $receipt_rows ) ) : ?>
                            <tr><td colspan="9" class="olama-reg-empty-state"><?php esc_html_e( 'ظ„ط§ طھظˆط¬ط¯ ط¥ظٹطµط§ظ„ط§طھ ظ…ط·ط§ط¨ظ‚ط©.', 'olama-registration' ); ?></td></tr>
                        <?php else : foreach ( $receipt_rows as $row ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $row->payment_no ?: '#' . (int) $row->id ); ?></strong></td>
                                <td><?php echo esc_html( $row->payment_date ); ?></td>
                                <td><?php echo esc_html( $row->invoice_number ?: 'â€”' ); ?></td>
                                <td><?php echo esc_html( $method_labels[ $row->method ] ?? $row->method ); ?></td>
                                <td><?php echo esc_html( Olama_Reg_Billing_Payment::get_status_label( $row->status ?: 'posted' ) ); ?></td>
                                <td><?php echo esc_html( $row->account_name ?: 'â€”' ); ?></td>
                                <td><?php echo esc_html( $row->session_no ?: 'â€”' ); ?></td>
                                <td class="olama-reg-text--success"><?php echo esc_html( $money( $row->amount ) ); ?></td>
                                <td><?php echo esc_html( $row->received_by_name ?: 'â€”' ); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ( $active_tab === 'allocations' ) : ?>
        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-filter"></span>
                <?php esc_html_e( 'ظپظ„ط§طھط± طھط®طµظٹطµط§طھ ط§ظ„ط¯ظپط¹ط§طھ', 'olama-registration' ); ?>
            </h3>
            <form method="get" class="olama-reg-grid olama-reg-grid--compact">
                <input type="hidden" name="page" value="olama-registration-reports">
                <input type="hidden" name="report_tab" value="allocations">
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ظ…ظ† طھط§ط±ظٹط®', 'olama-registration' ); ?></label>
                    <input type="date" name="date_from" value="<?php echo esc_attr( $allocation_report['filters']['date_from'] ); ?>">
                </div>
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ط¥ظ„ظ‰ طھط§ط±ظٹط®', 'olama-registration' ); ?></label>
                    <input type="date" name="date_to" value="<?php echo esc_attr( $allocation_report['filters']['date_to'] ); ?>">
                </div>
                <div class="olama-reg-field" style="justify-content:flex-end;">
                    <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                        <span class="dashicons dashicons-search"></span>
                        <?php esc_html_e( 'ط¹ط±ط¶ ط§ظ„طھظ‚ط±ظٹط±', 'olama-registration' ); ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-networking"></span>
                <?php esc_html_e( 'طھط®طµظٹطµط§طھ ط§ظ„ط¯ظپط¹ط§طھ ط¹ظ„ظ‰ ط§ظ„ط£ظ‚ط³ط§ط·', 'olama-registration' ); ?>
            </h3>
            <div class="olama-reg-table-wrap">
                <table class="olama-reg-fin-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'ط§ظ„ط¥ظٹطµط§ظ„', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ظپط§طھظˆط±ط©', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ظ‚ط³ط·', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'طھط§ط±ظٹط® ط§ظ„ط§ط³طھط­ظ‚ط§ظ‚', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط·ط±ظٹظ‚ط© ط§ظ„ط¯ظپط¹', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط­ط§ظ„ط© ط§ظ„ط¯ظپط¹ط©', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'طھط§ط±ظٹط® ط§ظ„طھط®طµظٹطµ', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ظ…ط¨ظ„ط؛', 'olama-registration' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $allocation_rows ) ) : ?>
                            <tr><td colspan="8" class="olama-reg-empty-state"><?php esc_html_e( 'ظ„ط§ طھظˆط¬ط¯ طھط®طµظٹطµط§طھ ظ…ط·ط§ط¨ظ‚ط©.', 'olama-registration' ); ?></td></tr>
                        <?php else : foreach ( $allocation_rows as $row ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $row->payment_no ?: '#' . (int) $row->payment_id ); ?></strong></td>
                                <td><?php echo esc_html( $row->invoice_number ?: 'â€”' ); ?></td>
                                <td><?php echo esc_html( $row->installment_no ?: 'â€”' ); ?></td>
                                <td><?php echo esc_html( $row->due_date ?: 'â€”' ); ?></td>
                                <td><?php echo esc_html( $method_labels[ $row->method ] ?? $row->method ); ?></td>
                                <td><?php echo esc_html( Olama_Reg_Billing_Payment::get_status_label( $row->payment_status ?: 'posted' ) ); ?></td>
                                <td><?php echo esc_html( $row->allocation_date ); ?></td>
                                <td class="olama-reg-text--success"><?php echo esc_html( $money( $row->amount_allocated ) ); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php elseif ( $active_tab === 'cheques' ) : ?>
        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-filter"></span>
                <?php esc_html_e( 'ظپظ„ط§طھط± ط§ظ„ط´ظٹظƒط§طھ', 'olama-registration' ); ?>
            </h3>
            <form method="get" class="olama-reg-grid olama-reg-grid--compact">
                <input type="hidden" name="page" value="olama-registration-reports">
                <input type="hidden" name="report_tab" value="cheques">
                <div class="olama-reg-field">
                    <label><?php esc_html_e( 'ط­ط§ظ„ط© ط§ظ„ط´ظٹظƒ', 'olama-registration' ); ?></label>
                    <select name="cheque_status">
                        <option value=""><?php esc_html_e( 'ط¬ظ…ظٹط¹ ط§ظ„ط­ط§ظ„ط§طھ', 'olama-registration' ); ?></option>
                        <?php foreach ( [ 'received' => __( 'ظ…ط³طھظ„ظ…', 'olama-registration' ), 'cleared' => __( 'ظ…ط­طµظ„', 'olama-registration' ), 'bounced' => __( 'ظ…ط±طھط¬ط¹', 'olama-registration' ), 'cancelled' => __( 'ظ…ظ„ط؛ظٹ', 'olama-registration' ) ] as $status_key => $status_label ) : ?>
                            <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $cheque_report['filters']['status'], $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="olama-reg-field" style="justify-content:flex-end;">
                    <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                        <span class="dashicons dashicons-search"></span>
                        <?php esc_html_e( 'ط¹ط±ط¶ ط§ظ„طھظ‚ط±ظٹط±', 'olama-registration' ); ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title">
                <span class="dashicons dashicons-tickets-alt"></span>
                <?php esc_html_e( 'طھظ‚ط±ظٹط± ط§ظ„ط´ظٹظƒط§طھ', 'olama-registration' ); ?>
            </h3>
            <div class="olama-reg-table-wrap">
                <table class="olama-reg-fin-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'ط±ظ‚ظ… ط§ظ„ط´ظٹظƒ', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ط¨ظ†ظƒ', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'طھط§ط±ظٹط® ط§ظ„ط§ط³طھط­ظ‚ط§ظ‚', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ط¥ظٹطµط§ظ„', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ظپط§طھظˆط±ط©', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ط­ط³ط§ط¨', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط­ط§ظ„ط© ط§ظ„ط´ظٹظƒ', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط­ط§ظ„ط© ط§ظ„ط¯ظپط¹ط©', 'olama-registration' ); ?></th>
                            <th><?php esc_html_e( 'ط§ظ„ظ…ط¨ظ„ط؛', 'olama-registration' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $cheque_rows ) ) : ?>
                            <tr><td colspan="9" class="olama-reg-empty-state"><?php esc_html_e( 'ظ„ط§ طھظˆط¬ط¯ ط´ظٹظƒط§طھ ظ…ط·ط§ط¨ظ‚ط©.', 'olama-registration' ); ?></td></tr>
                        <?php else : foreach ( $cheque_rows as $row ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $row->cheque_no ?: 'â€”' ); ?></strong></td>
                                <td><?php echo esc_html( $row->bank_name ?: 'â€”' ); ?></td>
                                <td><?php echo esc_html( $row->due_date ?: 'â€”' ); ?></td>
                                <td><?php echo esc_html( $row->payment_no ?: '#' . (int) $row->payment_id ); ?></td>
                                <td><?php echo esc_html( $row->invoice_number ?: 'â€”' ); ?></td>
                                <td><?php echo esc_html( $row->account_name ?: 'â€”' ); ?></td>
                                <td><?php echo esc_html( Olama_Reg_Status_Labels::label( $row->status, 'cheque' ) ); ?></td>
                                <td><?php echo esc_html( Olama_Reg_Billing_Payment::get_status_label( $row->payment_status ?: 'posted' ) ); ?></td>
                                <td class="olama-reg-text--success"><?php echo esc_html( $money( $row->amount ) ); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else : ?>
        <div class="olama-reg-filter-bar">
            <form method="get" class="olama-reg-filter-form">
                <input type="hidden" name="page" value="olama-registration-reports">
                <input type="hidden" name="report_tab" value="dashboard">
                <div class="olama-reg-filter-group">
                    <label><?php esc_html_e( 'ط§ظ„ط¹ط§ظ… ط§ظ„ط¯ط±ط§ط³ظٹ ط§ظ„ظ…ط³طھظ‡ط¯ظپ:', 'olama-registration' ); ?></label>
                    <select name="year_id" class="olama-reg-filter-input">
                        <option value="0"><?php esc_html_e( 'ط¬ظ…ظٹط¹ ط§ظ„ط£ط¹ظˆط§ظ… ط§ظ„ط¯ط±ط§ط³ظٹط©', 'olama-registration' ); ?></option>
                        <?php foreach ( $years as $y ) : ?>
                            <option value="<?php echo esc_attr( $y->id ); ?>" <?php selected( $year_id, $y->id ); ?>>
                                <?php echo esc_html( $y->year_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="olama-reg-filter-group">
                    <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                        <span class="dashicons dashicons-chart-bar"></span>
                        <?php esc_html_e( 'ط¹ط±ط¶ ط§ظ„طھظ‚ط±ظٹط±', 'olama-registration' ); ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="olama-reg-metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <div class="olama-reg-metric-card olama-reg-metric-card--primary">
                <div class="olama-reg-metric-icon"><span class="dashicons dashicons-media-text"></span></div>
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ط±ط³ظˆظ… ط§ظ„ظ…ظپظˆطھط±ط©', 'olama-registration' ); ?></div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( $money( $metrics['total_invoiced'] ) ); ?></div>
                </div>
            </div>
            <div class="olama-reg-metric-card olama-reg-metric-card--success">
                <div class="olama-reg-metric-icon"><span class="dashicons dashicons-money-alt"></span></div>
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ظ…ط¨ط§ظ„ط؛ ط§ظ„ظ…ط­طµظ„ط©', 'olama-registration' ); ?></div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( $money( $metrics['total_collected'] ) ); ?></div>
                </div>
            </div>
            <div class="olama-reg-metric-card olama-reg-metric-card--warning">
                <div class="olama-reg-metric-icon"><span class="dashicons dashicons-analytics"></span></div>
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'ط°ظ…ظ… ظ…ط¯ظٹظ†ط© ظ…ط³طھط­ظ‚ط©', 'olama-registration' ); ?></div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( $money( $metrics['total_receivables'] ) ); ?></div>
                </div>
            </div>
            <div class="olama-reg-metric-card olama-reg-metric-card--danger">
                <div class="olama-reg-metric-icon"><span class="dashicons dashicons-warning"></span></div>
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ط°ظ…ظ… ط§ظ„ظ…طھط£ط®ط±ط©', 'olama-registration' ); ?></div>
                    <div class="olama-reg-metric-value"><?php echo esc_html( $money( $metrics['total_overdue'] ) ); ?></div>
                </div>
            </div>
            <div class="olama-reg-metric-card" style="background:#f1f5f9; border-left:4px solid #94a3b8;">
                <div class="olama-reg-metric-icon" style="background:#e2e8f0; color:#64748b;"><span class="dashicons dashicons-tag"></span></div>
                <div class="olama-reg-metric-content">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„ط®طµظˆظ…ط§طھ ط§ظ„ظ…ظ…ظ†ظˆط­ط©', 'olama-registration' ); ?></div>
                    <div class="olama-reg-metric-value" style="color:#475569;"><?php echo esc_html( $money( $metrics['total_discount'] ) ); ?></div>
                </div>
            </div>
        </div>

        <div class="olama-reg-split-grid">
            <div class="olama-reg-section" style="margin:0;">
                <h3 class="olama-reg-section-title"><span class="dashicons dashicons-calendar-alt"></span><?php esc_html_e( 'ط­ط±ظƒط© ط§ظ„طھط­طµظٹظ„ ط§ظ„ط´ظ‡ط±ظٹ', 'olama-registration' ); ?></h3>
                <div class="olama-reg-table-wrap">
                    <table class="olama-reg-fin-table">
                        <thead><tr><th><?php esc_html_e( 'ط§ظ„ط´ظ‡ط±', 'olama-registration' ); ?></th><th><?php esc_html_e( 'ط§ظ„ظ…ط¨ظ„ط؛ ط§ظ„ظ…ط­طµظ„', 'olama-registration' ); ?></th></tr></thead>
                        <tbody>
                            <?php if ( empty( $monthly ) ) : ?>
                                <tr><td colspan="2" class="olama-reg-empty-state"><?php esc_html_e( 'ظ„ط§ طھظˆط¬ط¯ ط­ط±ظƒط§طھ طھط­طµظٹظ„ ظپظٹ ط§ظ„ظپطھط±ط© ط§ظ„ظ…ط­ط¯ط¯ط©.', 'olama-registration' ); ?></td></tr>
                            <?php else : foreach ( $monthly as $m ) : ?>
                                <tr><td><strong><?php echo esc_html( $m['month'] ); ?></strong></td><td class="olama-reg-text--success"><?php echo esc_html( $money( $m['amount'] ) ); ?></td></tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="olama-reg-section" style="margin:0;">
                <h3 class="olama-reg-section-title"><span class="dashicons dashicons-bank"></span><?php esc_html_e( 'طھظˆط²ظٹط¹ ط·ط±ظ‚ ط§ظ„ط¯ظپط¹ ظˆط§ظ„ط³ط¯ط§ط¯', 'olama-registration' ); ?></h3>
                <div class="olama-reg-table-wrap">
                    <table class="olama-reg-fin-table">
                        <thead><tr><th><?php esc_html_e( 'ط·ط±ظٹظ‚ط© ط§ظ„ط¯ظپط¹', 'olama-registration' ); ?></th><th><?php esc_html_e( 'ط¹ط¯ط¯ ط§ظ„ط­ط±ظƒط§طھ', 'olama-registration' ); ?></th><th><?php esc_html_e( 'ط¥ط¬ظ…ط§ظ„ظٹ ط§ظ„طھط­طµظٹظ„', 'olama-registration' ); ?></th></tr></thead>
                        <tbody>
                            <?php if ( empty( $methods ) ) : ?>
                                <tr><td colspan="3" class="olama-reg-empty-state"><?php esc_html_e( 'ظ„ط§ طھظˆط¬ط¯ ط­ط±ظƒط§طھ ط³ط¯ط§ط¯ ظ…ط³ط¬ظ„ط©.', 'olama-registration' ); ?></td></tr>
                            <?php else : foreach ( $methods as $met ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $method_labels[ $met['method'] ] ?? $met['method'] ); ?></strong></td>
                                    <td><?php echo esc_html( $met['count'] ); ?></td>
                                    <td class="olama-reg-text--success"><?php echo esc_html( $money( $met['amount'] ) ); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="olama-reg-section">
            <h3 class="olama-reg-section-title"><span class="dashicons dashicons-clock"></span><?php esc_html_e( 'ط£ط¹ظ…ط§ط± ط§ظ„ط¯ظٹظˆظ† ظˆط§ظ„ط°ظ…ظ… ط§ظ„ظ…طھط£ط®ط±ط© ط§ظ„ط³ط¯ط§ط¯', 'olama-registration' ); ?></h3>
            <div class="olama-reg-aging-grid">
                <div class="olama-reg-aging-card olama-reg-aging-card--30"><div class="olama-reg-aging-title"><?php esc_html_e( 'ظ…طھط£ط®ط± (1 - 30 ظٹظˆظ…)', 'olama-registration' ); ?></div><div class="olama-reg-aging-val"><?php echo esc_html( $money( $aging['band_30'] ) ); ?></div></div>
                <div class="olama-reg-aging-card olama-reg-aging-card--60"><div class="olama-reg-aging-title"><?php esc_html_e( 'ظ…طھط£ط®ط± (31 - 60 ظٹظˆظ…)', 'olama-registration' ); ?></div><div class="olama-reg-aging-val"><?php echo esc_html( $money( $aging['band_60'] ) ); ?></div></div>
                <div class="olama-reg-aging-card olama-reg-aging-card--90"><div class="olama-reg-aging-title"><?php esc_html_e( 'ظ…طھط£ط®ط± (61 - 90 ظٹظˆظ…)', 'olama-registration' ); ?></div><div class="olama-reg-aging-val"><?php echo esc_html( $money( $aging['band_90'] ) ); ?></div></div>
                <div class="olama-reg-aging-card olama-reg-aging-card--90-plus"><div class="olama-reg-aging-title"><?php esc_html_e( 'ظ…طھط£ط®ط± ط¬ط¯ط§ظ‹ (ط£ظƒط«ط± ظ…ظ† 90 ظٹظˆظ…)', 'olama-registration' ); ?></div><div class="olama-reg-aging-val"><?php echo esc_html( $money( $aging['band_90_plus'] ) ); ?></div></div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    .olama-reg-wrap .olama-reg-report-tabs {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin: 0 0 18px;
    }
    .olama-reg-wrap .olama-reg-report-tabs a {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 10px 16px;
        border: 1px solid var(--reg-border);
        border-radius: var(--reg-radius-sm);
        background: #fff;
        color: var(--reg-text);
        text-decoration: none;
        font-weight: 800;
        box-shadow: var(--reg-shadow-sm);
    }
    .olama-reg-wrap .olama-reg-report-tabs a.active,
    .olama-reg-wrap .olama-reg-report-tabs a:hover {
        border-color: var(--reg-primary);
        background: var(--reg-primary-light);
        color: var(--reg-primary-dark);
    }
</style>
