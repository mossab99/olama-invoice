<?php
/**
 * Payments Ledger and Receipt View
 */
if (!defined('ABSPATH'))
    exit;

global $wpdb;

$action = sanitize_text_field($_GET['action'] ?? '');
$payment_id = absint($_GET['id'] ?? 0);

// ── RECEIPT PRINT VIEW ────────────────────────────────────────────────────────
if ($action === 'print_receipt' && $payment_id) {
    $receipt = Olama_Reg_Billing_Payment::generate_receipt_data($payment_id);
    if (empty($receipt)) {
        wp_die(esc_html__('Receipt data not found.', 'olama-registration'));
    }

    $payment = $receipt['payment'];
    $invoice = $receipt['invoice'];
    $family = $receipt['family'];
    $account = $receipt['account'] ?? null;
    $cash_session = $receipt['cash_session'] ?? null;
    $method_details = $receipt['method_details'] ?? null;
    $ext_customer_name = $receipt['ext_customer_name'] ?? null;
    $received_by_name = $receipt['received_by_name'];
    $receipt_no = Olama_Reg_Billing_Payment::get_receipt_number($payment);
    $status_label = Olama_Reg_Billing_Payment::get_status_label($payment);
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">

    <head>
        <meta charset="UTF-8">
        <title>سند قبض - <?php echo esc_html($receipt_no); ?></title>
        <style>
            body {
                font-family: 'Tajawal', sans-serif;
                margin: 50px;
                color: #1a1a2e;
                background: #fcfcfc;
            }

            .receipt-wrap {
                max-width: 600px;
                margin: 0 auto;
                border: 2px solid #E8920A;
                padding: 40px;
                border-radius: 12px;
                background: #fff;
                position: relative;
                box-shadow: 0 4px 15px rgba(232, 146, 10, 0.15);
            }

            .receipt-wrap::before {
                content: '';
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: 8px;
                background: linear-gradient(90deg, #E8920A, #FFA726);
            }

            .header-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 30px;
                border-bottom: 2px solid #f0f0f0;
                padding-bottom: 15px;
            }

            .logo-title {
                font-size: 22px;
                font-weight: 800;
                color: #E8920A;
            }

            .receipt-title {
                font-size: 26px;
                font-weight: 800;
                text-align: left;
                color: #1a1a2e;
            }

            .receipt-no {
                font-size: 14px;
                color: #6B7280;
                font-weight: 700;
                text-align: left;
                margin-top: 4px;
            }

            .content-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 30px;
            }

            .content-table td {
                padding: 10px 6px;
                border-bottom: 1px dashed #f0f0f0;
                font-size: 14px;
            }

            .label {
                font-weight: 700;
                color: #6B7280;
                width: 140px;
            }

            .amount-box {
                background: #FFF3E0;
                border: 1.5px solid #E0C090;
                border-radius: 8px;
                padding: 15px;
                text-align: center;
                margin-bottom: 30px;
            }

            .amount-val {
                font-size: 28px;
                font-weight: 800;
                color: #C4780A;
            }

            .sign-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 40px;
            }

            .sign-table td {
                text-align: center;
                font-size: 14px;
                width: 50%;
            }

            .footer {
                margin-top: 40px;
                text-align: center;
                font-size: 11px;
                color: #999;
                border-top: 1px solid #eee;
                padding-top: 12px;
            }

            @media print {
                body {
                    background: none;
                    margin: 0;
                }

                .receipt-wrap {
                    border: none;
                    box-shadow: none;
                    padding: 20px;
                }

                .no-print {
                    display: none;
                }
            }
        </style>
        <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800&display=swap" rel="stylesheet">
    </head>

    <body>
        <div class="no-print" style="max-width: 600px; margin: 0 auto 20px; text-align: left;">
            <button onclick="window.print();"
                style="padding: 10px 20px; font-weight: 700; background: #E8920A; color:#fff; border:none; border-radius:4px; cursor:pointer;">طباعة
                السند</button>
        </div>
        <div class="receipt-wrap">
            <table class="header-table">
                <tr>
                    <td style="padding-bottom:15px;">
                        <?php
                        $school_settings = get_option('olama_school_settings', []);
                        $school_name = $school_settings['school_name_ar'] ?? 'مدارس أوتاد الإبداع';
                        ?>
                        <div class="logo-title"><?php echo esc_html($school_name); ?></div>
                        <div style="font-size: 11px; color:#6B7280; margin-top:4px;">إيصال سداد رسوم دراسية</div>
                    </td>
                    <td style="text-align: left; padding-bottom:15px;">
                        <div class="receipt-title">سند قبض مالي</div>
                        <div class="receipt-no">رقم الإيصال: <?php echo esc_html($receipt_no); ?></div>
                        <div class="receipt-no">حالة السند: <?php echo esc_html($status_label); ?></div>
                    </td>
                </tr>
            </table>

            <?php if ($invoice): ?>
                <div style="display:flex; gap:15px; margin-bottom: 30px;">
                    <div class="amount-box" style="flex:1; margin-bottom:0; padding:10px;">
                        <div style="font-size:12px; font-weight:700; color:#C4780A; margin-bottom:4px;">إجمالي الفاتورة</div>
                        <div class="amount-val" style="font-size:20px;"><?php echo esc_html(number_format((float) $invoice->total, 2)); ?> د.أ</div>
                    </div>
                    <div class="amount-box" style="flex:1; margin-bottom:0; padding:10px; background:#E8F5E9; border-color:#81C784;">
                        <div style="font-size:12px; font-weight:700; color:#2E7D32; margin-bottom:4px;">المبلغ المستلم</div>
                        <div class="amount-val" style="font-size:24px; color:#1B5E20;"><?php echo esc_html(number_format((float) $payment->amount, 2)); ?> د.أ</div>
                    </div>
                    <div class="amount-box" style="flex:1; margin-bottom:0; padding:10px; background:#FFEBEE; border-color:#E57373;">
                        <div style="font-size:12px; font-weight:700; color:#C62828; margin-bottom:4px;">الرصيد المتبقي</div>
                        <div class="amount-val" style="font-size:20px; color:#B71C1C;"><?php echo esc_html(number_format((float) $invoice->balance, 2)); ?> د.أ</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="amount-box">
                    <div style="font-size:12px; font-weight:700; color:#C4780A; margin-bottom:4px;">
                        <?php esc_html_e('المبلغ المستلم', 'olama-registration'); ?></div>
                    <div class="amount-val"><?php echo esc_html(number_format((float) $payment->amount, 2)); ?> د.أ</div>
                </div>
            <?php endif; ?>

            <table class="content-table">
                <tr>
                    <td class="label"><?php echo (strpos($payment->family_uid, 'CUST-') === 0) ? '' : 'وصلنا من السيد/ة:'; ?></td>
                    <td><strong><?php
                    if (strpos($payment->family_uid, 'CUST-') === 0) {
                        echo '';
                    } elseif ($family) {
                        echo esc_html(trim($family->father_first_name . ' ' . $family->father_family_name));
                    } elseif ($ext_customer_name) {
                        echo esc_html($ext_customer_name);
                    } else {
                        echo esc_html($payment->family_uid);
                    }
                    ?></strong></td>
                </tr>
                <tr>
                    <td class="label">رقم الملف / العميل:</td>
                    <td><strong style="color:#E8920A;"><?php echo esc_html($payment->family_uid); ?></strong></td>
                </tr>
                <tr>
                    <td class="label">وذلك دفعة عن فاتورة:</td>
                    <td><?php echo esc_html($invoice ? $invoice->invoice_number : '—'); ?></td>
                </tr>
                <?php if ($invoice && !empty($invoice->agreement_id)): 
                    $linked_agr = Olama_Reg_Agreement::get($invoice->agreement_id);
                    if ($linked_agr):
                ?>
                <tr>
                    <td class="label">رقم العقد:</td>
                    <td><strong style="color:#E8920A;"><?php echo esc_html($linked_agr->agreement_number); ?></strong></td>
                </tr>
                <?php endif; endif; ?>
                <tr>
                    <td class="label">طريقة الدفع:</td>
                    <td>
                        <?php
                        $methods = [
                            'cash' => 'نقدي (كاش)',
                            'bank_transfer' => 'تحويل بنكي',
                            'cheque' => 'شيك بنكي',
                            'online' => 'دفع إلكتروني',
                        ];
                        echo esc_html($methods[$payment->method] ?? $payment->method);
                        ?>
                    </td>
                </tr>
                <?php if ($payment->reference): ?>
                    <tr>
                        <td class="label">رقم المرجع / الشيك:</td>
                        <td><strong><?php echo esc_html($payment->reference); ?></strong></td>
                    </tr>
                <?php endif; ?>
                <?php if ($method_details && $payment->method === 'cheque'): ?>
                    <tr>
                        <td class="label"><?php esc_html_e('حالة الشيك:', 'olama-registration'); ?></td>
                        <td><?php echo esc_html( Olama_Reg_Status_Labels::label( $method_details->status, 'cheque' ) ); ?></td>
                    </tr>
                    <?php if (!empty($method_details->due_date)): ?>
                    <tr>
                        <td class="label"><?php esc_html_e('تاريخ الاستحقاق:', 'olama-registration'); ?></td>
                        <td><?php echo esc_html($method_details->due_date); ?></td>
                    </tr>
                    <?php endif; ?>
                <?php elseif ($method_details && $payment->method === 'bank_transfer'): ?>
                    <tr>
                        <td class="label"><?php esc_html_e('حالة التحويل:', 'olama-registration'); ?></td>
                        <td><?php echo esc_html( Olama_Reg_Status_Labels::label( $method_details->status, 'bank_transfer' ) ); ?></td>
                    </tr>
                <?php elseif ($method_details && $payment->method === 'online'): ?>
                    <tr>
                        <td class="label"><?php esc_html_e('مزود الدفع:', 'olama-registration'); ?></td>
                        <td><?php echo esc_html($method_details->provider ?: '—'); ?></td>
                    </tr>
                    <tr>
                        <td class="label"><?php esc_html_e('رقم العملية:', 'olama-registration'); ?></td>
                        <td><?php echo esc_html($method_details->transaction_id ?: '—'); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($account): ?>
                    <tr>
                        <td class="label"><?php esc_html_e('الحساب المالي:', 'olama-registration'); ?></td>
                        <td><strong><?php echo esc_html($account->account_name); ?></strong></td>
                    </tr>
                <?php endif; ?>
                <?php if ($cash_session): ?>
                    <tr>
                        <td class="label"><?php esc_html_e('جلسة الصندوق:', 'olama-registration'); ?></td>
                        <td><strong><?php echo esc_html($cash_session->session_no); ?></strong></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td class="label">تاريخ القبض:</td>
                    <td><?php echo esc_html($payment->payment_date); ?></td>
                </tr>
                <?php if (!empty($payment->reversed_payment_id)): ?>
                    <tr>
                        <td class="label"><?php esc_html_e('Reversal of:', 'olama-registration'); ?></td>
                        <td>#<?php echo esc_html((int) $payment->reversed_payment_id); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($payment->notes): ?>
                    <tr>
                        <td class="label">ملاحظات:</td>
                        <td><?php echo esc_html($payment->notes); ?></td>
                    </tr>
                <?php endif; ?>
            </table>

            <?php if ($invoice && !empty($invoice->items)): ?>
                <div style="margin-bottom: 30px;">
                    <div style="font-size:14px; font-weight:700; color:#E8920A; margin-bottom:10px;">تفاصيل الفاتورة والخدمات:
                    </div>
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background:#FFF3E0;">
                                <th
                                    style="padding:8px; border:1px solid #E0C090; text-align:right; font-size:13px; color:#C4780A;">
                                    التفاصيل / الخدمة</th>
                                <th
                                    style="padding:8px; border:1px solid #E0C090; text-align:center; font-size:13px; color:#C4780A; width:80px;">
                                    العدد</th>
                                <th
                                    style="padding:8px; border:1px solid #E0C090; text-align:center; font-size:13px; color:#C4780A; width:100px;">
                                    القيمة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoice->items as $item): ?>
                                <tr>
                                    <td style="padding:8px; border:1px solid #E0C090; font-size:13px; color:#1a1a2e;">
                                        <?php echo esc_html($item->description); ?></td>
                                    <td
                                        style="padding:8px; border:1px solid #E0C090; text-align:center; font-size:13px; color:#1a1a2e;">
                                        <?php echo esc_html((int) $item->quantity); ?></td>
                                    <td
                                        style="padding:8px; border:1px solid #E0C090; text-align:center; font-size:13px; color:#1a1a2e;">
                                        <?php echo esc_html(number_format($item->line_total, 2)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (isset($invoice->discount) && $invoice->discount > 0): ?>
                                <tr>
                                    <td colspan="2"
                                        style="padding:8px; border:1px solid #E0C090; text-align:left; font-size:13px; color:#c62828; font-weight:700;">
                                        الخصم الممنوح:</td>
                                    <td
                                        style="padding:8px; border:1px solid #E0C090; text-align:center; font-size:13px; color:#c62828; font-weight:700;">
                                        - <?php echo esc_html(number_format($invoice->discount, 2)); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($invoice): 
                $all_payments = Olama_Reg_Billing_Payment::get_invoice_payments((int)$invoice->id);
                if (!empty($all_payments)):
            ?>
                <div style="margin-bottom: 30px;">
                    <div style="font-size:14px; font-weight:700; color:#E8920A; margin-bottom:10px;">سجل الدفعات لهذه الفاتورة:</div>
                    <table style="width:100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background:#FFF3E0;">
                                <th style="padding:8px; border:1px solid #E0C090; text-align:right; font-size:13px; color:#C4780A;">رقم الإيصال</th>
                                <th style="padding:8px; border:1px solid #E0C090; text-align:center; font-size:13px; color:#C4780A;">التاريخ</th>
                                <th style="padding:8px; border:1px solid #E0C090; text-align:center; font-size:13px; color:#C4780A;">طريقة الدفع</th>
                                <th style="padding:8px; border:1px solid #E0C090; text-align:center; font-size:13px; color:#C4780A;">المبلغ المستلم</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_payments as $p): 
                                $methods = [
                                    'cash' => 'نقدي (كاش)',
                                    'bank_transfer' => 'تحويل بنكي',
                                    'cheque' => 'شيك بنكي',
                                    'online' => 'دفع إلكتروني',
                                    'reversal' => 'عكس قيد (سالب)',
                                ];
                                $method_label = $methods[$p->method] ?? $p->method;
                                $is_current = ($p->id == $payment->id);
                                $row_bg = $is_current ? '#E8F5E9' : '#FFFFFF';
                            ?>
                                <tr style="background:<?php echo $row_bg; ?>;">
                                    <td style="padding:8px; border:1px solid #E0C090; font-size:13px; color:#1a1a2e;">
                                        <?php echo esc_html(Olama_Reg_Billing_Payment::get_receipt_number($p)); ?> <?php if($is_current) echo '<span style="color:#2E7D32; font-size:11px; font-weight:bold;">(هذا السند)</span>'; ?>
                                    </td>
                                    <td style="padding:8px; border:1px solid #E0C090; text-align:center; font-size:13px; color:#1a1a2e;"><?php echo esc_html($p->payment_date); ?></td>
                                    <td style="padding:8px; border:1px solid #E0C090; text-align:center; font-size:13px; color:#1a1a2e;"><?php echo esc_html($method_label); ?></td>
                                    <td style="padding:8px; border:1px solid #E0C090; text-align:center; font-size:13px; font-weight:bold; color:<?php echo $p->amount < 0 ? '#C62828' : '#1B5E20'; ?>;">
                                        <?php echo esc_html(number_format((float)$p->amount, 2)); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; endif; ?>

            <table class="sign-table">
                <tr>
                    <td>
                        <div style="font-weight:700; color:#6B7280;">
                            <?php esc_html_e('المستلم (أمين الصندوق)', 'olama-registration'); ?></div>
                        <div style="margin-top:40px; font-weight:700;">
                            <?php echo esc_html($received_by_name ?: 'المحاسب المسؤول'); ?></div>
                    </td>
                    <td>
                        <div style="font-weight:700; color:#6B7280;">
                            <?php esc_html_e('توقيع ولي الأمر', 'olama-registration'); ?></div>
                        <div
                            style="margin-top:40px; border-bottom:1px dashed #ccc; width:150px; margin-right:auto; margin-left:auto;">
                        </div>
                    </td>
                </tr>
            </table>

            <div class="footer">
                <?php echo esc_html($school_name); ?> - هاتف: 060000000 | البريد الإلكتروني: info@awtad.edu
            </div>
        </div>
    </body>

    </html>
    <?php
    exit;
}

