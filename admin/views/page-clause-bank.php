<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$action = sanitize_text_field( $_POST['action'] ?? $_GET['action'] ?? '' );
$id     = (int) ( $_GET['id'] ?? 0 );

// Handle form submission
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['clause_nonce'] ) ) {
    if ( wp_verify_nonce( $_POST['clause_nonce'], 'save_clause' ) ) {
        $post_id = (int) $_POST['id'];
        $data = [
            'title'       => $_POST['title'],
            'clause_text' => $_POST['clause_text'],
            'is_active'   => isset( $_POST['is_active'] ) ? 1 : 0,
        ];
        
        if ( $post_id > 0 ) {
            Olama_Reg_Clause_Bank::update( $post_id, $data );
            echo '<div class="olama-reg-notice olama-reg-notice--success is-dismissible"><p style="margin:0;">' . esc_html__( 'تم تحديث البند بنجاح.', 'olama-registration' ) . '</p></div>';
        } else {
            Olama_Reg_Clause_Bank::add( $data );
            echo '<div class="olama-reg-notice olama-reg-notice--success is-dismissible"><p style="margin:0;">' . esc_html__( 'تمت إضافة البند بنجاح.', 'olama-registration' ) . '</p></div>';
        }
    }
}

// Handle delete
if ( $action === 'delete' && $id > 0 && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'delete_clause_' . $id ) ) {
    Olama_Reg_Clause_Bank::delete( $id );
    echo '<div class="olama-reg-notice olama-reg-notice--success is-dismissible"><p style="margin:0;">' . esc_html__( 'تم حذف البند.', 'olama-registration' ) . '</p></div>';
}

$edit_mode = false;
$edit_clause = null;
if ( $action === 'edit' && $id > 0 ) {
    $edit_mode = true;
    $edit_clause = Olama_Reg_Clause_Bank::get( $id );
}

