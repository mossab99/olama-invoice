<?php
/**
 * Plugin Name: Olama Family Billing
 * Plugin URI:  https://olama.online/olama-registration
 * Description: Family-centric billing and invoicing system for Olama School. Family and student data is provided by Olama Core.
 * Version:     2.2.0
 * Author:      د. مصعب الحنيطي
 * Author URI:  https://olama.online
 * Text Domain: olama-registration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Constants ────────────────────────────────────────────────────────────────
define( 'OLAMA_REG_VERSION',             '2.2.0' );
define( 'OLAMA_REG_MIN_SCHOOL_VERSION',  '2.3.9' );
define( 'OLAMA_REG_MIN_CORE_VERSION',    '0.6.3' );
define( 'OLAMA_REG_MIN_USERS_VERSION',   '0.6.1' );
define( 'OLAMA_REG_PATH',               plugin_dir_path( __FILE__ ) );
define( 'OLAMA_REG_URL',                plugin_dir_url( __FILE__ ) );
define( 'OLAMA_REG_FILE',               __FILE__ );

// ── Dependency Guard ─────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'olama_reg_init', 5 );

function olama_reg_init() {

    // Family and student identity records have a single source of truth.
    if (
        ! function_exists( 'olama_core' )
        || ! defined( 'OLAMA_CORE_VERSION' )
        || version_compare( OLAMA_CORE_VERSION, OLAMA_REG_MIN_CORE_VERSION, '<' )
    ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . '<strong>Olama Family Billing:</strong> '
                . sprintf(
                    esc_html__( 'Requires Olama Core version %s or newer as the exclusive family and student source.', 'olama-registration' ),
                    esc_html( OLAMA_REG_MIN_CORE_VERSION )
                )
                . '</p></div>';
        } );
        return;
    }

    if (
        ! function_exists( 'olama_users_register_module' )
        || ! defined( 'OLAMA_USERS_VERSION' )
        || version_compare( OLAMA_USERS_VERSION, OLAMA_REG_MIN_USERS_VERSION, '<' )
    ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . '<strong>Olama Family Billing:</strong> '
                . sprintf(
                    esc_html__( 'Requires OLAMA Users version %s or newer for centralized roles and capabilities.', 'olama-registration' ),
                    esc_html( OLAMA_REG_MIN_USERS_VERSION )
                )
                . '</p></div>';
        } );
        return;
    }

    // 1. Check parent plugin class exists
    if ( ! class_exists( 'Olama_School_DB' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . '<strong>Olama Registration:</strong> '
                . esc_html__( 'Requires the Olama School System plugin to be installed and active.', 'olama-registration' )
                . '</p></div>';
        } );
        return;
    }

    // 2. Check minimum version
    if ( defined( 'OLAMA_SCHOOL_VERSION' ) && version_compare( OLAMA_SCHOOL_VERSION, OLAMA_REG_MIN_SCHOOL_VERSION, '<' ) ) {
        add_action( 'admin_notices', function () {
            /* translators: %s = minimum required version */
            echo '<div class="notice notice-error"><p>'
                . sprintf(
                    esc_html__( 'Olama Registration requires Olama School System version %s or higher.', 'olama-registration' ),
                    esc_html( OLAMA_REG_MIN_SCHOOL_VERSION )
                )
                . '</p></div>';
        } );
        return;
    }

    // ── Load includes ────────────────────────────────────────────────────────
    require_once OLAMA_REG_PATH . 'includes/class-reg-status-labels.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-id-generator.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-activator.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-access.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-academic-year-context.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-core-gateway.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-family.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-student.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-customer.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-child.php';
    
    // Agreements
    require_once OLAMA_REG_PATH . 'includes/class-reg-agreement-participants.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-agreement.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-agreement-policy.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-agreement-amendment.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-agreement-fees.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-agreement-clauses.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-clause-bank.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-agreement-invoice.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-agreement-templates.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-contract-renderer.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-agreement-renderer.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-financial.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-billing-fees.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-billing-invoice.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-payment-policy.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-financial-account.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-cash-bank-movement.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-cash-session.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-payment-method-details.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-billing-payment.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-billing-reports.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-receipt-repair.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-settlement.php';
    require_once OLAMA_REG_PATH . 'includes/class-reg-family-financial-summary.php';

    add_action(
        'olama_users_register_modules',
        [ 'Olama_Reg_Access', 'register_with_olama_users' ]
    );

    // ── Load admin ───────────────────────────────────────────────────────────
    if ( is_admin() ) {
        require_once OLAMA_REG_PATH . 'admin/class-reg-ajax.php';
        require_once OLAMA_REG_PATH . 'admin/class-reg-family-table.php';
        require_once OLAMA_REG_PATH . 'admin/class-reg-student-table.php';
        require_once OLAMA_REG_PATH . 'admin/class-reg-admin.php';
        new Olama_Reg_Admin();
        new Olama_Reg_Ajax();
    }

    // Schema version check — run migrations if version changed
    $installed = get_option( 'olama_reg_version', '0' );
    if ( version_compare( $installed, OLAMA_REG_VERSION, '<' ) ) {
        Olama_Reg_Activator::upgrade();
    }

    // Versioned migrations check on admin_init (handles code updates dynamically)
    add_action( 'admin_init', function() {
        $target_version = '3.2.0'; // Cash-session physical-count reconciliation controls.
        $installed_version = get_option( 'olama_registration_db_version', '0' );

        if ( version_compare( $installed_version, $target_version, '<' ) ) {
            if ( get_transient( 'olama_registration_db_upgrade_lock' ) ) {
                return;
            }

            set_transient( 'olama_registration_db_upgrade_lock', 1, 5 * MINUTE_IN_SECONDS );

            try {
                Olama_Reg_Activator::upgrade();
                update_option( 'olama_registration_db_version', $target_version );
            } finally {
                delete_transient( 'olama_registration_db_upgrade_lock' );
            }
        }
    });

    // ── Load translations ────────────────────────────────────────────────────
    load_plugin_textdomain(
        'olama-registration',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}

