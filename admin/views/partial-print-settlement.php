<?php
/**
 * Print View for Settlement Receipt (A4 / Thermal Optimized Premium Design)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$id = (int) ( $_GET['id'] ?? 0 );
$receipt = Olama_Reg_Settlement::get_receipt( $id );

if ( ! $receipt ) {
    wp_die( __( 'Receipt not found.', 'olama-registration' ) );
}

// Enrolled family identity is owned by Olama Core.
$family_name = '';
if ( ! empty( $receipt->family_id ) ) {
    $family = Olama_Reg_Family::get_family( (string) $receipt->family_id );
    $family_name = $family ? (string) $family->family_name : '';
}

$school_settings = get_option('olama_school_settings', []);
$school_name = $school_settings['school_name_ar'] ?? 'مدارس أوتاد الإبداع';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إيصال تسوية - <?php echo esc_html( $receipt->receipt_number ); ?></title>
    <!-- Premium Arabic Font Loading -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #E8920A;
            --primary-light: #FFF8E5;
            --text-dark: #1F2937;
            --text-muted: #6B7280;
            --border-color: #E5E7EB;
            --emerald-green: #059669;
            --emerald-light: #ECFDF5;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #F9FAFB;
            color: var(--text-dark);
            margin: 0;
            padding: 40px 20px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Print Button Layout */
        .action-bar {
            max-width: 680px;
            margin: 0 auto 24px auto;
            display: flex;
            justify-content: center;
        }

        .print-btn {
            background-color: var(--primary);
            color: #fff;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(232, 146, 10, 0.25);
            transition: all 0.2s ease-in-out;
        }

        .print-btn:hover {
            background-color: #d18105;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(232, 146, 10, 0.35);
        }

        /* Receipt Card Styling */
        .receipt-card {
            max-width: 680px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }

        /* Decorative Brand Bar */
        .receipt-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(90deg, var(--primary), #FFA726);
        }

        /* Header Elements */
        .receipt-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #F3F4F6;
            padding-bottom: 24px;
            margin-bottom: 24px;
            padding-top: 15px; /* Gives room so it doesn't touch the orange bar */
        }

        .brand-info {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .school-title {
            font-size: 22px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.5px;
            line-height: 1.2;
            margin: 0 !important;
            padding: 0;
        }

        .school-subtitle {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            margin: 0 !important;
            padding: 0;
        }

        .doc-meta {
            text-align: left;
        }

        .doc-title {
            font-size: 22px; /* Match school-title exactly */
            font-weight: 800;
            color: var(--text-dark);
            margin: 0 0 8px 0 !important;
            padding: 0;
            line-height: 1.2;
        }

        .meta-row {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 4px;
            display: flex;
            justify-content: flex-end;
            gap: 6px;
        }

        .meta-row strong {
            color: var(--text-dark);
        }

        /* Amount Block */
        .amount-showcase {
            background: linear-gradient(135deg, #FFFDF9 0%, #FFF8E5 100%);
            border: 1px solid #FFE0B2;
            border-radius: 12px;
            padding: 24px;
            margin: 24px 0;
            text-align: center;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .amount-lbl {
            font-size: 14px;
            font-weight: 700;
            color: #C4780A;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .amount-val {
            font-size: 32px;
            font-weight: 800;
            color: #B25E00;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .amount-val .currency {
            font-size: 16px;
            font-weight: 700;
            color: #C4780A;
        }

        /* Info Grid List */
        .info-list {
            margin: 24px 0;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 4px;
            border-bottom: 1px dashed var(--border-color);
            font-size: 14px;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-lbl {
            font-weight: 700;
            color: var(--text-muted);
        }

        .info-val {
            font-weight: 700;
            color: var(--text-dark);
            text-align: left;
        }

        .info-val.badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 700;
        }

        .badge-pending {
            background-color: #FFF3E0;
            color: #E65100;
        }

        .badge-settled {
            background-color: var(--emerald-light);
            color: var(--emerald-green);
        }

        .badge-cancelled {
            background-color: #F3F4F6;
            color: #374151;
        }

        /* Disclaimer Notice */
        .disclaimer-box {
            background-color: #FFF8F8;
            border-right: 4px solid #EF4444;
            border-radius: 8px;
            padding: 18px;
            margin: 30px 0;
            font-size: 13px;
            text-align: center;
            line-height: 1.6;
            font-weight: 500;
            color: #991B1B;
            box-shadow: inset 0 0 10px rgba(239, 68, 68, 0.02);
        }

        /* Signatures Grid */
        .receipt-footer {
            margin-top: 40px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
        }

        .sig-block {
            text-align: center;
        }

        .sig-line {
            width: 100%;
            height: 1px;
            background-color: var(--text-muted);
            margin-bottom: 12px;
        }

        .sig-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
        }

        /* Print Settings Overlay & Customizations */
        @media print {
            .no-print, .action-bar {
                display: none !important;
            }

            body {
                background: #ffffff;
                padding: 0;
            }

            .receipt-card {
                border: none;
                box-shadow: none;
                padding: 20px 0 0 0;
                max-width: 100%;
            }

            .disclaimer-box {
                background-color: #FFF8F8 !important;
                border-right: 4px solid #EF4444 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

<!-- Action Bar (Hidden when printed) -->
<div class="action-bar no-print">
    <button class="print-btn" onclick="window.print();">
        <svg style="width:20px; height:20px; fill:currentColor;" viewBox="0 0 24 24">
            <path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/>
        </svg>
        <?php esc_html_e( 'طباعة السند مالي', 'olama-registration' ); ?>
    </button>
</div>

<!-- Receipt Card -->
<div class="receipt-card">
    
    <!-- Top Header Area -->
    <div class="receipt-header">
        <div class="brand-info">
            <h2 class="school-title"><?php echo esc_html( $school_name ); ?></h2>
            <span class="school-subtitle"><?php esc_html_e( 'إدارة الشؤون المالية والقبول', 'olama-registration' ); ?></span>
        </div>
        <div class="doc-meta">
            <h2 class="doc-title"><?php esc_html_e( 'سند تسوية مؤقت', 'olama-registration' ); ?></h2>
            <div class="meta-row">رقم السند: <strong><?php echo esc_html( $receipt->receipt_number ); ?></strong></div>
            <div class="meta-row">تاريخ الإصدار: <strong><?php echo esc_html( date_i18n( get_option('date_format'), strtotime($receipt->created_at) ) ); ?></strong></div>
        </div>
    </div>

    <!-- Amount Banner (Bilingual) -->
    <div class="amount-showcase">
        <span class="amount-lbl">المبلغ الإجمالي للمطالبة / Total Amount</span>
        <span class="amount-val">
            <?php echo number_format( $receipt->original_amount, 2 ); ?>
            <span class="currency">دينار أردني</span>
        </span>
    </div>

    <!-- Details Grid List -->
    <div class="info-list">
        <div class="info-row">
            <span class="info-lbl">رقم ملف العائلة / Family UID</span>
            <span class="info-val" style="color: var(--primary);"><?php echo esc_html( $receipt->family_id ); ?></span>
        </div>

        <?php if ( ! empty( $family_name ) ) : ?>
        <div class="info-row">
            <span class="info-lbl">اسم العائلة / Family Name</span>
            <span class="info-val"><?php echo esc_html( $family_name ); ?></span>
        </div>
        <?php endif; ?>

        <div class="info-row">
            <span class="info-lbl">فئة الدفع / Payment Category</span>
            <span class="info-val"><?php echo esc_html( $receipt->payment_category ); ?></span>
        </div>

        <div class="info-row">
            <span class="info-lbl">طريقة الدفع / Payment Method</span>
            <span class="info-val" style="text-transform: uppercase;"><?php echo esc_html( $receipt->payment_method ); ?></span>
        </div>

        <div class="info-row">
            <span class="info-lbl">حالة الإيصال / Status</span>
            <span class="info-val">
                <?php 
                if ( $receipt->status === 'pending_settlement' ) {
                    echo '<span class="info-val badge badge-pending">بانتظار التسوية في أوراكل</span>';
                } elseif ( $receipt->status === 'settled' ) {
                    echo '<span class="info-val badge badge-settled">تمت التسوية بنجاح</span>';
                } elseif ( $receipt->status === 'cancelled' ) {
                    echo '<span class="info-val badge badge-cancelled">ملغي / Voided</span>';
                } else {
                    echo esc_html( Olama_Reg_Status_Labels::label( $receipt->status, 'settlement' ) );
                }
                ?>
            </span>
        </div>

        <?php if ( ! empty( $receipt->notes ) ) : ?>
        <div class="info-row" style="flex-direction: column; gap: 6px; align-items: flex-start; border-bottom: none; padding-bottom: 0;">
            <span class="info-lbl">ملاحظات إضافية / Additional Notes</span>
            <span class="info-val" style="font-weight: 500; color: var(--text-muted); background: #F9FAFB; padding: 10px; border-radius: 6px; width: 100%; border: 1px solid var(--border-color); line-height: 1.5;">
                <?php echo nl2br( esc_html( $receipt->notes ) ); ?>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Temporary Collection Warning Box -->
    <div class="disclaimer-box">
        "This payment was collected temporarily and will be officially settled in the central Oracle financial system later."<br>
        "تم تحصيل هذه الدفعة مؤقتاً وسيتم تسويتها رسمياً في نظام أوراكل المالي المركزي لاحقاً."
    </div>

    <!-- Signatures -->
    <div class="receipt-footer">
        <div class="sig-block">
            <div class="sig-line"></div>
            <span class="sig-title">توقيع المستلم المفوض / Receiver Signature</span>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <span class="sig-title">توقيع دافع الرسوم / Payer Signature</span>
        </div>
    </div>

</div>

</body>
</html>
