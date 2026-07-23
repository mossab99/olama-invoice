<?php
/**
 * View: Agreement Edit/Create
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$id = (int) ($_GET['id'] ?? 0);
$is_new = empty($id);
$is_hub_embedded = sanitize_key($_GET['embedded'] ?? '') === 'hub';
$agreement = null;
$is_context_locked = false;
$has_financial_impact = false;
$can_edit_financial_fields = true;
$can_reschedule_installments = true;
$can_create_amendment = false;
$financial_status = 'open';
$lock_reasons = [];
$academic_year_end_date = class_exists('Olama_Reg_Agreement_Invoice') ? Olama_Reg_Agreement_Invoice::get_active_academic_year_end_date() : '';
$contract_templates = Olama_Reg_Agreement_Templates::get_list([ 'is_active' => 1 ]);
$default_contract_template = Olama_Reg_Agreement_Templates::get_default();

if (!$is_new) {
    $agreement = Olama_Reg_Agreement::get($id);
    if (!$agreement) {
        wp_die(__('العقد غير موجود.', 'olama-registration'));
    }
    if (class_exists('Olama_Reg_Agreement_Policy')) {
        $financial_status = Olama_Reg_Agreement_Policy::get_financial_status($id);
        $financial_edit = Olama_Reg_Agreement_Policy::can_edit_financial_fields($id);
        $schedule_edit = Olama_Reg_Agreement_Policy::can_reschedule_installments($id);
        $amendment_create = Olama_Reg_Agreement_Policy::can_create_amendment($id);
        $can_edit_financial_fields = !is_wp_error($financial_edit);
        $can_reschedule_installments = !is_wp_error($schedule_edit);
        $can_create_amendment = !is_wp_error($amendment_create);
        $has_financial_impact = !$can_edit_financial_fields;
        $lock_reasons = Olama_Reg_Agreement_Policy::get_lock_reasons($id);
    } else {
        global $wpdb;
        $has_financial_impact = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}olama_invoices WHERE agreement_id = %d AND status != 'cancelled'",
            $id
        )) > 0;
        $can_edit_financial_fields = !$has_financial_impact;
        $can_reschedule_installments = !$has_financial_impact;
    }
    if ($has_financial_impact) {
        $is_context_locked = true;
    }
} else {
    // Defaults for new
    $payer_type = sanitize_text_field($_GET['payer_type'] ?? 'customer');
    $payer_uid = sanitize_text_field($_GET['payer_uid'] ?? '');
    $payer_id = '';
    $payer_name = '';

    if (!empty($payer_uid)) {
        global $wpdb;
        if ($payer_type === 'customer') {
            $payer_row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, customer_name FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s OR id = %d LIMIT 1",
                $payer_uid,
                is_numeric($payer_uid) ? intval($payer_uid) : 0
            ));
            if ($payer_row) {
                $payer_id = $payer_row->id;
                $payer_name = $payer_row->customer_name;
                $is_context_locked = true;
            }
        } elseif ($payer_type === 'family') {
            $payer_row = Olama_Reg_Core_Gateway::family($payer_uid);
            if ($payer_row) {
                $payer_id = $payer_row->family_uid;
                $payer_name = $payer_row->display_name;
                $is_context_locked = true;
            }
        }
    }

    $agreement = (object) [
        'id' => 0,
        'agreement_number' => __('تلقائي', 'olama-registration'),
        'payer_type' => $payer_type,
        'payer_id' => $payer_id,
        'participant_type' => ($payer_type === 'family') ? 'student' : 'child',
        'participant_id' => '',
        'activity_type' => '',
        'academic_year_id' => 0,
        'start_date' => current_time('Y-m-d'),
        'end_date' => $academic_year_end_date,
        'status' => 'draft',
        'notes' => '',
        'total_amount' => 0,
        'payer_name' => $payer_name,
        'participant_name' => '',
        'template_id' => $default_contract_template ? (int) $default_contract_template->id : 0,
    ];
}

$payer_children_json = '[]';
$payer_children = [];
if ($agreement && $agreement->payer_id) {
    global $wpdb;
    if ($agreement->payer_type === 'customer' && is_numeric($agreement->payer_id)) {
        $table = $wpdb->prefix . 'olama_customer_children';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, child_name AS text FROM {$table} WHERE customer_id = %d AND is_active = 1", (int) $agreement->payer_id));
        foreach ($rows as $r) {
            $payer_children[] = ['id' => (int) $r->id, 'text' => $r->text];
        }
    } elseif ($agreement->payer_type === 'family' && $agreement->payer_id) {
        $family_reference = (string) ($agreement->family_uid ?? $agreement->payer_ref ?? $agreement->payer_id);
        $academic_year_id = (int) ($agreement->academic_year_id ?? 0);
        foreach (Olama_Reg_Core_Gateway::students_for_family($family_reference, $academic_year_id) as $student) {
            $payer_children[] = [
                'id'   => $student->student_uid,
                'text' => $student->display_name,
            ];
        }
    }
    $payer_children_json = wp_json_encode($payer_children);
}

$status_labels = [
    'draft' => __('مسودة', 'olama-registration'),
    'completed' => __('مكتمل', 'olama-registration'),
    'cancelled' => __('ملغي', 'olama-registration'),
    'open' => __('مفتوح', 'olama-registration'),
    'invoiced' => __('مفوتر', 'olama-registration'),
    'unpaid' => __('غير مدفوع', 'olama-registration'),
    'paid' => __('مدفوع', 'olama-registration'),
    'partially_paid' => __('مدفوع جزئياً', 'olama-registration'),
    'partial' => __('مدفوع جزئياً', 'olama-registration'),
    'issued' => __('مصدر', 'olama-registration'),
    'posted' => __('مرحل', 'olama-registration'),
    'approved' => __('معتمد', 'olama-registration'),
    'pending_approval' => __('بانتظار الاعتماد', 'olama-registration'),
    'overdue' => __('متأخر', 'olama-registration'),
    'financially_locked' => __('مقفل مالياً', 'olama-registration'),
    'cancelled_with_financial_impact' => __('ملغي مع أثر مالي', 'olama-registration'),
    'correction_error' => __('تصحيح خطأ', 'olama-registration'),
    'discount_change' => __('تعديل خصم', 'olama-registration'),
    'increase_amount' => __('زيادة مبلغ', 'olama-registration'),
    'decrease_amount' => __('خفض مبلغ', 'olama-registration'),
    'add_fee' => __('إضافة رسم', 'olama-registration'),
    'cash' => __('نقدي', 'olama-registration'),
    'cheque' => __('شيك', 'olama-registration'),
    'bank_transfer' => __('تحويل بنكي', 'olama-registration'),
    'online' => __('دفع إلكتروني', 'olama-registration'),
];
$status_label = static function ($status) use ($status_labels) {
    $status = (string) $status;
    return $status_labels[$status] ?? $status;
};
$money = static function ($amount, $decimals = 3) {
    return number_format((float) $amount, $decimals) . ' JD';
};

$invoice_id = 0;
$invoice = null;
$fees = [];
$templates = [];
$due_schedule = [];
$due_total = 0.0;
$paid_total = 0.0;
$invoiced_total = 0.0;
$remaining_total = 0.0;
$payments = [];
$amendments = [];
$activity_rows = [];
$has_unpaid = false;
$unpaid_fee_ids = [];
$total_discount = 0.0;
$due_schedule_saved = false;
$can_complete_from_workspace = false;
$can_pay_from_workspace = false;

if (!$is_new) {
    $invoice_id = class_exists('Olama_Reg_Agreement_Policy') ? Olama_Reg_Agreement_Policy::get_linked_invoice_id($id) : 0;
    if ($invoice_id > 0 && class_exists('Olama_Reg_Billing_Invoice')) {
        $invoice = Olama_Reg_Billing_Invoice::get_invoice($invoice_id);
    }
    $fees = class_exists('Olama_Reg_Agreement_Fees') ? Olama_Reg_Agreement_Fees::get_by_agreement($id) : [];
    $templates = class_exists('Olama_Reg_Billing_Fees') ? Olama_Reg_Billing_Fees::get_agreement_templates($agreement->activity_type) : [];
    foreach ($fees as $fee_row) {
        $total_discount += (float) $fee_row->discount;
        if ($fee_row->paid_status === 'unpaid') {
            $has_unpaid = true;
            $unpaid_fee_ids[] = (int) $fee_row->id;
        }
    }
    if (class_exists('Olama_Reg_Agreement_Invoice')) {
        $due_schedule = Olama_Reg_Agreement_Invoice::get_due_schedule($id);
        if (empty($due_schedule) && (float) $agreement->total_amount > 0) {
            Olama_Reg_Agreement_Invoice::generate_default_due_schedule($id);
            $due_schedule = Olama_Reg_Agreement_Invoice::get_due_schedule($id);
        }
    }
    foreach ($due_schedule as $line) {
        $due_total += (float) $line->amount_due;
    }
    $due_schedule_saved = !empty($due_schedule) && abs(round((float) $agreement->total_amount, 2) - round($due_total, 2)) <= 0.009;
    if ($invoice) {
        $invoiced_total = (float) ($invoice->total ?? $invoice->amount_due ?? $agreement->total_amount);
        $paid_total = (float) ($invoice->amount_paid ?? 0);
        $remaining_total = (float) ($invoice->balance ?? max(0, $invoiced_total - $paid_total));
        if (class_exists('Olama_Reg_Billing_Payment')) {
            $payments = Olama_Reg_Billing_Payment::get_invoice_payments((int) $invoice->id);
        }
    } else {
        $remaining_total = (float) $agreement->total_amount;
    }
    $amendments = class_exists('Olama_Reg_Agreement_Amendment') ? Olama_Reg_Agreement_Amendment::get_by_agreement($id) : [];
    $activity_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT a.*, u.display_name
         FROM {$wpdb->prefix}olama_billing_audit a
         LEFT JOIN {$wpdb->users} u ON u.ID = a.actor_id
         WHERE (a.entity_type = 'agreement' AND a.entity_id = %d)
            OR (a.entity_type = 'agreement_amendment' AND (
                a.entity_id IN (SELECT id FROM {$wpdb->prefix}olama_agreement_amendments WHERE agreement_id = %d)
                OR a.entity_id = %d
            ))
         ORDER BY a.id DESC
         LIMIT 30",
        $id, $id, $id
    )) ?: [];

    $can_complete_from_workspace = $due_schedule_saved && !in_array($agreement->status, ['completed', 'cancelled'], true);
    $can_pay_from_workspace = $due_schedule_saved && $invoice_id > 0 && $invoice && (float) $invoice->balance > 0;
}
?>
<script>
window.payerChildren = <?php echo $payer_children_json; ?>;
</script>
<div class="olama-reg-wrap os-wrap<?php echo $is_hub_embedded ? ' os-hub-embedded-form' : ''; ?>" id="os-agreement-app" data-id="<?php echo esc_attr($agreement->id); ?>" data-header-saved="<?php echo esc_attr(!$is_new && (int) $agreement->id > 0 ? '1' : '0'); ?>" data-can-edit-financial="<?php echo esc_attr($can_edit_financial_fields ? '1' : '0'); ?>" data-can-reschedule="<?php echo esc_attr($can_reschedule_installments ? '1' : '0'); ?>" data-can-create-amendment="<?php echo esc_attr($can_create_amendment ? '1' : '0'); ?>" data-financial-status="<?php echo esc_attr($financial_status); ?>" data-due-saved="<?php echo esc_attr($due_schedule_saved ? '1' : '0'); ?>" data-has-invoice="<?php echo esc_attr($invoice_id > 0 ? '1' : '0'); ?>" data-embedded="<?php echo esc_attr($is_hub_embedded ? 'hub' : 'page'); ?>">
    <?php if (!$is_hub_embedded && !$is_new): ?>
        <?php echo Olama_Reg_Agreement_Renderer::render_agreement_card($id, 'admin'); ?>
    <?php endif; ?>
    <?php if (!$is_hub_embedded): ?>
    <div class="olama-reg-page-header">
        <div style="display:flex; align-items:center; gap:15px;">
            <h1 class="wp-heading-inline" style="margin:0;">
                <?php echo $is_new ? esc_html__('إضافة عقد جديد', 'olama-registration') : esc_html__('تعديل العقد', 'olama-registration') . ' #' . esc_html($agreement->agreement_number); ?>
            </h1>
            <span
                class="olama-reg-badge <?php echo $agreement->status === 'completed' ? 'olama-reg-badge--active' : ($agreement->status === 'draft' ? 'olama-reg-badge--warning' : 'olama-reg-badge--inactive'); ?>"
                id="os-agr-status-badge-legacy">
                <?php echo esc_html($agreement->status); ?>
            </span>
        </div>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-registration-agreements' ) ); ?>" class="olama-reg-back-btn">
            <span class="dashicons dashicons-arrow-right-alt2"></span> <?php esc_html_e('العودة للقائمة', 'olama-registration'); ?>
        </a>
    </div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper wp-clearfix os-nav-tabs" style="margin-bottom: 20px; display:none;">
        <a href="#tab-header"
            class="nav-tab nav-tab-active"><?php esc_html_e('البيانات الأساسية', 'olama-registration'); ?></a>
        <a href="#tab-fees"
            class="nav-tab <?php echo $is_new ? 'os-disabled' : ''; ?>"><?php esc_html_e('الرسوم', 'olama-registration'); ?></a>
    </nav>

    <nav class="nav-tab-wrapper wp-clearfix os-nav-tabs olama-contract-tabs">
        <a href="#tab-header" class="nav-tab nav-tab-active"><?php esc_html_e('ملخص العقد', 'olama-registration'); ?></a>
        <a href="#tab-data" class="nav-tab"><?php esc_html_e('بيانات العقد', 'olama-registration'); ?></a>
        <a href="#tab-fees" class="nav-tab <?php echo $is_new ? 'os-disabled' : ''; ?>"><?php esc_html_e('الرسوم', 'olama-registration'); ?></a>
        <a href="#tab-installments" class="nav-tab <?php echo $is_new ? 'os-disabled' : ''; ?>"><?php esc_html_e('الأقساط والاستحقاقات', 'olama-registration'); ?></a>
        <a href="#tab-payments" class="nav-tab <?php echo $is_new ? 'os-disabled' : ''; ?>"><?php esc_html_e('الدفعات والسندات', 'olama-registration'); ?></a>
        <a href="#tab-adjustments" class="nav-tab <?php echo $is_new ? 'os-disabled' : ''; ?>"><?php esc_html_e('التعديلات المالية', 'olama-registration'); ?></a>
        <a href="#tab-activity" class="nav-tab <?php echo $is_new ? 'os-disabled' : ''; ?>"><?php esc_html_e('سجل النشاط', 'olama-registration'); ?></a>
    </nav>

    <div class="os-tab-content active" id="tab-header">
        <div class="olama-reg-section olama-contract-panel">
            <?php if ($has_financial_impact): ?>
                <div class="olama-lock-alert">
                    <strong><?php esc_html_e('هذا العقد مقفل مالياً للتعديل المباشر.', 'olama-registration'); ?></strong>
                    <p><?php esc_html_e('توجد فواتير أو دفعات مرتبطة بالعقد. لا يمكن تعديل البنود المالية مباشرة. يمكن إجراء التعديلات من خلال إنشاء تعديل مالي مستقل.', 'olama-registration'); ?></p>
                </div>
            <?php endif; ?>
            <div class="olama-summary-grid">
                <div>
                    <h3><?php esc_html_e('معلومات العقد', 'olama-registration'); ?></h3>
                    <dl class="olama-contract-dl">
                        <dt><?php esc_html_e('رقم العقد', 'olama-registration'); ?></dt><dd><?php echo esc_html($agreement->agreement_number); ?></dd>
                        <dt><?php esc_html_e('حالة العقد', 'olama-registration'); ?></dt><dd><?php echo esc_html($status_label($agreement->status)); ?></dd>
                        <dt><?php esc_html_e('الطالب / المشترك', 'olama-registration'); ?></dt><dd><?php echo esc_html($agreement->participant_name ?: '-'); ?></dd>
                        <dt><?php esc_html_e('الجهة الدافعة', 'olama-registration'); ?></dt><dd><?php echo esc_html($agreement->payer_name ?: '-'); ?></dd>
                        <dt><?php esc_html_e('طبيعة العقد', 'olama-registration'); ?></dt><dd><?php echo esc_html($agreement->activity_type ?: '-'); ?></dd>
                        <dt><?php esc_html_e('الفترة', 'olama-registration'); ?></dt><dd><?php echo esc_html(($agreement->start_date ?: '-') . ' - ' . ($agreement->end_date ?: '-')); ?></dd>
                    </dl>
                </div>
                <div>
                    <h3><?php esc_html_e('آخر الحركات', 'olama-registration'); ?></h3>
                    <ol class="olama-activity-mini">
                        <?php if (!empty($activity_rows)): ?>
                            <?php foreach (array_slice($activity_rows, 0, 3) as $activity): ?>
                                <li><strong><?php echo esc_html($activity->action); ?></strong><span><?php echo esc_html($activity->created_at ?? ''); ?></span></li>
                            <?php endforeach; ?>
                        <?php elseif (!$is_new): ?>
                            <li><strong><?php esc_html_e('تم إنشاء العقد', 'olama-registration'); ?></strong><span><?php echo esc_html($agreement->created_at ?? ''); ?></span></li>
                        <?php endif; ?>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="os-tab-content" id="tab-data" style="display:none;">
        <div class="olama-reg-section">
            <form id="os-form-agreement-header" style="margin:0;">
                <input type="hidden" name="id" value="<?php echo esc_attr($agreement->id); ?>">
                
                <h3 class="olama-reg-section-title"><?php esc_html_e('البيانات الأساسية', 'olama-registration'); ?></h3>
                <?php if (!$can_edit_financial_fields): ?>
                    <div class="notice notice-warning inline" style="padding:10px 14px; margin:0 0 14px;">
                        <p style="margin:0 0 8px;"><strong><?php esc_html_e('العقد مقفل مالياً للتعديل المباشر.', 'olama-registration'); ?></strong></p>
                        <?php if (!empty($lock_reasons)): ?>
                            <ul style="margin:0 18px 0 0; list-style:disc;">
                                <?php foreach ($lock_reasons as $reason): ?>
                                    <li><?php echo esc_html($reason); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php if ($can_create_amendment && (current_user_can('manage_options') || current_user_can('olama_create_agreement_amendment'))): ?>
                            <p style="margin:12px 0 0;">
                                <button type="button" class="button button-primary" id="os-agr-create-amendment-inline" data-action="add-fee"><?php esc_html_e('تعديل مالي على العقد', 'olama-registration'); ?></button>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="olama-reg-grid">
                    <div class="olama-reg-field olama-reg-field--required olama-reg-field--wide">
                        <label for="os-agr-contract-template"><?php esc_html_e('نموذج العقد', 'olama-registration'); ?></label>
                        <select name="template_id" id="os-agr-contract-template" style="width:100%;" required <?php disabled($has_financial_impact); ?>>
                            <option value=""><?php esc_html_e('اختر نموذج العقد', 'olama-registration'); ?></option>
                            <?php foreach ($contract_templates as $contract_template): ?>
                                <option
                                    value="<?php echo esc_attr((int) $contract_template->id); ?>"
                                    data-activity="<?php echo esc_attr($contract_template->activity_type); ?>"
                                    <?php selected((int) $agreement->template_id, (int) $contract_template->id); ?>>
                                    <?php echo esc_html($contract_template->name . ' — الإصدار ' . (int) $contract_template->version); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p style="margin:4px 0 0;color:#667085;"><?php esc_html_e('سيتم تثبيت نسخة مستقلة من هذا النموذج عند إكمال العقد.', 'olama-registration'); ?></p>
                        <?php if ($has_financial_impact): ?>
                            <input type="hidden" name="template_id" value="<?php echo esc_attr((int) $agreement->template_id); ?>">
                        <?php endif; ?>
                    </div>

                    <div class="olama-reg-field olama-reg-field--required">
                        <label><?php esc_html_e('نوع الجهة الدافعة', 'olama-registration'); ?></label>
                        <div style="margin-top:8px;">
                            <label style="margin-left: 20px;">
                                <input type="radio" name="payer_type" value="customer" <?php checked($agreement->payer_type, 'customer'); ?> <?php disabled($is_context_locked); ?>>
                                <?php esc_html_e('عميل (Walk-in)', 'olama-registration'); ?>
                            </label>
                            <label>
                                <input type="radio" name="payer_type" value="family" <?php checked($agreement->payer_type, 'family'); ?> <?php disabled($is_context_locked); ?>>
                                <?php esc_html_e('عائلة (مدرسة)', 'olama-registration'); ?>
                            </label>
                            <?php if ($is_context_locked): ?>
                                <input type="hidden" name="payer_type" value="<?php echo esc_attr($agreement->payer_type); ?>">
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="olama-reg-field olama-reg-field--required">
                        <label><?php esc_html_e('الجهة الدافعة', 'olama-registration'); ?></label>
                        <select name="payer_id" id="os-agr-payer" style="width: 100%;" required <?php disabled($is_context_locked); ?>>
                            <?php if ($agreement->payer_id): ?>
                                <option value="<?php echo esc_attr($agreement->payer_id); ?>" selected="selected">
                                    <?php echo esc_html($agreement->payer_name); ?></option>
                            <?php endif; ?>
                        </select>
                        <?php if ($is_context_locked): ?>
                            <input type="hidden" name="payer_id" value="<?php echo esc_attr($agreement->payer_id); ?>">
                        <?php endif; ?>
                    </div>

                    <div class="olama-reg-field olama-reg-field--required">
                        <label><?php esc_html_e('طبيعة العقد', 'olama-registration'); ?></label>
                        <select name="activity_type" id="os-agr-activity" style="width: 100%;" required <?php disabled($has_financial_impact); ?>>
                            <option value=""><?php esc_html_e('اختر طبيعة العقد', 'olama-registration'); ?></option>
                            <?php
                            $agreement_natures = get_option( 'olama_reg_agreement_natures', ['عقد مدرسة', 'عقد روضة', 'عقد نادي صيفي', 'رحلة مدرسية'] );
                            $agreement_nature_installments = get_option( 'olama_reg_agreement_nature_installments', [] );
                            if ( ! is_array( $agreement_nature_installments ) ) {
                                $agreement_nature_installments = [];
                            }
                            foreach ($agreement_natures as $nature) {
                                $has_installments = array_key_exists( $nature, $agreement_nature_installments ) ? ! empty( $agreement_nature_installments[ $nature ] ) : true;
                                echo '<option value="' . esc_attr($nature) . '" data-has-installments="' . ( $has_installments ? '1' : '0' ) . '" ' . selected($agreement->activity_type, $nature, false) . '>' . esc_html($nature) . '</option>';
                            }
                            // To support legacy values that are not in the current settings list
                            if (!empty($agreement->activity_type) && !in_array($agreement->activity_type, $agreement_natures)) {
                                $legacy_label = $agreement->activity_type;
                                if ($agreement->activity_type === 'kindergarten') $legacy_label = 'رياض الأطفال';
                                elseif ($agreement->activity_type === 'summer_club') $legacy_label = 'النادي الصيفي';
                                elseif ($agreement->activity_type === 'karate') $legacy_label = 'كاراتيه';
                                echo '<option value="' . esc_attr($agreement->activity_type) . '" selected>' . esc_html($legacy_label) . '</option>';
                            }
                            ?>
                        </select>
                        <?php if ($has_financial_impact): ?>
                            <input type="hidden" name="activity_type" value="<?php echo esc_attr($agreement->activity_type); ?>">
                        <?php endif; ?>
                    </div>

                    <div class="olama-reg-field olama-reg-field--required">
                        <label><?php esc_html_e('تاريخ البداية', 'olama-registration'); ?></label>
                        <input type="text" name="start_date" class="os-datepicker" value="<?php echo esc_attr($agreement->start_date); ?>" required style="width:100%;" <?php disabled($has_financial_impact); ?>>
                        <?php if ($has_financial_impact): ?>
                            <input type="hidden" name="start_date" value="<?php echo esc_attr($agreement->start_date); ?>">
                        <?php endif; ?>
                    </div>

                    <div class="olama-reg-field">
                        <label><?php esc_html_e('تاريخ النهاية', 'olama-registration'); ?> <span style="font-weight:normal; font-size:12px; color:#999;">(اختياري)</span></label>
                        <input type="text" name="end_date" class="os-datepicker" value="<?php echo esc_attr($agreement->end_date); ?>" style="width:100%;" <?php disabled($has_financial_impact); ?>>
                        <?php if ($has_financial_impact): ?>
                            <input type="hidden" name="end_date" value="<?php echo esc_attr($agreement->end_date); ?>">
                        <?php endif; ?>
                    </div>

                    <div class="olama-reg-field olama-reg-field--required">
                        <label><?php esc_html_e('حالة العقد', 'olama-registration'); ?></label>
                        <?php
                        $status_labels = [
                            'draft' => __('مسودة', 'olama-registration'),
                            'completed' => __('مكتمل', 'olama-registration'),
                            'cancelled' => __('ملغى', 'olama-registration'),
                        ];
                        ?>
                        <input type="text" value="<?php echo esc_attr($status_labels[$agreement->status] ?? $agreement->status); ?>" style="width:100%;" readonly>
                    </div>

                    <div class="olama-reg-field" style="grid-column: 1 / -1;">
                        <label><?php esc_html_e('ملاحظات', 'olama-registration'); ?></label>
                        <textarea name="notes" rows="4" style="width: 100%; border:1.5px solid #E0C090; border-radius:6px; padding:8px; font-family:inherit;"><?php echo esc_textarea($agreement->notes); ?></textarea>
                    </div>
                </div>
            </form>

            <div class="olama-reg-form-actions" style="margin-top: 20px; padding: 0 15px;">
                <button type="button" class="olama-reg-btn olama-reg-btn--primary" id="os-btn-save-header-bottom">
                    <span class="dashicons dashicons-saved"></span> <?php esc_html_e('حفظ البيانات', 'olama-registration'); ?>
                </button>
                <span class="spinner" style="float:none;"></span>
            </div>
        </div>
    </div>

    <div class="os-tab-content" id="tab-fees" style="display:none;">
        <?php 
        $invoice_id = 0;
        $invoice = null;
        if (!$is_new) {
            $invoice_id = class_exists('Olama_Reg_Agreement_Policy') ? Olama_Reg_Agreement_Policy::get_linked_invoice_id($id) : 0;
            if ($invoice_id > 0) {
                $invoice = class_exists('Olama_Reg_Billing_Invoice') ? Olama_Reg_Billing_Invoice::get_invoice($invoice_id) : null;
            }
        }
        ?>
        <div class="olama-reg-section">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px; margin-bottom:15px;">
                <h3 class="olama-reg-section-title" style="margin:0;"><?php esc_html_e('جدول الرسوم المستحقة', 'olama-registration'); ?></h3>
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                    <?php if (!$is_new): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=olama-registration-agreements&action=print&id=' . $id)); ?>" target="_blank" class="olama-reg-btn olama-reg-btn--secondary" id="os-btn-print-agreement-top">
                            <span class="dashicons dashicons-printer"></span> <?php esc_html_e('طباعة العقد', 'olama-registration'); ?>
                        </a>
                        <?php if ($invoice_id > 0 && $invoice && (float)$invoice->balance > 0): ?>
                            <button type="button" class="olama-reg-btn olama-reg-btn--primary olama-reg-pay-invoice-trigger os-agr-pay-requires-saved-due" id="os-btn-pay-agreement-top"
                                    data-id="<?php echo esc_attr($invoice->id); ?>"
                                    data-no="<?php echo esc_attr($invoice->invoice_number); ?>"
                                    data-bal="<?php echo esc_attr($invoice->balance); ?>"
                                    data-family="<?php echo esc_attr($invoice->family_uid); ?>">
                                <span class="dashicons dashicons-money-alt"></span> <?php esc_html_e('قبض دفعة', 'olama-registration'); ?>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <button type="button" class="olama-reg-btn olama-reg-btn--secondary"
                        id="os-agr-add-fee-row"><span class="dashicons dashicons-plus"></span> <?php esc_html_e('إضافة بند رسوم', 'olama-registration'); ?></button>
                    <button type="button" class="olama-reg-btn olama-reg-btn--primary" id="os-btn-save-fees-tab">
                        <span class="dashicons dashicons-saved"></span> <?php esc_html_e('حفظ البيانات', 'olama-registration'); ?>
                    </button>
                </div>
            </div>
            <?php if (!$is_new): ?>
            <div id="os-agr-fee-amendment-banner" class="notice notice-warning inline" style="padding:10px 14px; margin:0 0 14px; display:none;">
                <p style="margin:0 0 8px;"><strong><?php esc_html_e('وضع تعديل مالي: يمكنك إضافة بند رسوم جديد فقط.', 'olama-registration'); ?></strong></p>
                <p style="margin:0 0 8px; font-size:12px; color:#666;"><?php esc_html_e('البنود السابقة المرتبطة بالفاتورة لا يمكن تعديلها. أضف البند الجديد ثم انقر حفظ.', 'olama-registration'); ?></p>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:8px;">
                    <input type="text" id="os-agr-fee-amendment-reason" placeholder="<?php esc_attr_e('سبب التعديل', 'olama-registration'); ?>" style="flex:1; min-width:200px; padding:4px 8px; border:1px solid #ddd; border-radius:3px;">
                    <button type="button" class="button" id="os-agr-fee-amendment-cancel"><?php esc_html_e('إلغاء التعديل', 'olama-registration'); ?></button>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($is_new): ?>
                <p style="padding:15px; color:#666;"><?php esc_html_e('الرجاء حفظ البيانات الأساسية أولاً.', 'olama-registration'); ?></p>
            <?php else: ?>
                <div class="olama-reg-table-wrap" style="padding: 15px;">
                    <?php echo Olama_Reg_Agreement_Renderer::render_agreement_fees_table($id, $is_hub_embedded ? 'customer_hub' : 'admin'); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="os-tab-content" id="tab-installments" style="display:none;">
        <div class="olama-reg-section olama-contract-panel">
            <h3 class="olama-reg-section-title"><?php esc_html_e('توزيع الاستحقاق', 'olama-registration'); ?></h3>
            <div class="olama-installment-summary">
                <div class="olama-installment-metric olama-installment-metric--blue">
                    <span class="olama-installment-metric__icon"><span class="dashicons dashicons-clipboard"></span></span>
                    <div class="olama-installment-metric__body">
                        <span class="olama-installment-metric__label"><?php esc_html_e('صافي العقد', 'olama-registration'); ?></span>
                        <span class="olama-installment-metric__value"><?php echo esc_html($money($agreement->total_amount)); ?></span>
                    </div>
                </div>
                <div class="olama-installment-metric olama-installment-metric--green">
                    <span class="olama-installment-metric__icon"><span class="dashicons dashicons-yes-alt"></span></span>
                    <div class="olama-installment-metric__body">
                        <span class="olama-installment-metric__label"><?php esc_html_e('مجموع الاستحقاقات', 'olama-registration'); ?></span>
                        <span class="olama-installment-metric__value"><?php echo esc_html($money($due_total)); ?></span>
                    </div>
                </div>
                <div class="olama-installment-metric olama-installment-metric--orange">
                    <span class="olama-installment-metric__icon"><span class="dashicons dashicons-chart-bar"></span></span>
                    <div class="olama-installment-metric__body">
                        <span class="olama-installment-metric__label"><?php esc_html_e('الفرق', 'olama-registration'); ?></span>
                        <span class="olama-installment-metric__value"><?php echo esc_html($money(((float) $agreement->total_amount) - $due_total)); ?></span>
                    </div>
                </div>
                <div class="olama-installment-metric olama-installment-metric--purple">
                    <span class="olama-installment-metric__icon"><span class="dashicons dashicons-list-view"></span></span>
                    <div class="olama-installment-metric__body">
                        <span class="olama-installment-metric__label"><?php esc_html_e('عدد الأقساط', 'olama-registration'); ?></span>
                        <span class="olama-installment-metric__value"><?php echo esc_html(count($due_schedule)); ?></span>
                    </div>
                </div>
            </div>
            <div class="olama-reg-table-wrap olama-installments-table-wrap">
                <table class="olama-reg-fin-table" id="os-agr-due-table" data-agr-id="<?php echo esc_attr($id); ?>">
                    <colgroup>
                        <col class="olama-installments-col-no">
                        <col class="olama-installments-col-date">
                        <col class="olama-installments-col-amount">
                        <col class="olama-installments-col-money">
                        <col class="olama-installments-col-money">
                        <col class="olama-installments-col-status">
                        <col class="olama-installments-col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th style="width:90px;"><?php esc_html_e('رقم القسط', 'olama-registration'); ?></th>
                            <th><?php esc_html_e('تاريخ الاستحقاق', 'olama-registration'); ?></th>
                            <th><?php esc_html_e('مبلغ القسط', 'olama-registration'); ?></th>
                            <th><?php esc_html_e('المدفوع', 'olama-registration'); ?></th>
                            <th><?php esc_html_e('المتبقي', 'olama-registration'); ?></th>
                            <th><?php esc_html_e('الحالة', 'olama-registration'); ?></th>
                            <th style="width:80px;"><?php esc_html_e('إجراءات', 'olama-registration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $due_table_total = 0.0; ?>
                        <?php foreach ($due_schedule as $line): ?>
                            <?php
                            $amount = (float) $line->amount_due;
                            $paid = (float) $line->amount_paid;
                            $remaining = max(0, $amount - $paid);
                            $due_table_total += $amount;
                            $line_locked = !$can_reschedule_installments || $paid > 0;
                            ?>
                            <tr>
                                <td class="os-agr-due-no"><?php echo esc_html($line->installment_no); ?></td>
                                <td><input type="text" class="os-datepicker os-agr-due-date" value="<?php echo esc_attr($line->due_date); ?>" style="width:100%;" <?php disabled($line_locked); ?>></td>
                                <td><input type="number" step="0.01" min="0.01" class="os-agr-due-amount" value="<?php echo esc_attr(number_format($amount, 2, '.', '')); ?>" style="width:100%;" <?php disabled($line_locked); ?>></td>
                                <td><span class="olama-due-paid-badge<?php echo $paid > 0 ? ' olama-due-paid-badge--has-value' : ''; ?>"><?php echo esc_html(number_format($paid, 2)); ?></span></td>
                                <td><span class="olama-due-remaining-badge<?php echo $remaining > 0 ? ' olama-due-remaining-badge--has-value' : ($remaining == 0 && $paid > 0 ? ' olama-due-remaining-badge--zero' : ''); ?>"><?php echo esc_html(number_format($remaining, 2)); ?></span></td>
                                <td><span class="olama-installment-status olama-installment-status--<?php echo esc_attr($line->status); ?>"><?php echo esc_html($status_label($line->status)); ?></span></td>
                                <td>
                                    <?php if (!$line_locked): ?>
                                        <button type="button" class="button button-small os-agr-delete-due" aria-label="<?php esc_attr_e('حذف القسط', 'olama-registration'); ?>">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="7">
                                <div class="olama-installment-tfoot-totals">
                                    <div class="olama-tfoot-metric olama-tfoot-metric--blue">
                                        <span class="olama-tfoot-metric__label"><?php esc_html_e('صافي العقد', 'olama-registration'); ?></span>
                                        <span class="olama-tfoot-metric__value" id="os-agr-due-net"><?php echo esc_html(number_format((float) $agreement->total_amount, 2)); ?></span>
                                    </div>
                                    <div class="olama-tfoot-metric olama-tfoot-metric--green">
                                        <span class="olama-tfoot-metric__label"><?php esc_html_e('مجموع الاستحقاقات', 'olama-registration'); ?></span>
                                        <span class="olama-tfoot-metric__value" id="os-agr-due-total"><?php echo esc_html(number_format($due_table_total, 2)); ?></span>
                                    </div>
                                    <div class="olama-tfoot-metric olama-tfoot-metric--diff">
                                        <span class="olama-tfoot-metric__label"><?php esc_html_e('الفرق', 'olama-registration'); ?></span>
                                        <span class="olama-tfoot-metric__value" id="os-agr-due-diff"><?php echo esc_html(number_format(((float) $agreement->total_amount) - $due_table_total, 2)); ?></span>
                                    </div>
                                </div>
                                <div id="os-agr-due-warning" style="display:none; color:#b91c1c; margin-top:10px; font-weight:700; background:#fff1f2; border:1px solid #fecaca; border-radius:8px; padding:10px 14px;">
                                    <span class="dashicons dashicons-warning" style="vertical-align:middle; margin-inline-end:6px;"></span><?php esc_html_e('مجموع الاستحقاقات لا يساوي صافي العقد. يرجى تعديل توزيع الاستحقاق قبل الحفظ.', 'olama-registration'); ?>
                                </div>
                            </td>
                        </tr>
                    </tfoot>
                </table>

                <div class="olama-installment-actions">
                    <div class="olama-installment-actions__tools">
                        <label class="olama-count-label">
                            <span class="dashicons dashicons-list-view"></span>
                            <span><?php esc_html_e('عدد الأقساط', 'olama-registration'); ?></span>
                            <input type="number" id="os-agr-due-count" min="1" max="24" value="<?php echo esc_attr( count( $due_schedule ) ?: Olama_Reg_Agreement_Invoice::DEFAULT_INSTALLMENTS ); ?>" <?php disabled(!$can_reschedule_installments); ?>>
                        </label>
                        <button type="button" class="olama-iab olama-iab--ghost" id="os-agr-add-due-row">
                            <span class="dashicons dashicons-plus-alt2"></span>
                            <?php esc_html_e('إضافة قسط', 'olama-registration'); ?>
                        </button>
                        <button type="button" class="olama-iab olama-iab--ghost" id="os-agr-regenerate-due">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('توليد التوزيع', 'olama-registration'); ?>
                        </button>
                        <button type="button" class="olama-iab olama-iab--save" id="os-agr-save-due">
                            <span class="dashicons dashicons-saved"></span>
                            <?php esc_html_e('حفظ التوزيع', 'olama-registration'); ?>
                        </button>
                    </div>
                    <?php
                    $has_unpaid = false;
                    if (!empty($fees)) {
                        foreach ($fees as $f) {
                            if ($f->paid_status === 'unpaid') {
                                $has_unpaid = true;
                                break;
                            }
                        }
                    }
                    ?>
                    <div class="olama-installment-actions__final">
                        <?php if (!$is_new): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=olama-registration-agreements&action=print&id=' . $id)); ?>" target="_blank" class="olama-iab olama-iab--print" id="os-btn-print-agreement-bottom">
                                <span class="dashicons dashicons-printer"></span>
                                <?php esc_html_e('طباعة العقد', 'olama-registration'); ?>
                            </a>
                            <?php if ($invoice_id > 0 && $invoice && (float)$invoice->balance > 0): ?>
                                <button type="button" class="olama-iab olama-iab--pay olama-reg-pay-invoice-trigger os-agr-pay-requires-saved-due" id="os-btn-pay-agreement-bottom"
                                        data-id="<?php echo esc_attr($invoice->id); ?>"
                                        data-no="<?php echo esc_attr($invoice->invoice_number); ?>"
                                        data-bal="<?php echo esc_attr($invoice->balance); ?>"
                                        data-family="<?php echo esc_attr($invoice->family_uid); ?>">
                                    <span class="dashicons dashicons-money-alt"></span>
                                    <?php esc_html_e('قبض دفعة', 'olama-registration'); ?>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($agreement->status !== 'completed' && $agreement->status !== 'cancelled'): ?>
                            <button type="button" class="olama-iab olama-iab--complete os-agr-complete-agreement-trigger" id="os-agr-complete-agreement-bottom" <?php disabled(!$can_complete_from_workspace); ?>>
                                <span class="dashicons dashicons-yes-alt"></span>
                                <?php esc_html_e('إكمال العقد', 'olama-registration'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Hidden template for new row -->
                <table style="display:none;">
                    <tbody id="os-agr-fee-row-template">
                        <tr data-fee-id="0">
                            <td>
                                <select name="fee_category" style="width:100%" class="os-agr-fee-template-select">
                                    <option value=""><?php esc_html_e('اختر نموذج الرسوم', 'olama-registration'); ?></option>
                                    <?php
                                    foreach ($templates as $tpl) {
                                        $total = 0;
                                        $breakdown = [];
                                        foreach ($tpl->items as $it) {
                                            $item_amount = (float) ($it['amount'] ?? 0);
                                            $total += $item_amount;
                                            $breakdown[] = [
                                                'description' => (string) ($it['description'] ?? ''),
                                                'amount'      => $item_amount,
                                            ];
                                        }
                                        echo '<option value="' . esc_attr($tpl->id) . '" data-name="' . esc_attr($tpl->template_name) . '" data-amount="' . esc_attr($total) . '" data-breakdown="' . esc_attr(wp_json_encode($breakdown)) . '" data-subject-type="' . esc_attr($tpl->subject_type ?? 'general') . '" data-subject-value="' . esc_attr($tpl->subject_value ?? '') . '">' . esc_html($tpl->template_name) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                            <td>
                                <select name="child_id" style="width:100%" class="os-agr-fee-child-select">
                                    <option value=""><?php esc_html_e('اختر المشترك', 'olama-registration'); ?></option>
                                    <?php
                                    if (!empty($payer_children)) {
                                        foreach ($payer_children as $child) {
                                            echo '<option value="' . esc_attr($child['id']) . '">' . esc_html($child['text']) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </td>
                            <td class="os-agr-fee-description">
                                <input type="hidden" name="label" value="">
                                <strong class="os-agr-fee-template-name"></strong>
                                <div class="os-agr-fee-breakdown"></div>
                            </td>
                            <td><input type="number" step="0.01" name="amount" value="0.00"
                                    class="os-inline-input os-agr-fee-calc" style="width:100%" readonly></td>
                            <td><input type="number" step="0.01" name="discount" value="0.00"
                                    class="os-inline-input os-agr-fee-calc" style="width:100%"></td>
                            <td><span class="os-agr-fee-net">0.000</span></td>
                            <td><input type="text" name="due_date" class="os-inline-input os-datepicker" style="width:100%">
                            </td>
                            <td>unpaid</td>
                            <td>
                                <button type="button"
                                    class="button button-small os-agr-save-fee"><?php esc_html_e('حفظ', 'olama-registration'); ?></button>
                                <button type="button" class="button button-small os-agr-delete-fee"
                                    style="color:red;">X</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="os-tab-content" id="tab-payments" style="display:none;">
        <div class="olama-reg-section olama-contract-panel">
            <div class="olama-section-toolbar">
                <h3 class="olama-reg-section-title"><?php esc_html_e('الدفعات والسندات', 'olama-registration'); ?></h3>
                <?php if ($invoice_id > 0 && $invoice && (float)$invoice->balance > 0): ?>
                    <button type="button" class="olama-reg-btn olama-reg-btn--primary olama-reg-pay-invoice-trigger os-agr-pay-requires-saved-due" data-id="<?php echo esc_attr($invoice->id); ?>" data-no="<?php echo esc_attr($invoice->invoice_number); ?>" data-bal="<?php echo esc_attr($invoice->balance); ?>" data-family="<?php echo esc_attr($invoice->family_uid); ?>">
                        <span class="dashicons dashicons-money-alt"></span> <?php esc_html_e('قبض دفعة جديدة', 'olama-registration'); ?>
                    </button>
                <?php endif; ?>
            </div>
            <div class="olama-reg-table-wrap">
                <table class="olama-reg-fin-table">
                    <colgroup>
                        <col style="width: 17%;">
                        <col style="width: 17%;">
                        <col style="width: 13%;">
                        <col style="width: 13%;">
                        <col style="width: 16%;">
                        <col style="width: 12%;">
                        <col style="width: 12%;">
                    </colgroup>
                    <thead><tr><th><?php esc_html_e('رقم السند', 'olama-registration'); ?></th><th><?php esc_html_e('التاريخ', 'olama-registration'); ?></th><th><?php esc_html_e('طريقة الدفع', 'olama-registration'); ?></th><th><?php esc_html_e('المبلغ', 'olama-registration'); ?></th><th><?php esc_html_e('الصندوق / البنك', 'olama-registration'); ?></th><th><?php esc_html_e('الحالة', 'olama-registration'); ?></th><th><?php esc_html_e('الإجراءات', 'olama-registration'); ?></th></tr></thead>
                    <tbody>
                        <?php if (!empty($payments)): ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?php echo esc_html($payment->payment_no ?: ('#' . (int) $payment->id)); ?></td>
                                    <td><?php echo esc_html($payment->payment_date ?? $payment->created_at ?? ''); ?></td>
                                    <td><?php echo esc_html($status_label($payment->method ?? '-')); ?></td>
                                    <td><strong><?php echo esc_html($money($payment->amount)); ?></strong></td>
                                    <td><?php echo esc_html($payment->account_name ?? '-'); ?></td>
                                    <td><span class="olama-status-pill olama-status-pill--<?php echo esc_attr($payment->status ?? 'posted'); ?>"><?php echo esc_html($status_label($payment->status ?? 'posted')); ?></span></td>
                                    <td><a class="button button-small" target="_blank" href="<?php echo esc_url(admin_url('admin.php?page=olama-registration-payments&action=print_receipt&id=' . (int) $payment->id)); ?>"><?php esc_html_e('طباعة سند', 'olama-registration'); ?></a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7"><?php esc_html_e('لا توجد دفعات مسجلة على هذا العقد بعد.', 'olama-registration'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="os-tab-content" id="tab-adjustments" style="display:none;">
        <div class="olama-reg-section olama-contract-panel">
            <div class="olama-section-toolbar">
                <h3 class="olama-reg-section-title"><?php esc_html_e('التعديلات المالية', 'olama-registration'); ?></h3>
                <button type="button" class="olama-reg-btn olama-reg-btn--secondary" id="os-agr-create-amendment-legacy" <?php disabled(!$can_create_amendment); ?>>
                    <span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e('إنشاء تعديل مالي', 'olama-registration'); ?>
                </button>
            </div>
            <div class="olama-reg-table-wrap">
                <table class="olama-reg-fin-table os-agr-amendments-table">
                    <colgroup>
                        <col style="width: 17%;">
                        <col style="width: 13%;">
                        <col style="width: 13%;">
                        <col style="width: 11%;">
                        <col style="width: 11%;">
                        <col style="width: 11%;">
                        <col style="width: 11%;">
                        <col style="width: 13%;">
                    </colgroup>
                    <thead><tr><th><?php esc_html_e('رقم التعديل', 'olama-registration'); ?></th><th><?php esc_html_e('النوع', 'olama-registration'); ?></th><th><?php esc_html_e('تاريخ السريان', 'olama-registration'); ?></th><th><?php esc_html_e('القديم', 'olama-registration'); ?></th><th><?php esc_html_e('الجديد', 'olama-registration'); ?></th><th><?php esc_html_e('الفرق', 'olama-registration'); ?></th><th><?php esc_html_e('الحالة', 'olama-registration'); ?></th><th><?php esc_html_e('الإجراءات', 'olama-registration'); ?></th></tr></thead>
                    <tbody>
                        <?php if (!empty($amendments)): ?>
                            <?php foreach ($amendments as $amendment): ?>
                                <tr data-amendment-id="<?php echo esc_attr($amendment->id); ?>">
                                    <td><strong><?php echo esc_html($amendment->amendment_no); ?></strong></td>
                                    <td><?php echo esc_html($status_label($amendment->amendment_type)); ?></td>
                                    <td><?php echo esc_html($amendment->effective_date); ?></td>
                                    <td><?php echo esc_html($money($amendment->old_total)); ?></td>
                                    <td><?php echo esc_html($money($amendment->new_total)); ?></td>
                                    <td><?php echo esc_html($money($amendment->difference_amount)); ?></td>
                                    <td><span class="olama-status-pill olama-status-pill--<?php echo esc_attr($amendment->status); ?>"><?php echo esc_html($status_label($amendment->status)); ?></span></td>
                                    <td class="os-agr-amendments-actions">
                                        <?php if ($amendment->status === 'draft' && (current_user_can('manage_options') || current_user_can('olama_approve_agreement_amendment'))): ?><button type="button" class="button button-small os-agr-approve-amendment"><?php esc_html_e('اعتماد', 'olama-registration'); ?></button><?php endif; ?>
                                        <?php if ($amendment->status === 'approved' && (current_user_can('manage_options') || current_user_can('olama_post_agreement_amendment'))): ?><button type="button" class="button button-small button-primary os-agr-post-amendment"><?php esc_html_e('ترحيل', 'olama-registration'); ?></button><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8"><?php esc_html_e('لا توجد تعديلات مالية مسجلة بعد.', 'olama-registration'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="olama-lock-alert olama-lock-alert--info"><?php esc_html_e('أمثلة على التعديلات المالية: إضافة بند، تخفيض رسوم، زيادة رسوم، خصم لاحق، تعديل تاريخ استحقاق، أو إلغاء عقد. يتم ربط التنفيذ الكامل بمنطق التعديلات المالية الخلفي عند توفره.', 'olama-registration'); ?></div>
        </div>
    </div>

    <div class="os-tab-content" id="tab-activity" style="display:none;">
        <div class="olama-reg-section olama-contract-panel">
            <h3 class="olama-reg-section-title"><?php esc_html_e('سجل النشاط والتدقيق', 'olama-registration'); ?></h3>
            <div class="olama-reg-table-wrap">
                <table class="olama-reg-fin-table">
                    <colgroup>
                        <col style="width: 20%;">
                        <col style="width: 20%;">
                        <col style="width: 25%;">
                        <col style="width: 35%;">
                    </colgroup>
                    <thead><tr><th><?php esc_html_e('التاريخ', 'olama-registration'); ?></th><th><?php esc_html_e('المستخدم', 'olama-registration'); ?></th><th><?php esc_html_e('الإجراء', 'olama-registration'); ?></th><th><?php esc_html_e('التفاصيل', 'olama-registration'); ?></th></tr></thead>
                    <tbody>
                        <?php if (!empty($activity_rows)): ?>
                            <?php 
                            $action_labels = [
                                'created' => __('إنشاء العقد كمسودة', 'olama-registration'),
                                'updated' => __('تعديل بيانات العقد', 'olama-registration'),
                                'completed' => __('تفعيل واعتماد العقد', 'olama-registration'),
                                'cancelled' => __('إلغاء العقد', 'olama-registration'),
                                'status_changed' => __('تغيير حالة العقد', 'olama-registration'),
                                'fee_added' => __('إضافة بند رسوم', 'olama-registration'),
                                'fee_updated' => __('تعديل بند رسوم', 'olama-registration'),
                                'fee_deleted' => __('حذف بند رسوم', 'olama-registration'),
                                'template_applied' => __('تطبيق نموذج الرسوم', 'olama-registration'),
                                'agreement_amendment_created' => __('إنشاء مسودة تعديل مالي', 'olama-registration'),
                                'agreement_amendment_approved' => __('اعتماد مسودة التعديل المالي', 'olama-registration'),
                                'agreement_amendment_posted' => __('ترحيل واعتماد التعديل المالي', 'olama-registration'),
                                'agreement_amendment_cancelled' => __('إلغاء التعديل المالي', 'olama-registration'),
                                'agreement_amendment_rejected' => __('رفض التعديل المالي', 'olama-registration'),
                                'agreement_installments_rescheduled' => __('إعادة توزيع الاستحقاقات', 'olama-registration'),
                            ];
                            ?>
                            <?php foreach ($activity_rows as $activity): ?>
                                <?php
                                $act_label = $action_labels[$activity->action] ?? $activity->action;
                                $details = '';
                                if (!empty($activity->after_state)) {
                                    $after = json_decode($activity->after_state, true);
                                    $before = !empty($activity->before_state) ? json_decode($activity->before_state, true) : null;
                                    if ($after) {
                                        if ($activity->action === 'created') {
                                            $details = sprintf(__('تم إنشاء العقد بقيمة %s', 'olama-registration'), $money($after['total_amount'] ?? 0));
                                        } elseif ($activity->action === 'completed') {
                                            $details = __('تم اعتماد العقد وإصدار الفاتورة وتوزيع الاستحقاقات', 'olama-registration');
                                        } elseif ($activity->action === 'cancelled') {
                                            $details = __('تم إلغاء العقد وإلغاء الاستحقاقات غير المدفوعة', 'olama-registration');
                                        } elseif ($activity->action === 'updated') {
                                            $changes = [];
                                            if ($before) {
                                                if (isset($after['total_amount'], $before['total_amount']) && ($after['total_amount'] != $before['total_amount'])) {
                                                    $changes[] = sprintf(__('القيمة الإجمالية: %s', 'olama-registration'), $money($after['total_amount']));
                                                }
                                                if (isset($after['status'], $before['status']) && ($after['status'] !== $before['status'])) {
                                                    $changes[] = sprintf(__('تغيير الحالة إلى %s', 'olama-registration'), $status_label($after['status']));
                                                }
                                            }
                                            $details = !empty($changes) ? implode('، ', $changes) : __('تعديل الحقول الأساسية أو الملاحظات', 'olama-registration');
                                        } elseif ($activity->action === 'fee_added') {
                                            $details = sprintf(__('إضافة بند: %s بقيمة %s', 'olama-registration'), $after['label'] ?? '', $money($after['net_amount'] ?? 0));
                                        } elseif ($activity->action === 'fee_updated') {
                                            $details = sprintf(__('تعديل بند: %s بقيمة %s', 'olama-registration'), $after['label'] ?? '', $money($after['net_amount'] ?? 0));
                                        } elseif ($activity->action === 'fee_deleted') {
                                            $details = sprintf(__('حذف بند: %s بقيمة %s', 'olama-registration'), $after['label'] ?? $before['label'] ?? '', $money($after['net_amount'] ?? $before['net_amount'] ?? 0));
                                        } elseif ($activity->action === 'template_applied') {
                                            $details = sprintf(__('تطبيق النموذج: %s', 'olama-registration'), $after['template_name'] ?? '');
                                        } elseif (strpos($activity->action, 'amendment') !== false) {
                                            $details = sprintf(__('رقم التعديل: %s، السبب: %s', 'olama-registration'), $after['amendment_no'] ?? $before['amendment_no'] ?? '', $after['reason'] ?? $before['reason'] ?? '');
                                        }
                                    }
                                }
                                if (empty($details)) {
                                    $details = wp_trim_words((string) ($activity->after_state ?? $activity->before_state ?? ''), 22);
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html($activity->created_at ?? ''); ?></td>
                                    <td><?php echo esc_html($activity->display_name ?? ('#' . (int) $activity->actor_id)); ?></td>
                                    <td><?php echo esc_html($act_label); ?></td>
                                    <td><?php echo esc_html($details); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4"><?php esc_html_e('لا توجد حركات مسجلة بعد.', 'olama-registration'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php if (!$is_new): ?>
    <div id="os-agr-amendment-modal" class="olama-reg-modal olama-reg-wrap os-agr-amendment-modal" style="display:none;">
        <div class="olama-reg-modal-dialog os-agr-amendment-dialog">
            <div class="olama-reg-modal-header">
                <h2 class="olama-reg-modal-title"><?php esc_html_e('تعديل مالي على العقد', 'olama-registration'); ?></h2>
                <button type="button" class="olama-reg-modal-close" id="os-agr-close-amendment-modal">&times;</button>
            </div>
            <form id="os-agr-amendment-form" style="margin:0;">
                <div class="olama-reg-modal-body os-agr-amendment-body">
                    <div class="olama-reg-grid">
                        <div class="olama-reg-field">
                            <label><?php esc_html_e('نوع التعديل', 'olama-registration'); ?></label>
                            <select name="amendment_type" id="os-agr-amendment-type" style="width:100%;">
                                <option value="correction_error"><?php esc_html_e('تصحيح خطأ', 'olama-registration'); ?></option>
                                <option value="discount_change"><?php esc_html_e('تعديل خصم', 'olama-registration'); ?></option>
                                <option value="increase_amount"><?php esc_html_e('زيادة مبلغ', 'olama-registration'); ?></option>
                                <option value="decrease_amount"><?php esc_html_e('خفض مبلغ', 'olama-registration'); ?></option>
                            </select>
                        </div>
                        <div class="olama-reg-field">
                            <label><?php esc_html_e('تاريخ السريان', 'olama-registration'); ?></label>
                            <input type="text" name="effective_date" id="os-agr-amendment-date" class="os-datepicker" value="<?php echo esc_attr(current_time('Y-m-d')); ?>">
                        </div>
                        <div class="olama-reg-field">
                            <label><?php esc_html_e('القيمة القديمة', 'olama-registration'); ?></label>
                            <input type="text" id="os-agr-amendment-old" readonly>
                        </div>
                        <div class="olama-reg-field">
                            <label><?php esc_html_e('القيمة الجديدة', 'olama-registration'); ?></label>
                            <input type="number" step="0.001" name="new_total" id="os-agr-amendment-new" value="">
                        </div>
                        <div class="olama-reg-field">
                            <label><?php esc_html_e('الفرق', 'olama-registration'); ?></label>
                            <input type="text" id="os-agr-amendment-diff" readonly>
                        </div>
                    </div>
                    <div class="olama-reg-field" style="margin-top:14px;">
                        <label><?php esc_html_e('سبب التعديل', 'olama-registration'); ?></label>
                        <textarea name="reason" id="os-agr-amendment-reason" rows="4" style="width:100%;"></textarea>
                    </div>
                    <div class="olama-reg-field" style="margin-top:14px;">
                        <label><?php esc_html_e('ملاحظات داخلية', 'olama-registration'); ?></label>
                        <textarea name="admin_notes" id="os-agr-amendment-notes" rows="3" style="width:100%;"></textarea>
                    </div>
                    <div class="notice notice-warning inline os-agr-amendment-confirm">
                        <p style="margin:0;"><?php esc_html_e('أفهم أن هذا التعديل سيؤثر على الرصيد المالي للعقد وسيتم تسجيله في سجل التدقيق ولا يمكن حذفه بعد الترحيل.', 'olama-registration'); ?></p>
                    </div>
                </div>
                <div class="os-agr-amendment-footer">
                    <button type="button" class="button" id="os-agr-preview-amendment"><?php esc_html_e('معاينة الأثر', 'olama-registration'); ?></button>
                    <button type="submit" class="olama-reg-btn olama-reg-btn--primary" id="os-agr-save-amendment"><?php esc_html_e('حفظ المسودة', 'olama-registration'); ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (!$is_new): ?>
    <?php $amendments = class_exists('Olama_Reg_Agreement_Amendment') ? Olama_Reg_Agreement_Amendment::get_by_agreement($id) : []; ?>
    <div class="olama-reg-wrap os-wrap os-agr-amendments-wrap">
    <div class="olama-reg-section os-agr-amendments-section">
        <h3 class="olama-reg-section-title"><?php esc_html_e('سجل تعديلات العقد', 'olama-registration'); ?></h3>
        <div class="olama-reg-table-wrap">
            <table class="olama-reg-fin-table os-agr-amendments-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('رقم التعديل', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('النوع', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('الحالة', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('تاريخ السريان', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('القديم', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('الجديد', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('الفرق', 'olama-registration'); ?></th>
                        <th><?php esc_html_e('الإجراءات', 'olama-registration'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($amendments)): ?>
                        <?php foreach ($amendments as $amendment): ?>
                            <tr data-amendment-id="<?php echo esc_attr($amendment->id); ?>">
                                <td><strong><?php echo esc_html($amendment->amendment_no); ?></strong></td>
                                <td><?php 
                                    $amendment_type_labels = [
                                        'correction_error' => __('تصحيح خطأ', 'olama-registration'),
                                        'discount_change' => __('تعديل خصم', 'olama-registration'),
                                        'increase_amount' => __('زيادة مبلغ', 'olama-registration'),
                                        'decrease_amount' => __('خفض مبلغ', 'olama-registration'),
                                        'add_fee' => __('إضافة بند رسوم', 'olama-registration'),
                                    ];
                                    echo esc_html($amendment_type_labels[$amendment->amendment_type] ?? $amendment->amendment_type);
                                ?></td>
                                <td><?php echo esc_html($amendment->status); ?></td>
                                <td><?php echo esc_html($amendment->effective_date); ?></td>
                                <td><?php echo esc_html(number_format((float) $amendment->old_total, 3)); ?></td>
                                <td><?php echo esc_html(number_format((float) $amendment->new_total, 3)); ?></td>
                                <td><?php echo esc_html(number_format((float) $amendment->difference_amount, 3)); ?></td>
                                <td class="os-agr-amendments-actions">
                                    <?php if ($amendment->status === 'draft' && (current_user_can('manage_options') || current_user_can('olama_approve_agreement_amendment'))): ?>
                                        <button type="button" class="button button-small os-agr-approve-amendment"><?php esc_html_e('اعتماد', 'olama-registration'); ?></button>
                                    <?php endif; ?>
                                    <?php if ($amendment->status === 'approved' && (current_user_can('manage_options') || current_user_can('olama_post_agreement_amendment'))): ?>
                                        <button type="button" class="button button-small button-primary os-agr-post-amendment"><?php esc_html_e('ترحيل', 'olama-registration'); ?></button>
                                    <?php endif; ?>
                                    <?php if (in_array($amendment->status, ['draft', 'approved', 'pending_approval'], true) && (current_user_can('manage_options') || current_user_can('olama_approve_agreement_amendment'))): ?>
                                        <button type="button" class="button button-small os-agr-reject-amendment"><?php esc_html_e('رفض', 'olama-registration'); ?></button>
                                    <?php endif; ?>
                                    <?php if ($amendment->status !== 'posted' && (current_user_can('manage_options') || current_user_can('olama_cancel_financial_agreement'))): ?>
                                        <button type="button" class="button button-small os-agr-cancel-amendment"><?php esc_html_e('إلغاء', 'olama-registration'); ?></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8"><?php esc_html_e('لا توجد تعديلات مسجلة بعد.', 'olama-registration'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </div>
<?php endif; ?>

<?php if (!$is_new): ?>
    <!-- Invoice Generation Modal -->
    <div id="os-agr-invoice-modal"
        style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:99999; backdrop-filter:blur(3px);">
        <div
            style="background:#fff; width:500px; margin:100px auto; padding:30px; border-radius:8px; box-shadow:0 10px 25px rgba(0,0,0,0.2); border-top:4px solid #E8920A;">
            <h3 style="margin-top:0; color:#1a1a2e; font-family:'Tajawal', sans-serif;"><?php esc_html_e('إصدار فاتورة', 'olama-registration'); ?></h3>
            <p style="color:#666; margin-bottom:20px;"><?php esc_html_e('الرجاء تحديد الرسوم التي ترغب بفوترتها:', 'olama-registration'); ?></p>

            <form id="os-agr-invoice-form">
                <input type="hidden" name="agreement_id" value="<?php echo esc_attr($id); ?>">
                <table class="olama-reg-fin-table" style="margin-bottom:25px;">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" id="os-agr-inv-check-all" checked></th>
                            <th><?php esc_html_e('الرسم', 'olama-registration'); ?></th>
                            <th><?php esc_html_e('المبلغ', 'olama-registration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Rows will be injected via JS -->
                    </tbody>
                </table>

                <div style="text-align:left; display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" class="button"
                        id="os-agr-close-invoice-modal"><?php esc_html_e('إلغاء', 'olama-registration'); ?></button>
                    <button type="submit" class="olama-reg-btn olama-reg-btn--primary" <?php disabled(!$has_unpaid); ?>><?php esc_html_e('تأكيد الإصدار', 'olama-registration'); ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