// ── REGULAR LIST VIEW ─────────────────────────────────────────────────────────
$filter_method = sanitize_text_field($_GET['method'] ?? '');
$search_q = sanitize_text_field($_GET['s'] ?? '');

$where = "1=1";
$params = [];

if ($filter_method) {
    $where .= " AND p.method = %s";
    $params[] = $filter_method;
}
if ($search_q) {
    $like = '%' . $wpdb->esc_like($search_q) . '%';
    $where .= " AND (p.payment_no LIKE %s OR p.family_uid LIKE %s OR i.invoice_number LIKE %s OR f.sponsor_full_name LIKE %s OR f.father_name LIKE %s OR c.customer_name LIKE %s OR c.customer_uid LIKE %s)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$query = "SELECT p.*, i.invoice_number, COALESCE(f.sponsor_full_name, f.father_name, f.mother_name, c.customer_name, '') AS father_first_name, '' AS father_family_name, u.display_name AS received_by_name, a.account_name, cs.session_no
          FROM " . $wpdb->prefix . "olama_payments p
          LEFT JOIN " . $wpdb->prefix . "olama_invoices i ON i.id = p.invoice_id
          LEFT JOIN " . olama_core()->read_models()->table( 'families' ) . " f
            ON f.family_uid = p.family_uid
            OR (f.oracle_family_id = COALESCE(NULLIF(p.oracle_family_id, ''), p.family_uid))
          LEFT JOIN " . $wpdb->prefix . "olama_customers c ON c.customer_uid = p.family_uid OR c.id = i.ext_customer_id
          LEFT JOIN {$wpdb->users} u ON u.ID = p.received_by
          LEFT JOIN " . $wpdb->prefix . "olama_financial_accounts a ON a.id = p.account_id
          LEFT JOIN " . $wpdb->prefix . "olama_cash_sessions cs ON cs.id = p.cash_session_id
          WHERE {$where}
          ORDER BY p.payment_date DESC, p.id DESC";

if (!empty($params)) {
    $payments = $wpdb->get_results($wpdb->prepare($query, ...$params)) ?: [];
} else {
    $payments = $wpdb->get_results($query) ?: [];
}
?>
<?php
// Aggregate totals for the current filter
$_pay_total_query = "SELECT COALESCE(SUM(p.amount),0)
          FROM " . $wpdb->prefix . "olama_payments p
          LEFT JOIN " . $wpdb->prefix . "olama_invoices i ON i.id = p.invoice_id
          LEFT JOIN " . olama_core()->read_models()->table( 'families' ) . " f
            ON f.family_uid = p.family_uid
            OR (f.oracle_family_id = COALESCE(NULLIF(p.oracle_family_id, ''), p.family_uid))
          LEFT JOIN " . $wpdb->prefix . "olama_customers c ON c.customer_uid = p.family_uid OR c.id = i.ext_customer_id
          WHERE {$where}
            AND (p.status IS NULL OR p.status = '' OR p.status IN ('posted','reversed'))";
$_pay_total = ! empty( $params )
    ? $wpdb->get_var( $wpdb->prepare( $_pay_total_query, ...$params ) )
    : $wpdb->get_var( $_pay_total_query );
?>
<div class="wrap olama-reg-wrap" dir="rtl">

    <div class="olama-reg-page-header">
        <h1>
            <span class="dashicons dashicons-money-alt"></span>
            <?php esc_html_e('سجل السندات والمدفوعات المستلمة', 'olama-registration'); ?>
        </h1>
        <div style="display:flex; gap:12px; align-items:center;">
            <span class="olama-reg-uid-badge olama-reg-uid-badge--lg"
                style="background:linear-gradient(135deg,#2E7D32,#1B5E20);">
                <?php echo esc_html(number_format((float) $_pay_total, 2)); ?>
                <?php esc_html_e('إجمالي المحصل', 'olama-registration'); ?>
            </span>
            <button class="olama-reg-btn olama-reg-btn--primary" id="olama-reg-open-general-payment-btn">
                <span class="dashicons dashicons-plus"></span>
                <?php esc_html_e('تسجيل دفعة', 'olama-registration'); ?>
            </button>
        </div>
    </div>

    <!-- Notice area -->
    <div id="olama-reg-notice" class="olama-reg-notice" style="display:none;"></div>

    <!-- ── FILTERS BAR ─────────────────────────────────────────────── -->
    <div class="olama-reg-filter-bar">
        <form method="get" class="olama-reg-filter-form">
            <input type="hidden" name="page" value="olama-registration-payments">

            <div class="olama-reg-filter-group">
                <label><?php esc_html_e('بحث في المدفوعات', 'olama-registration'); ?></label>
                <input type="text" name="s" value="<?php echo esc_attr($search_q); ?>"
                    placeholder="<?php esc_attr_e('اسم ولي الأمر، رقم الملف...', 'olama-registration'); ?>"
                    class="olama-reg-filter-input">
            </div>

            <div class="olama-reg-filter-group">
                <label><?php esc_html_e('طريقة السداد', 'olama-registration'); ?></label>
                <select name="method" class="olama-reg-filter-input">
                    <option value=""><?php esc_html_e('جميع الطرق', 'olama-registration'); ?></option>
                    <option value="cash" <?php selected($filter_method, 'cash'); ?>>
                        <?php esc_html_e('نقدي (كاش)', 'olama-registration'); ?></option>
                    <option value="bank_transfer" <?php selected($filter_method, 'bank_transfer'); ?>>
                        <?php esc_html_e('تحويل بنكي', 'olama-registration'); ?></option>
                    <option value="cheque" <?php selected($filter_method, 'cheque'); ?>>
                        <?php esc_html_e('شيك', 'olama-registration'); ?></option>
                    <option value="online" <?php selected($filter_method, 'online'); ?>>
                        <?php esc_html_e('دفع إلكتروني', 'olama-registration'); ?></option>
                </select>
            </div>

            <div class="olama-reg-filter-group">
                <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e('تصفية', 'olama-registration'); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- ── PAYMENTS GRID TABLE ────────────────────────────────────── -->
    <div class="olama-reg-section">
        <h3 class="olama-reg-section-title">
            <span class="dashicons dashicons-list-view"></span>
            <?php esc_html_e('عمليات القبض المسجلة', 'olama-registration'); ?>
        </h3>
        <div class="olama-reg-table-wrap">
            <table class="olama-reg-fin-table">
                <thead>
                    <tr>
                        <th style="width:70px;"><?php esc_html_e('رقم السند', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('تاريخ القبض', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('رقم الفاتورة', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('رقم الملف', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('ولي الأمر', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('القيمة المقبوضة', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('طريقة الدفع', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('الحالة', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('الحساب المالي', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('جلسة الصندوق', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('رقم المرجع / الشيك', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('المستلم', 'olama-registration'); ?></th>
                        <th style="width:60px;"><?php esc_html_e('إيصال', 'olama-registration'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="13" class="olama-reg-empty-state">
                                <span class="dashicons dashicons-info"></span><br>
                                <?php esc_html_e('لا يوجد أي عمليات دفع مسجلة مطابقة.', 'olama-registration'); ?>
                            </td>
                        </tr>
                    <?php else: 
                        $reversed_ids = [];
                        foreach ($payments as $p) {
                            if (!empty($p->reversed_payment_id)) {
                                $reversed_ids[] = (int) $p->reversed_payment_id;
                            } elseif (strpos($p->reference ?? '', 'REVERSAL-') === 0) {
                                $reversed_ids[] = (int) str_replace('REVERSAL-', '', $p->reference);
                            }
                        }
                    ?>
                        <?php foreach ($payments as $pay):
                            $method_cfg = [
                                'cash' => ['label' => 'نقدي', 'class' => 'reg-method-pill--cash'],
                                'bank_transfer' => ['label' => 'تحويل بنكي', 'class' => 'reg-method-pill--transfer'],
                                'cheque' => ['label' => 'شيك بنكي', 'class' => 'reg-method-pill--cheque'],
                                'online' => ['label' => 'دفع إلكتروني', 'class' => 'reg-method-pill--online'],
                            ];
                            $m = $method_cfg[$pay->method] ?? ['label' => $pay->method, 'class' => 'reg-method-pill--cash'];
                            $is_reversal = (float) $pay->amount < 0;
                            ?>
                            <tr<?php echo $is_reversal ? ' class="os-hub-row--reversal"' : ''; ?>>
                                <td><span
                                        style="font-weight:700; color:var(--reg-text-muted);"><?php echo esc_html(Olama_Reg_Billing_Payment::get_receipt_number($pay)); ?></span>
                                </td>
                                <td style="color:var(--reg-text-muted);"><?php echo esc_html($pay->payment_date); ?></td>
                                <td><strong><?php echo esc_html($pay->invoice_number ?: '—'); ?></strong></td>
                                <td><span class="olama-reg-uid-badge"><?php echo esc_html($pay->family_uid); ?></span></td>
                                <td><?php echo esc_html($pay->father_first_name . ' ' . $pay->father_family_name); ?></td>
                                <td style="color:var(--reg-success); font-weight:800; font-size:15px;">
                                    <?php echo esc_html(number_format($pay->amount, 2)); ?>
                                </td>
                                <td>
                                    <span class="reg-method-pill <?php echo esc_attr($m['class']); ?>">
                                        <?php echo esc_html($m['label']); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(Olama_Reg_Billing_Payment::get_status_label($pay)); ?></td>
                                <td><?php echo esc_html($pay->account_name ?: '—'); ?></td>
                                <td><?php echo esc_html($pay->session_no ?: '—'); ?></td>
                                <td><?php echo esc_html($pay->reference ?: '—'); ?></td>
                                <td><?php echo esc_html($pay->received_by_name); ?></td>
                                <td style="text-align:center;">
                                    <a href="<?php echo esc_url(add_query_arg(['action' => 'print_receipt', 'id' => $pay->id], admin_url('admin.php?page=olama-registration-payments'))); ?>"
                                        target="_blank" class="button button-small"
                                        title="<?php esc_attr_e('طباعة سند القبض', 'olama-registration'); ?>">
                                        <span class="dashicons dashicons-printer"></span>
                                    </a>
                                    <?php if ((float) $pay->amount > 0 && $pay->method !== 'reversal'): 
                                        $is_already_reversed = in_array((int) $pay->id, $reversed_ids, true) || (string)($pay->status ?? '') === 'reversed';
                                        if ($is_already_reversed):
                                    ?>
                                        <button class="button button-small" disabled
                                            style="opacity:0.5; cursor:not-allowed;"
                                            title="<?php esc_attr_e('هذا السند معكوس مسبقاً', 'olama-registration'); ?>">
                                            <span class="dashicons dashicons-undo"></span>
                                        </button>
                                    <?php else: ?>
                                        <button class="button button-small olama-reg-reverse-payment-btn"
                                            data-id="<?php echo esc_attr($pay->id); ?>"
                                            title="<?php esc_attr_e('عكس السند', 'olama-registration'); ?>"
                                            style="color:#c62828;">
                                            <span class="dashicons dashicons-undo"></span>
                                        </button>
                                    <?php endif; endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
