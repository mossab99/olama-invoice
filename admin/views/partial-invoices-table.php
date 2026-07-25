<?php
/**
 * Invoices Table Shared View Partial
 *
 * Variables expected:
 * - $invoices: array of invoice objects/rows
 * - $is_hub: boolean flag indicating if this is rendered inside the Customer Hub
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="olama-reg-wrap" style="margin: 0 !important; max-width: none !important; padding: 0 !important; box-shadow: none !important;">
<div class="olama-reg-table-wrap" style="width: 100% !important; overflow-x: auto !important;">
<table class="olama-reg-fin-table">
    <thead>
        <tr>
            <th><?php esc_html_e( 'رقم الفاتورة', 'olama-registration' ); ?></th>
            <th><?php esc_html_e( 'رقم الملف', 'olama-registration' ); ?></th>
            <th><?php esc_html_e( 'ولي الأمر', 'olama-registration' ); ?></th>
            <th><?php esc_html_e( 'النوع / نموذج الرسم', 'olama-registration' ); ?></th>
            <th><?php esc_html_e( 'رقم العقد', 'olama-registration' ); ?></th>
            <th><?php esc_html_e( 'تاريخ الإصدار', 'olama-registration' ); ?></th>
            <th><?php esc_html_e( 'الإجمالي', 'olama-registration' ); ?></th>
            <th><?php esc_html_e( 'الخصم', 'olama-registration' ); ?></th>
            <th><?php esc_html_e( 'المدفوع', 'olama-registration' ); ?></th>
            <th><?php esc_html_e( 'المتبقي', 'olama-registration' ); ?></th>
            <th><?php esc_html_e( 'الحالة', 'olama-registration' ); ?></th>
            <th><?php esc_html_e( 'الخيارات', 'olama-registration' ); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if ( empty( $invoices ) ): ?>
            <tr>
                <td colspan="12" class="olama-reg-empty-state">
                    <span class="dashicons dashicons-info"></span><br>
                    <?php esc_html_e( 'لم يتم العثور على أي فواتير مطابقة.', 'olama-registration' ); ?>
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ( $invoices as $inv ):
                $status_lbl  = Olama_Reg_Status_Labels::label( $inv->status, 'invoice' );
                $badge_class = 'olama-reg-badge--' . Olama_Reg_Status_Labels::badge_class( $inv->status, 'invoice' );
            ?>
                <tr>
                    <td><strong style="letter-spacing:0.3px;"><?php echo esc_html( $inv->invoice_number ); ?></strong></td>
                    <td><span class="olama-reg-uid-badge"><?php echo esc_html( $inv->oracle_family_id ?: $inv->family_uid ); ?></span></td>
                    <td>
                        <?php echo esc_html( trim( $inv->father_first_name . ' ' . $inv->father_family_name ) ); ?>
                        <?php if ( ! empty( $inv->covered_children_names ) ) : ?>
                            <div style="margin-top:4px; color:var(--reg-text-muted); font-size:12px; line-height:1.6;">
                                <?php esc_html_e( 'الأبناء:', 'olama-registration' ); ?>
                                <?php echo esc_html( implode( '، ', $inv->covered_children_names ) ); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $subject_type = $inv->fee_subject_type ?? '';
                        $type_label = '';
                        if ( ! empty( $inv->agreement_id ) ) {
                            $type_label = __( 'عقد', 'olama-registration' );
                        } elseif ( $subject_type === 'service' ) {
                            $type_label = __( 'خدمة', 'olama-registration' );
                        } elseif ( $subject_type === 'agreement' ) {
                            $type_label = __( 'عقد', 'olama-registration' );
                        } elseif ( $subject_type === 'general' ) {
                            $type_label = __( 'عام', 'olama-registration' );
                        }
                        $template_label = $inv->fee_template_name ?: ( $inv->fee_subject_value ?? '' );
                        ?>
                        <?php if ( $type_label ) : ?>
                            <span class="olama-reg-badge olama-reg-badge--info"><?php echo esc_html( $type_label ); ?></span>
                        <?php endif; ?>
                        <?php if ( $template_label ) : ?>
                            <div style="margin-top:4px; font-weight:700;"><?php echo esc_html( $template_label ); ?></div>
                        <?php else : ?>
                            <span style="color:var(--reg-text-muted);">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( ! empty( $inv->agreement_id ) && ! empty( $inv->agreement_number ) ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-registration-agreements&action=edit&id=' . (int) $inv->agreement_id ) ); ?>" class="olama-reg-uid-badge">
                                <?php echo esc_html( $inv->agreement_number ); ?>
                            </a>
                        <?php else : ?>
                            <span style="color:var(--reg-text-muted);">-</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--reg-text-muted);"><?php echo esc_html( $inv->issue_date ); ?></td>
                    <td class="olama-reg-text--bold">
                        <?php echo esc_html( number_format( $inv->effective_total ?? $inv->total, 2 ) ); ?>
                        <?php if ( ! empty( $inv->debit_notes_total ) || ! empty( $inv->credit_notes_total ) ) : ?>
                            <div style="margin-top:3px; color:var(--reg-text-muted); font-size:11px; font-weight:500;">
                                <?php esc_html_e( 'يشمل الإشعارات المالية', 'olama-registration' ); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="color:#c62828; font-weight:700;"><?php echo esc_html( number_format( $inv->discount ?? 0, 2 ) ); ?></td>
                    <td style="color:var(--reg-success); font-weight:700;"><?php echo esc_html( number_format( $inv->amount_paid, 2 ) ); ?></td>
                    <td class="olama-reg-balance-cell"><?php echo esc_html( number_format( $inv->balance, 2 ) ); ?></td>
                    <td>
                        <span class="olama-reg-badge <?php echo esc_attr( $badge_class ); ?>">
                            <?php echo esc_html( $status_lbl ); ?>
                        </span>
                    </td>
                    <td style="text-align:center; white-space:nowrap;">
                        <?php if (!(!empty($inv->ext_customer_id) && $inv->status === 'partial')): ?>
                        <button class="button button-small olama-reg-view-invoice-btn"
                                 data-id="<?php echo esc_attr( $inv->id ); ?>"
                                 title="<?php esc_attr_e( 'عرض التفاصيل', 'olama-registration' ); ?>">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                        <?php endif; ?>
                        
                        <?php if ( isset( $is_hub ) && $is_hub && (float)$inv->balance > 0 && $inv->status !== 'cancelled' && $inv->status !== 'draft' ): ?>
                            <button type="button" class="button button-small button-primary os-hub-pay-invoice-btn" 
                                    data-id="<?php echo esc_attr($inv->id); ?>" 
                                    title="<?php esc_attr_e('دفع الفاتورة', 'olama-registration'); ?>" 
                                    style="background:#16a34a; border-color:#16a34a; color:#fff; margin-left: 2px;">
                                <?php esc_html_e('دفع', 'olama-registration'); ?>
                            </button>
                        <?php endif; ?>

                        <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'print', 'id' => $inv->id ], admin_url( 'admin.php?page=olama-registration-invoices' ) ) ); ?>"
                           target="_blank" class="button button-small"
                           title="<?php esc_attr_e( 'طباعة الفاتورة', 'olama-registration' ); ?>">
                            <span class="dashicons dashicons-printer"></span>
                        </a>
                        
                        <?php if ( (float)$inv->amount_paid == 0 && $inv->status !== 'cancelled' ): ?>
                            <button class="button button-small olama-reg-cancel-invoice-btn"
                                    data-id="<?php echo esc_attr( $inv->id ); ?>"
                                    title="<?php esc_attr_e( 'إلغاء الفاتورة', 'olama-registration' ); ?>"
                                    style="color:#c62828;">
                                <span class="dashicons dashicons-dismiss"></span>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
</div>
</div>