// ── Activation hook ──────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'olama_reg_activate' );

function olama_reg_activate() {
    ob_start();
    try {
        // Require files manually — plugins_loaded hasn't fired yet during activation
        if ( ! class_exists( 'Olama_Reg_Activator' ) ) {
            require_once OLAMA_REG_PATH . 'includes/class-reg-activator.php';
        }
        Olama_Reg_Activator::activate();
        flush_rewrite_rules();
    } catch ( Exception $e ) {
        error_log( 'Olama Registration Activation Error: ' . $e->getMessage() );
    }
    if ( ob_get_length() > 0 ) {
        ob_end_clean();
    }
}

// ── Deactivation hook ────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, 'olama_reg_deactivate' );

function olama_reg_deactivate() {
    flush_rewrite_rules();
}

// ── Temporary DB Cleanup Script ──────────────────────────────────────────────
add_action( 'admin_init', function() {
    if ( isset( $_GET['force_db_create'] ) ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'olama-registration' ), 403 );
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        require_once OLAMA_REG_PATH . 'includes/class-reg-activator.php';
        Olama_Reg_Activator::run_migrations();
        wp_die( '<h1>تم تحديث قاعدة البيانات</h1><p>تم إنشاء الجداول المفقودة بنجاح.</p>', 'تم' );
    }

    if ( ! isset( $_GET['olama_db_cleanup'] ) ) return;
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Unauthorized.', 'olama-registration' ), 403 );
    }
    
    global $wpdb;
    
    // Recalculate totals for all invoices to fix status issues
    $invoices = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}olama_invoices" );
    foreach ( $invoices as $inv_id ) {
        Olama_Reg_Billing_Invoice::recalculate_totals( (int) $inv_id );
    }

    wp_die( '<h1>تم تحديث حالة الفواتير بنجاح!</h1><p>تم إعادة احتساب المجاميع وتحديث حالة الفواتير بناءً على السندات الموجودة.</p>', 'تم التحديث' );
});
