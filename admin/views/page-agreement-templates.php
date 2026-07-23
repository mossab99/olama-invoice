<?php
/**
 * Versioned agreement-template administration.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'Unauthorized', 'olama-registration' ) );
}

$action = sanitize_key( $_GET['action'] ?? '' );
$id     = absint( $_GET['id'] ?? 0 );
$editing = in_array( $action, [ 'new', 'edit' ], true );

if ( $editing ) {
    $is_new = $action === 'new' || $id === 0;
    $template = $is_new
        ? (object) [
            'id'               => 0,
            'template_key'     => '',
            'activity_type'    => 'kindergarten',
            'name'             => '',
            'description'      => '',
            'contract_content' => Olama_Reg_Contract_Renderer::default_registration_content(),
            'version'          => 1,
            'is_default'       => 0,
            'is_active'        => 1,
        ]
        : Olama_Reg_Agreement_Templates::get( $id );

    if ( ! $template ) {
        wp_die( esc_html__( 'نموذج العقد غير موجود.', 'olama-registration' ) );
    }

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset( $_POST['template_nonce'] )
        && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['template_nonce'] ) ), 'save_agr_template' )
    ) {
        $data = [
            'template_key'     => sanitize_key( wp_unslash( $_POST['template_key'] ?? '' ) ),
            'activity_type'    => sanitize_text_field( wp_unslash( $_POST['activity_type'] ?? '' ) ),
            'name'             => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
            'description'      => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'contract_content' => wp_kses_post( wp_unslash( $_POST['contract_content'] ?? '' ) ),
            'is_default'       => isset( $_POST['is_default'] ) ? 1 : 0,
            'is_active'        => isset( $_POST['is_active'] ) ? 1 : 0,
        ];

        if ( $data['name'] === '' || trim( wp_strip_all_tags( $data['contract_content'] ) ) === '' ) {
            $save_error = __( 'اسم النموذج ومحتوى العقد مطلوبان.', 'olama-registration' );
        } else {
            $result = $is_new
                ? Olama_Reg_Agreement_Templates::create( $data )
                : Olama_Reg_Agreement_Templates::update( $id, $data );

            if ( is_wp_error( $result ) ) {
                $save_error = $result->get_error_message();
            } else {
                $saved_id = $is_new ? (int) $result : $id;
                wp_safe_redirect( admin_url( 'admin.php?page=olama-registration-agreements&tab=templates&action=edit&id=' . $saved_id . '&updated=1' ) );
                exit;
            }
        }
    }

    $tokens = [
        '{{academy.legal_name}}', '{{academy.address}}', '{{academic_year}}',
        '{{contract.number}}', '{{contract.date}}', '{{contract.start_date}}', '{{contract.end_date}}',
        '{{guardian.full_name}}', '{{guardian.national_id}}', '{{guardian.relationship}}',
        '{{guardian.primary_phone}}', '{{guardian.address}}',
        '{{student.full_name}}', '{{student.national_id}}', '{{student.birth_date}}',
        '{{student.gender}}', '{{student.grade}}', '{{student.section}}',
        '{{policy.payment_grace_days}}', '{{component.fee_table}}',
        '{{component.installment_schedule}}', '{{component.photo_consent}}',
    ];
    ?>
    <div class="olama-reg-wrap">
        <div class="olama-reg-page-header">
            <h1 class="wp-heading-inline" style="margin:0;">
                <?php echo $is_new ? esc_html__( 'إضافة نموذج عقد', 'olama-registration' ) : esc_html__( 'تعديل نموذج العقد', 'olama-registration' ); ?>
            </h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-registration-agreements&tab=templates' ) ); ?>" class="olama-reg-back-btn">
                <span class="dashicons dashicons-arrow-right-alt2"></span>
                <?php esc_html_e( 'العودة إلى النماذج', 'olama-registration' ); ?>
            </a>
        </div>

        <nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:20px;">
            <a href="?page=olama-registration-agreements&tab=agreements" class="nav-tab"><?php esc_html_e( 'العقود', 'olama-registration' ); ?></a>
            <a href="?page=olama-registration-agreements&tab=templates" class="nav-tab nav-tab-active"><?php esc_html_e( 'نماذج العقود', 'olama-registration' ); ?></a>
        </nav>

        <?php if ( isset( $_GET['updated'] ) ) : ?>
            <div class="olama-reg-notice olama-reg-notice--success"><p><?php esc_html_e( 'تم حفظ نموذج العقد بنجاح.', 'olama-registration' ); ?></p></div>
        <?php endif; ?>
        <?php if ( ! empty( $save_error ) ) : ?>
            <div class="olama-reg-notice olama-reg-notice--error"><p><?php echo esc_html( $save_error ); ?></p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( 'save_agr_template', 'template_nonce' ); ?>

            <div class="olama-reg-section">
                <h3 class="olama-reg-section-title"><?php esc_html_e( 'بيانات النموذج', 'olama-registration' ); ?></h3>
                <div class="olama-reg-grid">
                    <div class="olama-reg-field olama-reg-field--required">
                        <label for="template-name"><?php esc_html_e( 'اسم النموذج', 'olama-registration' ); ?></label>
                        <input id="template-name" type="text" name="name" value="<?php echo esc_attr( $template->name ); ?>" required>
                    </div>
                    <div class="olama-reg-field">
                        <label for="template-key"><?php esc_html_e( 'المعرف البرمجي', 'olama-registration' ); ?></label>
                        <input id="template-key" type="text" name="template_key" value="<?php echo esc_attr( $template->template_key ); ?>" placeholder="kindergarten-registration">
                    </div>
                    <div class="olama-reg-field">
                        <label for="template-activity"><?php esc_html_e( 'النشاط', 'olama-registration' ); ?></label>
                        <select id="template-activity" name="activity_type" required>
                            <option value="kindergarten" <?php selected( $template->activity_type, 'kindergarten' ); ?>><?php esc_html_e( 'رياض الأطفال', 'olama-registration' ); ?></option>
                            <option value="school" <?php selected( $template->activity_type, 'school' ); ?>><?php esc_html_e( 'المدرسة', 'olama-registration' ); ?></option>
                            <option value="summer_club" <?php selected( $template->activity_type, 'summer_club' ); ?>><?php esc_html_e( 'النادي الصيفي', 'olama-registration' ); ?></option>
                            <option value="other" <?php selected( $template->activity_type, 'other' ); ?>><?php esc_html_e( 'أخرى', 'olama-registration' ); ?></option>
                        </select>
                    </div>
                    <div class="olama-reg-field">
                        <label><?php esc_html_e( 'الإصدار', 'olama-registration' ); ?></label>
                        <input type="text" value="<?php echo esc_attr( (int) $template->version ); ?>" readonly>
                    </div>
                    <div class="olama-reg-field olama-reg-field--full">
                        <label for="template-description"><?php esc_html_e( 'الوصف', 'olama-registration' ); ?></label>
                        <textarea id="template-description" name="description" rows="2"><?php echo esc_textarea( $template->description ); ?></textarea>
                    </div>
                    <div class="olama-reg-field olama-reg-field--checkbox">
                        <label><input type="checkbox" name="is_default" value="1" <?php checked( $template->is_default, 1 ); ?>> <?php esc_html_e( 'النموذج الافتراضي', 'olama-registration' ); ?></label>
                    </div>
                    <div class="olama-reg-field olama-reg-field--checkbox">
                        <label><input type="checkbox" name="is_active" value="1" <?php checked( $template->is_active, 1 ); ?>> <?php esc_html_e( 'مفعل', 'olama-registration' ); ?></label>
                    </div>
                </div>
            </div>

            <div class="olama-reg-section">
                <h3 class="olama-reg-section-title"><?php esc_html_e( 'محتوى العقد', 'olama-registration' ); ?></h3>
                <div style="padding:20px;">
                    <p><?php esc_html_e( 'يمكن استخدام HTML بسيط والمتغيرات المعتمدة. يتم حفظ نسخة نهائية مستقلة عند إكمال العقد.', 'olama-registration' ); ?></p>
                    <textarea name="contract_content" rows="42" dir="rtl" style="width:100%;font-family:Tahoma,Arial,sans-serif;line-height:1.8;" required><?php echo esc_textarea( $template->contract_content ); ?></textarea>
                </div>
            </div>

            <div class="olama-reg-section">
                <h3 class="olama-reg-section-title"><?php esc_html_e( 'المتغيرات المتاحة', 'olama-registration' ); ?></h3>
                <div style="padding:18px;display:flex;flex-wrap:wrap;gap:8px;">
                    <?php foreach ( $tokens as $token ) : ?>
                        <code style="direction:ltr;background:#f5f6fa;padding:5px 8px;border-radius:4px;"><?php echo esc_html( $token ); ?></code>
                    <?php endforeach; ?>
                    <code style="direction:ltr;background:#fff4db;padding:5px 8px;border-radius:4px;">{{#if services.transportation}} ... {{/if}}</code>
                </div>
            </div>

            <div class="olama-reg-form-actions">
                <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                    <span class="dashicons dashicons-saved"></span>
                    <?php esc_html_e( 'حفظ النموذج', 'olama-registration' ); ?>
                </button>
            </div>
        </form>
    </div>
    <?php
    return;
}

$templates = Olama_Reg_Agreement_Templates::get_list();
?>
<div class="olama-reg-wrap">
    <div class="olama-reg-page-header">
        <h1 class="wp-heading-inline" style="margin:0;"><?php esc_html_e( 'نماذج العقود', 'olama-registration' ); ?></h1>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-registration-agreements&tab=templates&action=new' ) ); ?>" class="olama-reg-btn olama-reg-btn--primary">
            <span class="dashicons dashicons-plus-alt2"></span>
            <?php esc_html_e( 'إضافة نموذج جديد', 'olama-registration' ); ?>
        </a>
    </div>

    <nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:20px;">
        <a href="?page=olama-registration-agreements&tab=agreements" class="nav-tab"><?php esc_html_e( 'العقود', 'olama-registration' ); ?></a>
        <a href="?page=olama-registration-agreements&tab=templates" class="nav-tab nav-tab-active"><?php esc_html_e( 'نماذج العقود', 'olama-registration' ); ?></a>
    </nav>

    <div class="olama-reg-section">
        <div class="olama-reg-table-wrap">
            <table class="olama-reg-fin-table">
                <thead><tr>
                    <th><?php esc_html_e( 'اسم النموذج', 'olama-registration' ); ?></th>
                    <th><?php esc_html_e( 'النشاط', 'olama-registration' ); ?></th>
                    <th><?php esc_html_e( 'الإصدار', 'olama-registration' ); ?></th>
                    <th><?php esc_html_e( 'الحالة', 'olama-registration' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( ! $templates ) : ?>
                    <tr><td colspan="4"><?php esc_html_e( 'لا توجد نماذج عقود.', 'olama-registration' ); ?></td></tr>
                <?php else : foreach ( $templates as $row ) : ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-registration-agreements&tab=templates&action=edit&id=' . (int) $row->id ) ); ?>"><?php echo esc_html( $row->name ); ?></a></strong>
                            <?php if ( $row->is_default ) : ?><span class="olama-reg-badge olama-reg-badge--active"><?php esc_html_e( 'افتراضي', 'olama-registration' ); ?></span><?php endif; ?>
                            <p style="margin:4px 0 0;color:#667085;"><?php echo esc_html( $row->description ); ?></p>
                        </td>
                        <td><?php echo esc_html( $row->activity_type ); ?></td>
                        <td><?php echo esc_html( (int) $row->version ); ?></td>
                        <td><?php echo $row->is_active ? esc_html__( 'مفعل', 'olama-registration' ) : esc_html__( 'غير مفعل', 'olama-registration' ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
