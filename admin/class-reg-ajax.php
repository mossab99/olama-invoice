<?php
/**
 * AJAX Endpoints
 */

if (!defined('ABSPATH'))
    exit;

class Olama_Reg_Ajax
{

    public function __construct()
    {
        $actions = [
            'olama_reg_get_family',
            'olama_reg_get_student',

            'olama_reg_save_financial_row',
            'olama_reg_delete_financial_row',
            'olama_reg_get_financial',
            'olama_reg_search',
            'olama_reg_save_fee_template',
            'olama_reg_delete_fee_template',
            'olama_reg_create_invoice',
            'olama_reg_update_invoice',
            'olama_reg_get_invoice',
            'olama_reg_cancel_invoice',
            'olama_reg_create_invoice_adjustment',
            'olama_reg_cancel_invoice_adjustment',
            'olama_reg_get_invoice_activity',
            'olama_reg_record_payment',
            'olama_reg_reverse_payment',
            'olama_reg_confirm_payment',
            'olama_reg_reject_payment',
            'olama_reg_update_cheque_status',
            'olama_reg_get_receipt',
            'olama_reg_get_family_billing',
            'olama_reg_get_family_students',
            'olama_reg_save_custom_payment',
            'olama_reg_create_settlement',
            'olama_reg_settle_receipt',
            'olama_reg_cancel_settlement',

            // External Customer CRUD
            'olama_reg_search_external_customers',
            'olama_reg_get_external_customer',
            'olama_reg_add_external_customer',
            'olama_reg_update_external_customer',
            'olama_reg_delete_external_customer',

            // Child CRUD
            'olama_reg_get_external_customer_children',
            'olama_reg_add_child_to_customer',
            'olama_reg_update_child',
            'olama_reg_delete_child',

            // Agreements
            'olama_reg_agr_save_header',
            'olama_reg_agr_get_details',
            'olama_reg_agr_search_payer',
            'olama_reg_agr_get_participants',
            'olama_reg_agr_save_fee',
            'olama_reg_agr_delete_fee',
            'olama_reg_agr_add_clause',
            'olama_reg_agr_save_clause',
            'olama_reg_agr_delete_clause',
            'olama_reg_agr_save_due_schedule',
            'olama_reg_agr_generate_due_schedule',
            'olama_reg_agr_create_amendment',
            'olama_reg_agr_approve_amendment',
            'olama_reg_agr_reject_amendment',
            'olama_reg_agr_cancel_amendment',
            'olama_reg_agr_post_amendment',
            'olama_reg_agr_get_amendments',
            'olama_reg_agr_preview_amendment',
            'olama_reg_agr_complete',
            'olama_reg_agr_generate_invoice',
            'olama_reg_agr_get_unpaid_fees',
            'olama_reg_reset_system',
        ];

        foreach ($actions as $action) {
            $method = 'ajax_' . str_replace('olama_reg_', '', $action);
            add_action('wp_ajax_' . $action, [$this, $method]);
        }

        // ── Customer Hub handlers (Phase 2 + 3 + 4) ─────────────────────────────
        add_action('wp_ajax_os_hub_search',       [$this, 'hub_search']);
        add_action('wp_ajax_os_hub_counts',       [$this, 'hub_counts']);
        add_action('wp_ajax_os_hub_tile',         [$this, 'hub_tile']);
        add_action('wp_ajax_os_hub_save_profile', [$this, 'hub_save_profile']);
        add_action('wp_ajax_os_hub_toggle_active',[$this, 'hub_toggle_active']);
        add_action('wp_ajax_os_hub_add_child',    [$this, 'hub_add_child']);
        add_action('wp_ajax_os_hub_load_form',    [$this, 'hub_load_form']);
    }

    // ── Guard ─────────────────────────────────────────────────────────────────

