<?php
/**
 * Customer Hub — Main Router View
 *
 * Stage 1 → Stage 2 → Stage 3 panel switching.
 * Data is passed to JS via JSON hydration block (per OLAMASKILL.md §4.1).
 * Do NOT use wp_localize_script here.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── JSON Hydration Block (replaces wp_localize_script) ────────────────────
$hub_nonce = wp_create_nonce( 'os_hub_nonce' );
$reg_nonce = wp_create_nonce( 'olama_reg_nonce' );

// Gather current academic year
$current_year_id = 0;
if ( class_exists( 'Olama_School_Academic' ) ) {
    // get_active_year() is the confirmed method name in this codebase
    $ay = Olama_School_Academic::get_active_year();
    if ( $ay ) {
        $current_year_id = (int) $ay->id;
    }
}

$academic_years = [];
if ( class_exists( 'Olama_School_Academic' ) ) {
    $years = Olama_School_Academic::get_years();
    foreach ( (array) $years as $y ) {
        $academic_years[] = [ 'id' => (int) $y->id, 'name' => $y->year_name ];
    }
}

$preload_customer = null;
if ( isset( $_GET['family_uid'] ) ) {
    $uid = sanitize_text_field( $_GET['family_uid'] );
    $row = Olama_Reg_Family::get_family( $uid );
    if ( $row ) {
        $preload_customer = [
            'uid'       => $row->family_uid,
            'name'      => $row->family_name,
            'phone'     => $row->primary_mobile ?: $row->father_mobile ?: $row->mother_mobile,
            'type'      => 'family',
            'is_active' => (int) $row->is_active,
        ];
    }
} elseif ( isset( $_GET['customer_uid'] ) || isset( $_GET['customer_id'] ) ) {
    $uid = sanitize_text_field( $_GET['customer_uid'] ?? '' );
    $cid = (int) ( $_GET['customer_id'] ?? 0 );
    global $wpdb;
    if ( $uid ) {
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, customer_uid AS uid, customer_name AS name, phone, is_active 
             FROM {$wpdb->prefix}olama_customers 
             WHERE customer_uid = %s LIMIT 1",
            $uid
        ) );
    } else {
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, customer_uid AS uid, customer_name AS name, phone, is_active 
             FROM {$wpdb->prefix}olama_customers 
             WHERE id = %d LIMIT 1",
            $cid
        ) );
    }
    if ( $row ) {
        $preload_customer = [
            'id'          => (int) $row->id,
            'internal_id' => (int) $row->id,
            'uid'         => $row->uid,
            'name'        => $row->name,
            'phone'       => $row->phone,
            'type'        => 'external',
            'is_active'   => (int) $row->is_active,
        ];
    }
}
?>
<script id="os-hub-data" type="application/json">
<?php echo wp_json_encode( [
    'nonce'          => $hub_nonce,
    'regNonce'       => $reg_nonce,
    'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
    'adminUrl'       => admin_url(),
    'currentUserId'  => get_current_user_id(),
    'currentYearId'  => $current_year_id,
    'academicYears'  => $academic_years,
    'preloadCustomer'=> $preload_customer,
    'i18n'           => [
        'searchPlaceholder'   => __( 'ابحث باسم العائلة أو رقم الملف أو رقم الهاتف...', 'olama-registration' ),
        'searchBtn'           => __( 'بحث', 'olama-registration' ),
        'noResults'           => __( 'لا توجد نتائج مطابقة', 'olama-registration' ),
        'loading'             => __( 'جارٍ التحميل...', 'olama-registration' ),
        'errorGeneric'        => __( 'حدث خطأ، يُرجى المحاولة مجددًا', 'olama-registration' ),
        'family'              => __( 'عائلة', 'olama-registration' ),
        'external'            => __( 'عميل خارجي', 'olama-registration' ),
        'backToSearch'        => __( 'العودة للبحث', 'olama-registration' ),
        'backToType'          => __( 'تغيير نوع العميل', 'olama-registration' ),
        'recentLookups'       => __( 'آخر عمليات البحث', 'olama-registration' ),
        'networkError'        => __( 'فشل الاتصال. يُرجى المحاولة مجددًا.', 'olama-registration' ),
        'retry'               => __( 'إعادة المحاولة', 'olama-registration' ),
        'addNewCustomer'      => __( 'إضافة عميل جديد', 'olama-registration' ),
        'students'            => __( 'طالب', 'olama-registration' ),
        'children'            => __( 'أبناء', 'olama-registration' ),
        'active'              => __( 'نشط', 'olama-registration' ),
        'inactive'            => __( 'غير نشط', 'olama-registration' ),
        'confirmDeactivate'   => __( 'هل تريد تعطيل هذا الملف؟', 'olama-registration' ),
        'confirmActivate'     => __( 'هل تريد تفعيل هذا الملف؟', 'olama-registration' ),
        'yearAll'             => __( 'جميع السنوات', 'olama-registration' ),
        'newInvoice'          => __( 'فاتورة جديدة', 'olama-registration' ),
        'newAgreement'        => __( 'عقد جديد', 'olama-registration' ),
        'childAdded'          => __( 'تمت إضافة الابن بنجاح.', 'olama-registration' ),
        'childNameRequired'   => __( 'اسم الابن مطلوب.', 'olama-registration' ),
    ],
] ); ?>
</script>

<div class="wrap os-hub-wrap" dir="rtl">
    <h1 class="os-hub-page-title">
        <span class="dashicons dashicons-store"></span>
        <?php _e( 'لوحة خدمات العملاء', 'olama-registration' ); ?>
    </h1>

    <!-- ══ STAGE 1: Type Selection ══════════════════════════════════════════ -->
    <div id="os-hub-panel-type" class="os-hub-panel os-hub-panel--active" role="main" aria-label="<?php esc_attr_e( 'اختيار نوع العميل', 'olama-registration' ); ?>">
        <?php include __DIR__ . '/panel-type-select.php'; ?>
    </div>

    <!-- ══ STAGE 2: Customer Lookup ══════════════════════════════════════════ -->
    <div id="os-hub-panel-lookup" class="os-hub-panel" role="main" aria-label="<?php esc_attr_e( 'البحث عن عميل', 'olama-registration' ); ?>" aria-hidden="true">
        <?php include __DIR__ . '/panel-lookup.php'; ?>
    </div>

    <!-- ══ STAGE 3: Service Hub ═══════════════════════════════════════════════ -->
    <div id="os-hub-panel-hub" class="os-hub-panel" role="main" aria-label="<?php esc_attr_e( 'لوحة الخدمات', 'olama-registration' ); ?>" aria-hidden="true">
        <?php include __DIR__ . '/panel-hub.php'; ?>
    </div>
</div><!-- .os-hub-wrap -->

<?php
// Load variables for invoice modal
$years = [];
if ( class_exists( 'Olama_School_Academic' ) ) {
    $years = Olama_School_Academic::get_years();
}
$fee_templates = Olama_Reg_Billing_Fees::get_templates();
$invoice_fee_templates = array_values( array_filter(
    $fee_templates,
    static fn( $tpl ) => ( $tpl->subject_type ?? 'general' ) === 'service'
) );
$agreement_natures = get_option( 'olama_reg_agreement_natures', ['عقد مدرسة', 'عقد روضة', 'عقد نادي صيفي', 'رحلة مدرسية'] );
$custom_services = get_option( 'olama_reg_custom_services', ['دوسية', 'نشاط', 'مواصلات', 'امتحان إضافي'] );
?>
<!-- ── INVOICE GENERATOR MODAL ─────────────────────────────────── -->
<?php include __DIR__ . '/partial-original-form-modal.php'; ?>
<div id="olama-reg-invoice-modal" class="olama-reg-modal olama-reg-wrap" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(26,26,46,0.4); backdrop-filter:blur(4px);">
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
                            <select id="inv_family_uid" name="family_uid" style="width:100%;" required></select>
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
                                <?php foreach ( $invoice_fee_templates as $tpl ): ?>
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


<?php include __DIR__ . '/partial-settlement-modal.php'; ?>
<?php if ( false ) : ?>
<div id="olama-reg-settlement-modal" class="olama-reg-modal olama-reg-wrap" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(26,26,46,0.4); backdrop-filter:blur(4px);">
    <div class="olama-reg-modal-dialog" style="max-width:550px; width:90%;">
        <div class="olama-reg-modal-header">
            <h2 class="olama-reg-modal-title">
                <span class="dashicons dashicons-clipboard"></span>
                <?php esc_html_e( 'إنشاء إيصال تسوية جديد', 'olama-registration' ); ?>
            </h2>
            <button type="button" class="olama-reg-modal-close">&times;</button>
        </div>
        
        <form id="olama-reg-settlement-form" style="margin:0;">
            <div class="olama-reg-modal-body">
                <input type="hidden" name="family_id" id="settlement_family_id">
                
                <div class="olama-reg-section">
                    <h3 class="olama-reg-section-title"><?php esc_html_e( 'تفاصيل إيصال التسوية', 'olama-registration' ); ?></h3>
                    <div class="olama-reg-grid" style="grid-template-columns: 1fr; gap:16px; padding:16px;">
                        <div class="olama-reg-field olama-reg-field--required">
                            <label for="settlement_family_search"><?php esc_html_e( 'العائلة', 'olama-registration' ); ?></label>
                            <select id="settlement_family_search" style="width:100%;" required></select>
                        </div>
                        
                        <div class="olama-reg-field olama-reg-field--required">
                            <label for="settlement_category"><?php esc_html_e( 'قائمة الخدمات', 'olama-registration' ); ?></label>
                            <select id="settlement_category" name="payment_category" required style="width:100%;">
                                <option value=""><?php esc_html_e( '-- اختر --', 'olama-registration' ); ?></option>
                                <?php
                                $custom_services = get_option( 'olama_reg_custom_services', ['دوسية', 'نشاط', 'مواصلات', 'امتحان إضافي'] );
                                foreach ($custom_services as $srv) {
                                    echo '<option value="' . esc_attr($srv) . '">' . esc_html($srv) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="olama-reg-field olama-reg-field--required">
                            <label for="settlement_amount"><?php esc_html_e( 'المبلغ (د.أ)', 'olama-registration' ); ?></label>
                            <input type="number" id="settlement_amount" name="original_amount" step="0.01" min="0.01" required style="width:100%; border: 1.5px solid #E0C090; border-radius: 6px; padding: 8px;">
                        </div>
                        
                        <div class="olama-reg-field olama-reg-field--required">
                            <label for="settlement_method"><?php esc_html_e( 'طريقة الدفع', 'olama-registration' ); ?></label>
                            <select id="settlement_method" name="payment_method" style="width:100%;">
                                <option value="cash"><?php esc_html_e( 'نقدي (Cash)', 'olama-registration' ); ?></option>
                                <option value="card"><?php esc_html_e( 'بطاقة (Card)', 'olama-registration' ); ?></option>
                                <option value="transfer"><?php esc_html_e( 'تحويل بنكي (Transfer)', 'olama-registration' ); ?></option>
                            </select>
                        </div>
                        
                        <div class="olama-reg-field">
                            <label for="settlement_notes"><?php esc_html_e( 'ملاحظات', 'olama-registration' ); ?></label>
                            <textarea id="settlement_notes" name="notes" rows="3" style="width:100%; border:1.5px solid #E0C090; border-radius:6px; padding:8px; font-family:inherit;" placeholder="<?php esc_html_e( 'أدخل أي ملاحظات إضافية هنا...', 'olama-registration' ); ?>"></textarea>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="olama-reg-form-actions">
                <button type="submit" class="olama-reg-btn olama-reg-btn--primary" id="olama-reg-save-settlement-btn">
                    <?php esc_html_e( 'حفظ وإصدار الإيصال', 'olama-registration' ); ?>
                </button>
                <button type="button" class="button button-large olama-reg-modal-close"><?php esc_html_e( 'إلغاء', 'olama-registration' ); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- ── CUSTOM PAYMENT GENERATOR MODAL ────────────────────────────── -->
<?php endif; ?>
<div id="olama-reg-custom-payment-modal" class="olama-reg-modal olama-reg-wrap" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(26,26,46,0.4); backdrop-filter:blur(4px);">
    <div class="olama-reg-modal-dialog" style="max-width:800px; width:95%;">
        <div class="olama-reg-modal-header">
            <h2 class="olama-reg-modal-title">
                <span class="dashicons dashicons-money-alt"></span>
                <?php esc_html_e( 'إصدار دفعة مخصصة (Custom Payment)', 'olama-registration' ); ?>
            </h2>
            <button type="button" class="olama-reg-modal-close">&times;</button>
        </div>
        
        <form id="olama-reg-custom-payment-form" style="margin:0;">
            <?php wp_nonce_field( 'olama_reg_custom_payment', 'custom_payment_nonce' ); ?>
            
            <div class="olama-reg-modal-body" style="padding:20px;">
                <!-- ── Step 1: Customer & Beneficiaries ── -->
                <div class="olama-reg-section" style="margin-bottom:20px;">
                    <h3 class="olama-reg-section-title"><?php esc_html_e( '1. اختيار العميل والمستفيدين', 'olama-registration' ); ?></h3>
                    
                    <div style="padding:16px;">
                        <!-- Hide customer selection controls when in dashboard since customer is active -->
                        <div style="display:none;">
                            <!-- Customer Type Toggle -->
                            <div style="margin-bottom:15px; padding:12px; background:#f8fafc; border-radius:8px; border:1.5px solid #cbd5e1; display:flex; gap:24px; align-items:center;">
                                <label style="font-weight:700; color:var(--reg-primary); margin:0;"><?php esc_html_e( 'نوع العميل:', 'olama-registration' ); ?></label>
                                <label style="cursor:pointer; display:flex; align-items:center; gap:6px; margin:0;">
                                    <input type="radio" name="customer_type" value="internal" id="cp_type_internal" checked disabled>
                                    <span><?php esc_html_e( 'عائلة مسجلة (Internal)', 'olama-registration' ); ?></span>
                                </label>
                                <label style="cursor:pointer; display:flex; align-items:center; gap:6px; margin:0;">
                                    <input type="radio" name="customer_type" value="external" id="cp_type_external" disabled>
                                    <span><?php esc_html_e( 'عميل خارجي (Walk-in)', 'olama-registration' ); ?></span>
                                </label>
                            </div>
                            
                            <!-- Internal Family Selection -->
                            <div id="cp_internal_customer_wrap" style="margin-bottom:15px;">
                                <label for="cp_family_search" style="font-weight:700; display:block; margin-bottom:6px; color:var(--reg-primary);"><?php esc_html_e( 'العائلة المستهدفة:', 'olama-registration' ); ?></label>
                                <select id="cp_family_search" class="olama-reg-family-search" style="width:100%;"></select>
                                <input type="hidden" id="cp_family_uid" name="family_uid">
                                <input type="hidden" id="cp_customer_uid" name="customer_uid">
                            </div>
                            
                            <!-- External Customer Selection -->
                            <div id="cp_external_customer_wrap" style="display:none; margin-bottom:15px;">
                                <label for="cp_ext_customer_search" style="font-weight:700; display:block; margin-bottom:6px; color:var(--reg-primary);"><?php esc_html_e( 'العميل الخارجي المستهدف:', 'olama-registration' ); ?></label>
                                <select id="cp_ext_customer_search" style="width:100%;"></select>
                                <input type="hidden" id="cp_ext_customer_id" name="ext_customer_id">
                            </div>
                        </div>
                        
                        <!-- Internal Students Checklist -->
                        <div id="cp_students_container" style="display:none; margin-top:15px; background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #cbd5e1;">
                            <label style="display:block; font-weight:700; margin-bottom:12px; color:var(--reg-primary);"><?php esc_html_e( 'اختيار الطلاب المستفيدين:', 'olama-registration' ); ?></label>
                            <div id="cp_students_list" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px,1fr)); gap:10px;">
                                <!-- Dynamic checkboxes -->
                            </div>
                            <div id="cp_students_error" style="color:#dc2626; font-size:13px; margin-top:8px; display:none;"><?php esc_html_e( 'يجب اختيار طالب واحد على الأقل.', 'olama-registration' ); ?></div>
                        </div>
                        
                        <!-- External Children Checklist -->
                        <div id="cp_ext_children_section" style="display:none; margin-top:15px; background:#f8fafc; padding:15px; border-radius:8px; border:1px solid #cbd5e1;">
                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                                <label style="font-weight:700; color:var(--reg-primary);"><?php esc_html_e( 'اختيار الأبناء المستفيدين:', 'olama-registration' ); ?></label>
                                <button type="button" id="cp_ext_quick_add_child_btn" class="button button-secondary" style="font-size:12px; height:28px;">
                                    <span class="dashicons dashicons-plus" style="font-size:14px; margin-top:1px;"></span>
                                    <?php esc_html_e( 'إضافة ابن جديد', 'olama-registration' ); ?>
                                </button>
                            </div>
                            
                            <div id="cp_ext_students_list" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px,1fr)); gap:10px; margin-bottom:12px;">
                                <!-- Dynamic checkboxes -->
                            </div>
                            
                            <!-- Quick add child row -->
                            <div id="cp_ext_new_child_row" style="display:none; background:#f0fdf4; border:1px dashed #86efac; border-radius:8px; padding:12px; margin-top:8px;">
                                <div style="display:flex; gap:8px; align-items:flex-end;">
                                    <div style="flex:2;">
                                        <label style="font-size:12px; font-weight:700; margin-bottom:4px; display:block;"><?php esc_html_e( 'اسم الابن *', 'olama-registration' ); ?></label>
                                        <input type="text" id="cp_new_child_name" style="width:100%; border: 1.5px solid #cbd5e1; border-radius: 6px; padding: 6px;" placeholder="<?php esc_attr_e( 'الاسم الكامل', 'olama-registration' ); ?>">
                                    </div>
                                    <div style="flex:1;">
                                        <label style="font-size:12px; font-weight:700; margin-bottom:4px; display:block;"><?php esc_html_e( 'الصف', 'olama-registration' ); ?></label>
                                        <input type="text" id="cp_new_child_grade" style="width:100%; border: 1.5px solid #cbd5e1; border-radius: 6px; padding: 6px;" placeholder="<?php esc_attr_e( 'مثال: رابع', 'olama-registration' ); ?>">
                                    </div>
                                    <button type="button" id="cp_new_child_save_btn" class="button button-primary" style="background:var(--reg-success); border-color:var(--reg-success); height:32px;">
                                        <?php esc_html_e( 'حفظ وإضافة', 'olama-registration' ); ?>
                                    </button>
                                    <button type="button" id="cp_new_child_cancel_btn" class="button button-secondary" style="height:32px;"><?php esc_html_e( 'إلغاء', 'olama-registration' ); ?></button>
                                </div>
                            </div>
                            
                            <div id="cp_ext_no_children_msg" style="display:none; text-align:center; color:#64748b; font-size:13px; padding:12px; background:#f8fafc; border-radius:8px; border:1px solid #cbd5e1;">
                                <?php esc_html_e( 'لا يوجد أبناء مسجلون لهذا العميل بعد.', 'olama-registration' ); ?>
                            </div>
                            
                            <div style="margin-top:8px;">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; color:#475569;">
                                    <input type="checkbox" id="cp_ext_pay_customer_direct" style="width:16px; height:16px;">
                                    <span><?php esc_html_e( 'دفعة مباشرة للعميل (بدون تحديد ابن)', 'olama-registration' ); ?></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- ── Step 2: Service & Amounts ── -->
                <div class="olama-reg-section" style="margin-bottom:20px;">
                    <h3 class="olama-reg-section-title"><?php esc_html_e( '2. تفاصيل الخدمة والرسوم', 'olama-registration' ); ?></h3>
                    
                    <div style="padding:16px;">
                        <div class="olama-reg-grid" style="grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:15px;">
                            <div class="olama-reg-field olama-reg-field--required">
                                <label for="cp_service_type"><?php esc_html_e( 'نوع الخدمة', 'olama-registration' ); ?></label>
                                <select id="cp_service_type" name="service_type" style="width:100%;" required>
                                    <option value=""><?php esc_html_e( '-- اختر الخدمة --', 'olama-registration' ); ?></option>
                                    <?php
                                    $services = get_option( 'olama_reg_custom_services', ['دوسية', 'نشاط', 'مواصلات', 'امتحان إضافي'] );
                                    foreach ( $services as $srv ) : ?>
                                        <option value="<?php echo esc_attr($srv); ?>"><?php echo esc_html($srv); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="olama-reg-field">
                                <label for="cp_fee_template"><?php esc_html_e( 'نموذج الرسوم المرجعي', 'olama-registration' ); ?></label>
                                <select id="cp_fee_template" name="fee_template_id" style="width:100%;">
                                    <option value=""><?php esc_html_e( '-- اختر نموذج الرسوم --', 'olama-registration' ); ?></option>
                                    <?php
                                    $fee_templates = Olama_Reg_Billing_Fees::get_templates();
                                    foreach ( $fee_templates as $fee ) :
                                        $total_val = array_sum( array_column( $fee->items, 'amount' ) );
                                    ?>
                                        <option value="<?php echo esc_attr($fee->id); ?>"
                                                data-amount="<?php echo esc_attr($total_val); ?>"
                                                data-subject-type="<?php echo esc_attr( $fee->subject_type ?? 'general' ); ?>"
                                                data-subject-value="<?php echo esc_attr( $fee->subject_value ?? '' ); ?>">
                                            <?php echo esc_html($fee->template_name); ?> (<?php echo number_format($total_val, 2); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="olama-reg-grid" style="grid-template-columns: 1fr 1fr 1fr; gap:16px; margin-bottom:15px;">
                            <div class="olama-reg-field olama-reg-field--required">
                                <label for="cp_amount"><?php esc_html_e( 'قيمة الدفعة (للفرد)', 'olama-registration' ); ?></label>
                                <input type="number" id="cp_amount" name="amount" step="0.01" min="0.01" required style="width:100%; border: 1.5px solid #E0C090; border-radius: 6px; padding: 8px;">
                            </div>
                            
                            <div class="olama-reg-field">
                                <label for="cp_discount"><?php esc_html_e( 'الخصم الممنوح', 'olama-registration' ); ?></label>
                                <input type="number" id="cp_discount" name="discount" step="0.01" min="0" value="0.00" style="width:100%; border: 1.5px solid #E0C090; border-radius: 6px; padding: 8px;">
                            </div>
                            
                            <div class="olama-reg-field olama-reg-field--required">
                                <label for="cp_payment_method"><?php esc_html_e( 'طريقة الدفع', 'olama-registration' ); ?></label>
                                <select id="cp_payment_method" name="payment_method" style="width:100%;" required>
                                    <option value="cash"><?php esc_html_e( 'نقدي (كاش)', 'olama-registration' ); ?></option>
                                    <option value="bank_transfer"><?php esc_html_e( 'تحويل بنكي', 'olama-registration' ); ?></option>
                                    <option value="cheque"><?php esc_html_e( 'شيك بنكي', 'olama-registration' ); ?></option>
                                    <option value="online"><?php esc_html_e( 'دفع إلكتروني', 'olama-registration' ); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="olama-reg-form-actions" style="display:flex; justify-content:space-between; align-items:center; padding:15px 20px;">
                <div style="font-weight:700; color:var(--reg-primary); font-size:16px;">
                    <?php esc_html_e( 'الإجمالي:', 'olama-registration' ); ?> <span id="cp_total_display" style="color:var(--reg-success); font-size:20px;">0.00 د.أ</span>
                </div>
                <div>
                    <button type="submit" class="olama-reg-btn olama-reg-btn--primary" id="cp_submit_btn">
                        <?php esc_html_e( 'حفظ وإصدار الفاتورة والسند', 'olama-registration' ); ?>
                    </button>
                    <button type="button" class="button button-large olama-reg-modal-close"><?php esc_html_e( 'إلغاء', 'olama-registration' ); ?></button>
                </div>
            </div>
            
            <div id="cp_loading" style="display:none; text-align:center; padding:15px; color:var(--reg-primary); font-weight:700;">
                <span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> <?php esc_html_e( 'جاري المعالجة...', 'olama-registration' ); ?>
            </div>
            <div id="cp_response_msg" style="padding:15px; display:none;"></div>
        </form>
    </div>
</div>

<?php
$cust_modal_rendered = true;
include OLAMA_REG_PATH . 'admin/views/partial-customer-modal.php';

// ── INVOICE DETAILS DRAWER / OVERLAY FOR INLINE PREVIEWS ──────────────────
?>
<div id="olama-reg-invoice-drawer" class="olama-reg-modal olama-reg-wrap" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background-color:rgba(26,26,46,0.4); backdrop-filter:blur(4px);">
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
            <div class="olama-reg-metrics-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 20px;">
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
                <div class="olama-reg-metric-card" style="padding:15px; flex-direction:column; align-items:center; gap:8px;">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'الإشعار الدائن', 'olama-registration' ); ?></div>
                    <div id="drawer-credit-notes-val" class="olama-reg-metric-value" style="font-size:22px; margin-top:0; color:#1d4ed8;">0.00</div>
                </div>
                <div class="olama-reg-metric-card" style="padding:15px; flex-direction:column; align-items:center; gap:8px;">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'الإشعار المدين', 'olama-registration' ); ?></div>
                    <div id="drawer-debit-notes-val" class="olama-reg-metric-value" style="font-size:22px; margin-top:0; color:#b45309;">0.00</div>
                </div>
                <div class="olama-reg-metric-card olama-reg-metric-card--warning" style="padding:15px; flex-direction:column; align-items:center; gap:8px;">
                    <div class="olama-reg-metric-title"><?php esc_html_e( 'المتبقي المستحق', 'olama-registration' ); ?></div>
                    <div id="drawer-balance-val" class="olama-reg-metric-value" style="font-size:22px; margin-top:0;">0.00</div>
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
                                <th><?php esc_html_e( 'حالة الدفعة', 'olama-registration' ); ?></th>
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

<?php include __DIR__ . '/partial-settle-receipt-modal.php'; ?>
<?php if ( false ) : ?>
<!-- Legacy inline copy retained temporarily during dashboard modal extraction. -->
<!-- Modal: Settle Receipt -->
<div id="modal-settle-receipt" class="olama-reg-modal olama-reg-wrap" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; overflow:auto; background-color:rgba(26,26,46,0.4); backdrop-filter:blur(4px);" dir="rtl">
    <div class="olama-reg-modal-dialog" style="max-width:550px; width:90%;">
        <div class="olama-reg-modal-header">
            <h2 class="olama-reg-modal-title">
                <span class="dashicons dashicons-clipboard"></span>
                <?php esc_html_e( 'تسوية الإيصال في النظام', 'olama-registration' ); ?>
            </h2>
            <button type="button" class="olama-reg-modal-close btn-close-modal">&times;</button>
        </div>
        <form id="form-settle-receipt">
            <div class="olama-reg-modal-body">
                <input type="hidden" name="id" id="settle-receipt-id">
                <div class="olama-reg-section" style="border:none; box-shadow:none; margin:0; padding:0;">
                    <div class="olama-reg-grid" style="grid-template-columns: 1fr; gap:16px;">
                        <div class="olama-reg-field">
                            <label><?php esc_html_e( 'مبلغ التسوية', 'olama-registration' ); ?></label>
                            <input type="text" id="settle-amount-display" readonly style="background:#f1f1f1; width:100%;">
                        </div>
                        <div class="olama-reg-field olama-reg-field--required">
                            <label><?php esc_html_e( 'رقم الإيصال (أوراكل)', 'olama-registration' ); ?></label>
                            <input type="text" name="oracle_receipt_number" required style="width:100%;">
                        </div>
                        <div class="olama-reg-field">
                            <label><?php esc_html_e( 'ملاحظات المحاسب', 'olama-registration' ); ?></label>
                            <textarea name="notes" rows="3" style="width:100%;"></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="olama-reg-form-actions">
                <button type="submit" class="olama-reg-btn olama-reg-btn--primary"><?php esc_html_e( 'تأكيد التسوية', 'olama-registration' ); ?></button>
                <button type="button" class="button button-large btn-close-modal"><?php esc_html_e( 'إغلاق', 'olama-registration' ); ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
