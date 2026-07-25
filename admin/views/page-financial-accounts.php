<?php
/**
 * Financial accounts and receipt repair tools.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$notice = '';
$error = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    check_admin_referer( 'olama_financial_accounts_action', 'olama_financial_accounts_nonce' );
    $action = sanitize_key( $_POST['financial_action'] ?? '' );

    if ( $action === 'save_account' ) {
        $result = Olama_Reg_Financial_Account::save( $_POST );
        $notice = is_wp_error( $result ) ? '' : __( 'تم حفظ الحساب المالي.', 'olama-registration' );
        $error = is_wp_error( $result ) ? $result->get_error_message() : '';
    } elseif ( $action === 'toggle_account' ) {
        $result = Olama_Reg_Financial_Account::set_active( absint( $_POST['id'] ?? 0 ), ! empty( $_POST['active'] ) );
        $notice = is_wp_error( $result ) ? '' : __( 'تم تحديث حالة الحساب.', 'olama-registration' );
        $error = is_wp_error( $result ) ? $result->get_error_message() : '';
    } elseif ( $action === 'repair_receipts' ) {
        $result = Olama_Reg_Receipt_Repair::run( [
            'payment_numbers' => ! empty( $_POST['repair_payment_numbers'] ),
            'accounts'        => ! empty( $_POST['repair_accounts'] ),
            'movements'       => ! empty( $_POST['repair_movements'] ),
        ] );
        if ( is_wp_error( $result ) ) {
            $error = $result->get_error_message();
        } else {
            $notice = sprintf(
                __( 'تم الإصلاح: %1$d أرقام سندات، %2$d حسابات، %3$d حركات جديدة، %4$d تعارضات حركات قديمة.', 'olama-registration' ),
                (int) $result['payment_numbers'],
                (int) $result['accounts'],
                (int) $result['movements'],
                (int) ( $result['movement_conflicts'] ?? 0 )
            );
        }
    } elseif ( $action === 'transfer_between_accounts' ) {
        $result = Olama_Reg_Cash_Bank_Movement::record_transfer(
            absint( $_POST['from_account_id'] ?? 0 ),
            absint( $_POST['to_account_id'] ?? 0 ),
            (float) ( $_POST['amount'] ?? 0 ),
            [
                'transfer_date' => sanitize_text_field( $_POST['transfer_date'] ?? '' ),
                'reference'     => sanitize_text_field( $_POST['reference'] ?? '' ),
                'notes'         => sanitize_textarea_field( $_POST['notes'] ?? '' ),
            ]
        );
        if ( is_wp_error( $result ) ) {
            $error = $result->get_error_message();
        } else {
            $notice = __( 'تم تسجيل التحويل الداخلي بين الحسابات بنجاح.', 'olama-registration' );
        }
    }
}

$accounts = Olama_Reg_Financial_Account::all();
$type_labels = Olama_Reg_Financial_Account::type_labels();
$repair_preview = Olama_Reg_Receipt_Repair::preview();
$recent_transfers = Olama_Reg_Cash_Bank_Movement::get_transfers( 12 );
$money = static function ( $amount ): string {
    return number_format( (float) $amount, 2 );
};
?>
<div class="wrap olama-reg-wrap" dir="rtl">
    <div class="olama-reg-page-header">
        <h1><span class="dashicons dashicons-bank"></span> <?php esc_html_e( 'الحسابات المالية وأدوات الإصلاح', 'olama-registration' ); ?></h1>
    </div>

    <?php if ( $notice ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
    <?php endif; ?>
    <?php if ( $error ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
    <?php endif; ?>

    <div class="olama-reg-section">
        <h3 class="olama-reg-section-title"><span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e( 'إضافة / تعديل حساب مالي', 'olama-registration' ); ?></h3>
        <form method="post" class="olama-reg-grid olama-reg-grid--compact">
            <?php wp_nonce_field( 'olama_financial_accounts_action', 'olama_financial_accounts_nonce' ); ?>
            <input type="hidden" name="financial_action" value="save_account">
            <input type="hidden" name="is_active" value="1">
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'رقم الحساب', 'olama-registration' ); ?></label>
                <input type="text" name="account_code" required placeholder="CASH-MAIN">
            </div>
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'اسم الحساب', 'olama-registration' ); ?></label>
                <input type="text" name="account_name" required>
            </div>
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'النوع', 'olama-registration' ); ?></label>
                <select name="type" required>
                    <?php foreach ( $type_labels as $type => $label ) : ?>
                        <option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'العملة', 'olama-registration' ); ?></label>
                <input type="text" name="currency" value="JOD" maxlength="10">
            </div>
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'الرصيد الافتتاحي', 'olama-registration' ); ?></label>
                <input type="number" name="opening_balance" value="0.00" step="0.01">
            </div>
            <label style="display:flex; gap:8px; align-items:center; font-weight:800;">
                <input type="checkbox" name="is_default" value="1">
                <?php esc_html_e( 'الحساب الافتراضي لهذا النوع', 'olama-registration' ); ?>
            </label>
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'ملاحظات', 'olama-registration' ); ?></label>
                <input type="text" name="notes">
            </div>
            <div class="olama-reg-field" style="justify-content:flex-end;">
                <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                    <span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'حفظ الحساب', 'olama-registration' ); ?>
                </button>
            </div>
        </form>
    </div>

    <div class="olama-reg-section">
        <h3 class="olama-reg-section-title"><span class="dashicons dashicons-randomize"></span> <?php esc_html_e( 'تحويل بين الحسابات', 'olama-registration' ); ?></h3>
        <form method="post" class="olama-reg-grid olama-reg-grid--compact">
            <?php wp_nonce_field( 'olama_financial_accounts_action', 'olama_financial_accounts_nonce' ); ?>
            <input type="hidden" name="financial_action" value="transfer_between_accounts">
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'من حساب', 'olama-registration' ); ?></label>
                <select name="from_account_id" required>
                    <option value=""><?php esc_html_e( 'اختر الحساب المصدر', 'olama-registration' ); ?></option>
                    <?php foreach ( $accounts as $account ) : ?>
                        <?php if ( ! (int) $account->is_active ) continue; ?>
                        <option value="<?php echo esc_attr( $account->id ); ?>">
                            <?php echo esc_html( $account->account_name . ' (' . $account->account_code . ')' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'إلى حساب', 'olama-registration' ); ?></label>
                <select name="to_account_id" required>
                    <option value=""><?php esc_html_e( 'اختر الحساب المستهدف', 'olama-registration' ); ?></option>
                    <?php foreach ( $accounts as $account ) : ?>
                        <?php if ( ! (int) $account->is_active ) continue; ?>
                        <option value="<?php echo esc_attr( $account->id ); ?>">
                            <?php echo esc_html( $account->account_name . ' (' . $account->account_code . ')' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'المبلغ', 'olama-registration' ); ?></label>
                <input type="number" name="amount" step="0.01" min="0.01" required>
            </div>
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'تاريخ التحويل', 'olama-registration' ); ?></label>
                <input type="date" name="transfer_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required>
            </div>
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'المرجع', 'olama-registration' ); ?></label>
                <input type="text" name="reference" placeholder="<?php esc_attr_e( 'رقم الإيداع أو مرجع البنك', 'olama-registration' ); ?>">
            </div>
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'ملاحظات', 'olama-registration' ); ?></label>
                <input type="text" name="notes" placeholder="<?php esc_attr_e( 'مثال: إيداع حصيلة الصندوق في البنك', 'olama-registration' ); ?>">
            </div>
            <div class="olama-reg-field" style="justify-content:flex-end;">
                <button type="submit" class="olama-reg-btn olama-reg-btn--secondary">
                    <span class="dashicons dashicons-randomize"></span> <?php esc_html_e( 'تنفيذ التحويل', 'olama-registration' ); ?>
                </button>
            </div>
        </form>

        <div class="olama-reg-table-wrap" style="margin-top:18px;">
            <table class="olama-reg-fin-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'رقم التحويل', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'التاريخ', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'من حساب', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'إلى حساب', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'المبلغ', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'المرجع', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'أنشأها', 'olama-registration' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $recent_transfers ) ) : ?>
                        <tr><td colspan="7" class="olama-reg-empty-state"><?php esc_html_e( 'لا توجد تحويلات داخلية مسجلة بعد.', 'olama-registration' ); ?></td></tr>
                    <?php else : foreach ( $recent_transfers as $transfer ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $transfer->transfer_no ); ?></strong></td>
                            <td><?php echo esc_html( $transfer->transfer_date ); ?></td>
                            <td><?php echo esc_html( ( $transfer->from_account_name ?? '' ) . ' (' . ( $transfer->from_account_code ?? '' ) . ')' ); ?></td>
                            <td><?php echo esc_html( ( $transfer->to_account_name ?? '' ) . ' (' . ( $transfer->to_account_code ?? '' ) . ')' ); ?></td>
                            <td><strong><?php echo esc_html( $money( $transfer->amount ) ); ?></strong></td>
                            <td><?php echo esc_html( $transfer->reference ?: '—' ); ?></td>
                            <td><?php echo esc_html( $transfer->created_by_name ?: '—' ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="olama-reg-section">
        <h3 class="olama-reg-section-title"><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'الحسابات الحالية', 'olama-registration' ); ?></h3>
        <div class="olama-reg-table-wrap">
            <table class="olama-reg-fin-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'الرمز', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'الاسم', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'النوع', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'افتراضي', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'نشط', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'الرصيد الافتتاحي', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'الرصيد الحالي', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'إجراء', 'olama-registration' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $accounts ) ) : ?>
                        <tr><td colspan="8" class="olama-reg-empty-state"><?php esc_html_e( 'لا توجد حسابات مالية بعد.', 'olama-registration' ); ?></td></tr>
                    <?php else : foreach ( $accounts as $account ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $account->account_code ); ?></strong></td>
                            <td><?php echo esc_html( $account->account_name ); ?></td>
                            <td><?php echo esc_html( $type_labels[ $account->type ] ?? $account->type ); ?></td>
                            <td><?php echo (int) $account->is_default ? esc_html__( 'نعم', 'olama-registration' ) : '—'; ?></td>
                            <td><?php echo (int) $account->is_active ? esc_html__( 'نشط', 'olama-registration' ) : esc_html__( 'معطل', 'olama-registration' ); ?></td>
                            <td><?php echo esc_html( $money( $account->opening_balance ) ); ?></td>
                            <td><strong><?php echo esc_html( $money( Olama_Reg_Cash_Bank_Movement::get_account_balance( (int) $account->id ) ) ); ?></strong></td>
                            <td>
                                <form method="post">
                                    <?php wp_nonce_field( 'olama_financial_accounts_action', 'olama_financial_accounts_nonce' ); ?>
                                    <input type="hidden" name="financial_action" value="toggle_account">
                                    <input type="hidden" name="id" value="<?php echo esc_attr( $account->id ); ?>">
                                    <input type="hidden" name="active" value="<?php echo (int) $account->is_active ? '0' : '1'; ?>">
                                    <button type="submit" class="button button-small">
                                        <?php echo (int) $account->is_active ? esc_html__( 'تعطيل', 'olama-registration' ) : esc_html__( 'تفعيل', 'olama-registration' ); ?>
                                    </button>
                                </form>
                                <details style="margin-top:8px;">
                                    <summary class="button button-small"><?php esc_html_e( 'تعديل', 'olama-registration' ); ?></summary>
                                    <form method="post" style="display:grid; gap:8px; min-width:260px; margin-top:8px;">
                                        <?php wp_nonce_field( 'olama_financial_accounts_action', 'olama_financial_accounts_nonce' ); ?>
                                        <input type="hidden" name="financial_action" value="save_account">
                                        <input type="hidden" name="id" value="<?php echo esc_attr( $account->id ); ?>">
                                        <input type="text" name="account_code" value="<?php echo esc_attr( $account->account_code ); ?>" required>
                                        <input type="text" name="account_name" value="<?php echo esc_attr( $account->account_name ); ?>" required>
                                        <select name="type" required>
                                            <?php foreach ( $type_labels as $type => $label ) : ?>
                                                <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $account->type, $type ); ?>><?php echo esc_html( $label ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" name="currency" value="<?php echo esc_attr( $account->currency ); ?>" maxlength="10">
                                        <input type="number" name="opening_balance" value="<?php echo esc_attr( number_format( (float) $account->opening_balance, 2, '.', '' ) ); ?>" step="0.01">
                                        <label><input type="checkbox" name="is_default" value="1" <?php checked( (int) $account->is_default, 1 ); ?>> <?php esc_html_e( 'افتراضي', 'olama-registration' ); ?></label>
                                        <label><input type="checkbox" name="is_active" value="1" <?php checked( (int) $account->is_active, 1 ); ?>> <?php esc_html_e( 'نشط', 'olama-registration' ); ?></label>
                                        <textarea name="notes" rows="2"><?php echo esc_textarea( $account->notes ); ?></textarea>
                                        <button type="submit" class="button button-primary button-small"><?php esc_html_e( 'حفظ', 'olama-registration' ); ?></button>
                                    </form>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="olama-reg-section">
        <h3 class="olama-reg-section-title"><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'إصلاح السندات القديمة', 'olama-registration' ); ?></h3>
        <p>
            <?php
            echo esc_html( sprintf(
                __( 'المعاينة الحالية: %1$d سند بلا رقم، %2$d سند بلا حساب، %3$d سند منشور بلا حركة مالية، %4$d حركة قديمة متعارضة.', 'olama-registration' ),
                (int) $repair_preview['missing_payment_no'],
                (int) $repair_preview['missing_account'],
                (int) $repair_preview['missing_movements'],
                (int) ( $repair_preview['mismatched_movements'] ?? 0 )
            ) );
            ?>
        </p>
        <form method="post" class="olama-reg-grid olama-reg-grid--compact">
            <?php wp_nonce_field( 'olama_financial_accounts_action', 'olama_financial_accounts_nonce' ); ?>
            <input type="hidden" name="financial_action" value="repair_receipts">
            <label><input type="checkbox" name="repair_payment_numbers" value="1" checked> <?php esc_html_e( 'توليد أرقام للسندات القديمة', 'olama-registration' ); ?></label>
            <label><input type="checkbox" name="repair_accounts" value="1" checked> <?php esc_html_e( 'ربط السندات بالحسابات الافتراضية', 'olama-registration' ); ?></label>
            <label><input type="checkbox" name="repair_movements" value="1" checked> <?php esc_html_e( 'مصالحة حركات السندات المنشورة وإصلاح تعارضاتها', 'olama-registration' ); ?></label>
            <div style="display:flex; align-items:flex-end;">
                <button type="submit" class="olama-reg-btn olama-reg-btn--secondary">
                    <span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e( 'تشغيل الإصلاح المحدد', 'olama-registration' ); ?>
                </button>
            </div>
        </form>
    </div>
</div>
