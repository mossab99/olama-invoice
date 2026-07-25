<?php
/**
 * Settings View
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Handle save
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['olama_reg_settings_nonce'] ) && wp_verify_nonce( $_POST['olama_reg_settings_nonce'], 'save_settings' ) ) {
    if ( isset( $_POST['custom_services'] ) ) {
        // Sanitize array of strings
        $services = array_map( 'sanitize_text_field', (array) $_POST['custom_services'] );
        // Remove empty
        $services = array_filter( $services, 'strlen' );
        update_option( 'olama_reg_custom_services', array_values( $services ) );
    }

    if ( isset( $_POST['agreement_natures'] ) ) {
        $raw_natures = (array) $_POST['agreement_natures'];
        $raw_installment_flags = isset( $_POST['agreement_nature_has_installments'] ) ? (array) $_POST['agreement_nature_has_installments'] : [];
        $natures = [];
        $installment_flags = [];

        foreach ( $raw_natures as $row_key => $raw_nature ) {
            $nature = sanitize_text_field( $raw_nature );
            if ( $nature === '' ) {
                continue;
            }

            $natures[] = $nature;
            $installment_flags[ $nature ] = isset( $raw_installment_flags[ $row_key ] ) ? 1 : 0;
        }

        update_option( 'olama_reg_agreement_natures', array_values( $natures ) );
        update_option( 'olama_reg_agreement_nature_installments', $installment_flags );
    }
    
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'تم حفظ الإعدادات بنجاح.', 'olama-registration' ) . '</p></div>';
}

$services = get_option( 'olama_reg_custom_services', ['دوسية', 'نشاط', 'مواصلات', 'امتحان إضافي'] );
$agreement_natures = get_option( 'olama_reg_agreement_natures', ['عقد مدرسة', 'عقد روضة', 'عقد نادي صيفي', 'رحلة مدرسية'] );
$agreement_nature_installments = get_option( 'olama_reg_agreement_nature_installments', [] );
if ( ! is_array( $agreement_nature_installments ) ) {
    $agreement_nature_installments = [];
}
?>

<div class="wrap olama-reg-wrap">
    <div class="olama-reg-header">
        <h1><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'إعدادات التسجيل', 'olama-registration' ); ?></h1>
    </div>

    <div class="olama-reg-box" style="max-width: 800px; margin-top: 20px;">
        <h2 class="nav-tab-wrapper wp-clearfix os-nav-tabs" style="margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 0;">
            <a href="#tab-services" class="nav-tab nav-tab-active" style="margin-bottom: -1px; background: #fff; border-bottom-color: #fff; color: var(--reg-primary); font-weight: 700;">قائمة الخدمات</a>
            <a href="#tab-agreement-natures" class="nav-tab" style="margin-bottom: -1px;">طبيعة العقد</a>
            <a href="#tab-system-reset" class="nav-tab" style="margin-bottom: -1px; color: #dc2626;">تهيئة النظام (حساس)</a>
        </h2>

        <form method="post" action="">
            <?php wp_nonce_field( 'save_settings', 'olama_reg_settings_nonce' ); ?>
            
            <div class="os-tab-content active" id="tab-services">
                <p style="color: var(--reg-text-muted); margin-bottom: 20px;">
                    قم بإدارة قائمة الخدمات التي تظهر عند إنشاء دفعات مخصصة (مثل المواصلات، الأنشطة، الخ).
                </p>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label>قائمة الخدمات</label></th>
                            <td>
                                <div id="olama-reg-services-list">
                                    <?php foreach ( $services as $srv ): ?>
                                        <div class="olama-reg-service-row" style="margin-bottom: 10px; display: flex; gap: 10px; align-items: center;">
                                            <input type="text" name="custom_services[]" value="<?php echo esc_attr( $srv ); ?>" class="regular-text" required />
                                            <button type="button" class="button olama-reg-remove-service" style="color: #dc2626; border-color: #dc2626;"><span class="dashicons dashicons-trash" style="margin-top: 3px;"></span></button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button" id="olama-reg-add-service" style="margin-top: 10px;">
                                    <span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span> إضافة خدمة جديدة
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="os-tab-content" id="tab-agreement-natures" style="display:none;">
                <p style="color: var(--reg-text-muted); margin-bottom: 20px;">
                    قم بإدارة قائمة طبيعة العقد التي تظهر في شاشة إنشاء عقد جديد.
                </p>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label>طبيعة العقد</label></th>
                            <td>
                                <div id="olama-reg-natures-list">
                                    <?php foreach ( $agreement_natures as $idx => $nature ): ?>
                                        <?php $has_installments = array_key_exists( $nature, $agreement_nature_installments ) ? ! empty( $agreement_nature_installments[ $nature ] ) : true; ?>
                                        <div class="olama-reg-nature-row" style="margin-bottom: 10px; display: flex; gap: 10px; align-items: center;">
                                            <input type="text" name="agreement_natures[<?php echo esc_attr( $idx ); ?>]" value="<?php echo esc_attr( $nature ); ?>" class="regular-text" required />
                                            <label style="display:inline-flex; align-items:center; gap:6px; white-space:nowrap; font-weight:600;">
                                                <input type="checkbox" name="agreement_nature_has_installments[<?php echo esc_attr( $idx ); ?>]" value="1" <?php checked( $has_installments ); ?> />
                                                يدعم الأقساط
                                            </label>
                                            <button type="button" class="button olama-reg-remove-nature" style="color: #dc2626; border-color: #dc2626;"><span class="dashicons dashicons-trash" style="margin-top: 3px;"></span></button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button" id="olama-reg-add-nature" style="margin-top: 10px;">
                                    <span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span> إضافة طبيعة عقد جديدة
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="submit" id="olama-reg-settings-submit-container">
                <button type="submit" class="button button-primary" style="background: var(--reg-primary); border-color: var(--reg-primary);">
                    حفظ التغييرات
                </button>
            </p>
        </form>

        <div class="os-tab-content" id="tab-system-reset" style="display:none; padding-top: 10px;">
            <div style="background: #fef2f2; border-right: 4px solid #ef4444; padding: 15px; border-radius: 6px; margin-bottom: 20px; color: #991b1b;">
                <h3 style="margin-top: 0; color: #991b1b; font-weight: 700;"><span class="dashicons dashicons-warning" style="margin-top: 4px; vertical-align: middle; margin-left: 5px;"></span>تنبيه هام جداً وحساس</h3>
                <p style="margin: 0; font-size: 14px; line-height: 1.6;">
                    هذه الأداة مخصصة لمسح البيانات التجريبية وتهيئة النظام قبل الانتقال إلى خادم الإنتاج الفعلي (Go Live). 
                    <strong>مسح البيانات عملية نهائية ولا يمكن التراجع عنها أو استعادة البيانات المحذوفة نهائياً.</strong>
                </p>
            </div>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                <h4 style="margin-top: 0; margin-bottom: 15px; color: #334155; border-bottom: 1px solid #cbd5e1; padding-bottom: 10px;">حدد البيانات المطلوب مسحها وإعادة تهيئتها:</h4>
                
                <div style="margin-bottom: 15px;">
                    <label style="display: inline-flex; align-items: center; cursor: pointer; font-weight: 600; color: #1e293b;">
                        <input type="checkbox" id="reset-transactions" class="reset-checkbox" checked style="margin-left: 8px; width: 18px; height: 18px; cursor: pointer;" />
                        البيانات المالية والمعاملات والسندات
                    </label>
                    <p style="margin: 4px 26px 0 0; color: #64748b; font-size: 13px;">
                        يمسح كافة الفواتير، بنود الفواتير، الأقساط، سندات القبض، المدفوعات المخصصة، إيصالات التسوية، الحركات المالية، وقيود الاستحقاق المالي للعائلات، ويعيد أرصدة الحسابات المالية إلى صفر.
                    </p>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: inline-flex; align-items: center; cursor: pointer; font-weight: 600; color: #1e293b;">
                        <input type="checkbox" id="reset-agreements" class="reset-checkbox" checked style="margin-left: 8px; width: 18px; height: 18px; cursor: pointer;" />
                        بيانات العقود بنوعيها والرسوم والبنود المرتبطة بها
                    </label>
                    <p style="margin: 4px 26px 0 0; color: #64748b; font-size: 13px;">
                        يمسح جميع العقود المبرمة للطلاب وعقود الخدمات والأنشطة بالإضافة لرسوم العقود وبنودها.
                    </p>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: inline-flex; align-items: center; cursor: pointer; font-weight: 600; color: #1e293b;">
                        <input type="checkbox" id="reset-customers" class="reset-checkbox" checked style="margin-left: 8px; width: 18px; height: 18px; cursor: pointer;" />
                        بيانات جهات الاتصال والعملاء الإضافيين وأولادهم
                    </label>
                    <p style="margin: 4px 26px 0 0; color: #64748b; font-size: 13px;">
                        يمسح جهات الاتصال المسجلين وأولادهم التابعين لقسم المبيعات/الخدمات الإضافية.
                    </p>
                </div>

                <div style="margin-bottom: 5px; border-top: 1px dashed #cbd5e1; padding-top: 15px; margin-top: 15px;">
                    <label style="display: inline-flex; align-items: center; cursor: pointer; font-weight: 600; color: #dc2626;">
                        <input type="checkbox" id="reset-templates" class="reset-checkbox" style="margin-left: 8px; width: 18px; height: 18px; cursor: pointer;" />
                        حذف قوالب الإعدادات الأساسية (اختياري - غير مستحسن)
                    </label>
                    <p style="margin: 4px 26px 0 0; color: #64748b; font-size: 13px;">
                        يمسح قوالب الرسوم الدراسية، قوالب العقود المعدة مسبقاً، وبنك الشروط والبنود القانونية العامة.
                    </p>
                </div>
            </div>

            <div style="background: #fff; border: 1px solid #dc2626; padding: 20px; border-radius: 8px; margin-bottom: 10px;">
                <h4 style="margin-top: 0; margin-bottom: 10px; color: #dc2626; font-weight: 700;">قفل الأمان والتأكيد</h4>
                <p style="margin: 0 0 15px 0; color: #475569; font-size: 14px;">
                    لتجنب مسح البيانات عن طريق الخطأ، يرجى كتابة الكلمة التأكيدية <strong style="color: #dc2626; font-size: 16px; font-family: monospace; padding: 0 4px;">RESET</strong> في الحقل أدناه لتفعيل زر المسح:
                </p>
                <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <input type="text" id="confirm-reset-input" placeholder="اكتب RESET هنا..." class="regular-text" style="text-align: center; font-weight: bold; letter-spacing: 2px; border-color: #cbd5e1; height: 40px; font-size: 16px;" />
                    <button type="button" id="olama-reg-start-reset" class="button button-primary" style="background: #dc2626; border-color: #dc2626; padding: 5px 25px; height: 40px; font-size: 15px; font-weight: bold; display: inline-flex; align-items: center;" disabled>
                        بدء تهيئة النظام والمسح
                    </button>
                </div>
                <div id="reset-status-message" style="margin-top: 15px; display: none; padding: 12px; border-radius: 6px; font-size: 14px; line-height: 1.6;"></div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Tabs switching logic
    $('.os-nav-tabs .nav-tab').on('click', function(e) {
        e.preventDefault();
        $('.os-nav-tabs .nav-tab').removeClass('nav-tab-active').css({'background': '', 'border-bottom-color': '', 'color': '', 'font-weight': ''});
        $(this).addClass('nav-tab-active').css({'background': '#fff', 'border-bottom-color': '#fff', 'color': 'var(--reg-primary)', 'font-weight': '700'});
        
        $('.os-tab-content').hide().removeClass('active');
        $($(this).attr('href')).show().addClass('active');

        // Hide settings submit container if the System Reset tab is active
        if ($(this).attr('href') === '#tab-system-reset') {
            $('#olama-reg-settings-submit-container').hide();
        } else {
            $('#olama-reg-settings-submit-container').show();
        }
    });

    // System Reset validation lock
    $('#confirm-reset-input').on('input keyup', function() {
        const val = $(this).val().trim();
        if (val.toUpperCase() === 'RESET') {
            $('#olama-reg-start-reset').prop('disabled', false);
        } else {
            $('#olama-reg-start-reset').prop('disabled', true);
        }
    });

    // System Reset execution
    $('#olama-reg-start-reset').on('click', function(e) {
        e.preventDefault();

        if (!confirm('🚨 هل أنت متأكد تماماً من رغبتك في مسح كافة البيانات المحددة؟ لا يمكن التراجع عن هذه الخطوة أبداً!')) {
            return;
        }

        const btn = $(this);
        const statusMsg = $('#reset-status-message');

        btn.prop('disabled', true).text('جاري تهيئة النظام...');
        statusMsg.hide().css({'background': '#f1f5f9', 'color': '#334155', 'border-right': '4px solid #94a3b8'}).html('جاري معالجة طلب مسح البيانات... يرجى الانتظار ولا تغلق الصفحة.').show();

        $.ajax({
            url: olamaReg.ajaxurl,
            type: 'POST',
            data: {
                action: 'olama_reg_reset_system',
                nonce: olamaReg.nonce,
                confirm_reset: $('#confirm-reset-input').val(),
                reset_transactions: $('#reset-transactions').is(':checked') ? 1 : 0,
                reset_agreements: $('#reset-agreements').is(':checked') ? 1 : 0,
                reset_customers: $('#reset-customers').is(':checked') ? 1 : 0,
                reset_templates: $('#reset-templates').is(':checked') ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    statusMsg.css({'background': '#f0fdf4', 'color': '#166534', 'border-right': '4px solid #22c55e'}).html(response.data.message);
                    $('#confirm-reset-input').val('');
                    btn.text('تم المسح بنجاح!').prop('disabled', true);
                } else {
                    statusMsg.css({'background': '#fef2f2', 'color': '#991b1b', 'border-right': '4px solid #ef4444'}).html(response.data.message || 'حدث خطأ غير متوقع أثناء معالجة مسح البيانات.');
                    btn.text('بدء تهيئة النظام والمسح').prop('disabled', false);
                }
            },
            error: function() {
                statusMsg.css({'background': '#fef2f2', 'color': '#991b1b', 'border-right': '4px solid #ef4444'}).html('حدث خطأ في الاتصال بالخادم. يرجى المحاولة لاحقاً.');
                btn.text('بدء تهيئة النظام والمسح').prop('disabled', false);
            }
        });
    });

    $('#olama-reg-add-service').on('click', function() {
        const row = `
            <div class="olama-reg-service-row" style="margin-bottom: 10px; display: flex; gap: 10px; align-items: center;">
                <input type="text" name="custom_services[]" value="" class="regular-text" required placeholder="اسم الخدمة..." />
                <button type="button" class="button olama-reg-remove-service" style="color: #dc2626; border-color: #dc2626;"><span class="dashicons dashicons-trash" style="margin-top: 3px;"></span></button>
            </div>
        `;
        $('#olama-reg-services-list').append(row);
    });

    $(document).on('click', '.olama-reg-remove-service', function() {
        if ( $('.olama-reg-service-row').length > 1 ) {
            $(this).closest('.olama-reg-service-row').remove();
        } else {
            alert('يجب أن تحتوي القائمة على خدمة واحدة على الأقل.');
        }
    });

    $('#olama-reg-add-nature').on('click', function() {
        const rowKey = 'new_' + Date.now();
        const row = `
            <div class="olama-reg-nature-row" style="margin-bottom: 10px; display: flex; gap: 10px; align-items: center;">
                <input type="text" name="agreement_natures[${rowKey}]" value="" class="regular-text" required placeholder="طبيعة العقد..." />
                <label style="display:inline-flex; align-items:center; gap:6px; white-space:nowrap; font-weight:600;">
                    <input type="checkbox" name="agreement_nature_has_installments[${rowKey}]" value="1" checked />
                    يدعم الأقساط
                </label>
                <button type="button" class="button olama-reg-remove-nature" style="color: #dc2626; border-color: #dc2626;"><span class="dashicons dashicons-trash" style="margin-top: 3px;"></span></button>
            </div>
        `;
        $('#olama-reg-natures-list').append(row);
    });

    $(document).on('click', '.olama-reg-remove-nature', function() {
        if ( $('.olama-reg-nature-row').length > 1 ) {
            $(this).closest('.olama-reg-nature-row').remove();
        } else {
            alert('يجب أن تحتوي القائمة على قيمة واحدة على الأقل.');
        }
    });

});
</script>
