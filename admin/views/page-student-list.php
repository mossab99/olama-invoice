<?php
/**
 * Student List Partial
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$table = new Olama_Reg_Student_Table();
$table->prepare_items();
?>

<div id="olama-reg-notice" class="olama-reg-notice" style="display:none;"></div>

<p class="description">
    <?php esc_html_e( 'بيانات الطلاب للعرض فقط ومصدرها Olama Core. يتم تعديلها من بطاقة الطالب في Olama Core.', 'olama-registration' ); ?>
</p>

<form method="get">
    <input type="hidden" name="page" value="olama-registration-contacts">
    <input type="hidden" name="view" value="students">
    <?php $table->search_box( __( 'بحث باسم الطالب أو رقمه', 'olama-registration' ), 'student-search' ); ?>
</form>

<?php $table->display(); ?>
