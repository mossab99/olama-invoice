<?php
/**
 * Admin — menu registration, asset enqueue, page routing
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Admin {

    public function __construct() {
        add_action( 'admin_menu',             [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',             [ $this, 'handle_print_actions' ] );
        add_filter( 'admin_footer_text',      '__return_empty_string' );
        add_filter( 'update_footer',          '__return_empty_string', 11 );

    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public function register_menu(): void {

        add_menu_page(
            __( 'Olama Billing', 'olama-registration' ),
            __( 'المالية', 'olama-registration' ),
            'olama_access_registration',
            'olama-registration',
            [ $this, 'render_hub' ],
            'dashicons-money-alt',
            26
        );

        add_submenu_page(
            'olama-registration',
            __( 'Customer Hub', 'olama-registration' ),
            __( 'لوحة الخدمات', 'olama-registration' ),
            'olama_manage_registration_families',
            'olama-registration',
            [ $this, 'render_hub' ]
        );

        add_submenu_page(
            'olama-registration',
            __( 'Contacts', 'olama-registration' ),
            __( 'جهات الاتصال', 'olama-registration' ),
            'olama_manage_registration_families',
            'olama-registration-contacts',
            [ $this, 'render_families' ]
        );

        add_submenu_page(
            'olama-registration',
            __( 'Fee Templates', 'olama-registration' ),
            __( 'نماذج الرسوم', 'olama-registration' ),
            'olama_manage_registration_fees',
            'olama-registration-fees',
            [ $this, 'render_fees' ]
        );

        add_submenu_page(
            'olama-registration',
            __( 'Agreements', 'olama-registration' ),
            __( 'العقود', 'olama-registration' ),
            'olama_manage_registration_agreements',
            'olama-registration-agreements',
            [ $this, 'render_agreements' ]
        );

        // Agreement Templates submenu removed, now a tab under agreements

        // Clause bank submenu removed, now a tab under agreement-templates
        add_submenu_page(
            'olama-registration',
            __( 'Invoices', 'olama-registration' ),
            __( 'الفواتير', 'olama-registration' ),
            'olama_manage_registration_invoices',
            'olama-registration-invoices',
            [ $this, 'render_invoices' ]
        );

        add_submenu_page(
            'olama-registration',
            __( 'Payments', 'olama-registration' ),
            __( 'السندات والمدفوعات', 'olama-registration' ),
            'olama_manage_registration_payments',
            'olama-registration-payments',
            [ $this, 'render_payments' ]
        );

        add_submenu_page(
            'olama-registration',
            __( 'Financial Accounts', 'olama-registration' ),
            __( 'الحسابات المالية', 'olama-registration' ),
            'olama_manage_financial_accounts',
            'olama-registration-financial-accounts',
            [ $this, 'render_financial_accounts' ]
        );

        add_submenu_page(
            'olama-registration',
            __( 'Custom Payments', 'olama-registration' ),
            __( 'دفعات مخصصة', 'olama-registration' ),
            'olama_manage_registration_payments',
            'olama-registration-custom-payments',
            [ $this, 'render_custom_payments' ]
        );

        add_submenu_page(
            'olama-registration',
            __( 'Billing Reports', 'olama-registration' ),
            __( 'التقارير المالية', 'olama-registration' ),
            'olama_manage_registration_reports',
            'olama-registration-reports',
            [ $this, 'render_reports' ]
        );

        add_submenu_page(
            'olama-registration',
            __( 'Cash Sessions', 'olama-registration' ),
            __( 'الصناديق والجرد اليومي', 'olama-registration' ),
            'olama_manage_registration_payments',
            'olama-registration-cash-sessions',
            [ $this, 'render_cash_sessions' ]
        );

        add_submenu_page(
            'olama-registration',
            __( 'Payment Review', 'olama-registration' ),
            __( 'مراجعة التحويلات', 'olama-registration' ),
            'olama_manage_registration_payments',
            'olama-registration-payment-review',
            [ $this, 'render_payment_review' ]
        );

        add_submenu_page(
            'olama-registration',
            __( 'Settlement Receipts', 'olama-registration' ),
            __( 'إيصالات التسوية', 'olama-registration' ),
            'olama_manage_registration_payments',
            'olama-registration-settlements',
            [ $this, 'render_settlements' ]
        );

        add_submenu_page(
            'olama-registration',
            __( 'Settings', 'olama-registration' ),
            __( 'الإعدادات', 'olama-registration' ),
            'manage_options',
            'olama-registration-settings',
            [ $this, 'render_settings' ]
        );
    }

    // ── Page Renderers ────────────────────────────────────────────────────────

    public function render_families(): void {
        if ( ! current_user_can( 'olama_manage_registration_families' ) && ! current_user_can( 'manage_options' ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );

        // Print action — output the print view and exit
        $action     = sanitize_text_field( $_GET['action'] ?? '' );
        $family_uid = sanitize_text_field( $_GET['family_uid'] ?? '' );

        if ( $action === 'print' && $family_uid ) {
            include OLAMA_REG_PATH . 'admin/views/partial-print-card.php';
            return;
        }

        include OLAMA_REG_PATH . 'admin/views/page-contacts.php';
    }

    public function render_students(): void {
        if ( ! current_user_can( 'olama_manage_registration_students' ) && ! current_user_can( 'manage_options' ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );
        include OLAMA_REG_PATH . 'admin/views/page-student-list.php';
    }

    public function render_customers(): void {
        if ( ! current_user_can( 'olama_manage_registration_families' ) && ! current_user_can( 'manage_options' ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );
        include OLAMA_REG_PATH . 'admin/views/page-customers.php';
    }

    public function render_fees(): void {
        if ( ! current_user_can( 'olama_manage_registration_fees' ) && ! current_user_can( 'manage_options' ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );
        include OLAMA_REG_PATH . 'admin/views/page-fee-templates.php';
    }

    public function render_agreements(): void {
        if ( ! current_user_can( 'olama_manage_registration_agreements' ) && ! current_user_can( 'manage_options' ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );
        
        $tab = sanitize_text_field( $_GET['tab'] ?? 'agreements' );
        
        if ( $tab === 'templates' ) {
            include OLAMA_REG_PATH . 'admin/views/page-agreement-templates.php';
            return;
        }
        
        if ( $tab === 'clauses' ) {
            wp_safe_redirect( admin_url( 'admin.php?page=olama-registration-agreements&tab=templates' ) );
            exit;
        }

        $action = sanitize_text_field( $_GET['action'] ?? '' );
        $id     = (int) ( $_GET['id'] ?? 0 );

        if ( $action === 'edit' || $action === 'new' ) {
            include OLAMA_REG_PATH . 'admin/views/html-agreements-edit.php';
        } elseif ( $action === 'print' && $id ) {
            include OLAMA_REG_PATH . 'admin/views/html-agreements-print.php';
        } else {
            include OLAMA_REG_PATH . 'admin/views/html-agreements-list.php';
        }
    }

    // render_agreement_templates method removed as it is now handled via tabs

    // render_clause_bank method removed as it is now handled via tabs


    public function render_invoices(): void {
        if ( ! current_user_can( 'olama_manage_registration_invoices' ) && ! current_user_can( 'manage_options' ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );
        include OLAMA_REG_PATH . 'admin/views/page-invoices.php';
    }

    public function render_payments(): void {
        if ( ! Olama_Reg_Payment_Policy::current_user_can_any( [ 'olama_record_payments', 'olama_reverse_payments', 'olama_manage_registration_payments' ] ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );
        include OLAMA_REG_PATH . 'admin/views/page-payments.php';
    }

    public function render_financial_accounts(): void {
        if ( ! Olama_Reg_Payment_Policy::current_user_can_any( [ 'olama_manage_financial_accounts' ] ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );
        include OLAMA_REG_PATH . 'admin/views/page-financial-accounts.php';
    }

    public function render_reports(): void {
        if ( ! current_user_can( 'olama_manage_registration_reports' ) && ! current_user_can( 'manage_options' ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );
        include OLAMA_REG_PATH . 'admin/views/page-billing-reports.php';
    }

    public function render_cash_sessions(): void {
        if ( ! Olama_Reg_Payment_Policy::current_user_can_any( [ 'olama_open_cash_session', 'olama_close_cash_session', 'olama_review_cash_session', 'olama_manage_registration_payments' ] ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );
        include OLAMA_REG_PATH . 'admin/views/page-cash-sessions.php';
    }

    public function render_payment_review(): void {
        if ( ! Olama_Reg_Payment_Policy::current_user_can_any( [ 'olama_confirm_bank_payments', 'olama_manage_registration_payments' ] ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );
        include OLAMA_REG_PATH . 'admin/views/page-payment-review.php';
    }

    public function render_custom_payments(): void {
        if ( ! current_user_can( 'olama_manage_registration_payments' ) && ! current_user_can( 'manage_options' ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );
        include OLAMA_REG_PATH . 'admin/views/page-custom-payments.php';
    }

    public function render_settlements(): void {
        if ( ! current_user_can( 'olama_manage_registration_payments' ) && ! current_user_can( 'manage_options' ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );

        $action = sanitize_text_field( $_GET['action'] ?? '' );
        if ( $action === 'print' ) {
            include OLAMA_REG_PATH . 'admin/views/partial-print-settlement.php';
            return;
        }

        include OLAMA_REG_PATH . 'admin/views/page-settlements.php';
    }

    public function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Unauthorized', 'olama-registration' ) );
        include OLAMA_REG_PATH . 'admin/views/page-settings.php';
    }

    public function render_hub(): void {
        if ( ! current_user_can( 'olama_manage_registration_families' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'olama-registration' ) );
        }
        include OLAMA_REG_PATH . 'admin/views/dashboard/customer-hub.php';
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {

        if ( strpos( $hook, 'olama-registration' ) === false ) {
            return;
        }

        // ── Hub-only assets ───────────────────────────────────────────────────
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'olama-registration' ) {
            wp_enqueue_style(
                'os-hub',
                OLAMA_REG_URL . 'assets/css/os-hub.css',
                [ 'olama-reg' ],
                // Use file modification time so browser always gets the latest version after edits
                filemtime( OLAMA_REG_PATH . 'assets/css/os-hub.css' )
            );
            wp_enqueue_script(
                'os-hub',
                OLAMA_REG_URL . 'assets/js/os-hub.js',
                [ 'jquery' ],
                // Use file modification time so browser always gets the latest version after edits
                filemtime( OLAMA_REG_PATH . 'assets/js/os-hub.js' ),
                true
            );
        }

        // Select2
        wp_enqueue_style(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            [],
            '4.1.0'
        );
        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            [ 'jquery' ],
            '4.1.0',
            true
        );

        // WP datepicker (already bundled)
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style(
            'jquery-ui-theme',
            'https://code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css',
            [],
            '1.13.2'
        );

        // WP Media Library uploader
        wp_enqueue_media();

        // Plugin CSS
        wp_enqueue_style(
            'olama-reg',
            OLAMA_REG_URL . 'assets/css/olama-reg.css',
            [],
            filemtime( OLAMA_REG_PATH . 'assets/css/olama-reg.css' )
        );

        // Plugin JS
        wp_enqueue_script(
            'olama-reg',
            OLAMA_REG_URL . 'assets/js/olama-reg.js',
            [ 'jquery', 'select2', 'jquery-ui-datepicker' ],
            // Use file modification time so browser always gets the latest version after edits
            filemtime( OLAMA_REG_PATH . 'assets/js/olama-reg.js' ),
            true
        );

        // Get academic years for dropdowns
        $academic_years = [];
        if ( class_exists( 'Olama_School_Academic' ) ) {
            $years = Olama_School_Academic::get_years();
            foreach ( (array) $years as $y ) {
                $academic_years[] = [ 'id' => $y->id, 'name' => $y->year_name ];
            }
        }

        wp_localize_script( 'olama-reg', 'olamaReg', [
            'ajaxurl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'olama_reg_nonce' ),
            'pluginUrl'      => OLAMA_REG_URL,
            'academicYears'  => $academic_years,
            'strings'        => [
                'saving'          => __( 'جاري الحفظ...', 'olama-registration' ),
                'saved'           => __( 'تم الحفظ بنجاح.', 'olama-registration' ),
                'error'           => __( 'حدث خطأ. يرجى المحاولة مجدداً.', 'olama-registration' ),
                'confirmDelete'   => __( 'هل أنت متأكد من حذف هذا السجل؟', 'olama-registration' ),
                'confirmDeact'    => __( 'هل تريد إلغاء تفعيل هذه العائلة؟', 'olama-registration' ),
                'selectPhoto'     => __( 'اختر صورة', 'olama-registration' ),
                'addRow'          => __( 'إضافة سطر', 'olama-registration' ),
                'noStudents'      => __( 'لا يوجد طلاب مسجلين في هذه العائلة.', 'olama-registration' ),
                'blacklistWarn'   => __( 'يرجى إدخال سبب القائمة السوداء.', 'olama-registration' ),
                'maxStudents'     => __( 'وصل عدد الطلاب للحد الأقصى (99).', 'olama-registration' ),
                'studentAdded'    => __( 'تم إضافة الطالب بنجاح.', 'olama-registration' ),
                'familyCreated'   => __( 'تم إنشاء العائلة برقم: ', 'olama-registration' ),
            ],
            'i18n'           => [
                'amendmentReasonPrompt'  => __( 'أدخل سبب التعديل المالي:', 'olama-registration' ),
                'amendmentReasonRequired'=> __( 'سبب التعديل مطلوب.', 'olama-registration' ),
                'amendmentActivated'     => __( 'تم تفعيل وضع التعديل المالي. يمكنك إضافة بند رسوم جديد.', 'olama-registration' ),
                'amendmentCancelled'     => __( 'تم إلغاء وضع التعديل المالي.', 'olama-registration' ),
            ],
        ] );
    }

    /**
     * Intercept print actions early in admin_init to output pure HTML/CSS without WP Admin wrappers.
     */
    public function handle_print_actions(): void {
        if ( ! isset( $_GET['page'] ) || ! isset( $_GET['action'] ) ) {
            return;
        }

        $page   = sanitize_text_field( $_GET['page'] );
        $action = sanitize_text_field( $_GET['action'] );

        if ( $page === 'olama-registration-agreements' && $action === 'cancel' ) {
            if ( ! current_user_can( 'olama_cancel_financial_agreement' ) && ! current_user_can( 'manage_options' ) ) {
                wp_die( __( 'Unauthorized', 'olama-registration' ) );
            }
            $id = (int) ( $_GET['id'] ?? 0 );
            check_admin_referer( 'olama_cancel_agreement_' . $id );
            global $wpdb;
            if ($id) {
                $cancelled = Olama_Reg_Agreement::change_status($id, 'cancelled');
                if (is_wp_error($cancelled)) {
                    wp_die(esc_html($cancelled->get_error_message()));
                }
            }

            $redirect_to = sanitize_text_field($_GET['redirect_to'] ?? '');
            if ($redirect_to === 'hub') {
                $agreement = Olama_Reg_Agreement::get($id);
                if ($agreement) {
                    $param = ($agreement->payer_type === 'family') ? 'family_uid' : 'customer_uid';
                    $uid = $agreement->payer_id;
                    if ($agreement->payer_type === 'customer') {
                        $cust_uid = $wpdb->get_var($wpdb->prepare("SELECT customer_uid FROM {$wpdb->prefix}olama_customers WHERE id = %d LIMIT 1", $agreement->payer_id));
                        if ($cust_uid) {
                            $uid = $cust_uid;
                        }
                    }
                    wp_redirect(admin_url('admin.php?page=olama-registration&' . $param . '=' . $uid));
                } else {
                    wp_redirect(admin_url('admin.php?page=olama-registration'));
                }
            } else {
                wp_redirect(admin_url('admin.php?page=olama-registration-agreements'));
            }
            exit;
        }

        if ( $page === 'olama-registration-reports' && in_array( $action, [ 'print_cash_register', 'export_cash_register_excel', 'export_cash_register_pdf', 'print_family_balances', 'print_family_statement' ], true ) ) {
            if ( ! current_user_can( 'olama_manage_registration_reports' ) && ! current_user_can( 'manage_options' ) ) {
                wp_die( __( 'Unauthorized', 'olama-registration' ) );
            }
            include OLAMA_REG_PATH . 'admin/views/page-billing-reports.php';
            exit;
        }

        if ( $action !== 'print' && $action !== 'print_receipt' ) {
            return;
        }

        if ( $page === 'olama-registration-invoices' && $action === 'print' ) {
            if ( ! current_user_can( 'olama_manage_registration_invoices' ) && ! current_user_can( 'manage_options' ) ) {
                wp_die( __( 'Unauthorized', 'olama-registration' ) );
            }
            include OLAMA_REG_PATH . 'admin/views/page-invoices.php';
            exit;
        }

        if ( $page === 'olama-registration-payments' && $action === 'print_receipt' ) {
            if ( ! current_user_can( 'olama_manage_registration_payments' ) && ! current_user_can( 'manage_options' ) ) {
                wp_die( __( 'Unauthorized', 'olama-registration' ) );
            }
            include OLAMA_REG_PATH . 'admin/views/page-payments.php';
            exit;
        }

        if ( $page === 'olama-registration-agreements' && $action === 'print' ) {
            if ( ! current_user_can( 'olama_manage_registration_agreements' ) && ! current_user_can( 'manage_options' ) ) {
                wp_die( __( 'Unauthorized', 'olama-registration' ) );
            }
            include OLAMA_REG_PATH . 'admin/views/html-agreements-print.php';
            exit;
        }

        if ( $page === 'olama-registration-settlements' && $action === 'print' ) {
            if ( ! current_user_can( 'olama_manage_registration_payments' ) && ! current_user_can( 'manage_options' ) ) {
                wp_die( __( 'Unauthorized', 'olama-registration' ) );
            }
            include OLAMA_REG_PATH . 'admin/views/partial-print-settlement.php';
            exit;
        }

        if ( $page === 'olama-registration-contacts' && $action === 'print' ) {
            if ( ! current_user_can( 'olama_manage_registration_families' ) && ! current_user_can( 'manage_options' ) ) {
                wp_die( __( 'Unauthorized', 'olama-registration' ) );
            }
            include OLAMA_REG_PATH . 'admin/views/partial-print-card.php';
            exit;
        }
    }
}
