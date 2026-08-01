<?php
/**
 * Invoice CRUD and lifecycle management.
 *
 * Tables used:
 *   {prefix}olama_invoices
 *   {prefix}olama_invoice_items
 *   {prefix}olama_invoice_installments
 *   {prefix}olama_billing_audit
 */

if (!defined('ABSPATH'))
    exit;

class Olama_Reg_Billing_Invoice
{
    private const FINANCIAL_UPDATE_ERROR = 'لا يمكن تعديل البيانات المالية للفاتورة لأنها تحتوي على دفعات. يرجى استخدام إشعار دائن أو إشعار مدين أو عكس سند قبض.';

    private const FINANCIAL_FIELDS = [
        'family_uid',
        'student_uid',
        'fee_template_id',
        'ext_customer_id',
        'ext_child_id',
        'linked_agreement_id',
        'agreement_id',
        'status',
        'discount',
        'items',
        'installments',
        'subtotal',
        'total',
        'amount_paid',
        'balance',
    ];

    // ── Table helpers ─────────────────────────────────────────────────────────

    private static function t(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    // ── Generate invoice number ───────────────────────────────────────────────

    /**
     * Generate next sequential invoice number for the given year.
     * Format: INV-YYYY-NNNNN
     */
    public static function generate_number(int $year_id): string
    {
        global $wpdb;

        // Resolve the label from the Core-owned academic calendar.
        $year_label = '';
        if (function_exists('olama_core')) {
            $ay = olama_core()->academic_calendar()->year($year_id);
            if ($ay && !empty($ay->year_name)) {
                // Extract first 4-digit sequence from the label (e.g. "2024-2025" → 2024)
                preg_match('/(\d{4})/', $ay->year_name, $m);
                $year_label = $m[1] ?? date('Y');
            }
        }
        if (!$year_label) {
            $year_label = date('Y');
        }

        $prefix = 'INV-' . $year_label . '-';

        $max = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(invoice_number, %d) AS UNSIGNED)), 0)
             FROM " . self::t('olama_invoices') . "
             WHERE invoice_number LIKE %s",
            strlen($prefix) + 1,
            $wpdb->esc_like($prefix) . '%'
        ));

        return $prefix . str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    /**
     * Create a new invoice with optional line items.
     *
     * @param array $data {
     *   family_uid, student_uid, academic_year_id, fee_template_id,
     *   issue_date, due_date, status, discount, notes,
     *   items: [ [ description, quantity, unit_price ], ... ],
     *   installments: int
     * }
     * @return int|WP_Error
     */
    public static function create(array $data): int|\WP_Error
    {
        global $wpdb;

        $identity = self::resolve_payer_context($data);
        if (is_wp_error($identity)) {
            return $identity;
        }

        $year_id = absint($data['academic_year_id'] ?? 0);
        if (!$year_id) {
            return new \WP_Error('missing_year', __('Academic year is required.', 'olama-registration'));
        }
        $year_allowed = Olama_Reg_Academic_Year_Context::assert_writable($year_id);
        if (is_wp_error($year_allowed)) {
            return $year_allowed;
        }

        $issue_date = self::sanitize_date($data['issue_date'] ?? date('Y-m-d'));
        if (!$issue_date)
            $issue_date = date('Y-m-d');

        $notes = sanitize_textarea_field($data['notes'] ?? '');
        $service_type = sanitize_text_field($data['service_type'] ?? '');
        $fee_template_id = absint($data['fee_template_id'] ?? 0);
        $direct_service_invoice = $service_type !== '';

        if ($direct_service_invoice) {
            if (!$fee_template_id) {
                return new \WP_Error('missing_fee_template', __('يجب اختيار نموذج رسوم للفاتورة المباشرة.', 'olama-registration'));
            }

            $fee_template = Olama_Reg_Billing_Fees::get_template($fee_template_id);
            if (!$fee_template || ($fee_template->subject_type ?? 'general') !== 'service' || (string) ($fee_template->subject_value ?? '') !== $service_type) {
                return new \WP_Error('invalid_fee_template', __('نموذج الرسوم المختار يجب أن يكون مرتبطًا بالخدمة المختارة.', 'olama-registration'));
            }

            $data['installments'] = 1;
            $data['linked_agreement_id'] = 0;
        }

        if ($service_type) {
            $notes = "طبيعة الخدمة: " . $service_type . "\n" . $notes;
        }

        $payload = [
            'invoice_number' => self::generate_number($year_id),
            'payer_type' => $identity['payer_type'],
            'family_uid' => $identity['family_uid'],
            'oracle_family_id' => $identity['oracle_family_id'],
            'student_uid' => $identity['student_uid'],
            'oracle_student_id' => $identity['oracle_student_id'],
            'customer_id' => $identity['customer_id'],
            'academic_year_id' => $year_id,
            'study_year' => Olama_Reg_Academic_Year_Context::core_study_year($year_id),
            'payer_name_snapshot' => $identity['payer_name_snapshot'],
            'student_name_snapshot' => $identity['student_name_snapshot'],
            'grade_name_snapshot' => $identity['grade_name_snapshot'],
            'fee_template_id' => $fee_template_id ?: null,
            'ext_customer_id' => $identity['customer_id'],
            'ext_child_id' => absint($data['ext_child_id'] ?? 0) ?: null,
            'agreement_id' => absint($data['linked_agreement_id'] ?? 0) ?: null,
            'issue_date' => $issue_date,
            'due_date' => self::sanitize_date($data['due_date'] ?? '') ?: null,
            'status' => self::valid_status($data['status'] ?? 'draft'),
            'subtotal' => 0.00,
            'discount' => self::safe_decimal($data['discount'] ?? 0),
            'total' => 0.00,
            'amount_paid' => 0.00,
            'notes' => trim($notes),
            'created_by' => get_current_user_id(),
        ];

        if ($payload['discount'] < 0) {
            return new \WP_Error('invalid_discount', __('Invoice discount cannot be negative.', 'olama-registration'));
        }

        // Resolve and validate every Core student before creating the invoice.
        $prepared_items = [];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        foreach ($items as $item) {
            $qty = self::safe_decimal($item['quantity'] ?? 1);
            $unit_price = self::safe_decimal($item['unit_price'] ?? 0);
            $description = sanitize_text_field($item['description'] ?? '');
            if ($description === '' || $qty <= 0 || $unit_price < 0) {
                return new \WP_Error('invalid_invoice_item', __('Each invoice item requires a description, a positive quantity, and a non-negative price.', 'olama-registration'));
            }
            $item_student = null;
            $item_student_uid = sanitize_text_field($item['student_uid'] ?? $identity['student_uid'] ?? '');
            if ($item_student_uid !== '') {
                $item_student = Olama_Reg_Core_Gateway::student($item_student_uid);
                if (!$item_student || ($identity['family_uid'] && $item_student->family_uid !== $identity['family_uid'])) {
                    return new \WP_Error('invalid_student', __('The selected student does not belong to this family.', 'olama-registration'));
                }
            }

            $prepared_items[] = [
                'agreement_fee_id' => absint($item['agreement_fee_id'] ?? 0) ?: null,
                'student_uid' => $item_student ? $item_student->student_uid : null,
                'oracle_student_id' => $item_student ? $item_student->oracle_student_id : null,
                'student_name_snapshot' => $item_student ? $item_student->display_name : null,
                'fee_category' => sanitize_text_field($item['fee_category'] ?? '') ?: null,
                'description' => $description,
                'quantity' => $qty,
                'unit_price' => $unit_price,
                'line_total' => round($qty * $unit_price, 2),
            ];
        }

        $prepared_subtotal = round(array_sum(array_column($prepared_items, 'line_total')), 2);
        if ($payload['discount'] > $prepared_subtotal) {
            return new \WP_Error('discount_exceeds_subtotal', __('Invoice discount cannot exceed its subtotal.', 'olama-registration'));
        }

        $owns_transaction = self::begin_atomic('olama_invoice_create');
        $result = $wpdb->insert(self::t('olama_invoices'), $payload);
        if (!$result) {
            self::rollback_atomic($owns_transaction, 'olama_invoice_create');
            return new \WP_Error('db_error', $wpdb->last_error);
        }

        $invoice_id = (int) $wpdb->insert_id;

        // Insert line items
        foreach ($prepared_items as $item) {
            $item['invoice_id'] = $invoice_id;
            if (!$wpdb->insert(self::t('olama_invoice_items'), $item)) {
                self::rollback_atomic($owns_transaction, 'olama_invoice_create');
                return new \WP_Error('db_error', $wpdb->last_error);
            }
        }

        self::recalculate_totals($invoice_id);

        // Generate installments if requested
        $installments = absint($data['installments'] ?? 1);
        if (!empty($payload['fee_template_id'])) {
            $fee_template = Olama_Reg_Billing_Fees::get_template((int) $payload['fee_template_id']);
            if ($fee_template) {
                $subject_type = $fee_template->subject_type ?? 'general';
                $subject_value = $fee_template->subject_value ?? '';

                if ($subject_type === 'service') {
                    $installments = 1;
                } elseif ($subject_type === 'agreement') {
                    $agreement_nature_installments = get_option('olama_reg_agreement_nature_installments', []);
                    if (!is_array($agreement_nature_installments)) {
                        $agreement_nature_installments = [];
                    }
                    $supports_installments = array_key_exists($subject_value, $agreement_nature_installments)
                        ? !empty($agreement_nature_installments[$subject_value])
                        : true;

                    if (!$supports_installments) {
                        $installments = 1;
                    }
                }
            }
        }
        if ($installments > 1) {
            if (!self::generate_installments($invoice_id, $installments)) {
                self::rollback_atomic($owns_transaction, 'olama_invoice_create');
                return new \WP_Error('installments_failed', __('Could not generate the invoice installment schedule.', 'olama-registration'));
            }
        }

        self::commit_atomic($owns_transaction, 'olama_invoice_create');

        self::log_audit('invoice', $invoice_id, 'created', null, self::get_invoice($invoice_id));

        if ( class_exists( 'Olama_Reg_Family_Financial_Summary' ) && ! empty( $data['family_uid'] ) ) {
            Olama_Reg_Family_Financial_Summary::invalidate_snapshot( sanitize_text_field( $data['family_uid'] ), 0 );
        }

        return $invoice_id;
    }

    private static function resolve_payer_context(array $data): array|\WP_Error
    {
        global $wpdb;

        $agreement = null;
        $agreement_id = absint($data['linked_agreement_id'] ?? $data['agreement_id'] ?? 0);
        if ($agreement_id > 0) {
            $agreement = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . self::t('olama_agreements') . " WHERE id = %d",
                $agreement_id
            ));
        }

        $payer_type = sanitize_key($data['payer_type'] ?? ($agreement->payer_type ?? ''));
        if ($payer_type === '') {
            $payer_type = !empty($data['ext_customer_id']) ? 'customer' : 'family';
        }

        if ($payer_type === 'family') {
            $reference = sanitize_text_field(
                $data['family_uid']
                ?? ($agreement->family_uid ?? null)
                ?? ($agreement->payer_ref ?? null)
                ?? ($agreement->payer_id ?? '')
            );
            $family = Olama_Reg_Core_Gateway::family($reference);
            if (!$family) {
                return new \WP_Error('missing_family', __('A valid Olama Core family is required.', 'olama-registration'));
            }

            $student_uid = sanitize_text_field($data['student_uid'] ?? '');
            $student = $student_uid !== '' ? Olama_Reg_Core_Gateway::student($student_uid) : null;
            if ($student_uid !== '' && (!$student || $student->family_uid !== $family->family_uid)) {
                return new \WP_Error('invalid_student', __('The selected student does not belong to this family.', 'olama-registration'));
            }

            return [
                'payer_type' => 'family',
                'family_uid' => $family->family_uid,
                'oracle_family_id' => $family->oracle_family_id,
                'student_uid' => $student ? $student->student_uid : null,
                'oracle_student_id' => $student ? $student->oracle_student_id : null,
                'customer_id' => null,
                'payer_name_snapshot' => $family->display_name,
                'student_name_snapshot' => $student ? $student->display_name : null,
                'grade_name_snapshot' => $student ? $student->grade_name : null,
            ];
        }

        if ($payer_type === 'customer') {
            $reference = $data['customer_id']
                ?? $data['ext_customer_id']
                ?? ($agreement->customer_id ?? null)
                ?? ($agreement->payer_id ?? 0);
            $customer = is_numeric($reference)
                ? Olama_Reg_Customer::get((int) $reference)
                : Olama_Reg_Customer::get_by_uid(sanitize_text_field($reference));
            if (!$customer) {
                return new \WP_Error('missing_customer', __('A valid customer is required.', 'olama-registration'));
            }

            return [
                'payer_type' => 'customer',
                'family_uid' => null,
                'oracle_family_id' => null,
                'student_uid' => null,
                'oracle_student_id' => null,
                'customer_id' => (int) $customer->id,
                'payer_name_snapshot' => $customer->customer_name,
                'student_name_snapshot' => null,
                'grade_name_snapshot' => null,
            ];
        }

        return new \WP_Error('invalid_payer_type', __('Invalid payer type.', 'olama-registration'));
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /**
     * Update an existing invoice header and optionally its items.
     */
    public static function update(int $id, array $data): bool|\WP_Error
    {
        global $wpdb;

        $before = self::get_invoice($id);
        if (!$before) {
            return new \WP_Error('not_found', __('Invoice not found.', 'olama-registration'));
        }

        $has_financial_changes = self::contains_financial_changes($data);
        if ($has_financial_changes) {
            $can_update = self::can_update_financial_fields($before);
            if (is_wp_error($can_update)) {
                return $can_update;
            }
        } else {
            $can_update = self::can_update_non_financial_fields($before);
            if (is_wp_error($can_update)) {
                return $can_update;
            }
        }

        $payload = [];

        if ( isset( $data['family_uid'] ) || isset( $data['student_uid'] ) ) {
            $identity_input = array_merge( (array) $before, $data );
            $identity = self::resolve_payer_context( $identity_input );
            if ( is_wp_error( $identity ) ) {
                return $identity;
            }

            foreach ( [
                'family_uid',
                'oracle_family_id',
                'student_uid',
                'oracle_student_id',
                'payer_name_snapshot',
                'student_name_snapshot',
                'grade_name_snapshot',
            ] as $identity_key ) {
                $payload[ $identity_key ] = $identity[ $identity_key ];
            }
        }
        if (isset($data['fee_template_id']))
            $payload['fee_template_id'] = absint($data['fee_template_id']) ?: null;
        if (isset($data['ext_customer_id']))
            $payload['ext_customer_id'] = absint($data['ext_customer_id']) ?: null;
        if (isset($data['ext_child_id']))
            $payload['ext_child_id'] = absint($data['ext_child_id']) ?: null;
        if (isset($data['issue_date']))
            $payload['issue_date'] = self::sanitize_date($data['issue_date']) ?: $before->issue_date;
        if (isset($data['due_date']))
            $payload['due_date'] = self::sanitize_date($data['due_date']) ?: null;
        if (isset($data['status']))
            $payload['status'] = self::valid_status($data['status']);
        if (isset($data['discount']))
            $payload['discount'] = self::safe_decimal($data['discount']);
        if (isset($data['notes']))
            $payload['notes'] = sanitize_textarea_field($data['notes']);

        if (isset($payload['discount']) && $payload['discount'] < 0) {
            return new \WP_Error('invalid_discount', __('Invoice discount cannot be negative.', 'olama-registration'));
        }

        $prepared_update_items = null;
        if (isset($data['items']) && is_array($data['items'])) {
            $prepared_update_items = [];
            foreach ($data['items'] as $item) {
                $qty = self::safe_decimal($item['quantity'] ?? 1);
                $unit_price = self::safe_decimal($item['unit_price'] ?? 0);
                $description = sanitize_text_field($item['description'] ?? '');
                if ($description === '' || $qty <= 0 || $unit_price < 0) {
                    return new \WP_Error('invalid_invoice_item', __('Each invoice item requires a description, a positive quantity, and a non-negative price.', 'olama-registration'));
                }
                $item_student = null;
                $item_student_uid = sanitize_text_field($item['student_uid'] ?? '');
                if ($item_student_uid !== '') {
                    $item_student = Olama_Reg_Core_Gateway::student($item_student_uid);
                    $invoice_family_uid = (string) ($payload['family_uid'] ?? $before->family_uid ?? '');
                    if (!$item_student || ($invoice_family_uid !== '' && $item_student->family_uid !== $invoice_family_uid)) {
                        return new \WP_Error('invalid_student', __('The selected student does not belong to this family.', 'olama-registration'));
                    }
                }
                $prepared_update_items[] = [
                    'invoice_id' => $id,
                    'agreement_fee_id' => absint($item['agreement_fee_id'] ?? 0) ?: null,
                    'student_uid' => $item_student ? $item_student->student_uid : null,
                    'oracle_student_id' => $item_student ? $item_student->oracle_student_id : null,
                    'student_name_snapshot' => $item_student ? $item_student->display_name : null,
                    'fee_category' => sanitize_text_field($item['fee_category'] ?? '') ?: null,
                    'description' => $description,
                    'quantity' => $qty,
                    'unit_price' => $unit_price,
                    'line_total' => round($qty * $unit_price, 2),
                ];
            }
            $new_subtotal = round(array_sum(array_column($prepared_update_items, 'line_total')), 2);
            $new_discount = isset($payload['discount']) ? (float) $payload['discount'] : (float) $before->discount;
            if ($new_discount > $new_subtotal) {
                return new \WP_Error('discount_exceeds_subtotal', __('Invoice discount cannot exceed its subtotal.', 'olama-registration'));
            }
        } elseif (isset($payload['discount']) && $payload['discount'] > (float) $before->subtotal) {
            return new \WP_Error('discount_exceeds_subtotal', __('Invoice discount cannot exceed its subtotal.', 'olama-registration'));
        }

        $owns_transaction = self::begin_atomic('olama_invoice_update');
        if (!empty($payload)) {
            $result = $wpdb->update(self::t('olama_invoices'), $payload, ['id' => $id]);
            if (false === $result) {
                self::rollback_atomic($owns_transaction, 'olama_invoice_update');
                return new \WP_Error('db_error', $wpdb->last_error);
            }
        }

        // Re-insert items if provided
        if ($prepared_update_items !== null) {
            if (false === $wpdb->delete(self::t('olama_invoice_items'), ['invoice_id' => $id])) {
                self::rollback_atomic($owns_transaction, 'olama_invoice_update');
                return new \WP_Error('db_error', $wpdb->last_error);
            }
            foreach ($prepared_update_items as $item) {
                if (!$wpdb->insert(self::t('olama_invoice_items'), $item)) {
                    self::rollback_atomic($owns_transaction, 'olama_invoice_update');
                    return new \WP_Error('db_error', $wpdb->last_error);
                }
            }
            self::recalculate_totals($id);
        } elseif (isset($data['discount']) || isset($data['status'])) {
            self::recalculate_totals($id);
        }

        self::commit_atomic($owns_transaction, 'olama_invoice_update');

        self::log_audit('invoice', $id, 'updated', $before, self::get_invoice($id));

        if ( class_exists( 'Olama_Reg_Family_Financial_Summary' ) ) {
            if ( $before && ! empty( $before->family_uid ) ) {
                Olama_Reg_Family_Financial_Summary::invalidate_snapshot( $before->family_uid, 0 );
            }
            if ( ! empty( $data['family_uid'] ) && $data['family_uid'] !== $before->family_uid ) {
                Olama_Reg_Family_Financial_Summary::invalidate_snapshot( sanitize_text_field( $data['family_uid'] ), 0 );
            }
        }

        return true;
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * Get a single invoice with its line items and installments.
     */
    public static function get_invoice(int $id): ?object
    {
        global $wpdb;

        $inv = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::t('olama_invoices') . " WHERE id = %d",
            $id
        ));

        if (!$inv)
            return null;

        $inv->items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::t('olama_invoice_items') . " WHERE invoice_id = %d ORDER BY id ASC",
            $id
        )) ?: [];

        $inv->installments = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::t('olama_invoice_installments') . " WHERE invoice_id = %d ORDER BY installment_no ASC",
            $id
        )) ?: [];

        if (self::needs_payment_sync($inv)) {
            self::recalculate_totals($id);
            if (!empty($inv->installments)) {
                Olama_Reg_Billing_Payment::reallocate_all_installments($id);
            }

            $inv = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . self::t('olama_invoices') . " WHERE id = %d",
                $id
            ));

            if (!$inv) {
                return null;
            }

            $inv->items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . self::t('olama_invoice_items') . " WHERE invoice_id = %d ORDER BY id ASC",
                $id
            )) ?: [];

            $inv->installments = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . self::t('olama_invoice_installments') . " WHERE invoice_id = %d ORDER BY installment_no ASC",
                $id
            )) ?: [];
        }

        $adjustment_totals = self::get_adjustment_totals($id);
        $inv->debit_notes_total = $adjustment_totals['debit'];
        $inv->credit_notes_total = $adjustment_totals['credit'];
        $inv->effective_total = self::effective_total($inv);

        if (empty($inv->installments) && !empty($inv->agreement_id)) {
            $wpdb->query($wpdb->prepare(
                "UPDATE " . self::t('olama_invoice_installments') . "
                 SET invoice_id = %d
                 WHERE agreement_id = %d
                   AND (invoice_id = 0 OR invoice_id IS NULL)",
                $id,
                (int) $inv->agreement_id
            ));

            $inv->installments = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . self::t('olama_invoice_installments') . "
                 WHERE invoice_id = %d
                    OR (agreement_id = %d AND (invoice_id = 0 OR invoice_id IS NULL))
                 ORDER BY installment_no ASC",
                $id,
                (int) $inv->agreement_id
            )) ?: [];
        }

        $inv->is_overdue = self::is_overdue($inv);

        return $inv;
    }

    /**
     * Get all invoices for a family/year.
     */
    public static function get_family_invoices(string $family_uid, int $year_id): array
    {
        global $wpdb;

        $params = [$family_uid];
        $year_clause = '';
        if ($year_id > 0) {
            $year_clause = ' AND academic_year_id = %d';
            $params[] = $year_id;
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::t('olama_invoices') . "
             WHERE family_uid = %s {$year_clause}
             ORDER BY issue_date DESC, id DESC",
            ...$params
        )) ?: [];
    }

    /**
     * Get all invoices for an external customer/year.
     */
    public static function get_customer_invoices(int $customer_id, int $year_id): array
    {
        global $wpdb;

        $customer_uid = $wpdb->get_var($wpdb->prepare(
            "SELECT customer_uid FROM {$wpdb->prefix}olama_customers WHERE id = %d",
            $customer_id
        ));
        if (!$customer_uid) {
            $customer_uid = 'CUST-' . str_pad($customer_id, 4, '0', STR_PAD_LEFT);
        }

        $params = [$customer_id, $customer_uid];
        $year_clause = '';
        if ($year_id > 0) {
            $year_clause = ' AND academic_year_id = %d';
            $params[] = $year_id;
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::t('olama_invoices') . "
             WHERE ( ext_customer_id = %d OR ( family_uid = %s AND family_uid != '' ) ) {$year_clause}
             ORDER BY issue_date DESC, id DESC",
            ...$params
        )) ?: [];
    }

    /**
     * Lightweight summary for the external customer financial tab.
     */
    public static function get_customer_invoice_summary(int $customer_id, int $year_id): object
    {
        global $wpdb;

        $customer_uid = $wpdb->get_var($wpdb->prepare(
            "SELECT customer_uid FROM {$wpdb->prefix}olama_customers WHERE id = %d",
            $customer_id
        ));
        if (!$customer_uid) {
            $customer_uid = 'CUST-' . str_pad($customer_id, 4, '0', STR_PAD_LEFT);
        }

        $params = [$customer_id, $customer_uid];
        $year_clause = '';
        if ($year_id > 0) {
            $year_clause = ' AND i.academic_year_id = %d';
            $params[] = $year_id;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) AS invoice_count,
                COALESCE(SUM(total + COALESCE(adj.debit_total, 0) - COALESCE(adj.credit_total, 0)), 0) AS total_invoiced,
                COALESCE(SUM(amount_paid), 0) AS total_paid,
                COALESCE(SUM(balance), 0)     AS balance
             FROM " . self::t('olama_invoices') . " i
             LEFT JOIN (
                SELECT invoice_id,
                       SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) AS debit_total,
                       SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) AS credit_total
                FROM " . self::t('olama_invoice_adjustments') . "
                WHERE status = 'issued'
                GROUP BY invoice_id
             ) adj ON adj.invoice_id = i.id
             WHERE ( i.ext_customer_id = %d OR ( i.family_uid = %s AND i.family_uid != '' ) ) {$year_clause}
               AND i.status NOT IN ('draft','cancelled')",
            ...$params
        ));

        return $row ?: (object) [
            'invoice_count' => 0,
            'total_invoiced' => 0,
            'total_paid' => 0,
            'balance' => 0,
        ];
    }

    /**
     * Get all overdue invoices (due_date < today, balance > 0, not cancelled).
     */
    public static function get_overdue_invoices(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM " . self::t('olama_invoices') . "
             WHERE due_date < CURDATE()
               AND balance > 0
               AND status NOT IN ('paid','cancelled')
             ORDER BY due_date ASC"
        ) ?: [];
    }

    // ── Status ────────────────────────────────────────────────────────────────

    /**
     * Manually set invoice status (with audit trail).
     */
    public static function set_status(int $id, string $status): bool
    {
        global $wpdb;

        $before = self::get_invoice($id);
        if (!$before)
            return false;

        $status = self::valid_status($status);
        if ($status !== (string) $before->status) {
            $can_update = self::can_update_financial_fields($before);
            if (is_wp_error($can_update)) {
                return false;
            }
        }

        $result = $wpdb->update(
            self::t('olama_invoices'),
            ['status' => $status],
            ['id' => $id]
        );

        if (false !== $result) {
            self::log_audit('invoice', $id, 'status_changed', $before, self::get_invoice($id));
            if ( class_exists( 'Olama_Reg_Family_Financial_Summary' ) && $before && ! empty( $before->family_uid ) ) {
                Olama_Reg_Family_Financial_Summary::invalidate_snapshot( $before->family_uid, 0 );
            }
        }

        return false !== $result;
    }

    /**
     * Cancel (Void) an invoice. Blocked if payments exist.
     */
    public static function cancel(int $id): bool|\WP_Error
    {
        global $wpdb;

        $inv = self::get_invoice($id);
        if (!$inv) {
            return new \WP_Error('not_found', __('Invoice not found.', 'olama-registration'));
        }

        if (abs((float) $inv->amount_paid) > 0.009 || self::has_pending_payment_records($id)) {
            return new \WP_Error('has_payments', __('لا يمكن إلغاء الفاتورة لأنها تحتوي على سندات قبض. يجب عكس السندات أولاً.', 'olama-registration'));
        }

        if (self::has_active_adjustments($id)) {
            return new \WP_Error('has_adjustments', __('لا يمكن إلغاء الفاتورة لأنها تحتوي على إشعارات مالية نشطة. يجب إلغاء الإشعارات أولاً.', 'olama-registration'));
        }

        if ($inv->status === 'cancelled') {
            return true;
        }

        $cancel_payload = ['status' => 'cancelled'];
        $columns = $wpdb->get_col("DESCRIBE " . self::t('olama_invoices'), 0);
        if (in_array('cancelled_by', (array) $columns, true)) {
            $cancel_payload['cancelled_by'] = get_current_user_id();
        }
        if (in_array('cancelled_at', (array) $columns, true)) {
            $cancel_payload['cancelled_at'] = current_time('mysql');
        }

        $wpdb->update(self::t('olama_invoices'), $cancel_payload, ['id' => $id]);
        $wpdb->update(self::t('olama_invoice_installments'), ['status' => 'cancelled'], ['invoice_id' => $id]);

        self::log_audit('invoice', $id, 'cancelled', $inv, self::get_invoice($id));

        if ( class_exists( 'Olama_Reg_Family_Financial_Summary' ) && $inv && ! empty( $inv->family_uid ) ) {
            Olama_Reg_Family_Financial_Summary::invalidate_snapshot( $inv->family_uid, 0 );
        }

        return true;
    }

    // ── Totals recalculation ──────────────────────────────────────────────────

    /**
     * Recalculate subtotal, total, and auto-update status.
     * Called after any change to items or payments.
     */
    public static function recalculate_totals(int $id): void
    {
        global $wpdb;

        $inv = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::t('olama_invoices') . " WHERE id = %d",
            $id
        ));

        if (!$inv)
            return;

        // Sum line items
        $subtotal = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(line_total), 0) FROM " . self::t('olama_invoice_items') . " WHERE invoice_id = %d",
            $id
        ));

        $discount = (float) $inv->discount;
        $total = max(0.0, $subtotal - $discount);
        $adjustment_totals = self::get_adjustment_totals($id);
        $effective_total = max(0.0, $total + $adjustment_totals['debit'] - $adjustment_totals['credit']);

        // Sum payments
        $amount_paid = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM " . self::t('olama_payments') . "
             WHERE invoice_id = %d
               AND (status IS NULL OR status = '' OR status IN ('posted','reversed'))",
            $id
        ));

        $balance = $effective_total - $amount_paid;

        // Auto status
        $status = $inv->status;
        if ($status !== 'cancelled' && $status !== 'draft') {
            if ($balance <= 0) {
                $status = 'paid';
            } elseif ($amount_paid > 0) {
                $status = 'partial';
            } elseif (!empty($inv->due_date) && $inv->due_date < current_time('Y-m-d')) {
                $status = 'overdue';
            } else {
                $status = 'issued';
            }
        }

        $wpdb->update(
            self::t('olama_invoices'),
            [
                'subtotal' => round($subtotal, 2),
                'total' => round($total, 2),
                'amount_paid' => round($amount_paid, 2),
                'balance' => round($balance, 2),
                'status' => $status,
            ],
            ['id' => $id]
        );
    }

    // ── Installments ──────────────────────────────────────────────────────────

    /**
     * Generate installment schedule for an invoice.
     * Evenly divides total; remainder cents go to first installment.
     * Due dates are spaced 30 days apart from issue_date.
     */
    public static function generate_installments(int $id, int $count = 0): bool
    {
        global $wpdb;

        $inv = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::t('olama_invoices') . " WHERE id = %d",
            $id
        ));

        if (!$inv)
            return false;

        $has_payment_history = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::t('olama_payments') . " WHERE invoice_id = %d",
            $id
        )) > 0;
        if ($has_payment_history) {
            return false;
        }

        if ($count < 1) {
            // Read from fee_template if set
            $count = 1;
            if ($inv->fee_template_id) {
                $tpl = $wpdb->get_row($wpdb->prepare(
                    "SELECT installments FROM " . self::t('olama_fee_templates') . " WHERE id = %d",
                    $inv->fee_template_id
                ));
                if ($tpl)
                    $count = max(1, (int) $tpl->installments);
            }
        }

        // Remove existing installments
        $wpdb->delete(self::t('olama_invoice_installments'), ['invoice_id' => $id]);

        if ($count <= 1)
            return true;

        $total = (float) $inv->total;
        $base = floor(($total / $count) * 100) / 100; // truncate to cents
        $remainder = round($total - ($base * $count), 2);
        $issue_date = new \DateTime($inv->issue_date ?: date('Y-m-d'));

        for ($i = 1; $i <= $count; $i++) {
            $due = clone $issue_date;
            $due->modify('+' . (($i) * 30) . ' days');

            $amount = $base;
            if ($i === 1) {
                $amount = round($base + $remainder, 2);
            }

            $wpdb->insert(self::t('olama_invoice_installments'), [
                'invoice_id' => $id,
                'installment_no' => $i,
                'due_date' => $due->format('Y-m-d'),
                'amount_due' => $amount,
                'amount_paid' => 0.00,
                'status' => 'pending',
            ]);
        }

        return true;
    }

    public static function can_view(object $invoice): true|\WP_Error
    {
        return true;
    }

    public static function can_print(object $invoice): true|\WP_Error
    {
        return true;
    }

    public static function can_record_payment(object $invoice): true|\WP_Error
    {
        $status = (string) ($invoice->status ?? '');
        if ($status === 'cancelled') {
            return new \WP_Error('invoice_cancelled', __('لا يمكن تسجيل دفعة على فاتورة ملغاة.', 'olama-registration'));
        }
        if ($status === 'paid' || (float) ($invoice->balance ?? 0) <= 0) {
            return new \WP_Error('invoice_paid', __('لا يمكن تسجيل دفعة على فاتورة مدفوعة بالكامل.', 'olama-registration'));
        }
        if (!in_array($status, ['issued', 'partial', 'overdue'], true)) {
            return new \WP_Error('invoice_not_payable', __('لا يمكن تسجيل دفعة على هذه الفاتورة بحالتها الحالية.', 'olama-registration'));
        }
        return true;
    }

    public static function can_update_financial_fields(object $invoice): true|\WP_Error
    {
        if ((string) ($invoice->status ?? '') === 'cancelled') {
            return new \WP_Error('invoice_cancelled', __('لا يمكن تعديل فاتورة ملغاة.', 'olama-registration'));
        }
        if ((float) ($invoice->amount_paid ?? 0) > 0 || self::has_payment_records((int) ($invoice->id ?? 0))) {
            return new \WP_Error('financial_locked', __(self::FINANCIAL_UPDATE_ERROR, 'olama-registration'));
        }
        if (!in_array((string) ($invoice->status ?? ''), ['draft', 'issued'], true)) {
            return new \WP_Error('financial_locked_status', __('لا يمكن تعديل البيانات المالية إلا لفاتورة مسودة أو صادرة وغير مدفوعة.', 'olama-registration'));
        }
        return true;
    }

    public static function can_update_non_financial_fields(object $invoice): true|\WP_Error
    {
        if ((string) ($invoice->status ?? '') === 'cancelled') {
            return new \WP_Error('invoice_cancelled', __('لا يمكن تعديل فاتورة ملغاة.', 'olama-registration'));
        }
        return true;
    }

    public static function can_cancel(object $invoice): true|\WP_Error
    {
        if ((string) ($invoice->status ?? '') === 'cancelled') {
            return new \WP_Error('already_cancelled', __('الفاتورة ملغاة مسبقاً.', 'olama-registration'));
        }
        if (abs((float) ($invoice->amount_paid ?? 0)) > 0.009 || self::has_pending_payment_records((int) ($invoice->id ?? 0))) {
            return new \WP_Error('has_payments', __('لا يمكن إلغاء الفاتورة لأنها تحتوي على سندات قبض. يجب عكس السندات أولاً.', 'olama-registration'));
        }
        if (self::has_active_adjustments((int) $invoice->id)) {
            return new \WP_Error('has_adjustments', __('لا يمكن إلغاء الفاتورة لأنها تحتوي على إشعارات مالية نشطة. يجب إلغاء الإشعارات أولاً.', 'olama-registration'));
        }
        return true;
    }

    public static function can_create_credit_note(object $invoice): true|\WP_Error
    {
        return self::can_create_adjustment($invoice);
    }

    public static function can_create_debit_note(object $invoice): true|\WP_Error
    {
        return self::can_create_adjustment($invoice);
    }

    private static function can_create_adjustment(object $invoice): true|\WP_Error
    {
        if ((string) ($invoice->status ?? '') === 'cancelled') {
            return new \WP_Error('invoice_cancelled', __('لا يمكن إصدار إشعار مالي على فاتورة ملغاة.', 'olama-registration'));
        }
        if (!in_array((string) ($invoice->status ?? ''), ['issued', 'partial', 'paid', 'overdue'], true)) {
            return new \WP_Error('invalid_status', __('لا يمكن إصدار إشعار مالي على هذه الفاتورة بحالتها الحالية.', 'olama-registration'));
        }
        return true;
    }

    public static function get_adjustments(int $invoice_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, u.display_name AS created_by_name
             FROM " . self::t('olama_invoice_adjustments') . " a
             LEFT JOIN {$wpdb->users} u ON u.ID = a.created_by
             WHERE a.invoice_id = %d
             ORDER BY a.created_at ASC, a.id ASC",
            $invoice_id
        )) ?: [];
    }

    public static function create_adjustment(int $invoice_id, string $type, float $amount, string $reason, string $notes = ''): int|\WP_Error
    {
        global $wpdb;

        $invoice = self::get_invoice($invoice_id);
        if (!$invoice) {
            return new \WP_Error('not_found', __('Invoice not found.', 'olama-registration'));
        }

        $type = sanitize_key($type);
        if (!in_array($type, ['credit', 'debit'], true)) {
            return new \WP_Error('invalid_type', __('نوع الإشعار المالي غير صحيح.', 'olama-registration'));
        }

        $policy = $type === 'credit' ? self::can_create_credit_note($invoice) : self::can_create_debit_note($invoice);
        if (is_wp_error($policy)) {
            return $policy;
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            return new \WP_Error('invalid_amount', __('يجب أن تكون قيمة الإشعار أكبر من صفر.', 'olama-registration'));
        }

        $reason = sanitize_text_field($reason);
        if ($reason === '') {
            return new \WP_Error('missing_reason', __('يجب إدخال سبب الإشعار المالي.', 'olama-registration'));
        }

        if ($type === 'credit' && $amount > (float) $invoice->balance) {
            return new \WP_Error('credit_exceeds_balance', __('لا يمكن أن يكون الإشعار الدائن أكبر من الرصيد المتبقي دون وجود آلية رد مبالغ.', 'olama-registration'));
        }

        $before = self::get_invoice($invoice_id);
        $result = $wpdb->insert(self::t('olama_invoice_adjustments'), [
            'adjustment_no' => self::generate_adjustment_number($type),
            'invoice_id' => $invoice_id,
            'type' => $type,
            'amount' => $amount,
            'reason' => $reason,
            'notes' => sanitize_textarea_field($notes) ?: null,
            'status' => 'issued',
            'created_by' => get_current_user_id(),
        ]);

        if (!$result) {
            return new \WP_Error('db_error', $wpdb->last_error);
        }

        $adjustment_id = (int) $wpdb->insert_id;
        self::recalculate_totals($invoice_id);
        self::log_audit('invoice_adjustment', $adjustment_id, $type . '_note_issued', $before, self::get_invoice($invoice_id));

        if ( class_exists( 'Olama_Reg_Family_Financial_Summary' ) && $invoice && ! empty( $invoice->family_uid ) ) {
            Olama_Reg_Family_Financial_Summary::invalidate_snapshot( $invoice->family_uid, 0 );
        }

        return $adjustment_id;
    }

    public static function cancel_adjustment(int $adjustment_id): bool|\WP_Error
    {
        global $wpdb;

        $adjustment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::t('olama_invoice_adjustments') . " WHERE id = %d",
            $adjustment_id
        ));
        if (!$adjustment) {
            return new \WP_Error('not_found', __('الإشعار المالي غير موجود.', 'olama-registration'));
        }
        if ($adjustment->status === 'cancelled') {
            return true;
        }

        $before = self::get_invoice((int) $adjustment->invoice_id);
        if (!$before) {
            return new \WP_Error('invoice_not_found', __('Invoice not found.', 'olama-registration'));
        }

        if ($adjustment->type === 'debit') {
            $effective_total = round((float) $before->amount_paid + (float) $before->balance, 2);
            $new_effective_total = round($effective_total - (float) $adjustment->amount, 2);
            if ((float) $before->amount_paid > $new_effective_total + 0.009) {
                return new \WP_Error(
                    'adjustment_cancellation_creates_overpayment',
                    __('This debit note cannot be cancelled because the posted receipts would exceed the resulting invoice total. Reverse or refund the excess first.', 'olama-registration')
                );
            }
        }

        $updated = $wpdb->update(self::t('olama_invoice_adjustments'), [
            'status' => 'cancelled',
            'cancelled_by' => get_current_user_id(),
            'cancelled_at' => current_time('mysql'),
        ], ['id' => $adjustment_id]);
        if ($updated === false) {
            return new \WP_Error('db_error', $wpdb->last_error);
        }

        self::recalculate_totals((int) $adjustment->invoice_id);
        self::log_audit('invoice_adjustment', $adjustment_id, 'adjustment_cancelled', $before, self::get_invoice((int) $adjustment->invoice_id));

        if ( class_exists( 'Olama_Reg_Family_Financial_Summary' ) && $before && ! empty( $before->family_uid ) ) {
            Olama_Reg_Family_Financial_Summary::invalidate_snapshot( $before->family_uid, 0 );
        }

        return true;
    }

    public static function get_activity(int $invoice_id): array
    {
        global $wpdb;

        $events = [];
        $audit_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, u.display_name AS actor_name
             FROM " . self::t('olama_billing_audit') . " a
             LEFT JOIN {$wpdb->users} u ON u.ID = a.actor_id
             WHERE (a.entity_type = 'invoice' AND a.entity_id = %d)
                OR (a.entity_type = 'payment' AND a.entity_id IN (SELECT id FROM " . self::t('olama_payments') . " WHERE invoice_id = %d))
             ORDER BY a.created_at ASC, a.id ASC",
            $invoice_id,
            $invoice_id
        )) ?: [];

        foreach ($audit_rows as $row) {
            $events[] = [
                'date' => $row->created_at,
                'type' => self::activity_label((string) $row->action),
                'reference' => '#' . $row->entity_id,
                'user' => $row->actor_name ?: '',
                'amount' => '',
                'description' => $row->action,
            ];
        }

        $payments = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, u.display_name AS user_name
             FROM " . self::t('olama_payments') . " p
             LEFT JOIN {$wpdb->users} u ON u.ID = p.received_by
             WHERE p.invoice_id = %d",
            $invoice_id
        )) ?: [];
        foreach ($payments as $p) {
            $events[] = [
                'date' => $p->created_at ?: $p->payment_date,
                'type' => ((float) $p->amount < 0 || $p->method === 'reversal') ? 'عكس سند قبض' : 'إصدار سند قبض',
                'reference' => '#' . $p->id,
                'user' => $p->user_name ?: '',
                'amount' => (float) $p->amount,
                'description' => $p->notes ?: '',
            ];
        }

        foreach (self::get_adjustments($invoice_id) as $adj) {
            $events[] = [
                'date' => $adj->created_at,
                'type' => $adj->type === 'credit' ? 'إصدار إشعار دائن' : 'إصدار إشعار مدين',
                'reference' => $adj->adjustment_no,
                'user' => $adj->created_by_name ?: '',
                'amount' => (float) $adj->amount,
                'description' => $adj->reason,
            ];
        }

        usort($events, static fn($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
        return $events;
    }

    // ── Invoice summary for financial tab ─────────────────────────────────────

    /**
     * Lightweight summary for the family financial tab overlay.
     */
    public static function get_invoice_summary(string $family_uid, int $year_id): object
    {
        global $wpdb;

        $params = [$family_uid];
        $year_clause = '';
        if ($year_id > 0) {
            $year_clause = ' AND i.academic_year_id = %d';
            $params[] = $year_id;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) AS invoice_count,
                COALESCE(SUM(total + COALESCE(adj.debit_total, 0) - COALESCE(adj.credit_total, 0)), 0) AS total_invoiced,
                COALESCE(SUM(amount_paid), 0) AS total_paid,
                COALESCE(SUM(balance), 0)     AS balance
             FROM " . self::t('olama_invoices') . " i
             LEFT JOIN (
                SELECT invoice_id,
                       SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) AS debit_total,
                       SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) AS credit_total
                FROM " . self::t('olama_invoice_adjustments') . "
                WHERE status = 'issued'
                GROUP BY invoice_id
             ) adj ON adj.invoice_id = i.id
             WHERE i.family_uid = %s {$year_clause}
               AND i.status NOT IN ('draft','cancelled')",
            ...$params
        ));

        return $row ?: (object) [
            'invoice_count' => 0,
            'total_invoiced' => 0,
            'total_paid' => 0,
            'balance' => 0,
        ];
    }

    // ── Audit ─────────────────────────────────────────────────────────────────

    private static function log_audit(
        string $entity_type,
        int $entity_id,
        string $action,
        ?object $before,
        ?object $after
    ): void {
        global $wpdb;

        $wpdb->insert(self::t('olama_billing_audit'), [
            'entity_type' => sanitize_text_field($entity_type),
            'entity_id' => $entity_id,
            'action' => sanitize_text_field($action),
            'actor_id' => get_current_user_id(),
            'before_state' => $before ? wp_json_encode($before) : null,
            'after_state' => $after ? wp_json_encode($after) : null,
            'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private static function valid_status(string $s): string
    {
        $allowed = ['draft', 'issued', 'partial', 'paid', 'overdue', 'cancelled'];
        return in_array($s, $allowed, true) ? $s : 'draft';
    }

    private static function contains_financial_changes(array $data): bool
    {
        foreach (self::FINANCIAL_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                return true;
            }
        }
        return false;
    }

    private static function get_adjustment_totals(int $invoice_id): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) AS debit_total,
                COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) AS credit_total
             FROM " . self::t('olama_invoice_adjustments') . "
             WHERE invoice_id = %d AND status = 'issued'",
            $invoice_id
        ));

        return [
            'debit' => (float) ($row->debit_total ?? 0),
            'credit' => (float) ($row->credit_total ?? 0),
        ];
    }

    private static function has_active_adjustments(int $invoice_id): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::t('olama_invoice_adjustments') . " WHERE invoice_id = %d AND status = 'issued'",
            $invoice_id
        )) > 0;
    }

    private static function has_payment_records(int $invoice_id): bool
    {
        global $wpdb;
        if ($invoice_id <= 0) {
            return false;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM " . self::t('olama_payments') . "
             WHERE invoice_id = %d
               AND COALESCE(status, 'posted') NOT IN ('cancelled','failed')",
            $invoice_id
        )) > 0;
    }

    /** Pending receipts must be resolved before cancellation; fully reversed
     * posted history may remain attached as an immutable audit trail. */
    private static function has_pending_payment_records(int $invoice_id): bool
    {
        global $wpdb;
        if ($invoice_id <= 0) {
            return false;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM " . self::t('olama_payments') . "
             WHERE invoice_id = %d
               AND status IN ('draft', 'pending_review')",
            $invoice_id
        )) > 0;
    }

    private static function effective_total(object $invoice): float
    {
        $debit = (float) ($invoice->debit_notes_total ?? 0);
        $credit = (float) ($invoice->credit_notes_total ?? 0);
        return round(max(0.0, (float) ($invoice->total ?? 0) + $debit - $credit), 2);
    }

    private static function needs_payment_sync(object $invoice): bool
    {
        global $wpdb;

        $invoice_id = (int) ($invoice->id ?? 0);
        if ($invoice_id <= 0) {
            return false;
        }

        $posted_total = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM " . self::t('olama_payments') . "
             WHERE invoice_id = %d
               AND (status IS NULL OR status = '' OR status IN ('posted','reversed'))",
            $invoice_id
        ));

        if (abs(round((float) ($invoice->amount_paid ?? 0), 2) - round($posted_total, 2)) > 0.009) {
            return true;
        }

        $installment_paid = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount_paid), 0)
             FROM " . self::t('olama_invoice_installments') . "
             WHERE invoice_id = %d",
            $invoice_id
        ));

        return abs(round($installment_paid, 2) - round(max(0.0, $posted_total), 2)) > 0.009;
    }

    private static function is_overdue(object $invoice): bool
    {
        if (in_array((string) ($invoice->status ?? ''), ['paid', 'cancelled'], true)) {
            return false;
        }

        foreach ((array) ($invoice->installments ?? []) as $inst) {
            $remaining = (float) ($inst->amount_due ?? 0) - (float) ($inst->amount_paid ?? 0);
            if (!empty($inst->due_date) && $inst->due_date < current_time('Y-m-d') && $remaining > 0.009) {
                return true;
            }
        }

        return !empty($invoice->due_date) && $invoice->due_date < current_time('Y-m-d') && (float) ($invoice->balance ?? 0) > 0.009;
    }

    private static function generate_adjustment_number(string $type): string
    {
        global $wpdb;
        $prefix = ($type === 'credit' ? 'CRN-' : 'DBN-') . current_time('Y') . '-';
        $max = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(adjustment_no, %d) AS UNSIGNED)), 0)
             FROM " . self::t('olama_invoice_adjustments') . "
             WHERE adjustment_no LIKE %s",
            strlen($prefix) + 1,
            $wpdb->esc_like($prefix) . '%'
        ));

        return $prefix . str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private static function activity_label(string $action): string
    {
        return [
            'created' => 'إنشاء الفاتورة',
            'updated' => 'تعديل بيانات الفاتورة',
            'status_changed' => 'تغيير حالة الفاتورة',
            'cancelled' => 'إلغاء الفاتورة',
            'payment_created' => 'إنشاء سند قبض',
            'payment_confirmed' => 'اعتماد سند قبض',
            'payment_rejected' => 'رفض سند قبض',
            'reversed' => 'عكس سند قبض',
            'payment_reversed' => 'عكس سند قبض',
            'cash_session_opened' => 'فتح جلسة صندوق',
            'cash_session_closed' => 'إغلاق جلسة صندوق',
            'cash_session_reviewed' => 'اعتماد جرد صندوق',
            'cash_session_rejected' => 'رفض جرد صندوق',
            'financial_account_saved' => 'حفظ حساب مالي',
            'financial_account_activated' => 'تفعيل حساب مالي',
            'financial_account_deactivated' => 'تعطيل حساب مالي',
            'cheque_deposited' => 'إيداع شيك',
            'cheque_cleared' => 'تحصيل شيك',
            'cheque_bounced' => 'شيك راجع',
            'cheque_cancelled' => 'إلغاء شيك',
            'receipt_repair_run' => 'تشغيل إصلاح السندات',
        ][$action] ?? $action;
    }

    private static function sanitize_date(string $val): string
    {
        if (class_exists('Olama_School_Helpers') && method_exists('Olama_School_Helpers', 'sanitize_date')) {
            return (string) Olama_School_Helpers::sanitize_date($val);
        }
        $raw = sanitize_text_field($val);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
    }

    private static function safe_decimal($val): float
    {
        return round((float) $val, 2);
    }

    /**
     * Start an atomic section without committing a transaction owned by a
     * higher-level workflow (for example agreement completion).
     */
    private static function begin_atomic(string $savepoint): bool
    {
        global $wpdb;
        $in_transaction = (int) $wpdb->get_var('SELECT @@in_transaction');
        if ($in_transaction) {
            $wpdb->query('SAVEPOINT ' . $savepoint);
            return false;
        }
        $wpdb->query('START TRANSACTION');
        return true;
    }

    private static function commit_atomic(bool $owns_transaction, string $savepoint): void
    {
        global $wpdb;
        $wpdb->query($owns_transaction ? 'COMMIT' : 'RELEASE SAVEPOINT ' . $savepoint);
    }

    private static function rollback_atomic(bool $owns_transaction, string $savepoint): void
    {
        global $wpdb;
        $wpdb->query($owns_transaction ? 'ROLLBACK' : 'ROLLBACK TO SAVEPOINT ' . $savepoint);
    }
}