$clauses = Olama_Reg_Clause_Bank::get_all();
?>
<div class="olama-reg-wrap">
    <div class="olama-reg-page-header">
        <h1 class="wp-heading-inline" style="margin:0;"><?php esc_html_e( 'العقود', 'olama-registration' ); ?></h1>
        <?php if ( $edit_mode ) : ?>
            <a href="?page=olama-registration-agreements&tab=clauses" class="olama-reg-btn olama-reg-btn--primary">
                <span class="dashicons dashicons-plus-alt2" style="margin-top:4px;"></span> <?php esc_html_e( 'إضافة جديد', 'olama-registration' ); ?>
            </a>
        <?php endif; ?>
    </div>

    <nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom: 20px;">
        <a href="?page=olama-registration-agreements&tab=agreements" class="nav-tab">
            <?php esc_html_e( 'العقود', 'olama-registration' ); ?>
        </a>
        <a href="?page=olama-registration-agreements&tab=templates" class="nav-tab">
            <?php esc_html_e( 'نماذج العقود', 'olama-registration' ); ?>
        </a>
        <a href="?page=olama-registration-agreements&tab=clauses" class="nav-tab nav-tab-active">
            <?php esc_html_e( 'بنود العقود العامة', 'olama-registration' ); ?>
        </a>
    </nav>

    <div id="col-container" class="wp-clearfix olama-clause-bank-stack">
        <div id="col-left">
            <div class="col-wrap">
                <div class="olama-reg-section">
                    <h3 class="olama-reg-section-title"><?php echo $edit_mode ? esc_html__( 'تعديل البند', 'olama-registration' ) : esc_html__( 'إضافة بند جديد', 'olama-registration' ); ?></h3>
                    <form method="post" action="?page=olama-registration-agreements&tab=clauses" style="padding: 20px;">
                        <?php wp_nonce_field( 'save_clause', 'clause_nonce' ); ?>
                        <input type="hidden" name="id" value="<?php echo $edit_mode ? esc_attr( $edit_clause->id ) : '0'; ?>">
                        
                        <div class="olama-reg-field" style="margin-bottom: 15px;">
                            <label for="title"><?php esc_html_e( 'عنوان البند', 'olama-registration' ); ?></label>
                            <input type="text" name="title" id="title" value="<?php echo $edit_mode ? esc_attr( $edit_clause->title ) : ''; ?>" required>
                            <p style="margin:5px 0 0; color:#888; font-size:12px;"><?php esc_html_e( 'اسم يسهل التعرف عليه (مثل: شرط الانسحاب)', 'olama-registration' ); ?></p>
                        </div>

                        <div class="olama-reg-field" style="margin-bottom: 15px;">
                            <label for="clause_text"><?php esc_html_e( 'نص البند', 'olama-registration' ); ?></label>
                            <textarea name="clause_text" id="clause_text" rows="5" required><?php echo $edit_mode ? esc_textarea( $edit_clause->clause_text ) : ''; ?></textarea>
                            <p style="margin:5px 0 0; color:#888; font-size:12px;"><?php esc_html_e( 'نص الشرط الذي سيظهر في العقد.', 'olama-registration' ); ?></p>
                        </div>

                        <div class="olama-reg-field olama-reg-field--checkbox" style="margin-bottom: 25px; justify-content:flex-start;">
                            <label for="is_active">
                                <input type="checkbox" name="is_active" id="is_active" value="1" <?php checked( ! $edit_mode || $edit_clause->is_active ); ?>>
                                <?php esc_html_e( 'مفعل', 'olama-registration' ); ?>
                            </label>
                        </div>

                        <div style="display:flex; gap:10px;">
                            <button type="submit" name="submit" class="olama-reg-btn olama-reg-btn--primary">
                                <?php echo $edit_mode ? esc_html__( 'تحديث', 'olama-registration' ) : esc_html__( 'إضافة', 'olama-registration' ); ?>
                            </button>
                            <?php if ( $edit_mode ) : ?>
                                <a href="?page=olama-registration-agreements&tab=clauses" class="button" style="line-height:2.2;"><?php esc_html_e( 'إلغاء', 'olama-registration' ); ?></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div id="col-right">
            <div class="col-wrap">
                <div class="olama-reg-section">
                    <h3 class="olama-reg-section-title">
                        <?php esc_html_e( 'قائمة بنود العقود', 'olama-registration' ); ?>
                    </h3>
                    <div class="olama-reg-table-wrap">
                        <table class="olama-reg-fin-table">
                            <thead>
                                <tr>
                                    <th style="width: 30%;"><?php esc_html_e( 'العنوان', 'olama-registration' ); ?></th>
                                    <th style="width: 50%;"><?php esc_html_e( 'النص', 'olama-registration' ); ?></th>
                                    <th style="width: 20%;"><?php esc_html_e( 'الحالة', 'olama-registration' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( empty( $clauses ) ) : ?>
                                    <tr>
                                        <td colspan="3"><?php esc_html_e( 'لا يوجد بنود مسجلة.', 'olama-registration' ); ?></td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ( $clauses as $c ) : ?>
                                        <tr>
                                            <td>
                                                <strong>
                                                    <a href="?page=olama-registration-agreements&tab=clauses&action=edit&id=<?php echo $c->id; ?>" style="color:#1565C0; text-decoration:none;">
                                                        <?php echo esc_html( $c->title ); ?>
                                                    </a>
                                                </strong>
                                                <div class="row-actions">
                                                    <span class="edit">
                                                        <a href="?page=olama-registration-agreements&tab=clauses&action=edit&id=<?php echo $c->id; ?>">
                                                            <?php esc_html_e( 'تحرير', 'olama-registration' ); ?>
                                                        </a> |
                                                    </span>
                                                    <span class="delete">
                                                        <a href="<?php echo wp_nonce_url( '?page=olama-registration-agreements&tab=clauses&action=delete&id=' . $c->id, 'delete_clause_' . $c->id ); ?>" onclick="return confirm('<?php esc_attr_e( 'تأكيد الحذف؟', 'olama-registration' ); ?>');" class="submitdelete" style="color:#a00;">
                                                            <?php esc_html_e( 'حذف', 'olama-registration' ); ?>
                                                        </a>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo wp_trim_words( $c->clause_text, 15 ); ?>
                                            </td>
                                            <td>
                                                <?php if ( $c->is_active ) : ?>
                                                    <span class="olama-reg-badge olama-reg-badge--active"><?php esc_html_e( 'مفعل', 'olama-registration' ); ?></span>
                                                <?php else : ?>
                                                    <span class="olama-reg-badge olama-reg-badge--inactive"><?php esc_html_e( 'معطل', 'olama-registration' ); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
