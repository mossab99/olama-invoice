<?php
/**
 * Olama Registration Agreement Renderer.
 *
 * @package Olama_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Olama_Reg_Agreement_Renderer {

    /**
     * Render the list of agreements.
     *
     * @param string $payer_id
     * @param int    $academic_year_id
     * @param string $context
     * @return string HTML output
     */
    public static function render_agreements_list(
        string $payer_id = '',
        int $academic_year_id = 0,
        string $context = 'admin'
    ): string {
        global $wpdb;
        $args = [];

        if ( ! empty( $payer_id ) ) {
            // Families are owned by Olama Core. Query agreements with the
            // canonical UID even when Hub supplies an Oracle file number.
            $family = Olama_Reg_Core_Gateway::family( $payer_id );
            if ( $family ) {
                $args['payer_type'] = 'family';
                $args['payer_id']   = (string) $family->family_uid;
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

        if ( $academic_year_id > 0 ) {
            $args['academic_year_id'] = $academic_year_id;
        }

        if ( $context === 'admin' ) {
            if ( ! empty( $_REQUEST['status'] ) ) {
                $args['status'] = sanitize_text_field( $_REQUEST['status'] );
            }
            if ( ! empty( $_REQUEST['activity_type'] ) ) {
                $args['activity_type'] = sanitize_text_field( $_REQUEST['activity_type'] );
            }
        }

        $agreements = class_exists( 'Olama_Reg_Agreement' ) ? Olama_Reg_Agreement::get_list( $args ) : [];

        if ( empty( $agreements ) ) {
            if ( $context === 'customer_hub' ) {
                return self::render_empty_state(
                    __( 'لا توجد عقود مسجلة', 'olama-registration' ),
                    'dashicons-media-document'
                );
            }
            // Let the partial render its own table empty state
        }

        foreach ( $agreements as $agr ) {
            $agr->invoices = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, invoice_number, status, balance FROM {$wpdb->prefix}olama_invoices WHERE agreement_id = %d AND status != 'cancelled'",
                $agr->id
            ) ) ?: [];

            $agr->collected_amount = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT SUM(amount_paid) FROM {$wpdb->prefix}olama_invoices WHERE agreement_id = %d AND status != 'cancelled'",
                $agr->id
            ) );
            $agr->remaining_amount = max( 0.0, (float) $agr->total_amount - $agr->collected_amount );
        }

        ob_start();
        $is_hub = ( $context === 'customer_hub' );
        include OLAMA_REG_PATH . 'admin/views/partial-agreements-table.php';
        return ob_get_clean();
    }

    /**
     * Render the agreement summary workspace card.
     *
     * @param int    $agreement_id
     * @param string $context
     * @return string HTML output
     */
    public static function render_agreement_card(
        int $agreement_id,
        string $context = 'admin'
    ): string {
        $agreement = class_exists( 'Olama_Reg_Agreement' ) ? Olama_Reg_Agreement::get( $agreement_id ) : null;
        if ( ! $agreement ) {
            return '';
        }

        global $wpdb;
        $id = $agreement_id;
        $is_new = false;
        
        $financial_status = 'open';
        $can_edit_financial_fields = true;
        $can_reschedule_installments = true;
        $can_create_amendment = false;
        $has_financial_impact = false;

        if ( class_exists( 'Olama_Reg_Agreement_Policy' ) ) {
            $financial_status = Olama_Reg_Agreement_Policy::get_financial_status( $id );
            $financial_edit = Olama_Reg_Agreement_Policy::can_edit_financial_fields( $id );
            $schedule_edit = Olama_Reg_Agreement_Policy::can_reschedule_installments( $id );
            $amendment_create = Olama_Reg_Agreement_Policy::can_create_amendment( $id );
            
            $can_edit_financial_fields = ! is_wp_error( $financial_edit );
            $can_reschedule_installments = ! is_wp_error( $schedule_edit );
            $can_create_amendment = ! is_wp_error( $amendment_create );
            $has_financial_impact = ! $can_edit_financial_fields;
        } else {
            $has_financial_impact = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}olama_invoices WHERE agreement_id = %d AND status != 'cancelled'",
                $id
            ) ) > 0;
            $can_edit_financial_fields = ! $has_financial_impact;
            $can_reschedule_installments = ! $has_financial_impact;
        }

        $invoice_id = class_exists( 'Olama_Reg_Agreement_Policy' ) ? Olama_Reg_Agreement_Policy::get_linked_invoice_id( $id ) : 0;
        $invoice = null;
        if ( $invoice_id > 0 && class_exists( 'Olama_Reg_Billing_Invoice' ) ) {
            $invoice = Olama_Reg_Billing_Invoice::get_invoice( $invoice_id );
        }

        $due_schedule = class_exists( 'Olama_Reg_Agreement_Invoice' ) ? Olama_Reg_Agreement_Invoice::get_due_schedule( $id ) : [];
        $due_total = 0.0;
        foreach ( $due_schedule as $line ) {
            $due_total += (float) $line->amount_due;
        }
        $due_schedule_saved = ! empty( $due_schedule ) && abs( round( (float) $agreement->total_amount, 2 ) - round( $due_total, 2 ) ) <= 0.009;

        $invoiced_total = 0.0;
        $paid_total = 0.0;
        $remaining_total = 0.0;

        if ( $invoice ) {
            $invoiced_total = (float) ( $invoice->total ?? $invoice->amount_due ?? $agreement->total_amount );
            $paid_total = (float) ( $invoice->amount_paid ?? 0 );
            $remaining_total = (float) ( $invoice->balance ?? max( 0.0, $invoiced_total - $paid_total ) );
        } else {
            $remaining_total = (float) $agreement->total_amount;
        }

        $can_complete_from_workspace = $due_schedule_saved && ! in_array( $agreement->status, [ 'completed', 'cancelled' ], true );
        $can_pay_from_workspace = $due_schedule_saved && $invoice_id > 0 && $invoice && (float) $invoice->balance > 0;

        $status_label = Olama_Reg_Status_Labels::label( $agreement->status, 'agreement' );
        $badge_class = 'olama-contract-pill--' . Olama_Reg_Status_Labels::badge_class( $agreement->status, 'agreement' );

        $money = function( $amount ) {
            return number_format( (float) $amount, 3 ) . ' JD';
        };

        ob_start();
        ?>
        <div class="olama-contract-workspace" data-context="<?php echo esc_attr( $context ); ?>">
            <div class="olama-contract-hero">
                <div class="olama-contract-hero__main">
                    <div class="olama-contract-kicker"><?php esc_html_e( 'مساحة عمل العقد', 'olama-registration' ); ?></div>
                    <h1><?php echo esc_html( $agreement->agreement_number ); ?> <span><?php esc_html_e( 'العقد', 'olama-registration' ); ?></span></h1>
                    <div class="olama-contract-meta">
                        <span><?php esc_html_e( 'الطالب / المشترك:', 'olama-registration' ); ?> <strong><?php echo esc_html( $agreement->participant_name ?: '-' ); ?></strong></span>
                        <span><?php esc_html_e( 'ولي الأمر / الجهة الدافعة:', 'olama-registration' ); ?> <strong><?php echo esc_html( $agreement->payer_name ?: '-' ); ?></strong></span>
                        <span><?php esc_html_e( 'الفترة:', 'olama-registration' ); ?> <strong><?php echo esc_html( $agreement->start_date ?: '-' ); ?> - <?php echo esc_html( $agreement->end_date ?: '-' ); ?></strong></span>
                    </div>
                </div>
                <div class="olama-contract-hero__badges">
                    <span class="olama-contract-pill <?php echo esc_attr( $badge_class ); ?>" id="os-agr-status-badge"><?php echo esc_html( $status_label ); ?></span>
                    <span class="olama-contract-pill <?php echo $has_financial_impact ? 'olama-contract-pill--locked' : 'olama-contract-pill--open'; ?>">
                        <?php echo $has_financial_impact ? esc_html__( 'مقفل مالياً', 'olama-registration' ) : esc_html__( 'غير مقفل مالياً', 'olama-registration' ); ?>
                    </span>
                </div>
            </div>
            <div class="olama-financial-summary">
                <div class="olama-summary-card olama-summary-card--orange"><span><?php esc_html_e( 'إجمالي العقد', 'olama-registration' ); ?></span><strong><?php echo esc_html( $money( $agreement->total_amount ) ); ?></strong><small><?php esc_html_e( 'قيمة العقد بعد الخصومات', 'olama-registration' ); ?></small></div>
                <div class="olama-summary-card olama-summary-card--blue"><span><?php esc_html_e( 'المفوتر', 'olama-registration' ); ?></span><strong><?php echo esc_html( $money( $invoiced_total ) ); ?></strong><small><?php echo $invoice ? esc_html__( 'تم إصدار فاتورة للعقد', 'olama-registration' ) : esc_html__( 'لا توجد فاتورة مصدرة بعد', 'olama-registration' ); ?></small></div>
                <div class="olama-summary-card olama-summary-card--green"><span><?php esc_html_e( 'المدفوع', 'olama-registration' ); ?></span><strong><?php echo esc_html( $money( $paid_total ) ); ?></strong><small><?php echo $paid_total > 0 ? esc_html__( 'دفعات مسجلة', 'olama-registration' ) : esc_html__( 'لا توجد دفعات مسجلة', 'olama-registration' ); ?></small></div>
                <div class="olama-summary-card olama-summary-card--red"><span><?php esc_html_e( 'المتبقي', 'olama-registration' ); ?></span><strong><?php echo esc_html( $money( $remaining_total ) ); ?></strong><small><?php esc_html_e( 'مبلغ مستحق على ولي الأمر', 'olama-registration' ); ?></small></div>
            </div>
            <div class="olama-contract-actions">
                <?php if ( $context === 'admin' ) : ?>
                    <?php if ( $can_pay_from_workspace ) : ?>
                        <button type="button" class="olama-reg-btn olama-reg-btn--primary olama-contract-action-pay olama-reg-pay-invoice-trigger os-agr-pay-requires-saved-due" data-id="<?php echo esc_attr( $invoice->id ); ?>" data-no="<?php echo esc_attr( $invoice->invoice_number ); ?>" data-bal="<?php echo esc_attr( $invoice->balance ); ?>" data-family="<?php echo esc_attr( $invoice->family_uid ); ?>">
                            <span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'قبض دفعة', 'olama-registration' ); ?>
                        </button>
                    <?php else : ?>
                        <button type="button" class="olama-reg-btn olama-reg-btn--primary olama-contract-action-pay os-agr-main-pay-disabled" disabled title="<?php esc_attr_e( 'يتفعل قبض الدفعة بعد حفظ العقد، حفظ توزيع الاستحقاق، وإنشاء الفاتورة.', 'olama-registration' ); ?>">
                            <span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'قبض دفعة', 'olama-registration' ); ?>
                        </button>
                    <?php endif; ?>
                    <?php if ( ! in_array( $agreement->status, [ 'completed', 'cancelled' ], true ) ) : ?>
                        <button type="button" class="olama-reg-btn olama-reg-btn--primary olama-contract-action-complete os-agr-main-complete os-agr-complete-agreement-trigger" id="os-agr-complete-agreement" <?php disabled( ! $can_complete_from_workspace ); ?> title="<?php esc_attr_e( 'يتفعل بعد حفظ توزيع الاستحقاق المتوازن.', 'olama-registration' ); ?>">
                            <span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'إكمال العقد وإنشاء الفاتورة', 'olama-registration' ); ?>
                        </button>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-registration-agreements&action=print&id=' . $id ) ); ?>" target="_blank" class="olama-reg-btn olama-reg-btn--secondary"><span class="dashicons dashicons-printer"></span> <?php esc_html_e( 'طباعة العقد', 'olama-registration' ); ?></a>
                    <button type="button" class="olama-reg-btn olama-reg-btn--secondary" id="os-agr-create-amendment" data-action="add-fee" <?php disabled( ! $can_create_amendment ); ?>><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'إنشاء تعديل مالي', 'olama-registration' ); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-registration-agreements' ) ); ?>" class="olama-reg-btn olama-reg-btn--secondary"><span class="dashicons dashicons-arrow-right-alt2"></span> <?php esc_html_e( 'العودة للقائمة', 'olama-registration' ); ?></a>
                <?php elseif ( $context === 'customer_hub' ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-registration-agreements&action=print&id=' . $id ) ); ?>" target="_blank" class="olama-reg-btn olama-reg-btn--secondary"><span class="dashicons dashicons-printer"></span> <?php esc_html_e( 'طباعة العقد', 'olama-registration' ); ?></a>
                    <?php if ( $can_pay_from_workspace ) : ?>
                        <button type="button" class="olama-reg-btn olama-reg-btn--primary olama-contract-action-pay olama-reg-pay-invoice-trigger os-agr-pay-requires-saved-due" data-id="<?php echo esc_attr( $invoice->id ); ?>" data-no="<?php echo esc_attr( $invoice->invoice_number ); ?>" data-bal="<?php echo esc_attr( $invoice->balance ); ?>" data-family="<?php echo esc_attr( $invoice->family_uid ); ?>">
                            <span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'دفع الفاتورة', 'olama-registration' ); ?>
                        </button>
                    <?php endif; ?>
                <?php elseif ( $context === 'readonly' ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-registration-agreements&action=print&id=' . $id ) ); ?>" target="_blank" class="olama-reg-btn olama-reg-btn--secondary"><span class="dashicons dashicons-printer"></span> <?php esc_html_e( 'طباعة العقد', 'olama-registration' ); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the agreement fees table block.
     *
     * @param int    $agreement_id
     * @param string $context
     * @return string HTML output
     */
    public static function render_agreement_fees_table(
        int $agreement_id,
        string $context = 'admin'
    ): string {
        $agreement = class_exists( 'Olama_Reg_Agreement' ) ? Olama_Reg_Agreement::get( $agreement_id ) : null;
        if ( ! $agreement ) {
            return '';
        }

        global $wpdb;
        $id = $agreement_id;

        // Resolve financial status and impacts
        $has_financial_impact = false;
        if ( class_exists( 'Olama_Reg_Agreement_Policy' ) ) {
            $financial_edit = Olama_Reg_Agreement_Policy::can_edit_financial_fields( $id );
            $has_financial_impact = is_wp_error( $financial_edit );
        } else {
            $has_financial_impact = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}olama_invoices WHERE agreement_id = %d AND status != 'cancelled'",
                $id
            ) ) > 0;
        }

        // Get Payer Children
        $payer_children = [];
        if ( $agreement->payer_type === 'customer' && is_numeric( $agreement->payer_id ) ) {
            $table = $wpdb->prefix . 'olama_customer_children';
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, child_name AS text FROM {$table} WHERE customer_id = %d AND is_active = 1", (int) $agreement->payer_id ) );
            foreach ( $rows as $r ) {
                $payer_children[] = [ 'id' => (int) $r->id, 'text' => $r->text ];
            }
        } elseif ( $agreement->payer_type === 'family' && $agreement->payer_id ) {
            $family_reference = (string) ( $agreement->family_uid ?? $agreement->payer_ref ?? $agreement->payer_id );
            $academic_year_id = (int) ( $agreement->academic_year_id ?? 0 );
            foreach ( Olama_Reg_Core_Gateway::students_for_family( $family_reference, $academic_year_id ) as $student ) {
                $payer_children[] = [
                    'id'   => $student->student_uid,
                    'text' => $student->display_name,
                ];
            }
        }

        $templates = class_exists( 'Olama_Reg_Billing_Fees' ) ? Olama_Reg_Billing_Fees::get_agreement_templates( $agreement->activity_type ) : [];
        $fees = class_exists( 'Olama_Reg_Agreement_Fees' ) ? Olama_Reg_Agreement_Fees::get_by_agreement( $id ) : [];

        $is_readonly_context = ( $context === 'readonly' || $context === 'print' );

        ob_start();
        ?>
        <table class="olama-reg-fin-table" id="os-agr-fees-table" data-agr-id="<?php echo esc_attr( $id ); ?>">
            <colgroup>
                <col class="os-agr-fees-col-template">
                <col class="os-agr-fees-col-subscriber">
                <col class="os-agr-fees-col-details">
                <col class="os-agr-fees-col-amount">
                <col class="os-agr-fees-col-discount">
                <col class="os-agr-fees-col-net">
                <col class="os-agr-fees-col-due-date">
                <col class="os-agr-fees-col-status">
                <col class="os-agr-fees-col-actions">
            </colgroup>
            <thead>
                <tr>
                    <th style="width: 15%;"><?php esc_html_e( 'نموذج الرسوم', 'olama-registration' ); ?></th>
                    <th style="width: 15%;"><?php esc_html_e( 'المشترك', 'olama-registration' ); ?></th>
                    <th style="width: 20%;"><?php esc_html_e( 'تفاصيل النموذج', 'olama-registration' ); ?></th>
                    <th style="width: 12%;"><?php esc_html_e( 'المبلغ', 'olama-registration' ); ?></th>
                    <th style="width: 10%;"><?php esc_html_e( 'الخصم', 'olama-registration' ); ?></th>
                    <th style="width: 10%;"><?php esc_html_e( 'الصافي', 'olama-registration' ); ?></th>
                    <th style="width: 12%;"><?php esc_html_e( 'تاريخ الاستحقاق', 'olama-registration' ); ?></th>
                    <th style="width: 8%;"><?php esc_html_e( 'الحالة', 'olama-registration' ); ?></th>
                    <th style="width: 8%;"></th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ( $fees ) {
                    foreach ( $fees as $fee ) {
                        $is_locked = $has_financial_impact || in_array( $fee->paid_status, [ 'invoiced', 'paid' ], true ) || $is_readonly_context;
                        ?>
                        <tr data-fee-id="<?php echo esc_attr( $fee->id ); ?>">
                            <td>
                                <select name="fee_category" style="width:100%" class="os-agr-fee-template-select" <?php disabled( $is_locked ); ?>>
                                    <option value="" <?php selected( ! is_numeric( $fee->fee_category ) ); ?>><?php esc_html_e( 'اختر نموذج الرسوم', 'olama-registration' ); ?></option>
                                    <?php
                                    foreach ( $templates as $tpl ) {
                                        $total = 0;
                                        $breakdown = [];
                                        foreach ( $tpl->items as $it ) {
                                            $item_amount = (float) ( $it['amount'] ?? 0 );
                                            $total += $item_amount;
                                            $breakdown[] = [
                                                'description' => (string) ( $it['description'] ?? '' ),
                                                'amount'      => $item_amount,
                                            ];
                                        }
                                        $selected = selected( $fee->fee_category, $tpl->id, false );
                                        if ( ! $selected && $fee->fee_category === $tpl->template_name ) {
                                            $selected = 'selected="selected"';
                                        }
                                        echo '<option value="' . esc_attr( $tpl->id ) . '" data-name="' . esc_attr( $tpl->template_name ) . '" data-amount="' . esc_attr( $total ) . '" data-breakdown="' . esc_attr( wp_json_encode( $breakdown ) ) . '" data-subject-type="' . esc_attr( $tpl->subject_type ?? 'general' ) . '" data-subject-value="' . esc_attr( $tpl->subject_value ?? '' ) . '" ' . $selected . '>' . esc_html( $tpl->template_name ) . '</option>';
                                    }
                                    if ( $fee->fee_category !== 'general' && ! is_numeric( $fee->fee_category ) ) {
                                        echo '<option value="' . esc_attr( $fee->fee_category ) . '" selected>' . esc_html( $fee->fee_category ) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                            <td>
                                <select name="child_id" style="width:100%" class="os-agr-fee-child-select" <?php disabled( $is_locked ); ?>>
                                    <option value=""><?php esc_html_e( 'اختر المشترك', 'olama-registration' ); ?></option>
                                    <?php
                                    if ( ! empty( $payer_children ) ) {
                                        foreach ( $payer_children as $child ) {
                                            $selected = selected( $fee->child_id, $child['id'], false );
                                            echo '<option value="' . esc_attr( $child['id'] ) . '" ' . $selected . '>' . esc_html( $child['text'] ) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </td>
                            <td class="os-agr-fee-description">
                                <input type="hidden" name="label" value="<?php echo esc_attr( $fee->label ); ?>">
                                <strong class="os-agr-fee-template-name"><?php echo esc_html( $fee->label ); ?></strong>
                                <div class="os-agr-fee-breakdown">
                                    <?php
                                    if ( is_numeric( $fee->fee_category ) && (int) $fee->fee_category > 0 && class_exists( 'Olama_Reg_Billing_Fees' ) ) {
                                        $row_template = Olama_Reg_Billing_Fees::get_template( (int) $fee->fee_category );
                                        if ( $row_template && ! empty( $row_template->items ) ) {
                                            foreach ( $row_template->items as $item ) {
                                                echo '<span><span>' . esc_html( $item['description'] ?? '' ) . '</span><b>' . esc_html( number_format( (float) ( $item['amount'] ?? 0 ), 3 ) ) . '</b></span>';
                                            }
                                        }
                                    }
                                    ?>
                                </div>
                            </td>
                            <td>
                                <input type="number" step="0.001" inputmode="decimal" name="amount" value="<?php echo esc_attr( $fee->amount ); ?>" class="os-agr-fee-calc os-agr-money-input" readonly <?php disabled( $is_locked ); ?>>
                            </td>
                            <td>
                                <input type="number" step="0.001" inputmode="decimal" name="discount" value="<?php echo esc_attr( $fee->discount ); ?>" class="os-agr-fee-calc os-agr-money-input" <?php disabled( $is_locked ); ?>>
                            </td>
                            <td>
                                <span class="os-agr-fee-net"><?php echo number_format( (float) $fee->net_amount, 3 ); ?></span>
                            </td>
                            <td>
                                <input type="text" name="due_date" value="<?php echo esc_attr( $fee->due_date ); ?>" style="width:100%" class="os-datepicker" <?php disabled( $is_locked ); ?>>
                            </td>
                            <td>
                                <?php
                                if ( ! empty( $fee->status ) && $fee->status !== 'active' ) {
                                    echo esc_html( Olama_Reg_Status_Labels::label( $fee->status, 'fee' ) );
                                } else {
                                    echo esc_html( Olama_Reg_Status_Labels::label( $fee->paid_status, 'fee' ) );
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ( $is_readonly_context ) : ?>
                                    <!-- Read-only context has no actions -->
                                <?php elseif ( ! empty( $fee->status ) && $fee->status === 'cancelled_by_adjustment' ) : ?>
                                    <!-- No actions for already cancelled rows -->
                                <?php elseif ( ! $is_locked ) : ?>
                                    <button type="button" class="button button-small os-agr-save-fee"><?php esc_html_e( 'حفظ', 'olama-registration' ); ?></button>
                                    <button type="button" class="button button-small os-agr-delete-fee" style="color:red;"><?php esc_html_e( 'حذف البند', 'olama-registration' ); ?></button>
                                <?php else : ?>
                                    <button type="button" class="button button-small os-agr-cancel-fee-trigger" style="color:darkred;"><?php esc_html_e( 'إلغاء البند', 'olama-registration' ); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align:left;">
                        <strong><?php esc_html_e( 'الإجمالي الكلي للعقد:', 'olama-registration' ); ?></strong></td>
                    <td colspan="4"><strong><span id="os-agr-total-label"><?php echo number_format( (float) $agreement->total_amount, 3 ); ?></span> JD</strong></td>
                </tr>
            </tfoot>
        </table>
        <?php
        return ob_get_clean();
    }

    /**
     * Get allowed actions for the agreement.
     *
     * @param object $agreement
     * @param string $context
     * @return array
     */
    public static function get_allowed_actions(
        object $agreement,
        string $context = 'admin'
    ): array {
        $actions = [];

        $is_cancelled = ( $agreement->status === 'cancelled' );
        $has_invoices = ! empty( $agreement->invoices );

        if ( $context === 'admin' ) {
            $actions['edit'] = [
                'label' => __( 'تعديل', 'olama-registration' ),
                'url'   => admin_url( 'admin.php?page=olama-registration-agreements&action=edit&id=' . $agreement->id ),
                'class' => 'button button-small',
            ];
            $actions['print'] = [
                'label' => __( 'طباعة', 'olama-registration' ),
                'url'   => admin_url( 'admin.php?page=olama-registration-agreements&action=print&id=' . $agreement->id ),
                'class' => 'button button-small',
                'target' => '_blank',
            ];
            if ( ! $is_cancelled && ! $has_invoices ) {
                $cancel_url = wp_nonce_url(
                    admin_url( 'admin.php?page=olama-registration-agreements&action=cancel&id=' . $agreement->id ),
                    'olama_cancel_agreement_' . $agreement->id
                );
                $actions['cancel'] = [
                    'label' => __( 'إلغاء', 'olama-registration' ),
                    'url'   => $cancel_url,
                    'class' => 'button button-small',
                    'style' => 'color:#d63638; text-decoration:none;',
                    'onclick' => "return confirm('" . esc_js( __( 'هل أنت متأكد من إلغاء وحذف هذا العقد؟', 'olama-registration' ) ) . "');",
                ];
            }
        } elseif ( $context === 'customer_hub' ) {
            $actions['edit'] = [
                'label' => __( 'تعديل', 'olama-registration' ),
                'url'   => '#',
                'class' => 'button button-small os-hub-edit-agreement',
                'attribs' => 'data-id="' . esc_attr( $agreement->id ) . '"',
            ];
            $actions['print'] = [
                'label' => __( 'طباعة', 'olama-registration' ),
                'url'   => admin_url( 'admin.php?page=olama-registration-agreements&action=print&id=' . $agreement->id ),
                'class' => 'button button-small',
                'target' => '_blank',
            ];
            if ( ! $is_cancelled && ! $has_invoices ) {
                $cancel_url = wp_nonce_url(
                    add_query_arg( 'redirect_to', 'hub', admin_url( 'admin.php?page=olama-registration-agreements&action=cancel&id=' . $agreement->id ) ),
                    'olama_cancel_agreement_' . $agreement->id
                );
                $actions['cancel'] = [
                    'label' => __( 'إلغاء', 'olama-registration' ),
                    'url'   => $cancel_url,
                    'class' => 'button button-small',
                    'style' => 'color:#d63638; text-decoration:none;',
                    'onclick' => "return confirm('" . esc_js( __( 'هل أنت متأكد من إلغاء وحذف هذا العقد؟', 'olama-registration' ) ) . "');",
                ];
            }
            if ( $has_invoices ) {
                $actions['invoices'] = [];
                foreach ( $agreement->invoices as $inv ) {
                    $inv_actions = [];
                    $inv_actions['view'] = [
                        'label' => sprintf( __( 'عرض %s', 'olama-registration' ), $inv->invoice_number ),
                        'class' => 'button button-small olama-reg-view-invoice-btn',
                        'attribs' => 'data-id="' . esc_attr( $inv->id ) . '" title="' . esc_attr__( 'عرض الفاتورة', 'olama-registration' ) . '" style="background:#0284c7; border-color:#0284c7; color:#fff; display: block; width: 100%; text-align: center; justify-content: center; font-size: 11px; margin-bottom: 2px;"',
                    ];
                    if ( (float) $inv->balance > 0 && $inv->status !== 'cancelled' && $inv->status !== 'draft' ) {
                        $inv_actions['pay'] = [
                            'label' => sprintf( __( 'دفع %s', 'olama-registration' ), $inv->invoice_number ),
                            'class' => 'button button-small os-hub-pay-invoice-btn',
                            'attribs' => 'data-id="' . esc_attr( $inv->id ) . '" title="' . esc_attr__( 'دفع الفاتورة', 'olama-registration' ) . '" style="background:#16a34a; border-color:#16a34a; color:#fff; display: block; width: 100%; text-align: center; justify-content: center; font-size: 11px; margin-bottom: 4px;"',
                        ];
                    }
                    $actions['invoices'][] = $inv_actions;
                }
            }
        } elseif ( $context === 'readonly' ) {
            $actions['print'] = [
                'label' => __( 'طباعة', 'olama-registration' ),
                'url'   => admin_url( 'admin.php?page=olama-registration-agreements&action=print&id=' . $agreement->id ),
                'class' => 'button button-small',
                'target' => '_blank',
            ];
        }

        return $actions;
    }

    /**
     * Render empty state notice.
     *
     * @param string $message
     * @param string $icon
     * @return string HTML output
     */
    private static function render_empty_state( string $message, string $icon = 'dashicons-info-outline' ): string {
        return '<div class="os-hub-notice">'
             . '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>'
             . '<p class="os-hub-notice__title">' . esc_html( $message ) . '</p>'
             . '</div>';
    }
}