    private function guard(): void
    {
        check_ajax_referer('olama_reg_nonce', 'nonce');

        $action = sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) );
        $capabilities_by_action = [
            'olama_reg_get_family'                    => [ 'olama_manage_registration_families', 'olama_manage_registration_invoices', 'olama_manage_registration_payments', 'olama_manage_registration_reports', 'olama_manage_registration_agreements' ],
            'olama_reg_get_student'                   => [ 'olama_manage_registration_students', 'olama_manage_registration_families', 'olama_manage_registration_invoices', 'olama_manage_registration_agreements' ],
            'olama_reg_search'                        => [ 'olama_manage_registration_families', 'olama_manage_registration_students' ],
            'olama_reg_get_family_students'           => [ 'olama_manage_registration_families', 'olama_manage_registration_students', 'olama_manage_registration_invoices', 'olama_manage_registration_agreements' ],
            'olama_reg_search_external_customers'     => [ 'olama_manage_registration_families', 'olama_manage_registration_invoices', 'olama_manage_registration_payments', 'olama_manage_registration_agreements' ],
            'olama_reg_get_external_customer'         => [ 'olama_manage_registration_families', 'olama_manage_registration_invoices', 'olama_manage_registration_payments', 'olama_manage_registration_agreements' ],
            'olama_reg_get_external_customer_children'=> [ 'olama_manage_registration_families', 'olama_manage_registration_invoices', 'olama_manage_registration_agreements' ],
            'olama_reg_add_external_customer'         => [ 'olama_manage_registration_families' ],
            'olama_reg_update_external_customer'      => [ 'olama_manage_registration_families' ],
            'olama_reg_delete_external_customer'      => [ 'olama_manage_registration_families' ],
            'olama_reg_add_child_to_customer'         => [ 'olama_manage_registration_families' ],
            'olama_reg_update_child'                  => [ 'olama_manage_registration_families' ],
            'olama_reg_delete_child'                  => [ 'olama_manage_registration_families' ],
            'olama_reg_save_financial_row'            => [ 'olama_manage_registration_invoices' ],
            'olama_reg_delete_financial_row'          => [ 'olama_manage_registration_invoices' ],
            'olama_reg_get_financial'                 => [ 'olama_manage_registration_invoices', 'olama_manage_registration_reports' ],
            'olama_reg_save_fee_template'             => [ 'olama_manage_registration_fees' ],
            'olama_reg_delete_fee_template'           => [ 'olama_manage_registration_fees' ],
            'olama_reg_create_invoice'                => [ 'olama_manage_registration_invoices' ],
            'olama_reg_update_invoice'                => [ 'olama_manage_registration_invoices' ],
            'olama_reg_get_invoice'                   => [ 'olama_manage_registration_invoices', 'olama_manage_registration_payments', 'olama_manage_registration_reports' ],
            'olama_reg_cancel_invoice'                => [ 'olama_manage_registration_invoices' ],
            'olama_reg_create_invoice_adjustment'     => [ 'olama_manage_registration_invoices' ],
            'olama_reg_cancel_invoice_adjustment'     => [ 'olama_manage_registration_invoices' ],
            'olama_reg_get_invoice_activity'          => [ 'olama_manage_registration_invoices', 'olama_manage_registration_reports' ],
            'olama_reg_record_payment'                => [ 'olama_record_payments' ],
            'olama_reg_reverse_payment'               => [ 'olama_reverse_payments' ],
            'olama_reg_confirm_payment'               => [ 'olama_confirm_bank_payments' ],
            'olama_reg_reject_payment'                => [ 'olama_confirm_bank_payments' ],
            'olama_reg_update_cheque_status'          => [ 'olama_manage_cheques' ],
            'olama_reg_get_receipt'                   => [ 'olama_manage_registration_payments', 'olama_manage_registration_reports' ],
            'olama_reg_get_family_billing'            => [ 'olama_manage_registration_invoices', 'olama_manage_registration_payments', 'olama_manage_registration_reports' ],
            'olama_reg_save_custom_payment'           => [ 'olama_record_payments' ],
            'olama_reg_create_settlement'             => [ 'olama_record_payments' ],
            'olama_reg_settle_receipt'                => [ 'olama_record_payments' ],
            'olama_reg_cancel_settlement'             => [ 'olama_reverse_payments' ],
            'olama_reg_agr_save_header'               => [ 'olama_manage_registration_agreements' ],
            'olama_reg_agr_get_details'               => [ 'olama_manage_registration_agreements', 'olama_view_agreement_audit' ],
            'olama_reg_agr_search_payer'               => [ 'olama_manage_registration_agreements' ],
            'olama_reg_agr_get_participants'           => [ 'olama_manage_registration_agreements' ],
            'olama_reg_agr_save_fee'                  => [ 'olama_manage_registration_agreements' ],
            'olama_reg_agr_delete_fee'                => [ 'olama_manage_registration_agreements', 'olama_cancel_financial_agreement' ],
            'olama_reg_agr_add_clause'                => [ 'olama_manage_registration_agreements' ],
            'olama_reg_agr_save_clause'               => [ 'olama_manage_registration_agreements' ],
            'olama_reg_agr_delete_clause'             => [ 'olama_manage_registration_agreements' ],
            'olama_reg_agr_save_due_schedule'         => [ 'olama_manage_registration_agreements', 'olama_reschedule_agreement_installments' ],
            'olama_reg_agr_generate_due_schedule'     => [ 'olama_manage_registration_agreements', 'olama_reschedule_agreement_installments' ],
            'olama_reg_agr_create_amendment'          => [ 'olama_create_agreement_amendment' ],
            'olama_reg_agr_approve_amendment'         => [ 'olama_approve_agreement_amendment' ],
            'olama_reg_agr_reject_amendment'          => [ 'olama_approve_agreement_amendment' ],
            'olama_reg_agr_cancel_amendment'          => [ 'olama_cancel_financial_agreement' ],
            'olama_reg_agr_post_amendment'            => [ 'olama_post_agreement_amendment' ],
            'olama_reg_agr_get_amendments'            => [ 'olama_view_agreement_audit', 'olama_create_agreement_amendment' ],
            'olama_reg_agr_preview_amendment'         => [ 'olama_create_agreement_amendment' ],
            'olama_reg_agr_complete'                  => [ 'olama_manage_registration_agreements' ],
            'olama_reg_agr_generate_invoice'          => [ 'olama_manage_registration_agreements' ],
            'olama_reg_agr_get_unpaid_fees'           => [ 'olama_manage_registration_agreements', 'olama_manage_registration_invoices' ],
            'olama_reg_reset_system'                  => [ 'manage_options' ],
        ];

        $required = $capabilities_by_action[ $action ] ?? [];
        if ( ! $required || ! Olama_Reg_Access::can_any( $required ) ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }
    }

    // ── Family ────────────────────────────────────────────────────────────────

    public function ajax_get_family(): void
    {
        $this->guard();

        $family_uid = sanitize_text_field($_POST['family_uid'] ?? '');
        $family = Olama_Reg_Family::get_family($family_uid);

        if (!$family) {
            wp_send_json_error(['message' => __('Family not found.', 'olama-registration')]);
        }

        $students = Olama_Reg_Student::get_family_students($family_uid);

        wp_send_json_success([
            'family' => $family,
            'students' => $students,
        ]);
    }

    // ── External Customer CRUD ─────────────────────────────────────────────────

    public function ajax_search_external_customers(): void
    {
        $this->guard();
        global $wpdb;
        $q = sanitize_text_field($_POST['q'] ?? '');
        $like = '%' . $wpdb->esc_like($q) . '%';

        $table = $wpdb->prefix . 'olama_customers';
        $ctable = $wpdb->prefix . 'olama_customer_children';

        $customers = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT c.id, c.customer_uid, c.customer_name, c.phone
             FROM {$table} c
             LEFT JOIN {$ctable} ch ON ch.customer_id = c.id AND ch.is_active = 1
             WHERE c.is_active = 1
               AND ( c.customer_name LIKE %s OR c.phone LIKE %s OR c.customer_uid LIKE %s OR ch.child_name LIKE %s )
             ORDER BY c.customer_name ASC
             LIMIT 20",
            $like,
            $like,
            $like,
            $like
        ));

        wp_send_json_success(['customers' => $customers]);
    }

    public function ajax_get_external_customer(): void
    {
        $this->guard();
        $customer_id = absint($_POST['customer_id'] ?? 0);

        if (!$customer_id) {
            wp_send_json_error(['message' => 'معرف العميل مطلوب.']);
        }

        $customer = Olama_Reg_Customer::get($customer_id);
        if (!$customer) {
            wp_send_json_error(['message' => 'العميل غير موجود.']);
        }

        $children = Olama_Reg_Child::get_by_customer($customer_id);

        wp_send_json_success([
            'customer' => $customer,
            'children' => $children,
        ]);
    }

    public function ajax_add_external_customer(): void
    {
        $this->guard();

        $result = Olama_Reg_Customer::create([
            'customer_name' => sanitize_text_field($_POST['customer_name'] ?? $_POST['name'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $customer = Olama_Reg_Customer::get($result);
        $children_raw = json_decode(stripslashes($_POST['children'] ?? '[]'), true);
        $children = [];

        if (is_array($children_raw)) {
            foreach ($children_raw as $c) {
                $child_id = Olama_Reg_Child::add($result, [
                    'child_name' => $c['name'] ?? '',
                    'grade' => $c['grade'] ?? '',
                ]);
                if (!is_wp_error($child_id)) {
                    $children[] = Olama_Reg_Child::get($child_id);
                }
            }
        }

        wp_send_json_success([
            'message' => 'تم إضافة العميل بنجاح.',
            'customer_id' => $result,
            'customer_uid' => $customer->customer_uid ?? '',
            'customer' => $customer,
            'children' => $children,
        ]);
    }

    public function ajax_update_external_customer(): void
    {
        $this->guard();
        $customer_id = absint($_POST['customer_id'] ?? 0);

        if (!$customer_id) {
            wp_send_json_error(['message' => 'معرف العميل مطلوب.']);
        }

        $result = Olama_Reg_Customer::update($customer_id, [
            'customer_name' => sanitize_text_field($_POST['customer_name'] ?? $_POST['name'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'تم تحديث بيانات العميل.',
            'customer' => Olama_Reg_Customer::get($customer_id),
        ]);
    }

    public function ajax_delete_external_customer(): void
    {
        $this->guard();
        $customer_id = absint($_POST['customer_id'] ?? 0);

        if (!$customer_id) {
            wp_send_json_error(['message' => 'معرف العميل مطلوب.']);
        }

        $result = Olama_Reg_Customer::delete($customer_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => 'تم حذف العميل بنجاح.']);
    }

    // ── Child CRUD ────────────────────────────────────────────────────────────

    public function ajax_get_external_customer_children(): void
    {
        $this->guard();
        $customer_id = absint($_POST['customer_id'] ?? 0);

        if (!$customer_id) {
            wp_send_json_error(['message' => 'معرف العميل مطلوب.']);
        }

        $children = Olama_Reg_Child::get_by_customer($customer_id);
        wp_send_json_success(['children' => $children]);
    }

    public function ajax_add_child_to_customer(): void
    {
        $this->guard();
        $customer_id = absint($_POST['customer_id'] ?? 0);

        if (!$customer_id) {
            wp_send_json_error(['message' => 'معرف العميل مطلوب.']);
        }

        $result = Olama_Reg_Child::add($customer_id, [
            'child_name' => sanitize_text_field($_POST['child_name'] ?? $_POST['name'] ?? ''),
            'grade' => sanitize_text_field($_POST['grade'] ?? ''),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'تمت إضافة الابن بنجاح.',
            'child' => Olama_Reg_Child::get($result),
        ]);
    }

    public function ajax_update_child(): void
    {
        $this->guard();
        $child_id = absint($_POST['child_id'] ?? 0);

        if (!$child_id) {
            wp_send_json_error(['message' => 'معرف الابن مطلوب.']);
        }

        $result = Olama_Reg_Child::update($child_id, [
            'child_name' => sanitize_text_field($_POST['child_name'] ?? $_POST['name'] ?? ''),
            'grade' => sanitize_text_field($_POST['grade'] ?? ''),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'تم تحديث بيانات الابن.',
            'child' => Olama_Reg_Child::get($child_id),
        ]);
    }

    public function ajax_delete_child(): void
    {
        $this->guard();
        $child_id = absint($_POST['child_id'] ?? 0);

        if (!$child_id) {
            wp_send_json_error(['message' => 'معرف الابن مطلوب.']);
        }

        $result = Olama_Reg_Child::delete($child_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => 'تم حذف الابن.']);
    }

    // ── Student ───────────────────────────────────────────────────────────────

    public function ajax_get_student(): void
    {
        $this->guard();

        $student_uid = sanitize_text_field($_POST['student_uid'] ?? '');
        $student = Olama_Reg_Student::get_student($student_uid);

        if (!$student) {
            wp_send_json_error(['message' => __('Student not found.', 'olama-registration')]);
        }

        global $wpdb;
        $active_year_id = 0;
        if (class_exists('Olama_School_Academic')) {
            $ay = Olama_School_Academic::get_active_year();
            if ($ay)
                $active_year_id = (int) $ay->id;
        }

        wp_send_json_success([
            'student' => $student,
            'photo_url' => Olama_Reg_Student::get_student_photo_url((int) ($student->photo_attachment_id ?? 0)),
        ]);
    }



    // ── Financial ─────────────────────────────────────────────────────────────

    public function ajax_save_financial_row(): void
    {
        $this->guard();

        $result = Olama_Reg_Financial::save_row($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $family_uid = sanitize_text_field($_POST['family_uid'] ?? '');
        $academic_year_id = (int) ($_POST['academic_year_id'] ?? 0);

        wp_send_json_success([
            'message' => __('Row saved.', 'olama-registration'),
            'id' => $result,
            'totals' => Olama_Reg_Financial::get_totals($family_uid, $academic_year_id),
        ]);
    }

    public function ajax_delete_financial_row(): void
    {
        $this->guard();

        $id = (int) ($_POST['id'] ?? 0);
        $family_uid = sanitize_text_field($_POST['family_uid'] ?? '');
        $year_id = (int) ($_POST['academic_year_id'] ?? 0);

        Olama_Reg_Financial::delete_row($id);

        wp_send_json_success([
            'message' => __('Row deleted.', 'olama-registration'),
            'totals' => Olama_Reg_Financial::get_totals($family_uid, $year_id),
        ]);
    }

    public function ajax_get_financial(): void
    {
        $this->guard();

        $family_uid = sanitize_text_field($_POST['family_uid'] ?? '');
        $academic_year_id = (int) ($_POST['academic_year_id'] ?? 0);

        wp_send_json_success([
            'rows' => Olama_Reg_Financial::get_entitlements($family_uid, $academic_year_id),
            'totals' => Olama_Reg_Financial::get_totals($family_uid, $academic_year_id),
        ]);
    }

    // ── Search ────────────────────────────────────────────────────────────────

    public function ajax_search(): void
    {
        $this->guard();

        $q = sanitize_text_field($_POST['q'] ?? '');
        $families = array_map(static function ($family) {
            return (object) [
                'family_uid'        => $family->uid,
                'father_first_name' => $family->name,
                'father_family_name'=> '',
            ];
        }, Olama_Reg_Family::search($q, 10));
        $students = Olama_Reg_Student::search($q, 10);

        wp_send_json_success(['families' => $families, 'students' => $students]);
    }

    // ── Photo Upload ──────────────────────────────────────────────────────────

    public function ajax_upload_photo(): void
    {
        $this->guard();

        if (empty($_FILES['photo'])) {
            wp_send_json_error(['message' => __('No file received.', 'olama-registration')]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload('photo', 0);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => $attachment_id->get_error_message()]);
        }

        wp_send_json_success([
            'attachment_id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
            'thumb' => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
        ]);
    }

    // ── Billing - Fee Templates ──────────────────────────────────────────────

    public function ajax_save_fee_template(): void
    {
        $this->guard();
        $result = Olama_Reg_Billing_Fees::save_template($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Template saved successfully.', 'olama-registration'),
            'id' => $result,
        ]);
    }

    public function ajax_delete_fee_template(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $result = Olama_Reg_Billing_Fees::delete_template($id);

        if (!$result) {
            wp_send_json_error(['message' => __('Could not delete template.', 'olama-registration')]);
        }

        wp_send_json_success(['message' => __('Template deleted successfully.', 'olama-registration')]);
    }

    // ── Billing - Invoices ────────────────────────────────────────────────────

    public function ajax_create_invoice(): void
    {
        $this->guard();
        $result = Olama_Reg_Billing_Invoice::create($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Invoice created successfully.', 'olama-registration'),
            'invoice_id' => $result,
        ]);
    }

    public function ajax_update_invoice(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $result = Olama_Reg_Billing_Invoice::update($id, $_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Invoice updated successfully.', 'olama-registration'),
        ]);
    }

    public function ajax_get_invoice(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $invoice = Olama_Reg_Billing_Invoice::get_invoice($id);

        if (!$invoice) {
            wp_send_json_error(['message' => __('Invoice not found.', 'olama-registration')]);
        }

        if (class_exists('Olama_Reg_Billing_Payment')) {
            $invoice->payments = Olama_Reg_Billing_Payment::get_invoice_payments($id);
        } else {
            $invoice->payments = [];
        }
        $invoice->adjustments = Olama_Reg_Billing_Invoice::get_adjustments($id);
        $invoice->activity = Olama_Reg_Billing_Invoice::get_activity($id);
        $invoice->policy = [
            'can_record_payment' => !is_wp_error(Olama_Reg_Billing_Invoice::can_record_payment($invoice)),
            'can_update_financial_fields' => !is_wp_error(Olama_Reg_Billing_Invoice::can_update_financial_fields($invoice)),
            'can_update_non_financial_fields' => !is_wp_error(Olama_Reg_Billing_Invoice::can_update_non_financial_fields($invoice)),
            'can_cancel' => !is_wp_error(Olama_Reg_Billing_Invoice::can_cancel($invoice)),
            'can_create_credit_note' => !is_wp_error(Olama_Reg_Billing_Invoice::can_create_credit_note($invoice)),
            'can_create_debit_note' => !is_wp_error(Olama_Reg_Billing_Invoice::can_create_debit_note($invoice)),
        ];

        wp_send_json_success(['invoice' => $invoice]);
    }

    public function ajax_cancel_invoice(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $result = Olama_Reg_Billing_Invoice::cancel($id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('تم إلغاء الفاتورة بنجاح.', 'olama-registration'),
        ]);
    }

    public function ajax_create_invoice_adjustment(): void
    {
        $this->guard();

        $invoice_id = (int) ($_POST['invoice_id'] ?? 0);
        $type = sanitize_key($_POST['type'] ?? '');
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $reason = sanitize_text_field($_POST['reason'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        $result = Olama_Reg_Billing_Invoice::create_adjustment($invoice_id, $type, $amount, $reason, $notes);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => $type === 'credit' ? __('تم إصدار الإشعار الدائن بنجاح.', 'olama-registration') : __('تم إصدار الإشعار المدين بنجاح.', 'olama-registration'),
            'adjustment_id' => $result,
        ]);
    }

    public function ajax_cancel_invoice_adjustment(): void
    {
        $this->guard();

        $id = (int) ($_POST['id'] ?? 0);
        $result = Olama_Reg_Billing_Invoice::cancel_adjustment($id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('تم إلغاء الإشعار المالي بنجاح.', 'olama-registration'),
        ]);
    }

    public function ajax_get_invoice_activity(): void
    {
        $this->guard();
        $id = (int) ($_POST['invoice_id'] ?? $_POST['id'] ?? 0);
        wp_send_json_success([
            'activity' => Olama_Reg_Billing_Invoice::get_activity($id),
        ]);
    }

    // ── Billing - Payments ────────────────────────────────────────────────────

    public function ajax_record_payment(): void
    {
        $this->guard();
        if ( ! Olama_Reg_Payment_Policy::current_user_can_any( [ 'olama_record_payments' ] ) ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }
        $result = Olama_Reg_Billing_Payment::record($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Payment recorded successfully.', 'olama-registration'),
            'payment_id' => $result,
        ]);
    }

    public function ajax_reverse_payment(): void
    {
        $this->guard();
        if ( ! Olama_Reg_Payment_Policy::current_user_can_any( [ 'olama_reverse_payments' ] ) ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        $result = Olama_Reg_Billing_Payment::reverse($id, $notes);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('تم عكس السند بنجاح.', 'olama-registration'),
            'payment_id' => $result,
        ]);
    }

    public function ajax_confirm_payment(): void
    {
        $this->guard();
        if ( ! Olama_Reg_Payment_Policy::current_user_can_any( [ 'olama_confirm_bank_payments' ] ) ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        $result = Olama_Reg_Payment_Method_Details::confirm_payment($id, $notes);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('تم اعتماد الدفعة بنجاح.', 'olama-registration')]);
    }

    public function ajax_reject_payment(): void
    {
        $this->guard();
        if ( ! Olama_Reg_Payment_Policy::current_user_can_any( [ 'olama_confirm_bank_payments' ] ) ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }
        $id = (int) ($_POST['id'] ?? 0);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        $result = Olama_Reg_Payment_Method_Details::reject_payment($id, $notes);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('تم رفض الدفعة.', 'olama-registration')]);
    }

    public function ajax_update_cheque_status(): void
    {
        $this->guard();
        if ( ! Olama_Reg_Payment_Policy::current_user_can_any( [ 'olama_manage_cheques' ] ) ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }

        $cheque_id = absint($_POST['id'] ?? 0);
        $action = sanitize_key($_POST['cheque_action'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        $result = Olama_Reg_Payment_Method_Details::transition_cheque($cheque_id, $action, $notes);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('تم تحديث حالة الشيك.', 'olama-registration')]);
    }

    public function ajax_get_receipt(): void
    {
        $this->guard();
        $payment_id = (int) ($_POST['id'] ?? 0);
        $data = Olama_Reg_Billing_Payment::generate_receipt_data($payment_id);

        if (empty($data)) {
            wp_send_json_error(['message' => __('Receipt data not found.', 'olama-registration')]);
        }

        wp_send_json_success($data);
    }

    // ── Billing - Family Billing Tab overlay ──────────────────────────────────

    public function ajax_get_family_billing(): void
    {
        $this->guard();
        $family_uid = sanitize_text_field($_POST['family_uid'] ?? '');
        $year_id = (int) ($_POST['academic_year_id'] ?? 0);

        if (!$family_uid) {
            wp_send_json_error(['message' => __('Family UID is required.', 'olama-registration')]);
        }

        // If it starts with CUST-, it's an external customer
        if (strpos($family_uid, 'CUST-') === 0) {
            global $wpdb;
            $customer_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s LIMIT 1",
                $family_uid
            ));
            if ($customer_id) {
                $invoices = Olama_Reg_Billing_Invoice::get_customer_invoices($customer_id, $year_id);
                $payments = Olama_Reg_Billing_Payment::get_family_payments($family_uid, $year_id);
                $summary = Olama_Reg_Billing_Invoice::get_customer_invoice_summary($customer_id, $year_id);
            } else {
                $invoices = [];
                $payments = [];
                $summary = (object)[ 'total_invoiced' => 0, 'total_paid' => 0, 'balance' => 0 ];
            }
        } else {
            // The payment modal uses the human-facing Oracle file number from
            // the Hub. Billing records are keyed by Core's canonical UID.
            $family = Olama_Reg_Core_Gateway::family($family_uid);
            if (!$family) {
                wp_send_json_error(['message' => __('The selected family was not found in Olama Core.', 'olama-registration')]);
            }
            $family_uid = (string) $family->family_uid;

            $invoices = Olama_Reg_Billing_Invoice::get_family_invoices($family_uid, $year_id);
            $payments = Olama_Reg_Billing_Payment::get_family_payments($family_uid, $year_id);
            $summary = Olama_Reg_Billing_Invoice::get_invoice_summary($family_uid, $year_id);
        }

        wp_send_json_success([
            'invoices' => $invoices,
            'payments' => $payments,
            'summary' => $summary,
        ]);
    }

    // ── Custom Payments ────────────────────────────────────────────────────────

    public function ajax_get_family_students(): void
    {
        $this->guard();
        $family_uid = sanitize_text_field($_POST['family_uid'] ?? '');
        if (!$family_uid) {
            wp_send_json_error(['message' => __('Missing family UID.', 'olama-registration')]);
        }

        $students = Olama_Reg_Student::get_family_students($family_uid);
        wp_send_json_success(['students' => $students]);
    }

    public function ajax_save_custom_payment(): void
    {
        $this->guard();
        global $wpdb;

        $family_uid = sanitize_text_field($_POST['family_uid'] ?? '');
        $student_uids = isset($_POST['student_uids']) && is_array($_POST['student_uids']) ? array_map('sanitize_text_field', $_POST['student_uids']) : [];
        $service_type = sanitize_text_field($_POST['service_type'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        $discount = (float) ($_POST['discount'] ?? 0);
        $fee_template = absint($_POST['fee_template_id'] ?? 0);
        $payment_meth = sanitize_text_field($_POST['payment_method'] ?? 'cash');
        $is_external = !empty($_POST['is_external']) || !empty($_POST['is_external_customer']);
        $ext_customer = absint($_POST['ext_customer_id'] ?? 0);

        $linked_agreement_id = isset($_POST['linked_agreement_id']) ? (int) $_POST['linked_agreement_id'] : 0;
        $linked_fee_ids = isset($_POST['linked_fee_ids']) ? array_map('intval', explode(',', $_POST['linked_fee_ids'])) : [];
        
        $selected_fees = [];
        if ($linked_agreement_id && !empty($linked_fee_ids)) {
            $fees_db = Olama_Reg_Agreement_Fees::get_by_agreement($linked_agreement_id);
            if ($fees_db) {
                foreach ($fees_db as $f) {
                    if (in_array($f->id, $linked_fee_ids)) {
                        $selected_fees[] = $f;
                    }
                }
            }
        }

        $template_items = [];
        if ($fee_template) {
            $template_obj = Olama_Reg_Billing_Fees::get_template($fee_template);
            if ($template_obj && !empty($template_obj->items)) {
                $template_items = $template_obj->items;
            }
        }

        $items = [];
        $total_amount = 0;

        if (!$is_external) {
            if (!$family_uid || empty($student_uids) || !$service_type || $amount <= 0) {
                wp_send_json_error(['message' => __('بيانات غير مكتملة. تأكد من تحديد الطلاب وتحديد قيمة الدفعة.', 'olama-registration')]);
            }

            $per_child_discount = $discount;
            // Build line items
            foreach ($student_uids as $s_uid) {
                $student = Olama_Reg_Student::get_student($s_uid);
                $s_name = $student ? trim($student->student_name) : $s_uid;

                if (!empty($selected_fees)) {
                    foreach ($selected_fees as $f) {
                        $items[] = [
                            'description' => sprintf('%s - %s', $f->label ?: $f->fee_category, $s_name),
                            'quantity' => 1,
                            'unit_price' => (float)$f->amount,
                        ];
                    }
                } else if (!empty($template_items)) {
                    foreach ($template_items as $t) {
                        $t_desc = is_object($t) ? $t->description : $t['description'];
                        $t_amt = is_object($t) ? $t->amount : $t['amount'];
                        $items[] = [
                            'description' => sprintf('%s - %s', $t_desc, $s_name),
                            'quantity' => 1,
                            'unit_price' => (float)$t_amt,
                        ];
                    }
                } else {
                    $items[] = [
                        'description' => sprintf('%s - %s', $service_type, $s_name),
                        'quantity' => 1,
                        'unit_price' => $amount,
                    ];
                }
            }

            $num_children = count($student_uids);
            if (!empty($template_items) || !empty($selected_fees)) {
                $total_amount = $num_children * $amount;
            } else {
                $total_amount = max(0, $num_children * ($amount - $discount));
            }
            $discount_for_invoice = $num_children * $discount;
        } else {
            if (!$ext_customer || !$service_type || $amount <= 0) {
                wp_send_json_error(['message' => __('بيانات غير مكتملة. تأكد من تحديد نوع الخدمة وقيمة الدفعة.', 'olama-registration')]);
            }

            // Use CUST-uid as family_uid for external customers (not EXT-{id})
            $customer = Olama_Reg_Customer::get($ext_customer);
            $family_uid = $customer ? $customer->customer_uid : ('CUST-' . str_pad($ext_customer, 4, '0', STR_PAD_LEFT));

            // Children come as array of child IDs (from DB checkboxes)
            $child_ids_raw = isset($_POST['child_ids']) && is_array($_POST['child_ids'])
                ? array_map('absint', $_POST['child_ids'])
                : [];

            // Also handle newly typed children (quick-add during payment)
            $new_children_raw = json_decode(stripslashes($_POST['new_children'] ?? '[]'), true);
            if (is_array($new_children_raw)) {
                foreach ($new_children_raw as $nc) {
                    $nc_name = sanitize_text_field($nc['name'] ?? '');
                    if ($nc_name) {
                        $new_child_id = Olama_Reg_Child::add($ext_customer, [
                            'child_name' => $nc_name,
                            'grade' => sanitize_text_field($nc['grade'] ?? ''),
                        ]);
                        if (!is_wp_error($new_child_id)) {
                            $child_ids_raw[] = $new_child_id;
                        }
                    }
                }
            }

            if (!empty($child_ids_raw)) {
                foreach ($child_ids_raw as $child_id) {
                    $child = Olama_Reg_Child::get($child_id);
                    if (!$child)
                        continue;
                    if (!empty($selected_fees)) {
                        foreach ($selected_fees as $f) {
                            $items[] = [
                                'description' => sprintf('%s - %s', $f->label ?: $f->fee_category, $child->child_name),
                                'quantity' => 1,
                                'unit_price' => (float)$f->amount,
                                'ext_child_id' => $child->id,
                            ];
                        }
                    } else if (!empty($template_items)) {
                        foreach ($template_items as $t) {
                            $t_desc = is_object($t) ? $t->description : $t['description'];
                            $t_amt = is_object($t) ? $t->amount : $t['amount'];
                            $items[] = [
                                'description' => sprintf('%s - %s', $t_desc, $child->child_name),
                                'quantity' => 1,
                                'unit_price' => (float)$t_amt,
                                'ext_child_id' => $child->id,
                            ];
                        }
                    } else {
                        $items[] = [
                            'description' => sprintf('%s - %s', $service_type, $child->child_name),
                            'quantity' => 1,
                            'unit_price' => $amount,
                            'ext_child_id' => $child->id,
                        ];
                    }
                }
                $num_children = count($child_ids_raw);
                if (!empty($template_items) || !empty($selected_fees)) {
                    $total_amount = $num_children * $amount;
                } else {
                    $total_amount = max(0, $num_children * ($amount - $discount));
                }
                $discount_for_invoice = $num_children * $discount;
            } else {
                // Payment for the customer directly (no children)
                if (!empty($selected_fees)) {
                    foreach ($selected_fees as $f) {
                        $items[] = [
                            'description' => sprintf('%s', $f->label ?: $f->fee_category),
                            'quantity' => 1,
                            'unit_price' => (float)$f->amount,
                        ];
                    }
                } else if (!empty($template_items)) {
                    foreach ($template_items as $t) {
                        $t_desc = is_object($t) ? $t->description : $t['description'];
                        $t_amt = is_object($t) ? $t->amount : $t['amount'];
                        $items[] = [
                            'description' => sprintf('%s', $t_desc),
                            'quantity' => 1,
                            'unit_price' => (float)$t_amt,
                        ];
                    }
                } else {
                    $items[] = [
                        'description' => $service_type,
                        'quantity' => 1,
                        'unit_price' => $amount,
                    ];
                }
                if (!empty($template_items) || !empty($selected_fees)) {
                    $total_amount = $amount;
                } else {
                    $total_amount = max(0, $amount - $discount);
                }
                $discount_for_invoice = $discount;
            }
        }

        $academic_year_id = 0;
        if (class_exists('Olama_School_Academic')) {
            $active_year = Olama_School_Academic::get_active_year();
            if ($active_year) {
                $academic_year_id = (int) $active_year->id;
            }
        }



        // 1. Create a single Invoice containing all items
        $invoice_data = [
            'family_uid' => $family_uid,
            'academic_year_id' => $academic_year_id,
            'fee_template_id' => $fee_template ?: null,
            'issue_date' => date('Y-m-d'),
            'status' => 'issued',
            'notes' => 'رسوم خدمة إضافية: ' . $service_type,
            'items' => $items,
            'discount' => isset($discount_for_invoice) ? $discount_for_invoice : $discount,
            'linked_agreement_id' => $linked_agreement_id ?: null,
        ];

        if ($is_external) {
            $invoice_data['ext_customer_id'] = $ext_customer;
            // If there is only one child, we can set ext_child_id for clearer reporting
            if (count($items) === 1 && !empty($items[0]['ext_child_id'])) {
                $invoice_data['ext_child_id'] = $items[0]['ext_child_id'];
            }
            $invoice_data['notes'] = 'رسوم خدمة: ' . $service_type;
        }

        $invoice_id = Olama_Reg_Billing_Invoice::create($invoice_data);

        if (is_wp_error($invoice_id)) {
            wp_send_json_error(['message' => $invoice_id->get_error_message()]);
        }

        $created_invoice = Olama_Reg_Billing_Invoice::get_invoice((int) $invoice_id);
        $payment_amount = $created_invoice ? round((float) $created_invoice->balance, 2) : 0.0;
        if ($payment_amount <= 0) {
            Olama_Reg_Billing_Invoice::cancel((int) $invoice_id);
            wp_send_json_error(['message' => __('لا يمكن إصدار سند بقيمة صفرية. يرجى مراجعة قيمة الخدمة والخصم.', 'olama-registration')]);
        }

        // 2. Record a single Payment
        $payment_id = Olama_Reg_Billing_Payment::record([
            'family_uid' => $family_uid,
            'invoice_id' => $invoice_id,
            'amount' => $payment_amount,
            'payment_date' => date('Y-m-d'),
            'method' => $payment_meth,
            'notes' => $is_external ? 'دفعة: ' . $service_type : 'دفعة مقبوضة عن خدمات إضافية: ' . $service_type,
        ]);

        if (is_wp_error($payment_id)) {
            Olama_Reg_Billing_Invoice::cancel((int) $invoice_id);
            wp_send_json_error(['message' => $payment_id->get_error_message()]);
        }



        $payment_status = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}olama_payments WHERE id = %d",
            (int) $payment_id
        ));
        $fee_paid_status = $payment_status === 'posted' ? 'paid' : 'invoiced';

        if ($linked_agreement_id && !empty($linked_fee_ids)) {
            $agreement = Olama_Reg_Agreement::get($linked_agreement_id);
            if ($agreement) {
                foreach ($linked_fee_ids as $fid) {
                    $wpdb->update(
                        $wpdb->prefix . 'olama_agreement_fees',
                        ['paid_status' => $fee_paid_status, 'invoice_id' => $invoice_id],
                        ['id' => $fid, 'agreement_id' => $linked_agreement_id]
                    );
                }

                $all_fees = Olama_Reg_Agreement_Fees::get_by_agreement($linked_agreement_id);
                $all_paid = true;
                if ($all_fees) {
                    foreach ($all_fees as $f) {
                        // After update, we need to check the updated state or just skip the ones we just updated
                        // The get_by_agreement will fetch new state if caching allows, but to be safe:
                        $current_status = in_array((int) $f->id, $linked_fee_ids, true) ? $fee_paid_status : (string) $f->paid_status;
                        if ($current_status !== 'paid') {
                            $all_paid = false;
                            break;
                        }
                    }
                }

                if ($all_paid && $agreement->status === 'draft') {
                    $wpdb->update(
                        $wpdb->prefix . 'olama_agreements',
                        ['status' => 'active'],
                        ['id' => $linked_agreement_id]
                    );
                }
            }
        }

        wp_send_json_success([
            'message' => __('تم تسجيل الفاتورة وإصدار السند بنجاح.', 'olama-registration'),
            'invoice_id' => $invoice_id,
            'payment_id' => $payment_id,
        ]);
    }

    // ── Settlement Receipts ───────────────────────────────────────────────────

    public function ajax_create_settlement(): void
    {
        $this->guard();
        $result = Olama_Reg_Settlement::create_receipt($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('تم إنشاء إيصال التسوية بنجاح.', 'olama-registration'),
            'receipt_id' => $result,
        ]);
    }

    public function ajax_settle_receipt(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $oracle_receipt = sanitize_text_field($_POST['oracle_receipt_number'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        $result = Olama_Reg_Settlement::settle_receipt($id, $oracle_receipt, $notes);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('تمت التسوية بنجاح.', 'olama-registration'),
        ]);
    }

    public function ajax_cancel_settlement(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);

        $result = Olama_Reg_Settlement::cancel_receipt($id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('تم إلغاء الإيصال بنجاح.', 'olama-registration'),
        ]);
    }

    // ── Agreements ────────────────────────────────────────────────────────────

    public function ajax_agr_save_header(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $active_year_id = 0;
        if (class_exists('Olama_School_Academic')) {
            $active_year = Olama_School_Academic::get_active_year();
            if ($active_year) {
                $active_year_id = (int) $active_year->id;
            }
        }

        $participant_id = 0;
        $participant_ids = [];
        if (isset($_POST['participant_id'])) {
            if (is_array($_POST['participant_id'])) {
                $participant_ids = array_values(array_filter(array_map(
                    'sanitize_text_field',
                    wp_unslash($_POST['participant_id'])
                )));
            } else {
                $participant_ref = sanitize_text_field(wp_unslash($_POST['participant_id']));
                $participant_ids = $participant_ref !== '' ? [$participant_ref] : [];
            }

            if (sanitize_key($_POST['payer_type'] ?? '') !== 'family') {
                $participant_id = absint($participant_ids[0] ?? 0);
            }
        }

        $data = [
            'payer_type' => sanitize_text_field($_POST['payer_type'] ?? ''),
            'payer_id' => sanitize_text_field($_POST['payer_id'] ?? ''),
            'participant_type' => (sanitize_text_field($_POST['payer_type'] ?? '') === 'family') ? 'student' : 'child',
            'participant_id' => $participant_id,
            'participant_ids' => $participant_ids,
            'activity_type' => sanitize_text_field($_POST['activity_type'] ?? ''),
            'academic_year_id' => $active_year_id ?: null,
            'start_date' => sanitize_text_field($_POST['start_date'] ?? ''),
            'end_date' => Olama_Reg_Agreement_Invoice::get_academic_year_end_date($active_year_id),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
            'template_id' => absint($_POST['template_id'] ?? 0) ?: null,
        ];

        $contract_template = $data['template_id']
            ? Olama_Reg_Agreement_Templates::get((int) $data['template_id'])
            : null;
        if (!$contract_template || !(int) $contract_template->is_active) {
            wp_send_json_error(['message' => __('يجب اختيار نموذج عقد مفعل.', 'olama-registration')]);
        }

        $data['status'] = 'draft';

        $data['end_date'] = $data['end_date'] ?: null;

        if ($id > 0) {
            $old = Olama_Reg_Agreement::get($id);
            if ($old && class_exists('Olama_Reg_Agreement_Policy')) {
                $financial_edit = Olama_Reg_Agreement_Policy::can_edit_financial_fields($id);
                if (is_wp_error($financial_edit)) {
                    $old_participants = array_map('strval', (array) ($old->participant_ids_array ?? []));
                    $new_participants = array_map('strval', (array) $participant_ids);
                    sort($old_participants);
                    sort($new_participants);

                    $has_financial_change =
                        (string) $data['payer_type'] !== (string) $old->payer_type
                        || (string) $data['payer_id'] !== (string) $old->payer_id
                        || $new_participants !== $old_participants
                        || (string) $data['activity_type'] !== (string) $old->activity_type
                        || (string) $data['start_date'] !== (string) $old->start_date
                        || (string) ($data['end_date'] ?? '') !== (string) ($old->end_date ?? '')
                        || (int) ($data['template_id'] ?? 0) !== (int) ($old->template_id ?? 0);

                    if ($has_financial_change) {
                        wp_send_json_error(['message' => $financial_edit->get_error_message()]);
                    }

                    $data['payer_type'] = $old->payer_type;
                    $data['payer_id'] = $old->payer_id;
                    $data['participant_type'] = $old->participant_type;
                    $data['participant_id'] = $old->participant_id;
                    $data['participant_ids'] = $old->participant_ids_array ?? [];
                    $data['activity_type'] = $old->activity_type;
                    $data['academic_year_id'] = $old->academic_year_id;
                    $data['start_date'] = $old->start_date;
                    $data['end_date'] = $old->end_date;
                    $data['template_id'] = $old->template_id;
                }
            }
            if ($old && in_array($old->status, ['completed', 'cancelled'], true)) {
                $data['status'] = $old->status;
            }

            $result = Olama_Reg_Agreement::update($id, $data);
            if ($result) {
                $agreement = Olama_Reg_Agreement::get($id);
                wp_send_json_success([
                    'message'          => __('تم تحديث العقد.', 'olama-registration'),
                    'id'               => $id,
                    'agreement_number' => $agreement ? $agreement->agreement_number : '',
                ]);
            }
        } else {
            $id = Olama_Reg_Agreement::create($data);
            if ($id) {
                Olama_Reg_Agreement_Invoice::generate_default_due_schedule($id);

                $agreement = Olama_Reg_Agreement::get($id);
                wp_send_json_success([
                    'message'          => __('تم إنشاء العقد.', 'olama-registration'),
                    'id'               => $id,
                    'agreement_number' => $agreement ? $agreement->agreement_number : '',
                ]);
            }
        }

        wp_send_json_error(['message' => __('حدث خطأ أثناء الحفظ.', 'olama-registration')]);
    }

    public function ajax_agr_get_details(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $agreement = Olama_Reg_Agreement::get($id);
        if (!$agreement) {
            wp_send_json_error(['message' => __('العقد غير موجود.', 'olama-registration')]);
        }

        // Fetch fees
        $fees = Olama_Reg_Agreement_Fees::get_by_agreement($id);
        $fees_data = [];
        foreach ($fees as $f) {
            $fees_data[] = [
                'id' => $f->id,
                'fee_category' => $f->fee_category,
                'child_id' => $f->child_id,
                'label' => $f->label,
                'amount' => $f->amount,
                'discount' => $f->discount,
                'net_amount' => $f->net_amount,
                'due_date' => $f->due_date,
                'paid_status' => $f->paid_status,
                'invoice_id' => $f->invoice_id,
            ];
        }

        $financial_status = 'open';
        $lock_reasons = [];
        $can_edit_financial_fields = true;
        $can_reschedule_installments = true;
        if (class_exists('Olama_Reg_Agreement_Policy')) {
            $financial_status = Olama_Reg_Agreement_Policy::get_financial_status($id);
            $lock_reasons = Olama_Reg_Agreement_Policy::get_lock_reasons($id);
            $financial_edit = Olama_Reg_Agreement_Policy::can_edit_financial_fields($id);
            $schedule_edit = Olama_Reg_Agreement_Policy::can_reschedule_installments($id);
            $can_edit_financial_fields = !is_wp_error($financial_edit);
            $can_reschedule_installments = !is_wp_error($schedule_edit);
        }

        wp_send_json_success([
            'agreement' => [
                'id' => $agreement->id,
                'agreement_number' => $agreement->agreement_number,
                'payer_type' => $agreement->payer_type,
                'payer_id' => $agreement->payer_id,
                'activity_type' => $agreement->activity_type,
                'start_date' => $agreement->start_date,
                'end_date' => $agreement->end_date,
                'status' => $agreement->status,
                'notes' => $agreement->notes,
                'template_id' => $agreement->template_id,
                'total_amount' => $agreement->total_amount,
                'financial_status' => $financial_status,
                'can_edit_financial_fields' => $can_edit_financial_fields,
                'can_reschedule_installments' => $can_reschedule_installments,
                'lock_reasons' => $lock_reasons,
            ],
            'fees' => $fees_data,
        ]);
    }

    public function ajax_agr_search_payer(): void
    {
        $this->guard();
        global $wpdb;
        $q = sanitize_text_field($_POST['q'] ?? '');
        $payer_type = sanitize_text_field($_POST['payer_type'] ?? 'customer');
        $like = '%' . $wpdb->esc_like($q) . '%';
        $results = [];

        if ($payer_type === 'customer') {
            $table = $wpdb->prefix . 'olama_customers';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, customer_name AS text FROM {$table} WHERE customer_name LIKE %s OR phone LIKE %s LIMIT 15",
                $like,
                $like
            ));
            foreach ($rows as $r)
                $results[] = ['id' => $r->id, 'text' => $r->text];
        } elseif ($payer_type === 'family') {
            foreach (Olama_Reg_Family::search($q, 15) as $family) {
                $results[] = [
                    'id'   => $family->uid,
                    'text' => $family->uid . ' - ' . $family->name,
                ];
            }
        }

        wp_send_json_success(['results' => $results]);
    }

    public function ajax_agr_get_participants(): void
    {
        $this->guard();
        global $wpdb;
        $payer_type = sanitize_text_field($_POST['payer_type'] ?? 'customer');
        $payer_id = sanitize_text_field($_POST['payer_id'] ?? '');
        $results = [];

        if ($payer_type === 'customer' && $payer_id) {
            $customer_int_id = 0;
            if (is_numeric($payer_id)) {
                $customer_int_id = (int) $payer_id;
            } else {
                $customer_int_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s LIMIT 1",
                    $payer_id
                ));
            }

            if ($customer_int_id) {
                $table = $wpdb->prefix . 'olama_customer_children';
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id, child_name AS text FROM {$table} WHERE customer_id = %d AND is_active = 1", $customer_int_id));
                foreach ($rows as $r)
                    $results[] = ['id' => $r->id, 'text' => $r->text];
            }
        } elseif ($payer_type === 'family' && $payer_id) {
            foreach (Olama_Reg_Student::get_family_students($payer_id) as $student) {
                $results[] = [
                    'id'   => $student->student_uid,
                    'text' => $student->student_name,
                ];
            }
        }

        wp_send_json_success(['results' => $results]);
    }

    public function ajax_agr_save_fee(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $agreement_id = (int) ($_POST['agreement_id'] ?? 0);
        $is_fee_amendment = !empty($_POST['amendment_reason']) && $id === 0;
        if ( $is_fee_amendment && ! Olama_Reg_Access::can_any( [ 'olama_create_agreement_amendment' ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'olama-registration' ) ], 403 );
        }

        if ($id > 0) {
            $existing_fee = Olama_Reg_Agreement_Fees::get($id);
            if ($existing_fee) {
                $agreement_id = (int) $existing_fee->agreement_id;
            }
        }

        if ($agreement_id > 0 && class_exists('Olama_Reg_Agreement_Policy')) {
            $financial_edit = Olama_Reg_Agreement_Policy::can_edit_financial_fields($agreement_id);
            if (is_wp_error($financial_edit) && !$is_fee_amendment) {
                wp_send_json_error(['message' => $financial_edit->get_error_message()]);
            }
        }

        $data = [
            'child_id' => (isset($_POST['child_id']) && $_POST['child_id'] !== '') ? sanitize_text_field($_POST['child_id']) : null,
            'fee_category' => sanitize_text_field($_POST['fee_category'] ?? ''),
            'label' => sanitize_text_field($_POST['label'] ?? ''),
            'amount' => (float) ($_POST['amount'] ?? 0),
            'discount' => (float) ($_POST['discount'] ?? 0),
            'due_date' => sanitize_text_field($_POST['due_date'] ?? ''),
        ];
        if (empty($data['due_date']))
            $data['due_date'] = null;

        $template = is_numeric($data['fee_category']) ? Olama_Reg_Billing_Fees::get_template((int) $data['fee_category']) : null;
        if (!$template || ($template->subject_type ?? '') !== 'agreement') {
            wp_send_json_error(['message' => __('يجب اختيار نموذج عقد صالح من نماذج الرسوم المرتبطة بالعقود فقط.', 'olama-registration')]);
        }

        // The template owns the gross amount and description. The agreement
        // line may only add a participant, discount, and due date.
        $data['label'] = (string) $template->template_name;
        $data['amount'] = array_sum(array_map(
            static fn($item) => (float) ($item['amount'] ?? 0),
            (array) $template->items
        ));

        if ($data['discount'] < 0 || $data['discount'] > $data['amount']) {
            wp_send_json_error(['message' => __('الخصم لا يجوز أن يتجاوز المبلغ الأصلي.', 'olama-registration')]);
        }

        if ($id > 0) {
            $result = Olama_Reg_Agreement_Fees::update($id, $data);
            if ($result) {
                if (!$is_fee_amendment) {
                    Olama_Reg_Agreement_Invoice::generate_default_due_schedule($agreement_id);
                }
                wp_send_json_success([
                    'message'  => __('تم تحديث الرسم.', 'olama-registration'),
                    'total'    => Olama_Reg_Agreement::get($agreement_id)->total_amount,
                    'schedule' => Olama_Reg_Agreement_Invoice::get_due_schedule($agreement_id),
                ]);
            }
        } else {
            $data['agreement_id'] = $agreement_id;
            $new_id = Olama_Reg_Agreement_Fees::add($agreement_id, $data, $is_fee_amendment);
            if ($new_id) {
                if (!$is_fee_amendment) {
                    Olama_Reg_Agreement_Invoice::generate_default_due_schedule($agreement_id);
                }

                // If this is a fee amendment, create and auto-post an amendment
                if ($is_fee_amendment && class_exists('Olama_Reg_Agreement_Amendment')) {
                    $amendment_reason = sanitize_text_field($_POST['amendment_reason'] ?? '');
                    $amendment_type = sanitize_key($_POST['amendment_type'] ?? 'add_fee');
                    $agreement = Olama_Reg_Agreement::get($agreement_id);
                    $fee_net = round((float) $data['amount'] - (float) $data['discount'], 3);
                    $old_total = $agreement ? round((float) $agreement->total_amount - $fee_net, 3) : 0;
                    $new_total = $agreement ? round((float) $agreement->total_amount, 3) : 0;
                    $diff = $fee_net;

                    $amendment_payload = [
                        'amendment_type' => $amendment_type,
                        'effective_date' => current_time('Y-m-d'),
                        'reason' => $amendment_reason,
                        'old_total' => $old_total,
                        'new_total' => $new_total,
                    ];
                    $amendment_id = Olama_Reg_Agreement_Amendment::create($agreement_id, $amendment_payload);
                    if (!is_wp_error($amendment_id)) {
                        // Add the fee as a line in the amendment
                        $invoice_id = class_exists('Olama_Reg_Agreement_Policy') ? Olama_Reg_Agreement_Policy::get_linked_invoice_id($agreement_id) : 0;
                        Olama_Reg_Agreement_Amendment::add_line($amendment_id, $agreement_id, $invoice_id, [
                            'line_type' => 'add_fee',
                            'related_fee_id' => $new_id,
                            'description' => $data['label'] ?: __('بند رسوم جديد', 'olama-registration'),
                            'old_amount' => 0,
                            'new_amount' => $diff,
                            'difference_amount' => $diff,
                            'after_state' => [
                                'fee_id' => $new_id,
                                'fee_category' => $data['fee_category'],
                                'label' => $data['label'],
                                'amount' => $data['amount'],
                                'discount' => $data['discount'],
                            ],
                        ]);
                        // Auto-approve
                        Olama_Reg_Agreement_Amendment::approve($amendment_id);
                        // Auto-post
                        Olama_Reg_Agreement_Amendment::post($amendment_id);
                    }
                }

                wp_send_json_success([
                    'message'  => __('تمت إضافة الرسم وترحيل التعديل.', 'olama-registration'),
                    'id'       => $new_id,
                    'total'    => Olama_Reg_Agreement::get($agreement_id)->total_amount,
                    'schedule' => Olama_Reg_Agreement_Invoice::get_due_schedule($agreement_id),
                ]);
            }
        }
        wp_send_json_error(['message' => __('حدث خطأ أثناء حفظ الرسم.', 'olama-registration')]);
    }

    public function ajax_agr_delete_fee(): void
    {
        $this->guard();
        $fee_id = (int) ($_POST['id'] ?? 0);
        $agreement_id = (int) ($_POST['agreement_id'] ?? 0);

        if ($fee_id > 0) {
            $existing_fee = Olama_Reg_Agreement_Fees::get($fee_id);
            if ($existing_fee) {
                $agreement_id = (int) $existing_fee->agreement_id;
            }
        }

        if (!$agreement_id || !$fee_id) {
            wp_send_json_error(['message' => __('بيانات غير صالحة.', 'olama-registration')]);
        }

        if (
            class_exists( 'Olama_Reg_Agreement_Policy' )
            && Olama_Reg_Agreement_Policy::is_financially_locked( $agreement_id )
            && ! Olama_Reg_Access::can_any( [ 'olama_cancel_financial_agreement' ] )
        ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'olama-registration' ) ], 403 );
        }

        $args = [
            'reason'         => sanitize_text_field($_POST['reason'] ?? ''),
            'effective_date' => sanitize_text_field($_POST['effective_date'] ?? ''),
            'notes'          => sanitize_textarea_field($_POST['notes'] ?? ''),
            'paid_handling'  => sanitize_text_field($_POST['paid_handling'] ?? 'credit'),
        ];

        $result = Olama_Reg_Agreement_Fees::cancel_fee_item($agreement_id, $fee_id, $args);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        if ($result['mode'] === 'direct_delete') {
            Olama_Reg_Agreement_Invoice::generate_default_due_schedule($agreement_id);
        }

        $agreement = Olama_Reg_Agreement::get($agreement_id);
        $result['total'] = $agreement ? $agreement->total_amount : 0.0;
        $result['schedule'] = Olama_Reg_Agreement_Invoice::get_due_schedule($agreement_id);

        wp_send_json_success($result);
    }

    public function ajax_agr_add_clause(): void
    {
        $this->guard();
        $agreement_id = (int) ($_POST['agreement_id'] ?? 0);
        $text = sanitize_textarea_field($_POST['clause_text'] ?? '');

        $id = Olama_Reg_Agreement_Clauses::add($agreement_id, $text);
        if ($id) {
            wp_send_json_success(['message' => __('تمت الإضافة.', 'olama-registration'), 'id' => $id]);
        }
        wp_send_json_error(['message' => __('حدث خطأ.', 'olama-registration')]);
    }

    public function ajax_agr_save_clause(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);
        $text = sanitize_textarea_field($_POST['clause_text'] ?? '');

        if (Olama_Reg_Agreement_Clauses::update($id, $text)) {
            wp_send_json_success(['message' => __('تم الحفظ.', 'olama-registration')]);
        }
        wp_send_json_error(['message' => __('حدث خطأ.', 'olama-registration')]);
    }

    public function ajax_agr_delete_clause(): void
    {
        $this->guard();
        $id = (int) ($_POST['id'] ?? 0);

        global $wpdb;
        $agreement_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT agreement_id FROM {$wpdb->prefix}olama_agreement_clauses WHERE id = %d",
            $id
        ));

        if ($agreement_id > 0 && class_exists('Olama_Reg_Agreement_Policy')) {
            $financial_edit = Olama_Reg_Agreement_Policy::can_edit_financial_fields($agreement_id);
            if (is_wp_error($financial_edit)) {
                wp_send_json_error(['message' => $financial_edit->get_error_message()]);
            }
        }

        if (Olama_Reg_Agreement_Clauses::delete($id)) {
            wp_send_json_success(['message' => __('تم الحذف.', 'olama-registration')]);
        }
        wp_send_json_error(['message' => __('حدث خطأ.', 'olama-registration')]);
    }

    public function ajax_agr_reorder_clauses(): void
    {
        $this->guard();
        $ordered_ids = isset($_POST['ordered_ids']) && is_array($_POST['ordered_ids']) ? array_map('intval', $_POST['ordered_ids']) : [];
        if (!empty($ordered_ids)) {
            Olama_Reg_Agreement_Clauses::reorder($ordered_ids);
            wp_send_json_success();
        }
        wp_send_json_error();
    }

    public function ajax_agr_save_due_schedule(): void
    {
        $this->guard();
        $agreement_id = (int) ($_POST['agreement_id'] ?? 0);
        $is_amendment_reschedule = !empty($_POST['amendment_reason']);
        if (
            $is_amendment_reschedule
            && ! Olama_Reg_Access::can_any( [ 'olama_reschedule_agreement_installments' ] )
        ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'olama-registration' ) ], 403 );
        }
        $agreement = Olama_Reg_Agreement::get($agreement_id);
        if (!$is_amendment_reschedule && $agreement && $agreement->status === 'completed' && class_exists('Olama_Reg_Agreement_Policy') && Olama_Reg_Agreement_Policy::is_financially_locked($agreement_id)) {
            wp_send_json_error(['message' => __('لا يمكن إعادة إكمال عقد مقفل مالياً. استخدم مسار تعديل العقد.', 'olama-registration')]);
        }
        $lines = json_decode(stripslashes($_POST['lines'] ?? '[]'), true);
        if (!is_array($lines)) {
            $lines = [];
        }

        if (!$is_amendment_reschedule && $agreement_id > 0 && class_exists('Olama_Reg_Agreement_Policy')) {
            $schedule_edit = Olama_Reg_Agreement_Policy::can_reschedule_installments($agreement_id);
            if (is_wp_error($schedule_edit)) {
                wp_send_json_error(['message' => $schedule_edit->get_error_message()]);
            }
        }

        $result = Olama_Reg_Agreement_Invoice::save_due_schedule($agreement_id, $lines, 0, $is_amendment_reschedule);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('تم حفظ توزيع الاستحقاق.', 'olama-registration'),
            'schedule' => Olama_Reg_Agreement_Invoice::get_due_schedule($agreement_id),
        ]);
    }

    public function ajax_agr_generate_due_schedule(): void
    {
        $this->guard();
        $agreement_id = (int) ($_POST['agreement_id'] ?? 0);
        $is_amendment_reschedule = !empty($_POST['amendment_reason']);
        if (
            $is_amendment_reschedule
            && ! Olama_Reg_Access::can_any( [ 'olama_reschedule_agreement_installments' ] )
        ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'olama-registration' ) ], 403 );
        }
        $count = max(1, absint($_POST['count'] ?? 0));

        if (!$is_amendment_reschedule && $agreement_id > 0 && class_exists('Olama_Reg_Agreement_Policy')) {
            $schedule_edit = Olama_Reg_Agreement_Policy::can_reschedule_installments($agreement_id);
            if (is_wp_error($schedule_edit)) {
                wp_send_json_error(['message' => $schedule_edit->get_error_message()]);
            }
        }

        $result = Olama_Reg_Agreement_Invoice::generate_default_due_schedule($agreement_id, $count, $is_amendment_reschedule);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('تم إنشاء توزيع الاستحقاق المقترح.', 'olama-registration'),
            'schedule' => Olama_Reg_Agreement_Invoice::get_due_schedule($agreement_id),
        ]);
    }

    public function ajax_agr_create_amendment(): void
    {
        $this->guard();
        if (!current_user_can('manage_options') && !current_user_can('olama_create_agreement_amendment')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }
        $agreement_id = (int) ($_POST['agreement_id'] ?? 0);

        if (!class_exists('Olama_Reg_Agreement_Amendment')) {
            wp_send_json_error(['message' => __('نظام تعديلات العقود غير محمل.', 'olama-registration')]);
        }

        $payload = [
            'amendment_type' => sanitize_key($_POST['amendment_type'] ?? 'correction_error'),
            'effective_date' => sanitize_text_field($_POST['effective_date'] ?? current_time('Y-m-d')),
            'reason' => sanitize_text_field($_POST['reason'] ?? ''),
            'admin_notes' => sanitize_textarea_field($_POST['admin_notes'] ?? $_POST['notes'] ?? ''),
        ];
        if (isset($_POST['new_total']) && $_POST['new_total'] !== '') {
            $payload['new_total'] = (float) $_POST['new_total'];
        }

        $result = Olama_Reg_Agreement_Amendment::create($agreement_id, $payload);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $amendment = Olama_Reg_Agreement_Amendment::get((int) $result);
        wp_send_json_success([
            'message' => __('تم إنشاء مسودة تعديل العقد.', 'olama-registration'),
            'amendment' => $amendment,
        ]);
    }

    public function ajax_agr_approve_amendment(): void
    {
        $this->guard();
        if (!current_user_can('manage_options') && !current_user_can('olama_approve_agreement_amendment')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }
        $id = (int) ($_POST['amendment_id'] ?? 0);
        $result = Olama_Reg_Agreement_Amendment::approve($id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => __('تم اعتماد تعديل العقد.', 'olama-registration'), 'amendment' => Olama_Reg_Agreement_Amendment::get($id)]);
    }

    public function ajax_agr_reject_amendment(): void
    {
        $this->guard();
        if (!current_user_can('manage_options') && !current_user_can('olama_approve_agreement_amendment')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }
        $id = (int) ($_POST['amendment_id'] ?? 0);
        $result = Olama_Reg_Agreement_Amendment::reject($id, sanitize_textarea_field($_POST['reason'] ?? ''));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => __('تم رفض تعديل العقد.', 'olama-registration'), 'amendment' => Olama_Reg_Agreement_Amendment::get($id)]);
    }

    public function ajax_agr_cancel_amendment(): void
    {
        $this->guard();
        if (!current_user_can('manage_options') && !current_user_can('olama_cancel_financial_agreement')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }
        $id = (int) ($_POST['amendment_id'] ?? 0);
        $result = Olama_Reg_Agreement_Amendment::cancel($id, sanitize_textarea_field($_POST['reason'] ?? ''));
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => __('تم إلغاء تعديل العقد.', 'olama-registration'), 'amendment' => Olama_Reg_Agreement_Amendment::get($id)]);
    }

    public function ajax_agr_post_amendment(): void
    {
        $this->guard();
        if (!current_user_can('manage_options') && !current_user_can('olama_post_agreement_amendment')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }
        $id = (int) ($_POST['amendment_id'] ?? 0);
        $result = Olama_Reg_Agreement_Amendment::post($id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        wp_send_json_success(['message' => __('تم ترحيل تعديل العقد.', 'olama-registration'), 'amendment' => Olama_Reg_Agreement_Amendment::get($id)]);
    }

    public function ajax_agr_get_amendments(): void
    {
        $this->guard();
        if (!current_user_can('manage_options') && !current_user_can('olama_view_agreement_audit') && !current_user_can('olama_create_agreement_amendment')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }
        $agreement_id = (int) ($_POST['agreement_id'] ?? 0);
        wp_send_json_success(['amendments' => Olama_Reg_Agreement_Amendment::get_by_agreement($agreement_id)]);
    }

    public function ajax_agr_preview_amendment(): void
    {
        $this->guard();
        if (!current_user_can('manage_options') && !current_user_can('olama_create_agreement_amendment')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }
        $agreement_id = (int) ($_POST['agreement_id'] ?? 0);
        $new_total = (float) ($_POST['new_total'] ?? 0);
        $preview = Olama_Reg_Agreement_Amendment::calculate_difference($agreement_id, $new_total);
        wp_send_json_success(['preview' => $preview]);
    }

    public function ajax_agr_complete(): void
    {
        $this->guard();
        $agreement_id = (int) ($_POST['agreement_id'] ?? 0);
        $lines = json_decode(stripslashes($_POST['lines'] ?? '[]'), true);
        if (is_array($lines)) {
            $saved = Olama_Reg_Agreement_Invoice::save_due_schedule($agreement_id, $lines);
            if (is_wp_error($saved)) {
                wp_send_json_error(['message' => $saved->get_error_message()]);
            }
        }

        $result = Olama_Reg_Agreement_Invoice::complete_agreement($agreement_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('تم إكمال العقد وإنشاء الفاتورة بنجاح.', 'olama-registration')]);
    }

    public function ajax_agr_generate_invoice(): void
    {
        $this->guard();
        wp_send_json_error(['message' => __('يتم إنشاء فاتورة العقد فقط عند إكمال العقد بعد تحقق جميع الشروط.', 'olama-registration')]);
        $agreement_id = (int) ($_POST['agreement_id'] ?? 0);
        $fee_ids = isset($_POST['fee_ids']) && is_array($_POST['fee_ids']) ? array_map('intval', $_POST['fee_ids']) : [];

        if (empty($fee_ids)) {
            wp_send_json_error(['message' => __('يجب تحديد رسم واحد على الأقل.', 'olama-registration')]);
        }

        $result = Olama_Reg_Agreement_Invoice::generate_invoice($agreement_id, $fee_ids);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('تم إصدار الفاتورة بنجاح.', 'olama-registration'),
            'invoice_id' => $result
        ]);
    }

    public function ajax_agr_get_unpaid_fees(): void
    {
        $this->guard();
        $agreement_id = (int) ($_POST['agreement_id'] ?? 0);
        if (!$agreement_id) {
            wp_send_json_error(['message' => __('معرف العقد مطلوب.', 'olama-registration')]);
        }

        $fees = Olama_Reg_Agreement_Fees::get_by_agreement($agreement_id);
        $unpaid_fees = [];
        if ($fees) {
            foreach ($fees as $fee) {
                if ($fee->paid_status === 'unpaid') {
                    $unpaid_fees[] = $fee;
                }
            }
        }

        wp_send_json_success(['fees' => $unpaid_fees]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CUSTOMER HUB — Phase 2 Handlers
    // Nonce: 'os_hub_nonce'  (separate from main plugin nonce)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Separate guard for hub endpoints (uses os_hub_nonce).
     */
    private function hub_guard(): void
    {
        check_ajax_referer('os_hub_nonce', 'nonce');
    }

    private function hub_user_can(array $caps): bool
    {
        if (class_exists('Olama_Reg_Payment_Policy')) {
            return Olama_Reg_Payment_Policy::current_user_can_any($caps);
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        foreach ($caps as $cap) {
            if (current_user_can($cap)) {
                return true;
            }
        }

        return false;
    }

    private function hub_require_caps(array $caps): void
    {
        if (! $this->hub_user_can($caps)) {
            wp_send_json_error(['message' => __('Unauthorized.', 'olama-registration')], 403);
        }
    }

    // ── os_hub_search ─────────────────────────────────────────────────────────

    /**
     * Unified search across families and external customers.
     *
     * POST: q (string, min 2), type ('family'|'external')
     * Returns: { results: [ { uid, name, phone, is_active, student_count|child_count } ] }
     */
    public function hub_load_form(): void
    {
        $this->hub_guard();

        $form = sanitize_key($_POST['form'] ?? '');
        $uid  = sanitize_text_field($_POST['uid'] ?? '');
        $type = sanitize_key($_POST['type'] ?? 'family');
        $id   = absint($_POST['id'] ?? 0);

        if ($form !== 'agreement') {
            wp_send_json_error(['message' => __('Unsupported hub form.', 'olama-registration')], 400);
        }

        $this->hub_require_caps([
            'olama_manage_registration_agreements',
        ]);

        if ($id <= 0 && $uid === '') {
            wp_send_json_error(['message' => __('Missing customer context.', 'olama-registration')], 400);
        }

        $payer_type = ($type === 'family') ? 'family' : 'customer';
        $payer_uid  = $uid;
        if ($payer_type === 'customer') {
            $customer = class_exists('Olama_Reg_Customer') ? Olama_Reg_Customer::get_by_uid($uid) : null;
            if ($customer && ! empty($customer->id)) {
                $payer_uid = (string) $customer->id;
            }
        }

        $old_get = $_GET;
        $_GET['action']     = $id > 0 ? 'edit' : 'new';
        $_GET['id']         = $id;
        if ($id <= 0) {
            $_GET['payer_type'] = $payer_type;
            $_GET['payer_uid']  = $payer_uid;
        }
        $_GET['embedded']   = 'hub';

        ob_start();
        include OLAMA_REG_PATH . 'admin/views/html-agreements-edit.php';
        $html = ob_get_clean();
        $_GET = $old_get;

        $participants = [];
        global $wpdb;
        if ($id > 0 && class_exists('Olama_Reg_Agreement')) {
            $agreement = Olama_Reg_Agreement::get($id);
            if ($agreement) {
                $payer_type = $agreement->payer_type;
                $payer_uid = (string) $agreement->payer_id;
            }
        }

        if ($payer_type === 'family') {
            $rows = array_map(static function ($student) {
                return (object) [
                    'id'   => $student->student_uid,
                    'text' => $student->student_name,
                ];
            }, Olama_Reg_Student::get_family_students($payer_uid));
        } else {
            $rows = is_numeric($payer_uid)
                ? $wpdb->get_results($wpdb->prepare(
                    "SELECT id, child_name AS text FROM {$wpdb->prefix}olama_customer_children WHERE customer_id = %d AND is_active = 1",
                    (int) $payer_uid
                ))
                : [];
        }

        foreach ((array) $rows as $row) {
            $participants[] = ['id' => $row->id, 'text' => $row->text];
        }

        wp_send_json_success([
            'html' => $html,
            'title' => $id > 0 ? __('تعديل العقد', 'olama-registration') : __('إضافة عقد جديد', 'olama-registration'),
            'payerType' => $payer_type,
            'payerUid' => $payer_uid,
            'participants' => $participants,
        ]);
    }

    public function hub_search(): void
    {
        $this->hub_guard();
        $this->hub_require_caps( [ 'olama_manage_registration_families' ] );

        $query = sanitize_text_field($_POST['q'] ?? '');
        $type  = sanitize_key($_POST['type'] ?? 'family');

        if (strlen($query) < 2) {
            wp_send_json_success(['results' => []]);
        }

        $results = ($type === 'family')
            ? $this->hub_search_families($query)
            : $this->hub_search_customers($query);

        wp_send_json_success(['results' => $results]);
    }

    private function hub_search_families(string $query): array
    {
        return Olama_Reg_Family::search($query, 20);
    }

    private function hub_search_customers(string $query): array
    {
        global $wpdb;
        $like = '%' . $wpdb->esc_like($query) . '%';

        $sql = "SELECT
                    c.customer_uid AS uid,
                    c.customer_name AS name,
                    COALESCE(c.phone, '') AS phone,
                    c.is_active,
                    COUNT(ch.id) AS child_count,
                    c.id AS internal_id
                FROM {$wpdb->prefix}olama_customers c
                LEFT JOIN {$wpdb->prefix}olama_customer_children ch
                    ON ch.customer_id = c.id AND ch.is_active = 1
                WHERE c.is_active = 1
                  AND ( c.customer_name LIKE %s
                     OR c.customer_uid  LIKE %s
                     OR c.phone         LIKE %s
                     OR ch.child_name   LIKE %s )
                GROUP BY c.id
                ORDER BY c.customer_name
                LIMIT 20";

        return $wpdb->get_results(
            $wpdb->prepare($sql, $like, $like, $like, $like)
        ) ?: [];
    }

    // ── os_hub_counts ─────────────────────────────────────────────────────────

    /**
     * Batch-load badge counts for all 8 tiles in one round-trip.
     *
     * POST: uid (string), type ('family'|'external'), year (int, optional)
     * Returns: { counts: { profile, agreements, invoices, payments,
     *                      children, financial, history, settlements } }
     */
    public function hub_counts(): void
    {
        $this->hub_guard();
        $this->hub_require_caps( [ 'olama_manage_registration_families' ] );

        $uid  = sanitize_text_field($_POST['uid']  ?? '');
        $type = sanitize_key($_POST['type'] ?? 'family');
        $year = (int) ($_POST['year'] ?? 0);

        if (! $year && class_exists('Olama_School_Academic')) {
            $active = Olama_School_Academic::get_active_year();
            $year   = $active ? (int) $active->id : 0;
        }

        global $wpdb;

        if ($type === 'family') {
            // Hub URLs/search use the Oracle file number, while financial
            // records use Olama Core's canonical family UID.
            $core_family = Olama_Reg_Core_Gateway::family($uid);
            if (!$core_family) {
                wp_send_json_error([
                    'code'    => 'not_found',
                    'message' => __('العائلة المحددة غير موجودة في النظام أو تم حذفها.', 'olama-registration'),
                ]);
            }
            $uid = (string) $core_family->family_uid;

            // Agreements
            $agr_sql  = "SELECT COUNT(*) FROM {$wpdb->prefix}olama_agreements
                          WHERE payer_type = 'family' AND payer_id = %s";
            $agr_args = [$uid];
            if ($year) {
                $agr_sql .= ' AND academic_year_id = %d';
                $agr_args[] = $year;
            }
            $agreements = (int) $wpdb->get_var($wpdb->prepare($agr_sql, $agr_args));

            // Invoices (current year or all)
            $inv_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}olama_invoices
                         WHERE family_uid = %s AND status != 'cancelled'";
            $inv_args = [$uid];
            if ($year) {
                $inv_sql .= ' AND academic_year_id = %d';
                $inv_args[] = $year;
            }
            $invoices = (int) $wpdb->get_var($wpdb->prepare($inv_sql, $inv_args));

            // Payments
            $pay_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}olama_payments p
                         INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = p.invoice_id
                         WHERE p.family_uid = %s";
            $pay_args = [$uid];
            if ($year) {
                $pay_sql .= ' AND i.academic_year_id = %d';
                $pay_args[] = $year;
            }
            $payments = (int) $wpdb->get_var($wpdb->prepare($pay_sql, $pay_args));

            // Children (students)
            $children = count(array_filter(
                Olama_Reg_Student::get_family_students($uid),
                static fn($student) => (bool) $student->is_active
            ));

            // History / audit: count the financial records shown in the history tile.
            $hist_year_clause         = $year ? ' AND academic_year_id = %d' : '';
            $hist_payment_year_clause = $year ? ' AND i.academic_year_id = %d' : '';
            $hist_year_args           = $year ? [$year] : [];
            $invoice_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_invoices
                  WHERE family_uid = %s {$hist_year_clause}",
                array_merge([$uid], $hist_year_args)
            )) ?: [0];
            $payment_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.id FROM {$wpdb->prefix}olama_payments p
                  INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = p.invoice_id
                  WHERE p.family_uid = %s {$hist_payment_year_clause}",
                array_merge([$uid], $hist_year_args)
            )) ?: [0];
            $agreement_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_agreements
                  WHERE payer_type = 'family' AND payer_id = %s {$hist_year_clause}",
                array_merge([$uid], $hist_year_args)
            )) ?: [0];

            $invoice_ids_str   = implode(',', array_map('intval', $invoice_ids)) ?: '0';
            $payment_ids_str   = implode(',', array_map('intval', $payment_ids)) ?: '0';
            $agreement_ids_str = implode(',', array_map('intval', $agreement_ids)) ?: '0';
            $history = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}olama_billing_audit
                 WHERE (entity_type = 'invoice' AND entity_id IN ($invoice_ids_str))
                    OR (entity_type = 'payment' AND entity_id IN ($payment_ids_str))
                    OR (entity_type = 'agreement' AND entity_id IN ($agreement_ids_str))"
            );

            // Settlements (families only)
            $settle_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}olama_settlement_receipts
                            WHERE family_id = %s";
            $settle_args = [(string) $core_family->oracle_family_id];
            if ($year) {
                // The table may store year via dates; check if academic_year_id column exists
                $cols = $wpdb->get_col("DESCRIBE {$wpdb->prefix}olama_settlement_receipts", 0);
                if (in_array('academic_year_id', $cols)) {
                    $settle_sql .= ' AND academic_year_id = %d';
                    $settle_args[] = $year;
                }
            }
            $settlements = (int) $wpdb->get_var($wpdb->prepare($settle_sql, $settle_args));

            // Financial summary (show badge count if current balance > 0)
            $family_card_data = null;
            if ( class_exists( 'Olama_Reg_Family_Financial_Summary' ) ) {
                $summary = Olama_Reg_Family_Financial_Summary::get_family_summary( $uid, $year );
                $financial = $summary->current_balance > 0.009 ? 1 : 0;

                // Fetch Linked Student Names
                $students_list = array_filter(
                    Olama_Reg_Student::get_family_students($uid),
                    static fn($student) => (bool) $student->is_active
                );
                $students_str = implode('، ', wp_list_pluck($students_list, 'student_name'));

                // Build family financial card payload
                $family_card_data = [
                    'family_uid'      => (string) $core_family->oracle_family_id,
                    'total_fees'      => (float) $summary->total_fees,
                    'total_billed'    => (float) $summary->gross_invoiced,
                    'total_paid'      => (float) $summary->total_paid,
                    'total_balance'   => (float) $summary->current_balance,
                    'students'        => $students_str ? $students_str : __( 'لا يوجد طلاب مسجلين', 'olama-registration' )
                ];
            } else {
                $financial = null;
            }
            $statement = $invoices + $payments;

        } else {
            // External customer — resolve internal id from UID
            $customer = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s LIMIT 1",
                $uid
            ));
            if (!$customer) {
                wp_send_json_error([
                    'code'    => 'not_found',
                    'message' => __('العميل المحدد غير موجود في النظام أو تم حذفه.', 'olama-registration'),
                ]);
            }
            $cid = (int) $customer->id;

            $year_clause = '';
            $payment_year_clause = '';
            $year_args   = [];
            if ($year) {
                $year_clause = ' AND academic_year_id = %d';
                $payment_year_clause = ' AND i.academic_year_id = %d';
                $year_args[] = $year;
            }

            $agreements = 0;
            if ($cid) {
                $agreements = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}olama_agreements
                      WHERE payer_type = 'customer' AND payer_id = %d {$year_clause}",
                    array_merge([$cid], $year_args)
                ));
            }

            $invoices = 0;
            if ($cid) {
                $invoices = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}olama_invoices
                      WHERE (ext_customer_id = %d OR family_uid = %s)
                        AND status != 'cancelled' {$year_clause}",
                    array_merge([$cid, $uid], $year_args)
                ));
            }

            $payments = 0;
            if ($cid) {
                $payments = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}olama_payments p
                      INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = p.invoice_id
                      WHERE (i.ext_customer_id = %d OR i.family_uid = %s OR p.family_uid = %s)
                        {$payment_year_clause}",
                    array_merge([$cid, $uid, $uid], $year_args)
                ));
            }

            $children = $cid ? (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}olama_customer_children
                  WHERE customer_id = %d AND is_active = 1",
                $cid
            )) : 0;

            $invoice_ids = $cid ? ($wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_invoices
                  WHERE (ext_customer_id = %d OR family_uid = %s) {$year_clause}",
                array_merge([$cid, $uid], $year_args)
            )) ?: [0]) : [0];
            $payment_ids = $cid ? ($wpdb->get_col($wpdb->prepare(
                "SELECT p.id FROM {$wpdb->prefix}olama_payments p
                  INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = p.invoice_id
                  WHERE (i.ext_customer_id = %d OR i.family_uid = %s OR p.family_uid = %s)
                    {$payment_year_clause}",
                array_merge([$cid, $uid, $uid], $year_args)
            )) ?: [0]) : [0];
            $agreement_ids = $cid ? ($wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_agreements
                  WHERE payer_type = 'customer' AND payer_id = %d {$year_clause}",
                array_merge([$cid], $year_args)
            )) ?: [0]) : [0];

            $invoice_ids_str   = implode(',', array_map('intval', $invoice_ids)) ?: '0';
            $payment_ids_str   = implode(',', array_map('intval', $payment_ids)) ?: '0';
            $agreement_ids_str = implode(',', array_map('intval', $agreement_ids)) ?: '0';
            $history = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}olama_billing_audit
                 WHERE (entity_type = 'invoice' AND entity_id IN ($invoice_ids_str))
                    OR (entity_type = 'payment' AND entity_id IN ($payment_ids_str))
                    OR (entity_type = 'agreement' AND entity_id IN ($agreement_ids_str))"
            );
            $financial    = null;
            $statement    = $invoices + $payments;
            $settlements  = null; // Not applicable for external customers
        }

        wp_send_json_success([
            'counts' => [
                'profile'     => 1, // Always has a profile
                'agreements'  => $agreements,
                'invoices'    => $invoices,
                'payments'    => $payments,
                'children'    => $children,
                'financial'   => $financial,
                'statement'   => $statement,
                'history'     => $history,
                'settlements' => $settlements,
            ],
            // Also return mini financial summary for identity header
            'financial_mini' => ($type === 'family')
                ? $this->hub_financial_mini($uid, $year)
                : null,
            'family_financial_card' => ($type === 'family')
                ? $family_card_data
                : null,
        ]);
    }

    /**
     * Returns minimal financial data for the identity header.
     */
    private function hub_financial_mini(string $family_uid, int $year): array
    {
        if ( class_exists( 'Olama_Reg_Family_Financial_Summary' ) ) {
            $summary = Olama_Reg_Family_Financial_Summary::get_family_summary( $family_uid, $year );
            return [
                'total_billed'  => (float) $summary->gross_invoiced,
                'total_paid'    => (float) $summary->total_paid,
                'total_balance' => (float) $summary->current_balance,
            ];
        }

        global $wpdb;
        $args = [$family_uid];
        $year_clause = '';
        if ($year) {
            $year_clause = ' AND academic_year_id = %d';
            $args[] = $year;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(total),       0) AS total_billed,
                COALESCE(SUM(amount_paid), 0) AS total_paid,
                COALESCE(SUM(balance),     0) AS total_balance
             FROM {$wpdb->prefix}olama_invoices
             WHERE family_uid = %s
               AND status != 'cancelled'
               {$year_clause}",
            $args
        ));

        return [
            'total_billed'  => $row ? (float) $row->total_billed  : 0,
            'total_paid'    => $row ? (float) $row->total_paid    : 0,
            'total_balance' => $row ? (float) $row->total_balance : 0,
        ];
    }

    // ── os_hub_tile ───────────────────────────────────────────────────────────

    /**
     * Unified tile content loader — single endpoint.
     *
     * POST: tile (string), uid (string), type ('family'|'external'), year (int)
     * Returns: { html: '<string>', meta: {...} }
     */
    public function hub_tile(): void
    {
        $this->hub_guard();

        $tile = sanitize_key($_POST['tile'] ?? '');
        $uid  = sanitize_text_field($_POST['uid']  ?? '');
        $type = sanitize_key($_POST['type'] ?? 'family');
        $year = (int) ($_POST['year'] ?? 0);

        if (! $year && class_exists('Olama_School_Academic')) {
            $active = Olama_School_Academic::get_active_year();
            $year   = $active ? (int) $active->id : 0;
        }

        if ($type === 'family') {
            $core_family = Olama_Reg_Core_Gateway::family($uid);
            if (!$core_family) {
                wp_send_json_error([
                    'code'    => 'not_found',
                    'message' => __('The selected family was not found in Olama Core.', 'olama-registration'),
                ]);
            }
            $uid = (string) $core_family->family_uid;
        }

        // Settlement tile only for families
        if ($tile === 'settlements' && $type !== 'family') {
            wp_send_json_error(['message' => __('هذا القسم للعائلات فقط.', 'olama-registration')]);
        }

        $tile_caps = [
            'profile'     => ['olama_manage_registration_families'],
            'agreements'  => ['olama_manage_registration_agreements'],
            'invoices'    => ['olama_manage_registration_invoices'],
            'payments'    => ['olama_manage_registration_payments', 'olama_record_payments', 'olama_reverse_payments'],
            'children'    => ['olama_manage_registration_families', 'olama_manage_registration_students'],
            'financial'   => ['olama_manage_registration_reports', 'olama_manage_registration_invoices', 'olama_manage_registration_payments'],
            'statement'   => ['olama_manage_registration_reports', 'olama_manage_registration_invoices', 'olama_manage_registration_payments'],
            'history'     => ['olama_manage_registration_reports'],
            'settlements' => ['olama_manage_registration_payments'],
        ];

        if (isset($tile_caps[$tile])) {
            $this->hub_require_caps($tile_caps[$tile]);
        }

        $handlers = [
            'profile'     => [$this, 'hub_tile_profile'],
            'agreements'  => [$this, 'hub_tile_agreements'],
            'invoices'    => [$this, 'hub_tile_invoices'],
            'payments'    => [$this, 'hub_tile_payments'],
            'children'    => [$this, 'hub_tile_children'],
            'financial'   => [$this, 'hub_tile_financial'],
            'statement'   => [$this, 'hub_tile_statement'],
            'history'     => [$this, 'hub_tile_history'],
            'settlements' => [$this, 'hub_tile_settlements'],
        ];

        if (! isset($handlers[$tile]) || ! is_callable($handlers[$tile])) {
            wp_send_json_error(['message' => __('Unknown tile.', 'olama-registration')]);
        }

        $data = call_user_func($handlers[$tile], $uid, $type, $year);
        wp_send_json_success([
            'html' => $data['html'] ?? '',
            'meta' => $data['meta'] ?? [],
        ]);
    }

    // ── Tile: Profile ─────────────────────────────────────────────────────────

    private function hub_tile_profile(string $uid, string $type, int $year): array
    {
        if ($type === 'family') {
            $row = Olama_Reg_Family::get_family($uid);
            if (! $row) {
                return ['html' => $this->hub_empty_state(__('العائلة غير موجودة.', 'olama-registration'))];
            }

            $is_active    = (bool) $row->is_active;
            $status_badge = $is_active
                ? '<span class="os-hub-badge os-hub-badge--green">' . __('نشط', 'olama-registration') . '</span>'
                : '<span class="os-hub-badge os-hub-badge--gray">'  . __('غير نشط', 'olama-registration') . '</span>';

            // ── Data view ──────────────────────────────────────────────────
            $html  = '<div class="os-hub-profile-wrap" data-uid="' . esc_attr($uid) . '" data-type="family">';

            // Core owns enrolled-family profile data. Keep this module read-only.
            $core_family_url = add_query_arg(
                [
                    'page'       => 'olama-core-family-360',
                    'family_id'  => (int) $row->family_uid,
                    'study_year' => Olama_Reg_Academic_Year_Context::core_study_year($year),
                ],
                admin_url('admin.php')
            );
            $html .= '<div class="os-hub-profile-actions">';
            $html .= '<a class="button" href="' . esc_url($core_family_url) . '">';
            $html .= '<span class="dashicons dashicons-external" aria-hidden="true"></span> ';
            $html .= __('فتح العائلة في Olama Core', 'olama-registration');
            $html .= '</a>';
            $html .= '</div>'; // .os-hub-profile-actions

            // Read-only view
            $html .= '<table class="os-hub-data-table widefat fixed striped os-hub-profile-view">';
            $html .= '<tbody>';
            $html .= $this->hub_tr(__('رقم الملف', 'olama-registration'),  esc_html($row->family_uid));
            $html .= $this->hub_tr(__('اسم العائلة', 'olama-registration'), esc_html($row->family_name));
            $html .= $this->hub_tr(__('هاتف الأب', 'olama-registration'),  esc_html($row->father_mobile ?? '—'));
            $html .= $this->hub_tr(__('هاتف الأم', 'olama-registration'),  esc_html($row->mother_mobile ?? '—'));
            $html .= $this->hub_tr(__('العنوان', 'olama-registration'),    esc_html($row->address ?? '—'));
            $html .= $this->hub_tr(__('الحالة', 'olama-registration'),     $status_badge);
            $html .= '</tbody></table>';

            // Inline edit form (hidden by default)
            $html .= '<form class="os-hub-profile-form" id="os-hub-profile-form" style="display:none;" novalidate>';
            $html .= '<input type="hidden" name="uid"  value="' . esc_attr($uid) . '">';
            $html .= '<input type="hidden" name="type" value="family">';
            $html .= '<table class="os-hub-data-table widefat fixed">';
            $html .= '<tbody>';
            $html .= '<tr><th>' . __('اسم العائلة', 'olama-registration') . '</th><td>';
            $html .= '<input type="text" name="family_name" class="widefat" required value="' . esc_attr($row->family_name) . '">';
            $html .= '</td></tr>';
            $html .= '<tr><th>' . __('هاتف الأب', 'olama-registration') . '</th><td>';
            $html .= '<input type="text" name="father_mobile" class="widefat" value="' . esc_attr($row->father_mobile ?? '') . '">';
            $html .= '</td></tr>';
            $html .= '<tr><th>' . __('هاتف الأم', 'olama-registration') . '</th><td>';
            $html .= '<input type="text" name="mother_mobile" class="widefat" value="' . esc_attr($row->mother_mobile ?? '') . '">';
            $html .= '</td></tr>';
            $html .= '<tr><th>' . __('العنوان', 'olama-registration') . '</th><td>';
            $html .= '<input type="text" name="address" class="widefat" value="' . esc_attr($row->address ?? '') . '">';
            $html .= '</td></tr>';
            $html .= '</tbody></table>';
            $html .= '<div class="os-hub-form-actions">';
            $html .= '<button type="submit" class="button button-primary os-hub-form-save">';
            $html .= '<span class="dashicons dashicons-saved" aria-hidden="true"></span> ' . __('حفظ التغييرات', 'olama-registration');
            $html .= '</button>';
            $html .= ' <button type="button" class="button os-hub-form-cancel">' . __('إلغاء', 'olama-registration') . '</button>';
            $html .= '</div>';
            $html .= '</form>';

            // Toast placeholder
            $html .= '<div class="os-hub-profile-toast" aria-live="polite"></div>';
            $html .= '</div>'; // .os-hub-profile-wrap

        } else {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s LIMIT 1",
                $uid
            ));
            if (! $row) {
                return ['html' => $this->hub_empty_state(__('العميل غير موجود.', 'olama-registration'))];
            }

            $is_active    = (bool) $row->is_active;
            $status_badge = $is_active
                ? '<span class="os-hub-badge os-hub-badge--green">' . __('نشط', 'olama-registration') . '</span>'
                : '<span class="os-hub-badge os-hub-badge--gray">'  . __('غير نشط', 'olama-registration') . '</span>';

            $html  = '<div class="os-hub-profile-wrap" data-uid="' . esc_attr($uid) . '" data-type="external">';

            // Action bar
            $html .= '<div class="os-hub-profile-actions">';
            $html .= '<button type="button" class="button os-hub-edit-btn" id="os-hub-profile-edit-btn">';
            $html .= '<span class="dashicons dashicons-edit" aria-hidden="true"></span> ' . __('تعديل', 'olama-registration');
            $html .= '</button>';
            $html .= ' <button type="button" class="button os-hub-toggle-active-btn" data-active="' . ($is_active ? '1' : '0') . '">';
            $html .= '<span class="dashicons ' . ($is_active ? 'dashicons-lock' : 'dashicons-unlock') . '" aria-hidden="true"></span> ';
            $html .= $is_active ? __('تعطيل', 'olama-registration') : __('تفعيل', 'olama-registration');
            $html .= '</button>';
            $html .= '</div>';

            // Read-only view
            $html .= '<table class="os-hub-data-table widefat fixed striped os-hub-profile-view">';
            $html .= '<tbody>';
            $html .= $this->hub_tr(__('رقم العميل', 'olama-registration'), esc_html($row->customer_uid));
            $html .= $this->hub_tr(__('الاسم', 'olama-registration'),      esc_html($row->customer_name));
            $html .= $this->hub_tr(__('الهاتف', 'olama-registration'),     esc_html($row->phone ?? '—'));
            $html .= $this->hub_tr(__('الملاحظات', 'olama-registration'),  esc_html($row->notes ?? '—'));
            $html .= $this->hub_tr(__('الحالة', 'olama-registration'),     $status_badge);
            $html .= '</tbody></table>';

            // Inline edit form
            $html .= '<form class="os-hub-profile-form" id="os-hub-profile-form" style="display:none;" novalidate>';
            $html .= '<input type="hidden" name="uid"  value="' . esc_attr($uid) . '">';
            $html .= '<input type="hidden" name="type" value="external">';
            $html .= '<table class="os-hub-data-table widefat fixed">';
            $html .= '<tbody>';
            $html .= '<tr><th>' . __('الاسم', 'olama-registration') . '</th><td>';
            $html .= '<input type="text" name="customer_name" class="widefat" required value="' . esc_attr($row->customer_name) . '">';
            $html .= '</td></tr>';
            $html .= '<tr><th>' . __('الهاتف', 'olama-registration') . '</th><td>';
            $html .= '<input type="text" name="phone" class="widefat" value="' . esc_attr($row->phone ?? '') . '">';
            $html .= '</td></tr>';
            $html .= '<tr><th>' . __('الملاحظات', 'olama-registration') . '</th><td>';
            $html .= '<textarea name="notes" class="widefat" rows="3">' . esc_textarea($row->notes ?? '') . '</textarea>';
            $html .= '</td></tr>';
            $html .= '</tbody></table>';
            $html .= '<div class="os-hub-form-actions">';
            $html .= '<button type="submit" class="button button-primary os-hub-form-save">';
            $html .= '<span class="dashicons dashicons-saved" aria-hidden="true"></span> ' . __('حفظ التغييرات', 'olama-registration');
            $html .= '</button>';
            $html .= ' <button type="button" class="button os-hub-form-cancel">' . __('إلغاء', 'olama-registration') . '</button>';
            $html .= '</div>';
            $html .= '</form>';

            $html .= '<div class="os-hub-profile-toast" aria-live="polite"></div>';
            $html .= '</div>';
        }

        return ['html' => $html];
    }

    // ── Tile: Agreements ──────────────────────────────────────────────────────

    private function hub_tile_agreements(string $uid, string $type, int $year): array
    {
        global $wpdb;
        $payer_id   = $uid;

        // For external customers, resolve internal id
        if ($type === 'external') {
            $c = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s LIMIT 1",
                $uid
            ));
            $payer_id = $c ? (string) $c->id : $uid;
        }

        $html = class_exists( 'Olama_Reg_Agreement_Renderer' ) 
            ? Olama_Reg_Agreement_Renderer::render_agreements_list( $payer_id, $year, 'customer_hub' )
            : '';

        // Resolve agreements count safely
        $args = [];
        if ( ! empty( $payer_id ) ) {
            $family_exists = Olama_Reg_Family::get_family( $payer_id );
            if ( $family_exists ) {
                $args['payer_type'] = 'family';
                $args['payer_id']   = $payer_id;
            } else {
                $customer = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s OR id = %d LIMIT 1",
                    $payer_id,
                    is_numeric( $payer_id ) ? (int) $payer_id : 0
                ) );
                if ( $customer ) {
                    $args['payer_type'] = 'customer';
                    $args['payer_id']   = (string) $customer->id;
                } else {
                    $args['payer_type'] = 'customer';
                    $args['payer_id']   = $payer_id;
                }
            }
        }
        if ( $year > 0 ) {
            $args['academic_year_id'] = $year;
        }
        $agreements = class_exists( 'Olama_Reg_Agreement' ) ? Olama_Reg_Agreement::get_list( $args ) : [];

        return [
            'html' => $html,
            'meta' => ['count' => count($agreements)],
        ];
    }

        // ── Tile: Invoices ────────────────────────────────────────────────────────

    private function hub_tile_invoices(string $uid, string $type, int $year): array
    {
        global $wpdb;

        if ($type === 'family') {
            $where  = 'i.family_uid = %s AND (i.ext_customer_id IS NULL OR i.ext_customer_id = 0)';
            $args   = [$uid];
        } else {
            $c = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s LIMIT 1",
                $uid
            ));
            $cid    = $c ? (int) $c->id : 0;
            $where  = '(i.ext_customer_id = %d OR i.family_uid = %s)';
            $args   = [$cid, $uid];
        }

        if ($year) {
            $where .= ' AND i.academic_year_id = %d';
            $args[] = $year;
        }

        $query = "SELECT i.*,
                         COALESCE(NULLIF(f.sponsor_full_name, ''), NULLIF(f.father_name, ''), c.customer_name, '') AS father_first_name,
                         '' AS father_family_name,
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
                  LEFT JOIN " . olama_core()->read_models()->table( 'families' ) . " f
                    ON f.family_uid = i.family_uid
                    OR (i.oracle_family_id IS NOT NULL AND f.oracle_family_id = i.oracle_family_id)
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
                  LEFT JOIN " . olama_core()->read_models()->table( 'students' ) . " s ON s.student_uid = i.student_uid
                  LEFT JOIN " . $wpdb->prefix . "olama_customer_children ec ON ec.id = i.ext_child_id
                  WHERE {$where}
                  ORDER BY i.issue_date DESC, i.id DESC
                  LIMIT 50";

        $invoices = $wpdb->get_results($wpdb->prepare($query, ...$args)) ?: [];

        foreach ($invoices as $invoice_row) {
            if (empty($invoice_row->fee_template_name) && !empty($invoice_row->agreement_id)) {
                $templates = $wpdb->get_results($wpdb->prepare(
                    "SELECT DISTINCT ft.template_name, ft.subject_type, ft.subject_value
                     FROM {$wpdb->prefix}olama_agreement_fees af
                     LEFT JOIN {$wpdb->prefix}olama_fee_templates ft ON ft.id = CAST(af.fee_category AS UNSIGNED)
                     WHERE af.agreement_id = %d
                       AND af.fee_category REGEXP '^[0-9]+'
                       AND ft.id IS NOT NULL
                     ORDER BY ft.template_name ASC",
                    (int) $invoice_row->agreement_id
                )) ?: [];

                if (!empty($templates)) {
                    $invoice_row->fee_template_name = implode('، ', wp_list_pluck($templates, 'template_name'));
                    $invoice_row->fee_subject_type  = $templates[0]->subject_type ?? 'agreement';
                    $invoice_row->fee_subject_value = $templates[0]->subject_value ?? '';
                }
            }

            $covered_children = [];
            if (!empty($invoice_row->agreement_id)) {
                $agreement = $wpdb->get_row($wpdb->prepare(
                    "SELECT payer_type, participant_type FROM {$wpdb->prefix}olama_agreements WHERE id = %d",
                    (int) $invoice_row->agreement_id
                ));

                if ($agreement && $agreement->payer_type === 'family') {
                    $covered_children = $wpdb->get_col($wpdb->prepare(
                        "SELECT DISTINCT s.student_name
                         FROM {$wpdb->prefix}olama_agreement_fees af
                         LEFT JOIN " . olama_core()->read_models()->table( 'students' ) . " s
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
                    )) ?: [];
                } else {
                    $covered_children = $wpdb->get_col($wpdb->prepare(
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
                    )) ?: [];
                }
            } elseif (!empty($invoice_row->direct_student_name)) {
                $covered_children[] = $invoice_row->direct_student_name;
            } elseif (!empty($invoice_row->direct_child_name)) {
                $covered_children[] = $invoice_row->direct_child_name;
            }

            $invoice_row->covered_children_names = array_values(array_unique(array_filter(array_map(static function ($name) {
                $parts = preg_split('/\s+/u', trim((string) $name));
                return $parts[0] ?? '';
            }, $covered_children))));
        }

        $new_invoice_url = $type === 'family'
            ? admin_url('admin.php?page=olama-registration-invoices&action=new&family_uid=' . rawurlencode($uid))
            : admin_url('admin.php?page=olama-registration-invoices&action=new&ext_customer_uid=' . rawurlencode($uid));

        if (!$invoices) {
            $html = $this->hub_empty_state(
                __('لا توجد فواتير مسجلة', 'olama-registration') . ($wpdb->last_error ? (' (خطأ قاعدة البيانات: ' . esc_html($wpdb->last_error) . ')') : ''),
                'dashicons-media-text'
            );
            $html .= '<div class="os-hub-tile-footer"><a href="' . esc_url($new_invoice_url) . '" class="button button-primary">' . __('إصدار فاتورة جديدة', 'olama-registration') . '</a></div>';
            return [
                'html' => $html,
                'meta' => ['count' => 0, 'balance' => 0],
            ];
        }

        $total_balance = 0;
        foreach ($invoices as $inv) {
            if (!in_array((string) ($inv->status ?? ''), ['draft', 'cancelled'], true)) {
                $total_balance += (float) $inv->balance;
            }
        }

        ob_start();
        $is_hub = true;
        include OLAMA_REG_PATH . 'admin/views/partial-invoices-table.php';
        $html = ob_get_clean();

        $html .= '<div class="os-hub-tile-footer">';
        $html .= '<strong>' . __('إجمالي الرصيد المستحق: ', 'olama-registration') . '</strong>';
        $html .= '<span dir="ltr">' . number_format($total_balance, 2) . ' <small>د.أ</small></span>';
        $html .= '</div>';

        return [
            'html' => $html,
            'meta' => ['count' => count($invoices), 'balance' => $total_balance],
        ];
    }

        // ── Tile: Payments ────────────────────────────────────────────────────────

    private function hub_tile_payments(string $uid, string $type, int $year): array
    {
        global $wpdb;

        if ($type === 'family') {
            $join_where = 'p.family_uid = %s';
            $args       = [$uid];
        } else {
            $c = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s LIMIT 1",
                $uid
            ));
            $cid        = $c ? (int) $c->id : 0;
            $join_where = '(i.ext_customer_id = %d OR i.family_uid = %s OR p.family_uid = %s)';
            $args       = [$cid, $uid, $uid];
        }

        if ($year) {
            $join_where .= ' AND i.academic_year_id = %d';
            $args[] = $year;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.payment_no, p.invoice_id, p.payment_date, p.amount, p.method, p.status, p.reference,
                        p.reversed_payment_id, p.received_by, p.notes, p.family_uid, p.oracle_family_id,
                        i.invoice_number, p.amount AS payment_amount,
                        u.display_name AS received_by_name
                 FROM {$wpdb->prefix}olama_payments p
                 INNER JOIN {$wpdb->prefix}olama_invoices i ON i.id = p.invoice_id
                 LEFT JOIN {$wpdb->users} u ON u.ID = p.received_by
                 WHERE {$join_where}
                 ORDER BY p.id DESC
                 LIMIT 50",
                $args
            )
        );

        if (! $rows) {
            return ['html' => $this->hub_empty_state(
                __('لا توجد سندات قبض مسجلة', 'olama-registration'),
                'dashicons-money-alt'
            )];
        }

        // Get payer name (family name or customer name) for the active dashboard profile
        $payer_name = '';
        if ($type === 'family') {
            $family = Olama_Reg_Family::get_family($uid);
            $payer_name = $family ? $family->family_name : '';
        } else {
            $payer_name = $wpdb->get_var($wpdb->prepare(
                "SELECT customer_name FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s",
                $uid
            ));
        }
        if (!$payer_name) {
            $payer_name = $uid;
        }

        $reversed_ids = [];
        foreach ($rows as $row_pay) {
            if (!empty($row_pay->reversed_payment_id)) {
                $reversed_ids[] = (int) $row_pay->reversed_payment_id;
            } elseif (strpos($row_pay->reference ?? '', 'REVERSAL-') === 0) {
                $reversed_ids[] = (int) str_replace('REVERSAL-', '', $row_pay->reference);
            }
        }

        $method_cfg = [
            'cash'          => ['label' => __('نقدي', 'olama-registration'),          'class' => 'reg-method-pill--cash'],
            'bank_transfer' => ['label' => __('تحويل بنكي', 'olama-registration'),    'class' => 'reg-method-pill--transfer'],
            'cheque'        => ['label' => __('شيك بنكي', 'olama-registration'),       'class' => 'reg-method-pill--cheque'],
            'online'        => ['label' => __('دفع إلكتروني', 'olama-registration'),   'class' => 'reg-method-pill--online'],
            'reversal'      => ['label' => __('عكس قيد', 'olama-registration'),       'class' => 'reg-method-pill--reversal'],
        ];

        $total_paid = 0;
        $html  = '<table class="olama-reg-fin-table widefat fixed striped" style="width:100%; border-collapse:collapse; font-size:13px;">';
        $html .= '<thead><tr>';
        $html .= '<th style="width:70px;">' . __('رقم السند', 'olama-registration') . '</th>';
        $html .= '<th>' . __('تاريخ القبض', 'olama-registration') . '</th>';
        $html .= '<th>' . __('رقم الفاتورة', 'olama-registration') . '</th>';
        $html .= '<th>' . __('رقم الملف', 'olama-registration') . '</th>';
        $html .= '<th>' . __('ولي الأمر', 'olama-registration') . '</th>';
        $html .= '<th>' . __('القيمة المقبوضة', 'olama-registration') . '</th>';
        $html .= '<th>' . __('الحالة', 'olama-registration') . '</th>';
        $html .= '<th>' . __('طريقة الدفع', 'olama-registration') . '</th>';
        $html .= '<th>' . __('رقم المرجع / الشيك', 'olama-registration') . '</th>';
        $html .= '<th>' . __('المستلم', 'olama-registration') . '</th>';
        $html .= '<th style="width:80px; text-align:center;">' . __('إيصال', 'olama-registration') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $r) {
            if (in_array((string) ($r->status ?? ''), ['', 'posted', 'reversed'], true)) {
                $total_paid += (float) $r->amount;
            }
            $m = $method_cfg[$r->method] ?? ['label' => $r->method, 'class' => 'reg-method-pill--cash'];
            $is_reversal = (float) $r->amount < 0;

            $print_url = admin_url('admin.php?page=olama-registration-payments&action=print_receipt&id=' . (int) $r->id);

            $html .= '<tr' . ($is_reversal ? ' class="os-hub-row--reversal"' : '') . '>';
            $html .= '<td><span style="font-weight:700; color:var(--reg-text-muted);">' . esc_html(Olama_Reg_Billing_Payment::get_receipt_number($r)) . '</span></td>';
            $html .= '<td style="color:var(--reg-text-muted);">' . esc_html($r->payment_date) . '</td>';
            $html .= '<td><strong>' . esc_html($r->invoice_number ?: '—') . '</strong></td>';
            $html .= '<td><span class="olama-reg-uid-badge">' . esc_html($r->oracle_family_id ?: $r->family_uid) . '</span></td>';
            $html .= '<td>' . esc_html($payer_name) . '</td>';
            $html .= '<td style="' . ($is_reversal ? 'color:#d63638;' : 'color:var(--reg-success);') . ' font-weight:800; font-size:15px;" dir="ltr">';
            $html .= number_format((float)$r->amount, 2) . '</td>';
            $html .= '<td>' . esc_html(Olama_Reg_Billing_Payment::get_status_label($r)) . '</td>';
            $html .= '<td>';
            $html .= '<span class="reg-method-pill ' . esc_attr($m['class']) . '">';
            $html .= esc_html($m['label']);
            $html .= '</span>';
            $html .= '</td>';
            $html .= '<td>' . esc_html($r->reference ?: '—') . '</td>';
            $html .= '<td>' . esc_html($r->received_by_name ?: '—') . '</td>';
            $html .= '<td style="text-align:center; white-space:nowrap;">';
            $html .= '<a href="' . esc_url($print_url) . '" target="_blank" class="button button-small" title="' . esc_attr__('طباعة سند القبض', 'olama-registration') . '" style="margin-left: 2px;">';
            $html .= '<span class="dashicons dashicons-printer"></span>';
            $html .= '</a>';
            if ((float) $r->amount > 0 && $r->method !== 'reversal') {
                $is_already_reversed = in_array((int) $r->id, $reversed_ids, true) || (string)($r->status ?? '') === 'reversed';
                if ($is_already_reversed) {
                    $html .= '<button type="button" class="button button-small" disabled style="opacity:0.5; cursor:not-allowed;" title="' . esc_attr__('هذا السند معكوس مسبقاً', 'olama-registration') . '">';
                    $html .= '<span class="dashicons dashicons-undo"></span>';
                    $html .= '</button>';
                } else {
                    $html .= '<button type="button" class="button button-small olama-reg-reverse-payment-btn" data-id="' . esc_attr($r->id) . '" title="' . esc_attr__('عكس السند', 'olama-registration') . '" style="color:#c62828;">';
                    $html .= '<span class="dashicons dashicons-undo"></span>';
                    $html .= '</button>';
                }
            }
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '<div class="os-hub-tile-footer">';
        $html .= '<strong>' . __('إجمالي المدفوعات: ', 'olama-registration') . '</strong>';
        $html .= '<span dir="ltr">' . number_format($total_paid, 2) . ' <small>د.أ</small></span>';
        $html .= '</div>';

        return [
            'html' => $html,
            'meta' => ['count' => count($rows), 'total' => $total_paid],
        ];
    }

    // ── Tile: Children / Students ─────────────────────────────────────────────

    private function hub_tile_children(string $uid, string $type, int $year): array
    {
        global $wpdb;

        if ($type === 'family') {
            $rows = array_map(static function ($student) {
                return (object) [
                    'uid'       => $student->student_uid,
                    'name'      => $student->student_name,
                    'grade'     => $student->grade_name,
                    'section'   => $student->section_name,
                    'is_active' => $student->is_active,
                ];
            }, Olama_Reg_Student::get_family_students($uid));
            $label_uid  = __('رقم الطالب', 'olama-registration');
            $label_name = __('اسم الطالب', 'olama-registration');
        } else {
            $c = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s LIMIT 1",
                $uid
            ));
            $cid  = $c ? (int) $c->id : 0;
            $rows = $cid ? $wpdb->get_results($wpdb->prepare(
                "SELECT child_uid AS uid, child_name AS name, grade, '' AS section, is_active
                 FROM {$wpdb->prefix}olama_customer_children
                 WHERE customer_id = %d
                 ORDER BY child_name",
                $cid
            )) : [];
            $label_uid  = __('رقم الابن', 'olama-registration');
            $label_name = __('اسم الابن', 'olama-registration');
        }

        if (! $rows) {
            $empty = $this->hub_empty_state(
                __('لا يوجد طلاب / أبناء مسجلون', 'olama-registration'),
                'dashicons-groups'
            );
            if ($type === 'external') {
                $empty .= $this->hub_add_child_form($uid);
            }
            return ['html' => $empty];
        }

        $html  = '<table class="os-hub-data-table widefat fixed striped">';
        $html .= '<thead><tr>';
        $html .= "<th>{$label_uid}</th><th>{$label_name}</th>";
        $html .= '<th>' . __('الصف', 'olama-registration') . '</th>';
        $html .= '<th>' . __('الحالة', 'olama-registration') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $html .= '<tr>';
            $html .= '<td><code>' . esc_html($r->uid) . '</code></td>';
            $html .= '<td>' . esc_html($r->name) . '</td>';
            $html .= '<td>' . esc_html($r->grade ?: '—') . '</td>';
            $html .= '<td>' . ($r->is_active
                ? '<span class="os-hub-badge os-hub-badge--green">' . __('نشط', 'olama-registration') . '</span>'
                : '<span class="os-hub-badge os-hub-badge--gray">'  . __('غير نشط', 'olama-registration') . '</span>') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        // For external customers: show Add Child form below the table
        if ($type === 'external') {
            $html .= $this->hub_add_child_form($uid);
        }

        return ['html' => $html, 'meta' => ['count' => count($rows)]];
    }

    /**
     * Renders the inline "Add Child" form HTML for external customers.
     */
    private function hub_add_child_form(string $uid): string
    {
        $html  = '<div class="os-hub-add-child-wrap" data-uid="' . esc_attr($uid) . '">';
        $html .= '<div class="os-hub-tile-footer">';
        $html .= '<button type="button" class="button" id="os-hub-add-child-btn">';
        $html .= '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ';
        $html .= __('إضافة ابن', 'olama-registration');
        $html .= '</button>';
        $html .= '</div>';
        $html .= '<form class="os-hub-add-child-form" id="os-hub-add-child-form" style="display:none;" novalidate>';
        $html .= '<input type="hidden" name="uid" value="' . esc_attr($uid) . '">';
        $html .= '<table class="os-hub-data-table widefat fixed"><tbody>';
        $html .= '<tr><th>' . __('اسم الابن', 'olama-registration') . ' <span style="color:#d63638;">*</span></th><td>';
        $html .= '<input type="text" name="child_name" id="os-hub-child-name-input" class="widefat" required placeholder="' . esc_attr__('أدخل اسم الابن...', 'olama-registration') . '">';
        $html .= '</td></tr>';
        $html .= '<tr><th>' . __('الصف / المرحلة', 'olama-registration') . '</th><td>';
        $html .= '<input type="text" name="grade" class="widefat" placeholder="' . esc_attr__('اختياري...', 'olama-registration') . '">';
        $html .= '</td></tr>';
        $html .= '</tbody></table>';
        $html .= '<div class="os-hub-form-actions">';
        $html .= '<button type="submit" class="button button-primary os-hub-add-child-submit">';
        $html .= '<span class="dashicons dashicons-saved" aria-hidden="true"></span> ' . __('حفظ', 'olama-registration');
        $html .= '</button>';
        $html .= ' <button type="button" class="button os-hub-add-child-cancel">' . __('إلغاء', 'olama-registration') . '</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '<div class="os-hub-add-child-toast" aria-live="polite"></div>';
        $html .= '</div>';
        return $html;
    }

    // ── Tile: Financial Summary ────────────────────────────────────────────────

    private function hub_tile_financial(string $uid, string $type, int $year): array
    {
        global $wpdb;

        if ($type !== 'family') {
            // External customers: simple invoice summary
            $c   = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s LIMIT 1",
                $uid
            ));
            $cid = $c ? (int) $c->id : 0;

            $summary_args = [$cid, $uid];
            $year_clause = '';
            if ($year) {
                $year_clause = ' AND academic_year_id = %d';
                $summary_args[] = $year;
            }

            $summary = $cid ? $wpdb->get_row($wpdb->prepare(
                "SELECT
                     COALESCE(SUM(total), 0) AS billed,
                     COALESCE(SUM(amount_paid), 0) AS paid,
                 COALESCE(SUM(balance), 0) AS balance
                 FROM {$wpdb->prefix}olama_invoices
                 WHERE (ext_customer_id = %d OR family_uid = %s) AND status != 'cancelled' {$year_clause}",
                $summary_args
            )) : null;

            $billed  = $summary ? (float) $summary->billed  : 0;
            $paid    = $summary ? (float) $summary->paid    : 0;
            $balance = $summary ? (float) $summary->balance : 0;
        } else {
            // Family: year-scoped using summary service
            if ( class_exists( 'Olama_Reg_Family_Financial_Summary' ) ) {
                $summary = Olama_Reg_Family_Financial_Summary::get_family_summary( $uid, $year );

                $billed  = (float) $summary->gross_invoiced;
                $paid    = (float) $summary->total_paid;
                $balance = (float) $summary->current_balance;

                $due_now = (float) $summary->due_now;
                $overdue = (float) $summary->overdue;
                $unallocated = (float) $summary->unallocated_payments;
                $settlements = (float) $summary->total_settlements;
                $adjustments = (float) $summary->net_adjustments;
                $total_fees = (float) $summary->total_fees;
                $discounts = (float) $summary->total_discounts;
            } else {
                $args = [$uid];
                $yc   = '';
                if ($year) { $yc = 'AND academic_year_id = %d'; $args[] = $year; }

                $summary = $wpdb->get_row($wpdb->prepare(
                    "SELECT
                         COALESCE(SUM(total), 0)       AS billed,
                         COALESCE(SUM(amount_paid), 0) AS paid,
                         COALESCE(SUM(balance), 0)     AS balance
                     FROM {$wpdb->prefix}olama_invoices
                     WHERE family_uid = %s
                       AND (ext_customer_id IS NULL OR ext_customer_id = 0)
                       AND status != 'cancelled' {$yc}",
                    $args
                ));

                $billed  = $summary ? (float) $summary->billed  : 0;
                $paid    = $summary ? (float) $summary->paid    : 0;
                $balance = $summary ? (float) $summary->balance : 0;
            }
        }

        $paid_pct    = $billed > 0 ? round($paid / $billed * 100, 1) : 0;
        $balance_pct = $billed > 0 ? round($balance / $billed * 100, 1) : 0;

        $html  = '<div class="os-hub-financial-summary">';

        // Stat cards
        $html .= '<div class="os-hub-fin-cards">';
        $html .= $this->hub_fin_card(__('إجمالي المفوتر', 'olama-registration'), $billed, 'os-hub-badge--blue');
        $html .= $this->hub_fin_card(__('إجمالي المدفوع', 'olama-registration'), $paid,    'os-hub-badge--green');
        $html .= $this->hub_fin_card(__('الرصيد المستحق', 'olama-registration'), $balance, $balance > 0 ? 'os-hub-badge--red' : 'os-hub-badge--green');
        $html .= '</div>';

        if ($type === 'family' && class_exists( 'Olama_Reg_Family_Financial_Summary' )) {
            $html .= '<div class="os-hub-fin-subgrid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">';
            $html .= $this->hub_fin_card_small(__('المستحق حالياً', 'olama-registration'), $due_now, $due_now > 0 ? 'os-hub-badge--red' : 'os-hub-badge--gray');
            $html .= $this->hub_fin_card_small(__('المتأخرات', 'olama-registration'), $overdue, $overdue > 0 ? 'os-hub-badge--red' : 'os-hub-badge--gray');
            $html .= $this->hub_fin_card_small(__('مدفوعات غير مخصصة', 'olama-registration'), $unallocated, $unallocated > 0 ? 'os-hub-badge--blue' : 'os-hub-badge--gray');
            $html .= $this->hub_fin_card_small(__('إجمالي الرسوم', 'olama-registration'), $total_fees, 'os-hub-badge--blue');
            $html .= $this->hub_fin_card_small(__('إجمالي الخصومات', 'olama-registration'), $discounts, $discounts > 0 ? 'os-hub-badge--green' : 'os-hub-badge--gray');
            $html .= $this->hub_fin_card_small(__('صافي الإشعارات', 'olama-registration'), $adjustments, $adjustments >= 0 ? 'os-hub-badge--blue' : 'os-hub-badge--red');
            $html .= '</div>';

            if ($settlements > 0) {
                $html .= '<div class="os-hub-settlements-banner" style="margin-top: 10px; background: #fdf6ec; border: 1px solid #faecd8; padding: 8px 12px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">';
                $html .= '<span style="color: #e6a23c; font-size: 13px; font-weight: bold;">' . esc_html__('إجمالي التسويات المعتمدة', 'olama-registration') . '</span>';
                $html .= '<span style="font-weight: bold; color: #e6a23c;" dir="ltr">' . number_format($settlements, 2) . ' د.أ</span>';
                $html .= '</div>';
            }
        }

        // Progress bar
        if ($billed > 0) {
            $html .= '<div class="os-hub-progress-wrap">';
            $html .= '<div class="os-hub-progress-label">';
            $html .= '<span>' . __('نسبة السداد', 'olama-registration') . '</span>';
            $html .= '<span dir="ltr">' . $paid_pct . '%</span>';
            $html .= '</div>';
            $html .= '<div class="os-hub-progress">';
            $html .= '<div class="os-hub-progress__bar" style="width:' . $paid_pct . '%;"></div>';
            $html .= '</div>';
            $html .= '</div>';
        }

        if ($type === 'family' && class_exists( 'Olama_Reg_Family_Financial_Summary' )) {
            $student_breakdown = Olama_Reg_Family_Financial_Summary::get_student_breakdown($uid, $year);
            if (!empty($student_breakdown)) {
                $html .= '<div class="os-hub-student-breakdown" style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px;">';
                $html .= '<h4 style="margin: 0 0 12px; font-size: 14px; color: var(--os-hub-accent-family);">' . __('التحليل المالي على مستوى الطلاب', 'olama-registration') . '</h4>';
                $html .= '<div class="os-hub-table-wrap" style="overflow-x: auto;">';
                $html .= '<table class="widefat striped" style="width: 100%; border-collapse: collapse; font-size: 12px;">';
                $html .= '<thead><tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">';
                $html .= '<th style="padding: 8px; font-weight: bold; text-align: right;">' . __('الطالب', 'olama-registration') . '</th>';
                $html .= '<th style="padding: 8px; font-weight: bold; text-align: center;">' . __('العقود', 'olama-registration') . '</th>';
                $html .= '<th style="padding: 8px; font-weight: bold; text-align: left;">' . __('إجمالي الرسوم', 'olama-registration') . '</th>';
                $html .= '<th style="padding: 8px; font-weight: bold; text-align: left;">' . __('المفوتر', 'olama-registration') . '</th>';
                $html .= '<th style="padding: 8px; font-weight: bold; text-align: left;">' . __('المدفوع', 'olama-registration') . '</th>';
                $html .= '<th style="padding: 8px; font-weight: bold; text-align: left;">' . __('المتبقي', 'olama-registration') . '</th>';
                $html .= '<th style="padding: 8px; font-weight: bold; text-align: left;">' . __('المتأخر', 'olama-registration') . '</th>';
                $html .= '</tr></thead><tbody>';
                foreach ($student_breakdown as $sb) {
                    $html .= '<tr style="border-bottom: 1px solid #edf2f7;">';
                    $html .= '<td style="padding: 8px; font-weight: bold;">' . esc_html($sb->student_name) . '</td>';
                    $html .= '<td style="padding: 8px; text-align: center;">' . (int)$sb->agreement_count . '</td>';
                    $html .= '<td style="padding: 8px; text-align: left;" dir="ltr">' . number_format($sb->total_fees, 2) . ' د.أ</td>';
                    $html .= '<td style="padding: 8px; text-align: left;" dir="ltr">' . number_format($sb->total_invoiced, 2) . ' د.أ</td>';
                    $html .= '<td style="padding: 8px; text-align: left; color: #10b981;" dir="ltr">' . number_format($sb->total_paid, 2) . ' د.أ</td>';
                    $html .= '<td style="padding: 8px; text-align: left; font-weight: bold; color: ' . ($sb->remaining > 0.009 ? '#ef4444' : '#10b981') . ';" dir="ltr">' . number_format($sb->remaining, 2) . ' د.أ</td>';
                    $html .= '<td style="padding: 8px; text-align: left; color: ' . ($sb->overdue > 0.009 ? '#ef4444' : '#6b7280') . ';" dir="ltr">' . number_format($sb->overdue, 2) . ' د.أ</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                $html .= '</div>';
                $html .= '</div>';
            }
        }

        // Footer CTA: New Invoice
        $new_invoice_url = admin_url('admin.php?page=olama-registration-invoices&action=new');
        if ($type === 'family') {
            $new_invoice_url .= '&family_uid=' . urlencode($uid);
        } else {
            $new_invoice_url .= '&ext_customer_uid=' . urlencode($uid);
        }

        $html .= '<div class="os-hub-tile-footer">';
        $html .= '<a href="' . esc_url($new_invoice_url) . '" class="button button-primary button-small">';
        $html .= '<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ';
        $html .= __('إنشاء فاتورة جديدة', 'olama-registration');
        $html .= '</a>';
        $html .= '</div>';

        $html .= '</div>';

        return [
            'html' => $html,
            'meta' => ['billed' => $billed, 'paid' => $paid, 'balance' => $balance],
        ];
    }

    private function hub_fin_card(string $label, float $amount, string $cls): string
    {
        return '<div class="os-hub-fin-card">'
             . '<div class="os-hub-fin-card__label">' . esc_html($label) . '</div>'
             . '<div class="os-hub-fin-card__amount ' . $cls . '" dir="ltr">'
             . number_format($amount, 2) . ' <small>د.أ</small>'
             . '</div>'
             . '</div>';
    }

    private function hub_fin_card_small(string $label, float $amount, string $cls): string
    {
        return '<div class="os-hub-fin-card os-hub-fin-card--small" style="padding: 8px; border: 1px solid #f0f0f0; border-radius: 4px; background: #fafafa;">'
             . '<div class="os-hub-fin-card__label" style="font-size: 11px; color: #666; margin-bottom: 4px;">' . esc_html($label) . '</div>'
             . '<div class="os-hub-fin-card__amount ' . $cls . '" style="font-size: 14px; font-weight: bold;" dir="ltr">'
             . number_format($amount, 2) . ' <small style="font-size: 9px; font-weight: normal;">د.أ</small>'
             . '</div>'
             . '</div>';
    }

    // ── Tile: History & Audit ─────────────────────────────────────────────────

    private function hub_tile_statement(string $uid, string $type, int $year): array
    {
        $student_uid = ! empty($_POST['student_uid']) ? sanitize_text_field($_POST['student_uid']) : '';

        $report = Olama_Reg_Billing_Reports::get_family_statement_report([
            'entity_type' => $type === 'external' ? 'external' : 'family',
            'uid'         => $uid,
            'year_id'     => $year,
            'student_uid' => $student_uid,
        ]);

        $rows    = array_slice($report['rows'] ?? [], -40);
        $summary = $report['summary'] ?? [];
        $entity  = $report['entity'] ?? ['uid' => $uid, 'name' => ''];

        // Fetch students for family filtering
        $students = [];
        if ($type === 'family') {
            $students = array_values(array_filter(
                Olama_Reg_Student::get_family_students($uid),
                static fn($student) => (bool) $student->is_active
            ));
        }

        $html  = '<div class="os-hub-financial-summary os-hub-statement-summary">';

        // Student Select Dropdown Filter (RTL)
        if ($type === 'family' && !empty($students)) {
            $html .= '<div class="os-hub-statement-filter-bar" style="margin-bottom: 15px; display: flex; align-items: center; gap: 8px; justify-content: flex-start;" dir="rtl">';
            $html .= '<label for="os-hub-statement-student-select" style="font-weight: bold; font-size: 13px;">' . __('تصفية حسب الطالب:', 'olama-registration') . '</label>';
            $html .= '<select id="os-hub-statement-student-select" style="padding: 4px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 13px; min-width: 180px;">';
            $html .= '<option value="">' . __('جميع الطلاب', 'olama-registration') . '</option>';
            foreach ($students as $stu) {
                $html .= '<option value="' . esc_attr($stu->student_uid) . '" ' . selected($student_uid, $stu->student_uid, false) . '>' . esc_html($stu->student_name) . '</option>';
            }
            $html .= '</select>';
            $html .= '</div>';
        }

        $html .= '<div class="os-hub-fin-cards">';
        $html .= $this->hub_fin_card(__('الرصيد الافتتاحي', 'olama-registration'), (float) ($summary['opening_balance'] ?? 0), 'os-hub-badge--blue');
        $html .= $this->hub_fin_card(__('إجمالي المدين', 'olama-registration'), (float) ($summary['total_debit'] ?? 0), 'os-hub-badge--red');
        $html .= $this->hub_fin_card(__('إجمالي الدائن', 'olama-registration'), (float) ($summary['total_credit'] ?? 0), 'os-hub-badge--green');
        $html .= $this->hub_fin_card(__('الرصيد الختامي', 'olama-registration'), (float) ($summary['closing_balance'] ?? 0), ((float) ($summary['closing_balance'] ?? 0) > 0 ? 'os-hub-badge--red' : 'os-hub-badge--green'));
        $html .= '</div>';

        if (!empty($entity['name'])) {
            $html .= '<div class="os-hub-inline-note" style="margin:12px 0 16px; color:#475569;">';
            $html .= esc_html($entity['name']) . ' <span dir="ltr">(' . esc_html($entity['uid']) . ')</span>';
            $html .= '</div>';
        }

        $html .= '<div class="os-hub-table-wrap"><table class="widefat striped">';
        $html .= '<thead><tr><th>' . esc_html__('التاريخ', 'olama-registration') . '</th><th>' . esc_html__('النوع', 'olama-registration') . '</th><th>' . esc_html__('المرجع', 'olama-registration') . '</th><th>' . esc_html__('البيان', 'olama-registration') . '</th><th>' . esc_html__('مدين', 'olama-registration') . '</th><th>' . esc_html__('دائن', 'olama-registration') . '</th><th>' . esc_html__('الرصيد', 'olama-registration') . '</th></tr></thead><tbody>';

        if (empty($rows)) {
            $html .= '<tr><td colspan="7" class="os-hub-empty-state">' . esc_html__('لا توجد حركات على كشف الحساب حالياً.', 'olama-registration') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                $html .= '<tr>';
                $html .= '<td>' . esc_html($row->movement_date ?: '—') . '</td>';
                $html .= '<td>' . esc_html(Olama_Reg_Billing_Reports::format_statement_entry_type(
                    (string) $row->entry_type,
                    (string) ($row->entry_subtype ?? '')
                )) . '</td>';
                $html .= '<td><strong>' . esc_html($row->reference_no ?: '—') . '</strong></td>';
                $html .= '<td>' . esc_html($row->details ?: '—') . '</td>';
                $html .= '<td style="color:#c62828;" dir="ltr">' . ($row->debit_amount > 0 ? esc_html(number_format((float) $row->debit_amount, 2)) : '—') . '</td>';
                $html .= '<td class="olama-reg-text--success" dir="ltr">' . ($row->credit_amount > 0 ? esc_html(number_format((float) $row->credit_amount, 2)) : '—') . '</td>';
                $html .= '<td dir="ltr"><strong>' . esc_html(number_format((float) $row->running_balance, 2)) . '</strong></td>';
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table></div>';
        $report_url = add_query_arg([
            'page'        => 'olama-registration-reports',
            'report_tab'  => 'family_statement',
            'year_id'     => $year,
            'entity_type' => $type === 'external' ? 'external' : 'family',
            'uid'         => $uid,
            'student_uid' => $student_uid,
        ], admin_url('admin.php'));
        $print_url = add_query_arg('action', 'print_family_statement', $report_url);
        $html .= '<div class="os-hub-tile-footer">';
        $html .= '<a href="' . esc_url($report_url) . '" class="button button-small"><span class="dashicons dashicons-visibility" aria-hidden="true"></span> ' . esc_html__('عرض الكشف الكامل', 'olama-registration') . '</a> ';
        $html .= '<a href="' . esc_url($print_url) . '" target="_blank" class="button button-small"><span class="dashicons dashicons-printer" aria-hidden="true"></span> ' . esc_html__('طباعة كشف الحساب', 'olama-registration') . '</a>';
        $html .= '</div>';
        $html .= '</div>';

        return [
            'html' => $html,
            'meta' => [
                'row_count'       => count($report['rows'] ?? []),
                'closing_balance' => (float) ($summary['closing_balance'] ?? 0),
            ],
        ];
    }

    private function hub_tile_history(string $uid, string $type, int $year): array
    {
        global $wpdb;

        // Retrieve filter, sorting, and pagination parameters
        $search = sanitize_text_field($_POST['search'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $filter_amount = sanitize_text_field($_POST['amount'] ?? '');
        $service_type = sanitize_text_field($_POST['service_type'] ?? '');
        $agreement_nature = sanitize_text_field($_POST['agreement_nature'] ?? '');
        $orderby = sanitize_key($_POST['orderby'] ?? 'date'); 
        $order = sanitize_key($_POST['order'] ?? 'desc'); 
        $paged = max(1, (int)($_POST['paged'] ?? 1));
        $per_page = 10;

        $custom_services = get_option('olama_reg_custom_services', ['دوسية', 'نشاط', 'مواصلات', 'امتحان إضافي']);
        $agreement_natures = get_option('olama_reg_agreement_natures', ['عقد مدرسة', 'عقد روضة', 'عقد نادي صيفي', 'رحلة مدرسية']);

        // Resolve external customer ID if applicable
        $customer_id = 0;
        if (strpos($uid, 'CUST-') === 0) {
            $customer_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s LIMIT 1",
                $uid
            ));
        }

        // Get all related entity IDs for this family/customer to match against audit records
        if ($customer_id) {
            $invoice_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_invoices WHERE ext_customer_id = %d OR family_uid = %s",
                $customer_id, $uid
            )) ?: [0];
            $payment_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_payments WHERE family_uid = %s",
                $uid
            )) ?: [0];
            $agreement_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_agreements WHERE payer_id = %s",
                $uid
            )) ?: [0];
        } else {
            $invoice_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_invoices WHERE family_uid = %s",
                $uid
            )) ?: [0];
            $payment_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_payments WHERE family_uid = %s",
                $uid
            )) ?: [0];
            $agreement_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_agreements WHERE payer_id = %s",
                $uid
            )) ?: [0];
        }

        $invoice_ids_str = implode(',', array_map('intval', $invoice_ids)) ?: '0';
        $payment_ids_str = implode(',', array_map('intval', $payment_ids)) ?: '0';
        $agreement_ids_str = implode(',', array_map('intval', $agreement_ids)) ?: '0';

        // Select before_state and after_state columns instead of the non-existent notes column
        $rows = $wpdb->get_results(
            "SELECT id, action, entity_type, entity_id, actor_id, created_at, before_state, after_state
             FROM {$wpdb->prefix}olama_billing_audit
             WHERE (entity_type = 'invoice' AND entity_id IN ($invoice_ids_str))
                OR (entity_type = 'payment' AND entity_id IN ($payment_ids_str))
                OR (entity_type = 'agreement' AND entity_id IN ($agreement_ids_str))
             ORDER BY id DESC"
        ) ?: [];

        // Action label map (Arabic)
        $action_labels = [
            'created'          => __('إنشاء', 'olama-registration'),
            'updated'          => __('تعديل', 'olama-registration'),
            'cancelled'        => __('إلغاء', 'olama-registration'),
            'reversed'         => __('عكس دفعة', 'olama-registration'),
            'status_changed'   => __('تغيير الحالة', 'olama-registration'),
            'payment_created'  => __('إنشاء سند قبض', 'olama-registration'),
            'payment_reversed' => __('عكس سند قبض', 'olama-registration'),
        ];

        // Pre-fetch user display names (batch, avoid N+1)
        $actor_ids = array_unique(array_filter(array_column($rows, 'actor_id')));
        $user_names = [];
        foreach ($actor_ids as $actor_id) {
            $u = get_userdata((int) $actor_id);
            $user_names[$actor_id] = $u ? $u->display_name : __('مجهول', 'olama-registration');
        }

        // Process, populate, and filter rows in PHP
        $processed = [];
        $service_types = [];

        foreach ($rows as $r) {
            $after = !empty($r->after_state) ? json_decode($r->after_state) : null;
            $actor_name   = $user_names[$r->actor_id] ?? __('نظام', 'olama-registration');
            $action_label = $action_labels[$r->action] ?? esc_html($r->action);
            
            // Extract amount
            $amount = 0.0;
            if ($r->entity_type === 'invoice') {
                $amount = (float)($after->total ?? 0);
            } elseif ($r->entity_type === 'payment') {
                $amount = (float)($after->amount ?? 0);
            } elseif ($r->entity_type === 'agreement') {
                $amount = (float)($after->total_amount ?? 0);
            }
            $r->extracted_amount = $amount;

            // Describe the accounting effect of the audited operation. Audit
            // rows store the original payment amount when a receipt is
            // reversed, so the action determines whether it is debit/credit.
            $movement_nature = __('غير مالية', 'olama-registration');
            if ($r->entity_type === 'invoice' && in_array($r->action, ['created', 'updated'], true)) {
                $movement_nature = __('مدينة', 'olama-registration');
            } elseif ($r->entity_type === 'payment' && in_array($r->action, ['payment_created', 'created'], true)) {
                $movement_nature = __('دائنة', 'olama-registration');
            } elseif ($r->entity_type === 'payment' && in_array($r->action, ['payment_reversed', 'reversed'], true)) {
                $movement_nature = __('مدينة (عكس دفعة)', 'olama-registration');
            }
            $r->movement_nature = $movement_nature;

            // Extract service type
            $stype = '';
            if ($r->entity_type === 'invoice' && !empty($after->notes)) {
                $clean_notes = trim($after->notes);
                if (preg_match('/^(?:طبيعة الخدمة|رسوم خدمة|رسوم خدمة إضافية):\s*(.+)$/mu', $clean_notes, $matches)) {
                    $stype = trim($matches[1]);
                }
            }
            $r->extracted_service_type = $stype;

            // Extract contract nature
            $cnature = '';
            if ($r->entity_type === 'agreement' && !empty($after->activity_type)) {
                $cnature = $after->activity_type;
            }
            $r->extracted_contract_nature = $cnature;

            // Build dynamic description
            $note_desc = '—';
            switch ($r->action) {
                case 'payment_created':
                case 'created':
                    if ($r->entity_type === 'invoice') {
                        $note_desc = sprintf(__('تم إصدار الفاتورة %s بقيمة %s د.أ', 'olama-registration'), 
                            '<code>' . esc_html($after->invoice_number ?? '') . '</code>', 
                            '<strong>' . number_format((float)($after->total ?? 0), 2) . '</strong>'
                        );
                    } elseif ($r->entity_type === 'payment') {
                        $note_desc = sprintf(__('تم تسجيل سند قبض بقيمة %s د.أ للفاتورة %s', 'olama-registration'), 
                            '<strong>' . number_format((float)($after->amount ?? 0), 2) . '</strong>',
                            '<code>' . esc_html($after->invoice_number ?? ('#' . ($after->invoice_id ?? ''))) . '</code>'
                        );
                    } elseif ($r->entity_type === 'agreement') {
                        $note_desc = sprintf(__('تم إنشاء العقد %s بقيمة إجمالية %s د.أ', 'olama-registration'), 
                            '<code>' . esc_html($after->agreement_number ?? '') . '</code>', 
                            '<strong>' . number_format((float)($after->total_amount ?? 0), 2) . '</strong>'
                        );
                    }
                    break;
                case 'updated':
                    if ($r->entity_type === 'invoice') {
                        $note_desc = sprintf(__('تم تعديل تفاصيل الفاتورة %s', 'olama-registration'), 
                            '<code>' . esc_html($after->invoice_number ?? '') . '</code>'
                        );
                    } elseif ($r->entity_type === 'agreement') {
                        $note_desc = sprintf(__('تم تعديل تفاصيل العقد %s', 'olama-registration'), 
                            '<code>' . esc_html($after->agreement_number ?? '') . '</code>'
                        );
                    }
                    break;
                case 'cancelled':
                    if ($r->entity_type === 'invoice') {
                        $note_desc = sprintf(__('تم إلغاء الفاتورة %s', 'olama-registration'), 
                            '<code>' . esc_html($after->invoice_number ?? '') . '</code>'
                        );
                    }
                    break;
                case 'payment_reversed':
                case 'reversed':
                    if ($r->entity_type === 'payment') {
                        $note_desc = sprintf(__('تم عكس السند المالي للفاتورة %s بقيمة %s د.أ', 'olama-registration'), 
                            '<code>' . esc_html($after->invoice_number ?? ('#' . ($after->invoice_id ?? ''))) . '</code>',
                            '<strong>' . number_format((float)($after->amount ?? 0), 2) . '</strong>'
                        );
                    }
                    break;
                case 'status_changed':
                    if ($r->entity_type === 'invoice') {
                        $note_desc = sprintf(__('تغيرت حالة الفاتورة %s إلى: %s', 'olama-registration'), 
                            '<code>' . esc_html($after->invoice_number ?? '') . '</code>',
                            '<strong>' . esc_html($after->status ?? '') . '</strong>'
                        );
                    }
                    break;
            }

            if ($note_desc === '—' && !empty($after->notes)) {
                $note_desc = esc_html($after->notes);
            }

            $r->dynamic_desc = $note_desc;
            $r->action_label = $action_label;
            $r->actor_name = $actor_name;

            // 1. Search Filter (checks description, action, or type)
            if ($search) {
                $search_lower = mb_strtolower($search);
                $found = (strpos(mb_strtolower($r->dynamic_desc), $search_lower) !== false)
                    || (strpos(mb_strtolower($r->action_label), $search_lower) !== false)
                    || (strpos(mb_strtolower($r->actor_name), $search_lower) !== false)
                    || (strpos(mb_strtolower($r->entity_type), $search_lower) !== false);
                if (!$found) continue;
            }

            // 2. Date From Filter
            if ($date_from) {
                $row_date = date('Y-m-d', strtotime($r->created_at));
                if ($row_date < $date_from) continue;
            }

            // 3. Date To Filter
            if ($date_to) {
                $row_date = date('Y-m-d', strtotime($r->created_at));
                if ($row_date > $date_to) continue;
            }

            // 4. Amount Filter
            if ($filter_amount !== '') {
                if (abs($amount - (float)$filter_amount) > 0.01) continue;
            }

            // 5. Service Type Filter
            if ($service_type) {
                if (mb_strtolower($r->extracted_service_type) !== mb_strtolower($service_type)) continue;
            }

            // 6. Agreement Nature Filter
            if ($agreement_nature) {
                if (mb_strtolower($r->extracted_contract_nature) !== mb_strtolower($agreement_nature)) continue;
            }

            $processed[] = $r;
        }

        // Apply Sorting
        usort($processed, function ($a, $b) use ($orderby, $order) {
            if ($orderby === 'amount') {
                $valA = $a->extracted_amount;
                $valB = $b->extracted_amount;
            } else {
                $valA = strtotime($a->created_at);
                $valB = strtotime($b->created_at);
            }

            if ($valA == $valB) return 0;
            if ($order === 'asc') {
                return ($valA < $valB) ? -1 : 1;
            } else {
                return ($valA > $valB) ? -1 : 1;
            }
        });

        // Apply Pagination
        $total_items = count($processed);
        $total_pages = ceil($total_items / $per_page);
        $paged = min($paged, max(1, $total_pages));
        $offset = ($paged - 1) * $per_page;
        $paginated_rows = array_slice($processed, $offset, $per_page);

        // Build HTML Filters Bar
        $html = '<div class="os-hub-history-filters" style="display: flex; gap: 12px; margin-bottom: 16px; background: #f8fafc; padding: 12px; border-radius: 8px; border: 1px solid #e2e8f0; align-items: flex-end; flex-wrap: wrap;" dir="rtl">';
        $html .= '  <div style="flex: 1; min-width: 150px;">';
        $html .= '      <label style="font-weight: 700; font-size: 11px; display: block; margin-bottom: 4px; color: #475569;">' . __('البحث العام', 'olama-registration') . '</label>';
        $html .= '      <input type="text" id="history-filter-search" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('بحث بالعملية...', 'olama-registration') . '" style="width: 100%; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; height: 32px;">';
        $html .= '  </div>';
        $html .= '  <div>';
        $html .= '      <label style="font-weight: 700; font-size: 11px; display: block; margin-bottom: 4px; color: #475569;">' . __('من تاريخ', 'olama-registration') . '</label>';
        $html .= '      <input type="date" id="history-filter-date-from" value="' . esc_attr($date_from) . '" style="padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; height: 32px;">';
        $html .= '  </div>';
        $html .= '  <div>';
        $html .= '      <label style="font-weight: 700; font-size: 11px; display: block; margin-bottom: 4px; color: #475569;">' . __('إلى تاريخ', 'olama-registration') . '</label>';
        $html .= '      <input type="date" id="history-filter-date-to" value="' . esc_attr($date_to) . '" style="padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; height: 32px;">';
        $html .= '  </div>';
        $html .= '  <div>';
        $html .= '      <label style="font-weight: 700; font-size: 11px; display: block; margin-bottom: 4px; color: #475569;">' . __('القيمة (د.أ)', 'olama-registration') . '</label>';
        $html .= '      <input type="number" step="0.01" id="history-filter-amount" value="' . esc_attr($filter_amount) . '" placeholder="0.00" style="width: 80px; padding: 5px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; height: 32px;">';
        $html .= '  </div>';
        $html .= '  <div>';
        $html .= '      <label style="font-weight: 700; font-size: 11px; display: block; margin-bottom: 4px; color: #475569;">' . __('طبيعة الخدمة', 'olama-registration') . '</label>';
        $html .= '      <select id="history-filter-service-type" style="padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; height: 32px; min-width: 110px;">';
        $html .= '          <option value="">' . __('الكل', 'olama-registration') . '</option>';
        foreach ($custom_services as $st) {
            $html .= '      <option value="' . esc_attr($st) . '" ' . selected($service_type, $st, false) . '>' . esc_html($st) . '</option>';
        }
        $html .= '      </select>';
        $html .= '  </div>';
        $html .= '  <div>';
        $html .= '      <label style="font-weight: 700; font-size: 11px; display: block; margin-bottom: 4px; color: #475569;">' . __('طبيعة العقد', 'olama-registration') . '</label>';
        $html .= '      <select id="history-filter-agreement-nature" style="padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; height: 32px; min-width: 110px;">';
        $html .= '          <option value="">' . __('الكل', 'olama-registration') . '</option>';
        foreach ($agreement_natures as $an) {
            $html .= '      <option value="' . esc_attr($an) . '" ' . selected($agreement_nature, $an, false) . '>' . esc_html($an) . '</option>';
        }
        $html .= '      </select>';
        $html .= '  </div>';
        $html .= '  <div>';
        $html .= '      <label style="font-weight: 700; font-size: 11px; display: block; margin-bottom: 4px; color: #475569;">' . __('ترتيب حسب', 'olama-registration') . '</label>';
        $html .= '      <select id="history-filter-orderby" style="padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 12px; height: 32px;">';
        $html .= '          <option value="date" ' . selected($orderby, 'date', false) . '>' . __('التاريخ', 'olama-registration') . '</option>';
        $html .= '          <option value="amount" ' . selected($orderby, 'amount', false) . '>' . __('القيمة المالية', 'olama-registration') . '</option>';
        $html .= '      </select>';
        $html .= '  </div>';
        $html .= '  <div>';
        $html .= '      <button type="button" id="history-sort-toggle-btn" class="button" data-order="' . esc_attr($order) . '" style="height: 32px; display: flex; align-items: center; justify-content: center; gap: 4px; font-size:12px;">';
        if ($order === 'asc') {
            $html .= '      <span class="dashicons dashicons-arrow-up-alt2"></span> ' . __('تصاعدي', 'olama-registration');
        } else {
            $html .= '      <span class="dashicons dashicons-arrow-down-alt2"></span> ' . __('تنازلي', 'olama-registration');
        }
        $html .= '      </button>';
        $html .= '  </div>';
        $html .= '  <div>';
        $html .= '      <button type="button" id="history-filter-apply-btn" class="button button-primary" style="height: 32px; background: #E8920A; border-color: #E8920A; color: white;">' . __('تصفية', 'olama-registration') . '</button>';
        $html .= '  </div>';
        $html .= '</div>';

        // Check if there are results
        if (empty($paginated_rows)) {
            $html .= $this->hub_empty_state(
                __('لا توجد سجلات تطابق عوامل التصفية الحالية', 'olama-registration'),
                'dashicons-backup'
            );
            return ['html' => $html];
        }

        // Render Table
        $html .= '<table class="os-hub-data-table widefat fixed striped">';
        $html .= '<thead><tr>';
        $html .= '<th>' . __('الإجراء', 'olama-registration') . '</th>';
        $html .= '<th>' . __('المستخدم', 'olama-registration') . '</th>';
        $html .= '<th>' . __('طبيعة الحركة', 'olama-registration') . '</th>';
        $html .= '<th>' . __('قيمة الحركة', 'olama-registration') . '</th>';
        
        $date_active = ($orderby === 'date');
        $date_next_order = ($date_active && $order === 'desc') ? 'asc' : 'desc';
        $date_icon = '';
        if ($date_active) {
            $date_icon = $order === 'asc' ? ' <span class="dashicons dashicons-arrow-up-alt2" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>' : ' <span class="dashicons dashicons-arrow-down-alt2" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>';
        }
        $html .= '<th style="cursor: pointer; user-select: none;" id="history-header-date" data-order="' . esc_attr($date_next_order) . '" title="' . esc_attr__('ترتيب حسب التاريخ', 'olama-registration') . '">' . __('التاريخ', 'olama-registration') . $date_icon . '</th>';
        
        $html .= '<th>' . __('التفاصيل والعملية', 'olama-registration') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($paginated_rows as $r) {
            $date = $r->created_at ? wp_date('Y/m/d H:i', strtotime($r->created_at)) : '—';

            $html .= '<tr>';
            $html .= '<td><span class="os-hub-badge os-hub-badge--blue">' . esc_html($r->action_label) . '</span></td>';
            $html .= '<td>' . esc_html($r->actor_name) . '</td>';
            $html .= '<td>' . esc_html($r->movement_nature) . '</td>';
            $html .= '<td dir="ltr" style="white-space:nowrap; font-weight:600;">' . ($r->extracted_amount != 0.0 ? esc_html(number_format(abs((float) $r->extracted_amount), 2)) . ' ' . esc_html__('د.أ', 'olama-registration') : '—') . '</td>';
            $html .= '<td dir="ltr" style="white-space:nowrap;">' . esc_html($date) . '</td>';
            $html .= '<td>' . $r->dynamic_desc . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        // Render Pagination Controls
        if ($total_pages > 1) {
            $html .= '<div class="os-hub-pagination" style="display: flex; gap: 4px; justify-content: center; margin-top: 16px; align-items: center;" dir="rtl">';
            
            $first_disabled = $paged === 1 ? 'disabled="disabled"' : '';
            $last_disabled = $paged === $total_pages ? 'disabled="disabled"' : '';
            
            $html .= '  <button type="button" class="button history-paged-btn" data-page="1" ' . $first_disabled . ' title="' . esc_attr__('الصفحة الأولى', 'olama-registration') . '">&laquo;</button>';
            $html .= '  <button type="button" class="button history-paged-btn" data-page="' . ($paged - 1) . '" ' . $first_disabled . ' title="' . esc_attr__('السابق', 'olama-registration') . '">&lsaquo;</button>';
            
            $html .= '  <span style="margin: 0 8px; font-size: 13px;">';
            $html .= sprintf(__('صفحة %d من %d (الإجمالي: %d عملية)', 'olama-registration'), $paged, $total_pages, $total_items);
            $html .= '  </span>';

            $html .= '  <button type="button" class="button history-paged-btn" data-page="' . ($paged + 1) . '" ' . $last_disabled . ' title="' . esc_attr__('التالي', 'olama-registration') . '">&rsaquo;</button>';
            $html .= '  <button type="button" class="button history-paged-btn" data-page="' . $total_pages . '" ' . $last_disabled . ' title="' . esc_attr__('الصفحة الأخيرة', 'olama-registration') . '">&raquo;</button>';
            $html .= '</div>';
        }

        return ['html' => $html, 'meta' => ['count' => count($processed)]];
    }

    // ── Tile: Settlement Receipts (families only) ─────────────────────────────

    private function hub_tile_settlements(string $uid, string $type, int $year): array
    {
        if ($type !== 'family') {
            return ['html' => $this->hub_empty_state(
                __('إيصالات التسوية للعائلات المسجلة فقط', 'olama-registration'),
                'dashicons-bank'
            )];
        }

        global $wpdb;
        $family = Olama_Reg_Core_Gateway::family($uid);
        $args  = [$family ? (string) $family->oracle_family_id : $uid];
        $yc    = '';

        if ($year) {
            $cols = $wpdb->get_col("DESCRIBE {$wpdb->prefix}olama_settlement_receipts", 0);
            if (in_array('academic_year_id', $cols)) {
                $yc    = ' AND academic_year_id = %d';
                $args[] = $year;
            }
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.receipt_number, r.family_id, r.payment_category, r.original_amount,
                    r.settled_amount, r.remaining_balance, r.payment_method,
                    r.oracle_receipt_number, r.settlement_date, r.status, r.created_at,
                    COALESCE(NULLIF(f.sponsor_full_name, ''), NULLIF(f.father_name, ''), r.family_id) AS father_first_name,
                    '' AS father_family_name
             FROM {$wpdb->prefix}olama_settlement_receipts r
             LEFT JOIN " . olama_core()->read_models()->table( 'families' ) . " f
                ON f.family_uid = COALESCE(NULLIF(r.family_uid, ''), r.family_id)
                OR f.oracle_family_id = COALESCE(NULLIF(r.oracle_family_id, ''), r.family_id)
             WHERE r.family_id = %s {$yc}
             ORDER BY r.id DESC
             LIMIT 50",
            $args
        ));

        if (! $rows) {
            return ['html' => $this->hub_empty_state(
                __('لا توجد إيصالات تسوية لهذه العائلة', 'olama-registration'),
                'dashicons-bank'
            )];
        }

        $status_map = [
            'pending_settlement' => ['label' => __('بانتظار التسوية', 'olama-registration'), 'cls' => 'os-hub-badge--orange'],
            'settled'            => ['label' => __('تمت التسوية', 'olama-registration'), 'cls' => 'os-hub-badge--green'],
            'cancelled'          => ['label' => __('ملغي', 'olama-registration'),    'cls' => 'os-hub-badge--gray'],
        ];

        $html  = '<table class="os-hub-data-table widefat fixed striped">';
        $html .= '<thead><tr>';
        $html .= '<th style="width: 120px;">' . __('رقم الإيصال', 'olama-registration') . '</th>';
        $html .= '<th style="width: 100px;">' . __('رقم العائلة', 'olama-registration') . '</th>';
        $html .= '<th>' . __('اسم العائلة', 'olama-registration') . '</th>';
        $html .= '<th>' . __('قائمة الخدمات', 'olama-registration') . '</th>';
        $html .= '<th>' . __('المبلغ', 'olama-registration') . '</th>';
        $html .= '<th>' . __('المتبقي', 'olama-registration') . '</th>';
        $html .= '<th>' . __('التاريخ', 'olama-registration') . '</th>';
        $html .= '<th>' . __('الحالة', 'olama-registration') . '</th>';
        $html .= '<th style="width: 200px;">' . __('إجراءات', 'olama-registration') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $s = $status_map[$r->status] ?? ['label' => esc_html($r->status), 'cls' => 'os-hub-badge--gray'];
            $family_name = trim(($r->father_first_name ?? '') . ' ' . ($r->father_family_name ?? ''));
            $date = date_i18n(get_option('date_format'), strtotime($r->created_at));

            $style = '';
            if ($r->status === 'pending_settlement') {
                $style = ' style="background-color: #fff8e5;"';
            }

            $html .= '<tr class="status-' . esc_attr($r->status) . '"' . $style . '>';
            $html .= '<td><strong>' . esc_html($r->receipt_number) . '</strong></td>';
            $html .= '<td><span class="olama-reg-uid-badge">' . esc_html($r->family_id) . '</span></td>';
            $html .= '<td>' . esc_html($family_name) . '</td>';
            $html .= '<td>' . esc_html($r->payment_category) . '</td>';
            $html .= '<td dir="ltr">' . number_format((float)$r->original_amount, 2) . '</td>';
            $html .= '<td dir="ltr">' . number_format((float)$r->remaining_balance, 2) . '</td>';
            $html .= '<td>' . esc_html($date) . '</td>';
            $html .= '<td><span class="os-hub-badge ' . $s['cls'] . '">' . $s['label'] . '</span></td>';
            
            $html .= '<td>';
            $print_url = admin_url('admin.php?page=olama-registration-settlements&action=print&id=' . (int)$r->id);
            $html .= '<a href="' . esc_url($print_url) . '" target="_blank" class="button button-small">' . __('طباعة', 'olama-registration') . '</a> ';
            
            if ($r->status === 'pending_settlement') {
                $html .= '<button type="button" class="button button-small button-primary btn-settle-receipt" data-id="' . (int)$r->id . '" data-amount="' . number_format((float)$r->original_amount, 2) . '">' . __('تسوية', 'olama-registration') . '</button> ';
                $html .= '<button type="button" class="button button-small btn-cancel-receipt" data-id="' . (int)$r->id . '" style="color:#d63638; border-color:#d63638;">' . __('إلغاء', 'olama-registration') . '</button>';
            }
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return ['html' => $html, 'meta' => ['count' => count($rows)]];
    }

    // ── HTML Helpers ──────────────────────────────────────────────────────────

    // ══════════════════════════════════════════════════════════════════════════
    // PHASE 4: Add Child (External Customers)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Add a child record to an external customer.
     *
     * POST: uid (customer_uid), child_name, grade
     */
    public function hub_add_child(): void
    {
        $this->hub_guard();
        $this->hub_require_caps(['olama_manage_registration_families']);

        $uid        = sanitize_text_field($_POST['uid']        ?? '');
        $child_name = sanitize_text_field($_POST['child_name'] ?? '');
        $grade      = sanitize_text_field($_POST['grade']      ?? '');

        if (! $uid) {
            wp_send_json_error(['message' => __('معرف العميل غير صالح.', 'olama-registration')]);
        }

        if (! $child_name) {
            wp_send_json_error(['message' => __('اسم الابن مطلوب.', 'olama-registration')]);
        }

        global $wpdb;

        $customer = Olama_Reg_Customer::get_by_uid($uid);

        if (! $customer) {
            wp_send_json_error(['message' => __('العميل غير موجود.', 'olama-registration')]);
        }

        $child_id = Olama_Reg_Child::add((int) $customer->id, [
            'child_name' => $child_name,
            'grade'      => $grade,
            'notes'      => sanitize_textarea_field($_POST['notes'] ?? ''),
        ]);

        if (is_wp_error($child_id)) {
            wp_send_json_error(['message' => $child_id->get_error_message()]);
        }

        $child = Olama_Reg_Child::get((int) $child_id);

        wp_send_json_success([
            'message'      => __('Child added successfully.', 'olama-registration'),
            'child'        => $child,
            'child_name'   => $child ? $child->child_name : $child_name,
            'child_uid'    => $child ? $child->child_uid : '',
            'grade'        => $child ? $child->grade : $grade,
            'updatedTiles' => ['children'],
        ]);

    }

    // ══════════════════════════════════════════════════════════════════════════
    // PHASE 3: Interactive Profile Actions
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Create a core Olama School family from the contacts hub.
     *
     * POST: family_uid (optional), family_name, father_mobile, mother_mobile, address
     */
    public function hub_add_family(): void
    {
        $this->hub_guard();
        $this->hub_require_caps(['olama_manage_registration_families']);

        wp_send_json_error([
            'message' => __(
                'Enrolled families are managed in Olama Core. Open the family there to create or edit it.',
                'olama-registration'
            ),
        ]);
    }

    /**
     * Save profile edits for family or external customer.
     *
     * POST: uid, type ('family'|'external'), + field values
     */
    public function hub_save_profile(): void
    {
        $this->hub_guard();
        $this->hub_require_caps(['olama_manage_registration_families']);

        $uid  = sanitize_text_field($_POST['uid']  ?? '');
        $type = sanitize_key($_POST['type'] ?? 'family');

        if (! $uid) {
            wp_send_json_error(['message' => __('معرف غير صالح.', 'olama-registration')]);
        }

        if ($type === 'family') {
            wp_send_json_error([
                'message' => __(
                    'Enrolled family profiles are read-only here and must be edited in Olama Core.',
                    'olama-registration'
                ),
            ]);
        }

        global $wpdb;

        // External customer — resolve by UID. School family data is read-only.
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s LIMIT 1",
            $uid
        ));

        if (! $customer) {
            wp_send_json_error(['message' => __('العميل غير موجود.', 'olama-registration')]);
        }

        $data = [
            'customer_name' => sanitize_text_field($_POST['customer_name'] ?? ''),
            'phone'         => sanitize_text_field($_POST['phone']         ?? ''),
            'notes'         => sanitize_textarea_field($_POST['notes']     ?? ''),
        ];

        if (empty($data['customer_name'])) {
            wp_send_json_error(['message' => __('اسم العميل مطلوب.', 'olama-registration')]);
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'olama_customers',
            $data,
            ['id' => $customer->id]
        );

        if ($updated === false) {
            wp_send_json_error(['message' => __('حدث خطأ أثناء الحفظ.', 'olama-registration')]);
        }

        wp_send_json_success([
            'message' => __('تم تحديث بيانات العميل.', 'olama-registration'),
            'name'    => $data['customer_name'],
        ]);
    }

    /**
     * Toggle active/inactive status for family or customer.
     *
     * POST: uid, type ('family'|'external'), active (0|1)
     */
    public function hub_toggle_active(): void
    {
        $this->hub_guard();
        $this->hub_require_caps(['olama_manage_registration_families']);

        $uid    = sanitize_text_field($_POST['uid']  ?? '');
        $type   = sanitize_key($_POST['type']   ?? 'family');
        $active = (int) ($_POST['active'] ?? 1) ? 0 : 1; // toggle: flip the incoming value

        if (! $uid) {
            wp_send_json_error(['message' => __('معرف غير صالح.', 'olama-registration')]);
        }

        if ($type === 'family') {
            wp_send_json_error([
                'message' => __(
                    'Enrolled family status is owned by Olama Core and cannot be changed here.',
                    'olama-registration'
                ),
            ]);
        }

        global $wpdb;

        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}olama_customers WHERE customer_uid = %s LIMIT 1",
            $uid
        ));

        $updated = $customer ? $wpdb->update(
            $wpdb->prefix . 'olama_customers',
            ['is_active' => $active],
            ['id' => $customer->id]
        ) : false;

        if ($updated === false) {
            wp_send_json_error(['message' => __('حدث خطأ أثناء تغيير الحالة.', 'olama-registration')]);
        }

        wp_send_json_success([
            'message' => $active
                ? __('تم تفعيل الملف.', 'olama-registration')
                : __('تم تعطيل الملف.', 'olama-registration'),
            'is_active' => $active,
        ]);
    }

    // ── HTML Helpers ──────────────────────────────────────────────────────────

    private function hub_empty_state(string $message, string $icon = 'dashicons-info-outline'): string
    {
        return '<div class="os-hub-notice">'
             . '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>'
             . '<p class="os-hub-notice__title">' . esc_html($message) . '</p>'
             . '</div>';
    }

    private function hub_tr(string $label, string $value): string
    {
        return '<tr><th scope="row" style="width:160px;">' . esc_html($label) . '</th>'
             . '<td>' . $value . '</td></tr>';
    }

    public function ajax_reset_system(): void
    {
        $this->guard();

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'هذه العملية متاحة لمدير النظام فقط.', 'olama-registration' ) ], 403 );
        }

        // Safety keyword lock
        $confirm = sanitize_text_field($_POST['confirm_reset'] ?? '');
        if (strtoupper($confirm) !== 'RESET') {
            wp_send_json_error(['message' => 'يرجى كتابة كلمة التأكيد RESET بشكل صحيح للمتابعة.']);
        }

        global $wpdb;
        $cleared = [];
        $reset_errors = [];
        $tables_to_verify = [];

        $truncate_tables = static function ( array $tables ) use ( $wpdb, &$reset_errors, &$tables_to_verify ): void {
            foreach ( $tables as $table_suffix ) {
                $table = $wpdb->prefix . $table_suffix;
                $exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
                if ( $exists !== $table ) {
                    continue;
                }

                $tables_to_verify[] = $table;
                if ( $wpdb->query( "TRUNCATE TABLE `{$table}`" ) === false ) {
                    $reset_errors[] = $table . ': ' . $wpdb->last_error;
                }
            }
        };

        // Disable foreign key checks temporarily if needed, though they are not set on these tables typically
        $wpdb->query("SET FOREIGN_KEY_CHECKS = 0;");

        // 1. Transactional & Financial Data
        if (!empty($_POST['reset_transactions'])) {
            $tables = [
                'olama_payment_allocations',
                'olama_invoice_adjustments',
                'olama_invoice_items',
                'olama_invoice_installments',
                'olama_bank_transfer_details',
                'olama_epayment_details',
                'olama_cheques',
                'olama_payments',
                'olama_invoices',
                'olama_cash_bank_movements',
                'olama_account_transfers',
                'olama_cash_sessions',
                'olama_billing_audit',
                'olama_settlement_receipts',
                'olama_family_financial_snapshots',
                'olama_reg_financial',
            ];
            $truncate_tables( $tables );

            // Account definitions remain available after a reset, but their
            // configured baseline must not carry a pre-reset balance forward.
            $accounts_reset = $wpdb->query(
                "UPDATE {$wpdb->prefix}olama_financial_accounts
                 SET opening_balance = 0.00"
            );
            if ( $accounts_reset === false ) {
                $reset_errors[] = $wpdb->prefix . 'olama_financial_accounts: ' . $wpdb->last_error;
            }

            /*
             * Agreements may intentionally be retained while their invoices and
             * receipts are reset. Remove every reference to the deleted financial
             * documents so a fresh invoice can be generated in the next test run.
             */
            $agreement_fees_table = $wpdb->prefix . 'olama_agreement_fees';
            if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $agreement_fees_table ) ) ) === $agreement_fees_table ) {
                $result = $wpdb->query(
                    "UPDATE `{$agreement_fees_table}`
                     SET invoice_id = NULL, paid_status = 'unpaid'"
                );
                if ( $result === false ) {
                    $reset_errors[] = $agreement_fees_table . ': ' . $wpdb->last_error;
                }
            }

            $amendments_table = $wpdb->prefix . 'olama_agreement_amendments';
            if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $amendments_table ) ) ) === $amendments_table ) {
                $result = $wpdb->query(
                    "UPDATE `{$amendments_table}`
                     SET invoice_id = NULL, credit_adjustment_id = NULL, debit_adjustment_id = NULL"
                );
                if ( $result === false ) {
                    $reset_errors[] = $amendments_table . ': ' . $wpdb->last_error;
                }
            }

            $amendment_lines_table = $wpdb->prefix . 'olama_agreement_amendment_lines';
            if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $amendment_lines_table ) ) ) === $amendment_lines_table ) {
                $result = $wpdb->query( "UPDATE `{$amendment_lines_table}` SET invoice_id = NULL" );
                if ( $result === false ) {
                    $reset_errors[] = $amendment_lines_table . ': ' . $wpdb->last_error;
                }
            }

            $cleared[] = 'البيانات المالية والمعاملات والسندات والقيود الاستحقاقية وأرصدة الحسابات';
        }

        // 2. Agreements Data
        if (!empty($_POST['reset_agreements'])) {
            $tables = [
                'olama_agreement_amendment_lines',
                'olama_agreement_amendments',
                'olama_agreement_participants',
                'olama_agreement_fees',
                'olama_agreement_clauses',
                'olama_agreements',
            ];
            $truncate_tables( $tables );
            $cleared[] = 'بيانات العقود بنوعيها والرسوم والبنود المرتبطة بها';
        }

        // 3. Customers Data
        if (!empty($_POST['reset_customers'])) {
            $tables = [
                'olama_customer_children',
                'olama_customers',
            ];
            $truncate_tables( $tables );
            $cleared[] = 'بيانات جهات الاتصال والعملاء الإضافيين وأولادهم';
        }

        // 4. Configuration Templates
        if (!empty($_POST['reset_templates'])) {
            $tables = [
                'olama_fee_templates',
                'olama_agreement_templates',
                'olama_agreement_template_fees',
                'olama_agreement_template_clauses',
                'olama_agreement_clause_bank'
            ];
            $truncate_tables( $tables );
            $cleared[] = 'قوالب الرسوم، قوالب العقود، وبنك الشروط';
        }

        $wpdb->query("SET FOREIGN_KEY_CHECKS = 1;");

        if (empty($cleared)) {
            wp_send_json_error(['message' => 'لم يتم اختيار أي بيانات لمسحها.']);
        }

        foreach ( array_unique( $tables_to_verify ) as $table ) {
            $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
            if ( $remaining !== 0 ) {
                $reset_errors[] = sprintf( '%s: بقي %d سجل بعد المسح', $table, $remaining );
            }
        }

        if ( ! empty( $_POST['reset_transactions'] ) ) {
            $remaining_opening_balances = (float) $wpdb->get_var(
                "SELECT COALESCE(SUM(ABS(opening_balance)), 0)
                 FROM {$wpdb->prefix}olama_financial_accounts"
            );
            if ( $remaining_opening_balances > 0.0001 ) {
                $reset_errors[] = __( 'لم تتم إعادة جميع الأرصدة الافتتاحية للحسابات إلى صفر.', 'olama-registration' );
            }
        }

        if ( $reset_errors ) {
            wp_send_json_error( [
                'message' => __( 'اكتمل المسح جزئيًا، لكن تعذر تنظيف بعض البيانات. التفاصيل: ', 'olama-registration' )
                    . implode( ' | ', array_unique( $reset_errors ) ),
            ] );
        }

        wp_send_json_success([
            'message' => 'تم تهيئة النظام ومسح البيانات المحددة بنجاح: <br>• ' . implode('<br>• ', $cleared),
        ]);
    }

}
