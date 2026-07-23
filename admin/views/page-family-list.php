<?php
/**
 * Read-only Olama Core family directory.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$table = new Olama_Reg_Family_Table();
$table->prepare_items();
?>

<div id="olama-reg-notice" class="olama-reg-notice" style="display:none;"></div>

<p class="description">
    <?php esc_html_e( 'بيانات العائلات للعرض فقط ومصدرها Olama Core. يتم تعديلها من لوحة العائلة في Olama Core.', 'olama-registration' ); ?>
</p>

<form method="get" class="olama-reg-search-form">
    <input type="hidden" name="page" value="olama-registration-contacts">
    <input type="hidden" name="view" value="families">
    <?php $table->search_box( __( 'بحث', 'olama-registration' ), 'olama-reg-search' ); ?>
</form>

<?php $table->views(); ?>
<?php $table->display(); ?>
