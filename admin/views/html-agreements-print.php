<?php
/**
 * Print the live draft or immutable completed-contract snapshot.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'Unauthorized', 'olama-registration' ) );
}

$id = absint( $_GET['id'] ?? 0 );
$agreement = Olama_Reg_Agreement::get( $id );
if ( ! $agreement ) {
    wp_die( esc_html__( 'العقد غير موجود.', 'olama-registration' ) );
}

if ( ! empty( $agreement->contract_snapshot ) ) {
    $contract_html = (string) $agreement->contract_snapshot;
    $is_snapshot = true;
} else {
    $contract_html = Olama_Reg_Contract_Renderer::render( $agreement );
    $is_snapshot = false;
    if ( is_wp_error( $contract_html ) ) {
        wp_die( esc_html( $contract_html->get_error_message() ) );
    }
}

$posted_amendments = [];
if ( class_exists( 'Olama_Reg_Agreement_Amendment' ) ) {
    $posted_amendments = array_values( array_filter(
        Olama_Reg_Agreement_Amendment::get_by_agreement( $id ),
        static fn( $amendment ) => (string) ( $amendment->status ?? '' ) === 'posted'
    ) );
    $posted_amendments = array_reverse( $posted_amendments );

    foreach ( $posted_amendments as $amendment ) {
        $amendment->print_lines = Olama_Reg_Agreement_Amendment::get_lines( (int) $amendment->id );
    }
}

$format_contract_money = static function ( $amount, bool $signed = false ): string {
    $value = (float) $amount;
    $prefix = $signed && $value > 0 ? '+' : '';
    return $prefix . number_format( $value, 3 ) . ' د.أ';
};
?>
<style>
    body {
        margin: 0;
        background: #f4f5f7;
        color: #17172f;
        font-family: Tahoma, Arial, sans-serif;
        line-height: 1.8;
    }
    .contract-toolbar {
        box-sizing: border-box;
        max-width: 920px;
        margin: 20px auto;
        padding: 12px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        background: #fff;
        border: 1px solid #d9dee8;
        border-radius: 8px;
    }
    .contract-document {
        box-sizing: border-box;
        max-width: 920px;
        margin: 20px auto 50px;
        padding: 42px 48px;
        background: #fff;
        border: 1px solid #d9dee8;
        box-shadow: 0 5px 20px rgba(20, 26, 45, .08);
    }
    .contract-document h1 { color: #17172f; font-size: 25px; margin: 0 0 8px; }
    .contract-document h2 {
        color: #17172f;
        font-size: 18px;
        margin: 28px 0 12px;
        padding: 8px 12px;
        border-right: 4px solid #e8920a;
        background: #fff8ec;
    }
    .contract-document h3 { color: #8a5607; margin: 22px 0 10px; }
    .contract-document table {
        width: 100%;
        border-collapse: collapse;
        margin: 12px 0 22px;
        font-size: 13px;
    }
    .contract-document th,
    .contract-document td {
        border: 1px solid #d9dee8;
        padding: 8px 10px;
        text-align: right;
        vertical-align: top;
    }
    .contract-document th { background: #f5f7fa; font-weight: 700; }
    .contract-fee-amount {
        min-width: 150px;
        vertical-align: top;
    }
    .contract-fee-amount-details {
        display: grid;
        gap: 2px;
        margin-top: 7px;
        padding-top: 6px;
        border-top: 1px dashed #cbd2dc;
        font-size: 10px;
        font-weight: 400;
        color: #596273;
    }
    .contract-fee-amount-details em {
        display: block;
        margin-bottom: 2px;
        color: #7a8494;
        font-style: normal;
        font-weight: 700;
    }
    .contract-fee-amount-details > div {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        line-height: 1.55;
    }
    .contract-fee-amount-details b {
        color: #17172f;
        font-size: 10px;
        font-weight: 600;
        white-space: nowrap;
    }
    .contract-document ol { padding-right: 24px; }
    .contract-document li { margin-bottom: 7px; }
    .contract-signatures {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 50px;
        margin-top: 55px;
    }
    .contract-signatures > div { border-top: 1px dashed #8992a3; padding-top: 14px; }
    .contract-record {
        max-width: 920px;
        margin: -35px auto 40px;
        color: #667085;
        font-size: 11px;
        direction: ltr;
        text-align: center;
    }
    .contract-amendments {
        margin-top: 45px;
        padding-top: 8px;
        border-top: 3px solid #17172f;
    }
    .contract-amendments-intro {
        margin: 0 0 18px;
        padding: 10px 12px;
        border: 1px solid #f1d59d;
        background: #fffaf0;
        color: #5f4a22;
        font-size: 12px;
    }
    .contract-amendment {
        margin: 0 0 28px;
        padding: 16px;
        border: 1px solid #cfd6e2;
        border-radius: 6px;
        break-inside: avoid;
    }
    .contract-amendment-header {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: baseline;
        margin-bottom: 12px;
    }
    .contract-amendment-header strong { font-size: 16px; }
    .contract-amendment-header span { color: #667085; font-size: 12px; direction: ltr; }
    .contract-amendment-total {
        font-weight: 700;
        color: #8a5607;
        white-space: nowrap;
    }
    .contract-money {
        display: inline-block;
        direction: ltr;
        unicode-bidi: isolate;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }
    .contract-amendment-signatures {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 50px;
        margin-top: 34px;
    }
    .contract-amendment-signatures > div {
        border-top: 1px dashed #8992a3;
        padding-top: 10px;
    }
    @media print {
        body { background: #fff; }
        .contract-toolbar, #adminmenuwrap, #adminmenuback, #wpadminbar, #wpfooter { display: none !important; }
        #wpcontent { margin: 0 !important; padding: 0 !important; }
        .contract-document { border: 0; box-shadow: none; max-width: none; margin: 0; padding: 0; }
        .contract-record { margin: 20px 0 0; }
        .contract-amendments { break-before: page; page-break-before: always; }
        @page { size: A4; margin: 14mm; }
    }
    @media (max-width: 700px) {
        .contract-document { margin: 0; padding: 24px 18px; }
        .contract-signatures { grid-template-columns: 1fr; }
    }
</style>

<div class="contract-toolbar no-print" dir="rtl">
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-registration-agreements&action=edit&id=' . $id ) ); ?>" class="button">
        <?php esc_html_e( 'العودة إلى العقد', 'olama-registration' ); ?>
    </a>
    <span>
        <?php echo $is_snapshot
            ? esc_html__( 'نسخة العقد المثبتة', 'olama-registration' )
            : esc_html__( 'معاينة مسودة — ستثبت النسخة عند الإكمال', 'olama-registration' ); ?>
    </span>
    <button type="button" class="button button-primary" onclick="window.print();"><?php esc_html_e( 'طباعة العقد', 'olama-registration' ); ?></button>
</div>

<article class="contract-document" dir="rtl">
    <?php echo wp_kses_post( $contract_html ); ?>

    <?php if ( $posted_amendments ) : ?>
        <section class="contract-amendments" aria-label="ملحق التعديلات المالية">
            <h2>ملحق التعديلات المالية المعتمدة</h2>
            <p class="contract-amendments-intro">
                تبقى نسخة العقد الأصلية المثبتة دون تغيير لأغراض التدقيق، وتعد التعديلات المالية المرحلة أدناه ملاحق مكملة للعقد وجزءاً من قيمته المالية الحالية.
            </p>

            <?php foreach ( $posted_amendments as $amendment ) : ?>
                <section class="contract-amendment">
                    <div class="contract-amendment-header">
                        <strong>تعديل مالي رقم <?php echo esc_html( $amendment->amendment_no ); ?></strong>
                        <span><?php echo esc_html( $amendment->effective_date ); ?></span>
                    </div>

                    <table>
                        <tbody>
                            <tr>
                                <th>سبب التعديل</th>
                                <td colspan="3"><?php echo esc_html( $amendment->reason ?: '—' ); ?></td>
                            </tr>
                            <tr>
                                <th>قيمة العقد قبل التعديل</th>
                                <td><span class="contract-money"><?php echo esc_html( $format_contract_money( $amendment->old_total ) ); ?></span></td>
                                <th>قيمة التعديل</th>
                                <td class="contract-amendment-total"><span class="contract-money"><?php echo esc_html( $format_contract_money( $amendment->difference_amount, true ) ); ?></span></td>
                            </tr>
                            <tr>
                                <th>قيمة العقد بعد التعديل</th>
                                <td colspan="3"><strong class="contract-money"><?php echo esc_html( $format_contract_money( $amendment->new_total ) ); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if ( ! empty( $amendment->print_lines ) ) : ?>
                        <h3>تفاصيل التعديل</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>البيان</th>
                                    <th>القيمة السابقة</th>
                                    <th>القيمة الجديدة</th>
                                    <th>الفرق</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $amendment->print_lines as $line ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $line->description ?: '—' ); ?></td>
                                        <td><span class="contract-money"><?php echo esc_html( $format_contract_money( $line->old_amount ) ); ?></span></td>
                                        <td><span class="contract-money"><?php echo esc_html( $format_contract_money( $line->new_amount ) ); ?></span></td>
                                        <td class="contract-amendment-total"><span class="contract-money"><?php echo esc_html( $format_contract_money( $line->difference_amount, true ) ); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <div class="contract-amendment-signatures">
                        <div>اعتماد الأكاديمية</div>
                        <div>إقرار ولي الأمر</div>
                    </div>
                </section>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</article>

<?php if ( $is_snapshot ) : ?>
    <div class="contract-record">
        Version <?php echo esc_html( (int) $agreement->template_version ); ?>
        · <?php echo esc_html( $agreement->contract_generated_at ); ?>
        · SHA-256 <?php echo esc_html( $agreement->contract_hash ); ?>
    </div>
<?php endif; ?>
