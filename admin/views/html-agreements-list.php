<?php
/**
 * View: Agreements List
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;



?>
<div class="olama-reg-wrap">
    <div class="olama-reg-page-header">
        <h1 class="wp-heading-inline" style="margin:0;"><?php esc_html_e( 'العقود', 'olama-registration' ); ?></h1>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=olama-registration-agreements&action=new' ) ); ?>" class="olama-reg-btn olama-reg-btn--primary">
            <span class="dashicons dashicons-plus-alt2" style="margin-top:4px;"></span> <?php esc_html_e( 'إضافة عقد جديد', 'olama-registration' ); ?>
        </a>
    </div>

    <nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom: 20px;">
        <a href="?page=olama-registration-agreements&tab=agreements" class="nav-tab nav-tab-active">
            <?php esc_html_e( 'العقود', 'olama-registration' ); ?>
        </a>
        <a href="?page=olama-registration-agreements&tab=templates" class="nav-tab">
            <?php esc_html_e( 'نماذج العقود', 'olama-registration' ); ?>
        </a>
    </nav>

    <div class="olama-reg-section" style="margin-top: 20px;">
        <form method="get">
            <input type="hidden" name="page" value="olama-registration-agreements">
            
            <div class="olama-reg-filter-bar">
                <div class="olama-reg-filter-form">
                    <select name="status">
                        <option value=""><?php esc_html_e( 'جميع الحالات', 'olama-registration' ); ?></option>
                        <option value="draft" <?php selected( $_REQUEST['status'] ?? '', 'draft' ); ?>><?php esc_html_e( 'مسودة', 'olama-registration' ); ?></option>
                        <option value="completed" <?php selected( $_REQUEST['status'] ?? '', 'completed' ); ?>><?php esc_html_e( 'مكتمل', 'olama-registration' ); ?></option>
                        <option value="cancelled" <?php selected( $_REQUEST['status'] ?? '', 'cancelled' ); ?>><?php esc_html_e( 'ملغي', 'olama-registration' ); ?></option>
                    </select>
                    
                    <input type="text" name="activity_type" placeholder="<?php esc_attr_e( 'النشاط (مثل النادي الصيفي)', 'olama-registration' ); ?>" value="<?php echo esc_attr( wp_unslash( $_REQUEST['activity_type'] ?? '' ) ); ?>" />
                    
                    <button type="submit" class="olama-reg-btn olama-reg-btn--primary">
                        <?php esc_attr_e( 'تصفية', 'olama-registration' ); ?>
                    </button>
                </div>
            </div>
            
            <?php echo Olama_Reg_Agreement_Renderer::render_agreements_list( '', 0, 'admin' ); ?>
        </form>
    </div>
</div>
