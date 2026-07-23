<?php
/**
 * Cash sessions and daily closing.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$notice = '';
$error  = '';

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    check_admin_referer( 'olama_cash_sessions_action', 'olama_cash_sessions_nonce' );

    $cash_action = sanitize_key( $_POST['cash_action'] ?? '' );
    if ( $cash_action === 'save_settings' ) {
        update_option( 'olama_require_cash_session', ! empty( $_POST['require_cash_session'] ) ? '1' : '0' );
        $notice = __( 'تم حفظ إعدادات جلسات الصندوق.', 'olama-registration' );
    } elseif ( $cash_action === 'open_session' ) {
        $result = Olama_Reg_Cash_Session::open( $_POST );
        $notice = is_wp_error( $result ) ? '' : __( 'تم فتح جلسة الصندوق بنجاح.', 'olama-registration' );
        $error  = is_wp_error( $result ) ? $result->get_error_message() : '';
    } elseif ( $cash_action === 'close_session' ) {
        $result = Olama_Reg_Cash_Session::close(
            absint( $_POST['session_id'] ?? 0 ),
            $_POST['actual_closing_balance'] ?? '',
            sanitize_textarea_field( $_POST['notes'] ?? '' )
        );
        $notice = is_wp_error( $result ) ? '' : __( 'تم إغلاق الجلسة وإرسالها للمراجعة.', 'olama-registration' );
        $error  = is_wp_error( $result ) ? $result->get_error_message() : '';
    } elseif ( $cash_action === 'review_session' ) {
        $result = Olama_Reg_Cash_Session::review(
            absint( $_POST['session_id'] ?? 0 ),
            sanitize_key( $_POST['decision'] ?? 'approve' ),
            sanitize_textarea_field( $_POST['notes'] ?? '' )
        );
        $notice = is_wp_error( $result ) ? '' : __( 'تم تحديث مراجعة جلسة الصندوق.', 'olama-registration' );
        $error  = is_wp_error( $result ) ? $result->get_error_message() : '';
    }
}

$cash_accounts = Olama_Reg_Cash_Session::get_cash_accounts();
$sessions = Olama_Reg_Cash_Session::get_sessions();
$require_cash_session = get_option( 'olama_require_cash_session', '0' ) === '1';
$money = static function ( $amount ): string {
    return number_format( (float) $amount, 2 );
};
?>
<div class="wrap olama-reg-wrap" dir="rtl">
    <div class="olama-reg-page-header">
        <h1>
            <span class="dashicons dashicons-portfolio"></span>
            <?php esc_html_e( 'الصناديق والجرد اليومي', 'olama-registration' ); ?>
        </h1>
    </div>

    <?php if ( $notice ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
    <?php endif; ?>
    <?php if ( $error ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
    <?php endif; ?>

    <div class="olama-reg-section">
        <h3 class="olama-reg-section-title">
            <span class="dashicons dashicons-admin-settings"></span>
            <?php esc_html_e( 'إعدادات جلسات الصندوق', 'olama-registration' ); ?>
        </h3>
        <form method="post" class="olama-reg-grid olama-reg-grid--compact">
            <?php wp_nonce_field( 'olama_cash_sessions_action', 'olama_cash_sessions_nonce' ); ?>
            <input type="hidden" name="cash_action" value="save_settings">
            <label style="display:flex; gap:8px; align-items:center; font-weight:800;">
                <input type="checkbox" name="require_cash_session" value="1" <?php checked( $require_cash_session ); ?>>
                <?php esc_html_e( 'منع تسجيل سندات نقدية بدون جلسة صندوق مفتوحة', 'olama-registration' ); ?>
            </label>
            <div style="display:flex; align-items:flex-end;">
                <button type="submit" class="olama-reg-btn olama-reg-btn--secondary">
                    <span class="dashicons dashicons-saved"></span>
                    <?php esc_html_e( 'حفظ الإعدادات', 'olama-registration' ); ?>
                </button>
            </div>
        </form>
    </div>

    <div class="olama-reg-section">
        <h3 class="olama-reg-section-title">
            <span class="dashicons dashicons-plus-alt"></span>
            <?php esc_html_e( 'فتح جلسة صندوق', 'olama-registration' ); ?>
        </h3>
        <form method="post" class="olama-reg-grid olama-reg-grid--compact os-cash-session-open-form">
            <?php wp_nonce_field( 'olama_cash_sessions_action', 'olama_cash_sessions_nonce' ); ?>
            <input type="hidden" name="cash_action" value="open_session">
            <input type="hidden" name="cashier_id" value="<?php echo esc_attr( get_current_user_id() ); ?>">
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'الصندوق', 'olama-registration' ); ?></label>
                <select name="account_id" required>
                    <?php foreach ( $cash_accounts as $account ) : ?>
                        <option
                            value="<?php echo esc_attr( $account->id ); ?>"
                            data-reference-balance="<?php echo esc_attr( number_format( (float) $account->reference_balance, 2, '.', '' ) ); ?>"
                        ><?php echo esc_html( $account->account_name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'تاريخ الجلسة', 'olama-registration' ); ?></label>
                <input type="date" name="session_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required>
            </div>
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'الرصيد الدفتري المرجعي', 'olama-registration' ); ?></label>
                <output class="os-cash-reference-balance olama-reg-readonly-value">0.00</output>
                <small><?php esc_html_e( 'للمقارنة فقط؛ لا يغني عن عدّ النقد فعلياً.', 'olama-registration' ); ?></small>
            </div>
            <div class="olama-reg-field olama-reg-field--required">
                <label><?php esc_html_e( 'النقد الفعلي المعدود عند الافتتاح', 'olama-registration' ); ?></label>
                <input type="number" name="opening_balance" value="" step="0.01" min="0" placeholder="0.00" required autocomplete="off">
            </div>
            <div class="olama-reg-field">
                <label><?php esc_html_e( 'ملاحظات الافتتاح', 'olama-registration' ); ?></label>
                <input type="text" name="notes" placeholder="<?php esc_attr_e( 'مطلوبة عند وجود فرق', 'olama-registration' ); ?>">
            </div>
            <div class="olama-reg-field os-cash-opening-variance" hidden>
                <strong><?php esc_html_e( 'فرق الافتتاح:', 'olama-registration' ); ?> <span class="os-cash-opening-variance-value">0.00</span></strong>
                <label class="os-cash-acknowledgement">
                    <input type="checkbox" name="opening_variance_acknowledged" value="1">
                    <?php esc_html_e( 'قمت بعدّ النقد وأؤكد فرق الافتتاح الموضح.', 'olama-registration' ); ?>
                </label>
            </div>
            <div class="olama-reg-field" style="justify-content:flex-end;">
                <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                    <span class="dashicons dashicons-plus"></span>
                    <?php esc_html_e( 'فتح الجلسة', 'olama-registration' ); ?>
                </button>
            </div>
        </form>
    </div>

    <div class="olama-reg-section">
        <h3 class="olama-reg-section-title">
            <span class="dashicons dashicons-list-view"></span>
            <?php esc_html_e( 'جلسات الصندوق', 'olama-registration' ); ?>
        </h3>
        <div class="olama-reg-table-wrap">
            <table class="olama-reg-fin-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'رقم الجلسة', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'التاريخ', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'الصندوق', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'أمين الصندوق', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'افتتاحي', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'وارد', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'صادر', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'متوقع', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'فعلي', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'الفرق', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'الحالة', 'olama-registration' ); ?></th>
                        <th><?php esc_html_e( 'إجراء', 'olama-registration' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $sessions ) ) : ?>
                        <tr><td colspan="12" class="olama-reg-empty-state"><?php esc_html_e( 'لا توجد جلسات صندوق بعد.', 'olama-registration' ); ?></td></tr>
                    <?php else : foreach ( $sessions as $session ) : ?>
                        <?php
                        $totals = Olama_Reg_Cash_Session::refresh_totals( (int) $session->id );
                        $expected = $totals['expected'];
                        $actual = $session->actual_closing_balance;
                        $difference = $actual === null ? null : round( (float) $actual - $expected, 2 );
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $session->session_no ); ?></strong></td>
                            <td><?php echo esc_html( $session->session_date ); ?></td>
                            <td><?php echo esc_html( $session->account_name ?: '—' ); ?></td>
                            <td><?php echo esc_html( $session->cashier_name ?: '—' ); ?></td>
                            <td>
                                <strong><?php echo esc_html( $money( $session->opening_balance ) ); ?></strong>
                                <?php if ( isset( $session->opening_reference_balance ) && $session->opening_reference_balance !== null ) : ?>
                                    <small class="os-cash-cell-detail">
                                        <?php esc_html_e( 'مرجعي:', 'olama-registration' ); ?>
                                        <?php echo esc_html( $money( $session->opening_reference_balance ) ); ?>
                                        ·
                                        <?php esc_html_e( 'الفرق:', 'olama-registration' ); ?>
                                        <?php echo esc_html( $money( $session->opening_difference_amount ) ); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td class="olama-reg-text--success"><?php echo esc_html( $money( $totals['cash_in'] ) ); ?></td>
                            <td style="color:#c62828;"><?php echo esc_html( $money( $totals['cash_out'] ) ); ?></td>
                            <td><strong><?php echo esc_html( $money( $expected ) ); ?></strong></td>
                            <td><?php echo $actual === null ? '—' : esc_html( $money( $actual ) ); ?></td>
                            <td><?php echo $difference === null ? '—' : esc_html( $money( $difference ) ); ?></td>
                            <td><?php echo esc_html( Olama_Reg_Status_Labels::label( $session->status, 'cash_session' ) ); ?></td>
                            <td>
                                <?php if ( $session->status === 'open' ) : ?>
                                    <form method="post" class="os-cash-session-close-form" data-expected="<?php echo esc_attr( number_format( (float) $expected, 2, '.', '' ) ); ?>">
                                        <?php wp_nonce_field( 'olama_cash_sessions_action', 'olama_cash_sessions_nonce' ); ?>
                                        <input type="hidden" name="cash_action" value="close_session">
                                        <input type="hidden" name="session_id" value="<?php echo esc_attr( $session->id ); ?>">
                                        <label>
                                            <span><?php esc_html_e( 'النقد الفعلي المعدود', 'olama-registration' ); ?></span>
                                            <input type="number" name="actual_closing_balance" step="0.01" min="0" value="" placeholder="0.00" required autocomplete="off">
                                        </label>
                                        <label>
                                            <span><?php esc_html_e( 'ملاحظات الإغلاق', 'olama-registration' ); ?></span>
                                            <input type="text" name="notes" placeholder="<?php esc_attr_e( 'مطلوبة عند وجود فرق', 'olama-registration' ); ?>">
                                        </label>
                                        <small class="os-cash-closing-variance" hidden>
                                            <?php esc_html_e( 'فرق الإغلاق:', 'olama-registration' ); ?>
                                            <strong>0.00</strong>
                                        </small>
                                        <button type="submit" class="button button-small"><?php esc_html_e( 'إغلاق', 'olama-registration' ); ?></button>
                                    </form>
                                <?php elseif ( $session->status === 'pending_review' ) : ?>
                                    <form method="post" class="os-cash-session-review-form">
                                        <?php wp_nonce_field( 'olama_cash_sessions_action', 'olama_cash_sessions_nonce' ); ?>
                                        <input type="hidden" name="cash_action" value="review_session">
                                        <input type="hidden" name="session_id" value="<?php echo esc_attr( $session->id ); ?>">
                                        <?php if ( abs( (float) $difference ) >= 0.01 ) : ?>
                                            <input type="text" name="notes" required placeholder="<?php esc_attr_e( 'ملاحظة المراجع مطلوبة', 'olama-registration' ); ?>">
                                        <?php endif; ?>
                                        <button type="submit" name="decision" value="approve" class="button button-small button-primary"><?php esc_html_e( 'اعتماد', 'olama-registration' ); ?></button>
                                        <button type="submit" name="decision" value="reject" class="button button-small"><?php esc_html_e( 'رفض', 'olama-registration' ); ?></button>
                                    </form>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
