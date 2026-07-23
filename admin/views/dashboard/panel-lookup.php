<?php
/**
 * Hub Stage 2 — Customer Lookup Panel
 *
 * Search input shell. AJAX wiring happens in Phase 2 (os-hub.js SearchModule).
 * The panel shows:
 *   - Back link to Stage 1
 *   - Current type badge
 *   - Search input + button
 *   - Results listbox (populated by JS)
 *   - Recent lookups section (populated from localStorage by JS)
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="os-hub-lookup-screen">

    <!-- Back navigation -->
    <div class="os-hub-lookup-screen__nav" style="display:flex; justify-content:space-between; align-items:center; width:100%;">
        <div style="display:flex; gap:12px; align-items:center;">
            <button type="button"
                    class="button os-hub-back-btn"
                    id="os-hub-back-to-type"
                    aria-label="<?php esc_attr_e( 'العودة لاختيار نوع العميل', 'olama-registration' ); ?>">
                <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
                <?php _e( 'تغيير نوع العميل', 'olama-registration' ); ?>
            </button>

            <span class="os-hub-type-badge" id="os-hub-current-type-badge" aria-live="polite">
                <!-- Populated by JS: shows "عائلة" or "عميل خارجي" badge -->
            </span>
        </div>

        <!-- Add New Customer Button (Visible only when currentType is 'external') -->
        <button type="button"
                id="cust_btn_add_new"
                class="button button-primary os-hub-add-cust-btn"
                style="display:none; background:#d97706; border-color:#d97706; color:#fff; align-items:center; gap:6px; font-weight:bold; height:34px; padding:0 16px; border-radius:6px; border:none; cursor:pointer;">
            <span class="dashicons dashicons-plus" style="font-size:16px; width:16px; height:16px; margin-top:3px;"></span>
            <?php _e( 'إضافة عميل جديد', 'olama-registration' ); ?>
        </button>
    </div>

    <!-- Search form -->
    <div class="os-hub-search-wrap">
        <div class="os-hub-search-box" role="search">
            <span class="dashicons dashicons-search os-hub-search-icon" aria-hidden="true"></span>
            <input type="search"
                   id="os-hub-search-input"
                   class="os-hub-search-input"
                   placeholder="<?php esc_attr_e( 'ابحث باسم العائلة أو رقم الملف أو رقم الهاتف...', 'olama-registration' ); ?>"
                   autocomplete="off"
                   aria-label="<?php esc_attr_e( 'البحث عن عميل', 'olama-registration' ); ?>"
                   aria-controls="os-hub-results-list"
                   aria-autocomplete="list"
                   dir="rtl" />
            <button type="button"
                    id="os-hub-search-btn"
                    class="button button-primary os-hub-search-btn"
                    aria-label="<?php esc_attr_e( 'بحث', 'olama-registration' ); ?>">
                <?php _e( 'بحث', 'olama-registration' ); ?>
            </button>
        </div>

        <!-- Search status / spinner -->
        <div class="os-hub-search-status" id="os-hub-search-status" aria-live="polite" role="status">
            <!-- Populated by JS: spinner, "no results", error messages -->
        </div>

        <!-- Results list -->
        <ul id="os-hub-results-list"
            class="os-hub-results"
            role="listbox"
            aria-label="<?php esc_attr_e( 'نتائج البحث', 'olama-registration' ); ?>"
            tabindex="-1">
            <!-- Populated by JS -->
        </ul>
    </div><!-- .os-hub-search-wrap -->

    <!-- Recent lookups (populated from localStorage by JS) -->
    <div class="os-hub-recent" id="os-hub-recent-section" aria-label="<?php esc_attr_e( 'آخر عمليات البحث', 'olama-registration' ); ?>">
        <h3 class="os-hub-recent__title">
            <span class="dashicons dashicons-backup" aria-hidden="true"></span>
            <?php _e( 'آخر عمليات البحث', 'olama-registration' ); ?>
        </h3>
        <ul class="os-hub-recent__list" id="os-hub-recent-list" role="list">
            <!-- Populated by JS from localStorage -->
        </ul>
    </div>

</div><!-- .os-hub-lookup-screen -->
