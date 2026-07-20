<?php
/**
 * Activator — schema migrations via dbDelta.
 * Extends existing tables; creates new tables.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Activator {

    /**
     * Called once on plugin activation hook.
     */
    public static function activate(): void {
        self::upgrade();
    }

    /**
     * Public upgrade method running all migrations and setups.
     */
    public static function upgrade(): void {
        self::run_migrations();
        update_option( 'olama_reg_version', OLAMA_REG_VERSION );
    }

    public static function run_migrations(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        self::create_financial_table( $wpdb, $charset );
        self::create_billing_tables( $wpdb, $charset );
        self::create_billing_control_tables( $wpdb, $charset );
        self::create_cash_bank_tables( $wpdb, $charset );
        self::create_cash_sessions_table( $wpdb, $charset );
        self::create_payment_method_detail_tables( $wpdb, $charset );
        self::create_settlement_receipts_table( $wpdb, $charset );
        self::create_customers_table( $wpdb, $charset );
        self::create_customer_children_table( $wpdb, $charset );
        self::create_agreements_tables( $wpdb, $charset );
        self::create_agreement_amendment_tables( $wpdb, $charset );
        self::create_agreement_participants_table( $wpdb, $charset );
        self::create_financial_snapshots_table( $wpdb, $charset );
        self::upgrade_invoices_table( $wpdb );
        self::upgrade_payments_table( $wpdb );
        self::upgrade_installments_table( $wpdb );
        self::upgrade_allocations_table( $wpdb );
        self::upgrade_agreement_fees_table( $wpdb );
        self::backfill_agreement_participants( $wpdb );
    }

    public static function financial_capabilities(): array {
        return [
            'olama_manage_financial_accounts',
            'olama_open_cash_session',
            'olama_close_cash_session',
            'olama_review_cash_session',
            'olama_record_payments',
            'olama_reverse_payments',
            'olama_confirm_bank_payments',
            'olama_manage_cheques',
            'olama_transfer_cash_bank',
            'olama_view_cash_reports',
            'olama_edit_agreement_admin_fields',
            'olama_create_agreement_amendment',
            'olama_approve_agreement_amendment',
            'olama_post_agreement_amendment',
            'olama_reschedule_agreement_installments',
            'olama_cancel_financial_agreement',
            'olama_view_agreement_audit',
        ];
    }

    private static function install_capabilities(): void {
        $caps = self::financial_capabilities();
        $legacy_payment_caps = [
            'olama_record_payments',
            'olama_open_cash_session',
            'olama_close_cash_session',
            'olama_reverse_payments',
            'olama_confirm_bank_payments',
            'olama_manage_cheques',
            'olama_transfer_cash_bank',
        ];

        foreach ( wp_roles()->roles as $role_key => $details ) {
            $role = get_role( $role_key );
            if ( ! $role ) {
                continue;
            }

            if ( ! empty( $details['capabilities']['manage_options'] ) ) {
                foreach ( $caps as $cap ) {
                    $role->add_cap( $cap );
                }
                continue;
            }

            if ( ! empty( $details['capabilities']['olama_manage_registration_payments'] ) ) {
                foreach ( $legacy_payment_caps as $cap ) {
                    $role->add_cap( $cap );
                }
            }

            if ( ! empty( $details['capabilities']['olama_manage_registration_reports'] ) ) {
                $role->add_cap( 'olama_view_cash_reports' );
            }
        }
    }




    // ── Create olama_customers ───────────────────────────────────────────────
    private static function create_customers_table( $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'olama_customers';

        $sql = "CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_uid varchar(20) NOT NULL DEFAULT '',
            customer_name varchar(150) NOT NULL,
            phone varchar(50) DEFAULT NULL,
            notes text DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY customer_uid (customer_uid),
            KEY idx_customer_name (customer_name),
            KEY idx_phone (phone)
        ) {$charset};";

        dbDelta( $sql );

        // Safely add columns on pre-existing tables
        $existing = $wpdb->get_col( "DESCRIBE {$table}", 0 );

        if ( ! in_array( 'customer_uid', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `customer_uid` varchar(20) NOT NULL DEFAULT '' AFTER `id`" );
        }
        if ( ! in_array( 'notes', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `notes` text DEFAULT NULL AFTER `phone`" );
        }
        if ( ! in_array( 'is_active', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1 AFTER `notes`" );
        }
        // Remove the broken UNIQUE constraint on phone (allow NULL duplicates safely)
        $indexes = $wpdb->get_col( "SHOW INDEX FROM {$table} WHERE Key_name = 'phone'", 2 );
        if ( ! empty( $indexes ) ) {
            $wpdb->query( "ALTER TABLE {$table} DROP INDEX `phone`" );
        }

        // Backfill missing customer_uid values
        $rows = $wpdb->get_results( "SELECT id FROM {$table} WHERE customer_uid = '' OR customer_uid IS NULL" );
        foreach ( $rows as $row ) {
            $uid = 'CUST-' . str_pad( $row->id, 4, '0', STR_PAD_LEFT );
            $wpdb->update( $table, [ 'customer_uid' => $uid ], [ 'id' => $row->id ] );
        }

        // Drop children_names column (data migrated to olama_customer_children)
        // Only drop after children table is populated (done in create_customer_children_table)
    }

    // ── Create olama_customer_children ───────────────────────────────────────
    private static function create_customer_children_table( $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'olama_customer_children';

        $sql = "CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            child_uid varchar(30) NOT NULL DEFAULT '',
            customer_id bigint(20) UNSIGNED NOT NULL,
            child_name varchar(150) NOT NULL,
            grade varchar(100) DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY child_uid (child_uid),
            KEY idx_customer_id (customer_id)
        ) {$charset};";

        dbDelta( $sql );

        // Safely add columns on pre-existing tables
        $existing = $wpdb->get_col( "DESCRIBE {$table}", 0 );
        if ( ! in_array( 'is_active', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1 AFTER `grade`" );
        }
        if ( ! in_array( 'notes', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `notes` text DEFAULT NULL AFTER `is_active`" );
        }

        // Backfill: migrate JSON children from olama_customers.children_names → this table
        $customers_table = $wpdb->prefix . 'olama_customers';
        $customers = $wpdb->get_results( "SELECT id, customer_uid, children_names FROM {$customers_table} WHERE children_names IS NOT NULL AND children_names != '' AND children_names != '[]'" );

        foreach ( $customers as $cust ) {
            $children = json_decode( $cust->children_names, true );
            if ( ! is_array( $children ) || empty( $children ) ) continue;

            $seq = 1;
            foreach ( $children as $child ) {
                $child_name = sanitize_text_field( $child['name'] ?? '' );
                if ( empty( $child_name ) ) { $seq++; continue; }

                $child_uid = $cust->customer_uid . '-C' . str_pad( $seq, 2, '0', STR_PAD_LEFT );

                // Skip if this child_uid already exists
                $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE child_uid = %s", $child_uid ) );
                if ( $exists ) { $seq++; continue; }

                $wpdb->insert( $table, [
                    'child_uid'   => $child_uid,
                    'customer_id' => $cust->id,
                    'child_name'  => $child_name,
                    'grade'       => sanitize_text_field( $child['grade'] ?? '' ) ?: null,
                    'is_active'   => 1,
                ], [ '%s', '%d', '%s', '%s', '%d' ] );

                $seq++;
            }
        }
    }

    // ── Add ext columns to olama_invoices ─────────────────────────────────────
    private static function upgrade_invoices_table( $wpdb ): void {
        $table = $wpdb->prefix . 'olama_invoices';
        $existing = $wpdb->get_col( "DESCRIBE {$table}", 0 );

        if ( ! in_array( 'ext_customer_id', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `ext_customer_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `student_uid`" );
        }
        if ( ! in_array( 'ext_child_id', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `ext_child_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `ext_customer_id`" );
        }
        if ( ! in_array( 'agreement_id', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `agreement_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `id`" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY `agreement_id` (`agreement_id`)" );
        }
        if ( ! in_array( 'cancelled_by', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `cancelled_by` bigint(20) UNSIGNED DEFAULT NULL AFTER `created_by`" );
        }
        if ( ! in_array( 'cancelled_at', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `cancelled_at` datetime DEFAULT NULL AFTER `cancelled_by`" );
        }
    }

    private static function upgrade_payments_table( $wpdb ): void {
        $table = $wpdb->prefix . 'olama_payments';
        $existing = $wpdb->get_col( "DESCRIBE {$table}", 0 );

        if ( ! in_array( 'payment_no', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `payment_no` varchar(50) DEFAULT NULL AFTER `id`" );
            $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY `payment_no` (`payment_no`)" );
        }
        if ( ! in_array( 'account_id', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `account_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `payment_no`" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY `account_id` (`account_id`)" );
        }
        if ( ! in_array( 'cash_session_id', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `cash_session_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `account_id`" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY `cash_session_id` (`cash_session_id`)" );
        }
        if ( ! in_array( 'status', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `status` varchar(30) NOT NULL DEFAULT 'posted' AFTER `method`" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY `status` (`status`)" );
        }
        if ( ! in_array( 'posted_at', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `posted_at` datetime DEFAULT NULL AFTER `created_at`" );
        }
        if ( ! in_array( 'confirmed_by', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `confirmed_by` bigint(20) UNSIGNED DEFAULT NULL AFTER `posted_at`" );
        }
        if ( ! in_array( 'confirmed_at', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `confirmed_at` datetime DEFAULT NULL AFTER `confirmed_by`" );
        }
        if ( ! in_array( 'reversed_by', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `reversed_by` bigint(20) UNSIGNED DEFAULT NULL AFTER `confirmed_at`" );
        }
        if ( ! in_array( 'reversed_at', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `reversed_at` datetime DEFAULT NULL AFTER `reversed_by`" );
        }
        if ( ! in_array( 'external_reference', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `external_reference` varchar(190) DEFAULT NULL AFTER `reference`" );
        }
        if ( ! in_array( 'admin_notes', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `admin_notes` text DEFAULT NULL AFTER `notes`" );
        }
        if ( ! in_array( 'reversed_payment_id', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `reversed_payment_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `reference`" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY `reversed_payment_id` (`reversed_payment_id`)" );
        }

        $wpdb->query( "UPDATE {$table} SET status = 'posted' WHERE status IS NULL OR status = ''" );
        $wpdb->query( "UPDATE {$table} SET posted_at = created_at WHERE posted_at IS NULL AND amount <> 0 AND (status = 'posted' OR status = 'reversed')" );
    }

    private static function upgrade_installments_table( $wpdb ): void {
        $table = $wpdb->prefix . 'olama_invoice_installments';
        $existing = $wpdb->get_col( "DESCRIBE {$table}", 0 );

        if ( ! in_array( 'agreement_id', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `agreement_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `invoice_id`" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY `agreement_id` (`agreement_id`)" );
        }
    }

    private static function create_cash_bank_tables( $wpdb, string $charset ): void {
        $accounts = $wpdb->prefix . 'olama_financial_accounts';
        $movements = $wpdb->prefix . 'olama_cash_bank_movements';

        $sql_accounts = "CREATE TABLE {$accounts} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_code varchar(50) NOT NULL,
            account_name varchar(190) NOT NULL,
            type varchar(30) NOT NULL,
            currency varchar(10) NOT NULL DEFAULT 'JOD',
            is_default tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            opening_balance decimal(12,2) NOT NULL DEFAULT 0.00,
            notes text DEFAULT NULL,
            created_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY account_code (account_code),
            KEY type_active (type,is_active),
            KEY is_default (is_default)
        ) {$charset};";

        $sql_movements = "CREATE TABLE {$movements} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            movement_no varchar(50) NOT NULL,
            account_id bigint(20) UNSIGNED NOT NULL,
            cash_session_id bigint(20) UNSIGNED DEFAULT NULL,
            movement_type varchar(50) NOT NULL,
            source_type varchar(50) NOT NULL,
            source_id bigint(20) UNSIGNED NOT NULL,
            direction varchar(10) NOT NULL,
            amount decimal(12,2) NOT NULL DEFAULT 0.00,
            movement_date date DEFAULT NULL,
            status varchar(30) NOT NULL DEFAULT 'posted',
            created_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            notes text DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY movement_no (movement_no),
            UNIQUE KEY source_event (source_type,source_id,movement_type),
            KEY account_date (account_id,movement_date),
            KEY cash_session_id (cash_session_id),
            KEY direction (direction),
            KEY status (status)
        ) {$charset};";

        dbDelta( $sql_accounts );
        dbDelta( $sql_movements );

        self::seed_default_financial_accounts( $wpdb );
    }

    private static function create_cash_sessions_table( $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'olama_cash_sessions';

        $sql = "CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_no varchar(50) NOT NULL,
            account_id bigint(20) UNSIGNED NOT NULL,
            cashier_id bigint(20) UNSIGNED NOT NULL,
            session_date date NOT NULL,
            opened_at datetime DEFAULT NULL,
            closed_at datetime DEFAULT NULL,
            opening_balance decimal(12,2) NOT NULL DEFAULT 0.00,
            cash_in_total decimal(12,2) NOT NULL DEFAULT 0.00,
            cash_out_total decimal(12,2) NOT NULL DEFAULT 0.00,
            expected_closing_balance decimal(12,2) NOT NULL DEFAULT 0.00,
            actual_closing_balance decimal(12,2) DEFAULT NULL,
            difference_amount decimal(12,2) DEFAULT NULL,
            status varchar(30) NOT NULL DEFAULT 'open',
            reviewed_by bigint(20) UNSIGNED DEFAULT NULL,
            reviewed_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY session_no (session_no),
            KEY account_cashier_date (account_id,cashier_id,session_date),
            KEY cashier_status (cashier_id,status),
            KEY status (status)
        ) {$charset};";

        dbDelta( $sql );
    }

    private static function create_payment_method_detail_tables( $wpdb, string $charset ): void {
        $cheques = $wpdb->prefix . 'olama_cheques';
        $transfers = $wpdb->prefix . 'olama_bank_transfer_details';
        $epayments = $wpdb->prefix . 'olama_epayment_details';

        $sql_cheques = "CREATE TABLE {$cheques} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id bigint(20) UNSIGNED NOT NULL,
            check_no varchar(100) NOT NULL DEFAULT '',
            bank_name varchar(190) DEFAULT NULL,
            branch_name varchar(190) DEFAULT NULL,
            check_date date DEFAULT NULL,
            due_date date DEFAULT NULL,
            amount decimal(12,2) NOT NULL DEFAULT 0.00,
            status varchar(30) NOT NULL DEFAULT 'received',
            deposited_at datetime DEFAULT NULL,
            cleared_at datetime DEFAULT NULL,
            bounced_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY payment_id (payment_id),
            KEY check_no (check_no),
            KEY status (status)
        ) {$charset};";

        $sql_transfers = "CREATE TABLE {$transfers} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id bigint(20) UNSIGNED NOT NULL,
            bank_account_id bigint(20) UNSIGNED DEFAULT NULL,
            transfer_reference varchar(190) NOT NULL DEFAULT '',
            transfer_date date DEFAULT NULL,
            sender_name varchar(190) DEFAULT NULL,
            attachment_id bigint(20) UNSIGNED DEFAULT NULL,
            status varchar(30) NOT NULL DEFAULT 'confirmed',
            confirmed_by bigint(20) UNSIGNED DEFAULT NULL,
            confirmed_at datetime DEFAULT NULL,
            rejected_by bigint(20) UNSIGNED DEFAULT NULL,
            rejected_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY payment_id (payment_id),
            KEY status (status),
            KEY transfer_reference (transfer_reference)
        ) {$charset};";

        $sql_epayments = "CREATE TABLE {$epayments} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id bigint(20) UNSIGNED NOT NULL,
            provider varchar(100) DEFAULT NULL,
            transaction_id varchar(190) DEFAULT NULL,
            gateway_reference varchar(190) DEFAULT NULL,
            gross_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            fee_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            net_amount decimal(12,2) NOT NULL DEFAULT 0.00,
            status varchar(30) NOT NULL DEFAULT 'confirmed',
            confirmed_at datetime DEFAULT NULL,
            failed_at datetime DEFAULT NULL,
            refunded_at datetime DEFAULT NULL,
            raw_payload longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY payment_id (payment_id),
            KEY status (status),
            KEY transaction_id (transaction_id)
        ) {$charset};";

        dbDelta( $sql_cheques );
        dbDelta( $sql_transfers );
        dbDelta( $sql_epayments );
    }

    private static function seed_default_financial_accounts( $wpdb ): void {
        $table = $wpdb->prefix . 'olama_financial_accounts';
        $defaults = [
            [ 'CASH-MAIN', 'Main Cashbox', 'cash' ],
            [ 'BANK-MAIN', 'Main Bank Account', 'bank' ],
            [ 'CHQ-CLEAR', 'Cheque Clearing Account', 'cheque_clearing' ],
            [ 'EPAY-MAIN', 'Electronic Payments Account', 'electronic' ],
        ];

        foreach ( $defaults as $row ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE account_code = %s", $row[0] ) );
            if ( $exists ) {
                continue;
            }

            $wpdb->insert( $table, [
                'account_code' => $row[0],
                'account_name' => $row[1],
                'type'         => $row[2],
                'is_default'   => 1,
                'is_active'    => 1,
                'created_by'   => get_current_user_id(),
            ] );
        }
    }


    // ── Create olama_reg_financial ───────────────────────────────────────────
    private static function create_financial_table( $wpdb, string $charset ): void {

        $table = $wpdb->prefix . 'olama_reg_financial';

        $sql = "CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            family_uid varchar(20) NOT NULL,
            academic_year_id int(10) UNSIGNED NOT NULL DEFAULT 0,
            entitlement_date date DEFAULT NULL,
            calculation_method varchar(100) DEFAULT NULL,
            percentage decimal(5,2) DEFAULT NULL,
            amount_due decimal(10,2) NOT NULL DEFAULT 0.00,
            amount_paid decimal(10,2) NOT NULL DEFAULT 0.00,
            payments_revolving decimal(10,2) NOT NULL DEFAULT 0.00,
            payment_reference varchar(150) DEFAULT NULL,
            fin_notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_family_year (family_uid,academic_year_id)
        ) {$charset};";

        dbDelta( $sql );

        // Safely add updated_at on pre-existing tables where dbDelta may skip it.
        $existing = $wpdb->get_col( "DESCRIBE {$table}", 0 );
        if ( ! in_array( 'updated_at', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" );
        }
    }


    // ── Create billing-related tables ─────────────────────────────────────────
    private static function create_billing_tables( $wpdb, string $charset ): void {

        // 1. Fee templates
        $sql_fee_templates = "CREATE TABLE {$wpdb->prefix}olama_fee_templates (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            template_name varchar(255) NOT NULL,
            subject_type varchar(20) NOT NULL DEFAULT 'general',
            subject_value varchar(255) DEFAULT NULL,
            grade_id varchar(100) DEFAULT NULL,
            installments int(11) DEFAULT 1,
            items text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) {$charset};";

        // 2. Invoices
        $sql_invoices = "CREATE TABLE {$wpdb->prefix}olama_invoices (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_number varchar(50) NOT NULL,
            family_uid varchar(50) NOT NULL,
            student_uid varchar(50) DEFAULT NULL,
            academic_year_id int(10) UNSIGNED NOT NULL DEFAULT 0,
            fee_template_id mediumint(9) DEFAULT NULL,
            issue_date date DEFAULT NULL,
            due_date date DEFAULT NULL,
            status varchar(50) NOT NULL DEFAULT 'draft',
            subtotal decimal(10,2) NOT NULL DEFAULT 0.00,
            discount decimal(10,2) NOT NULL DEFAULT 0.00,
            total decimal(10,2) NOT NULL DEFAULT 0.00,
            amount_paid decimal(10,2) NOT NULL DEFAULT 0.00,
            balance decimal(10,2) NOT NULL DEFAULT 0.00,
            notes text DEFAULT NULL,
            created_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY invoice_number (invoice_number),
            KEY family_uid (family_uid),
            KEY academic_year_id (academic_year_id)
        ) {$charset};";

        // 3. Invoice items
        $sql_items = "CREATE TABLE {$wpdb->prefix}olama_invoice_items (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id bigint(20) UNSIGNED NOT NULL,
            description varchar(255) NOT NULL,
            quantity decimal(10,2) NOT NULL DEFAULT 1.00,
            unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
            line_total decimal(10,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY  (id),
            KEY invoice_id (invoice_id)
        ) {$charset};";

        // 4. Invoice installments
        $sql_installments = "CREATE TABLE {$wpdb->prefix}olama_invoice_installments (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id bigint(20) UNSIGNED NOT NULL,
            agreement_id bigint(20) UNSIGNED DEFAULT NULL,
            installment_no int(11) NOT NULL,
            due_date date DEFAULT NULL,
            amount_due decimal(10,2) NOT NULL DEFAULT 0.00,
            amount_paid decimal(10,2) NOT NULL DEFAULT 0.00,
            status varchar(50) NOT NULL DEFAULT 'pending',
            PRIMARY KEY  (id),
            KEY invoice_id (invoice_id),
            KEY agreement_id (agreement_id)
        ) {$charset};";

        // 5. Payments
        $sql_payments = "CREATE TABLE {$wpdb->prefix}olama_payments (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id bigint(20) UNSIGNED NOT NULL,
            installment_id bigint(20) UNSIGNED DEFAULT NULL,
            family_uid varchar(50) NOT NULL,
            payment_date date DEFAULT NULL,
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            method varchar(50) NOT NULL DEFAULT 'cash',
            reference varchar(150) DEFAULT NULL,
            received_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY invoice_id (invoice_id),
            KEY family_uid (family_uid)
        ) {$charset};";

        // 6. Billing Audit
        $sql_audit = "CREATE TABLE {$wpdb->prefix}olama_billing_audit (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type varchar(50) NOT NULL,
            entity_id bigint(20) UNSIGNED NOT NULL,
            action varchar(100) NOT NULL,
            actor_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            before_state longtext DEFAULT NULL,
            after_state longtext DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY entity_type_id (entity_type,entity_id)
        ) {$charset};";

        dbDelta( $sql_fee_templates );
        dbDelta( $sql_invoices );
        dbDelta( $sql_items );
        dbDelta( $sql_installments );
        dbDelta( $sql_payments );
        dbDelta( $sql_audit );
    }

    private static function create_billing_control_tables( $wpdb, string $charset ): void {
        $sql_allocations = "CREATE TABLE {$wpdb->prefix}olama_payment_allocations (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id bigint(20) UNSIGNED NOT NULL,
            invoice_id bigint(20) UNSIGNED NOT NULL,
            installment_id bigint(20) UNSIGNED DEFAULT NULL,
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            allocation_date date DEFAULT NULL,
            type varchar(20) NOT NULL DEFAULT 'normal',
            student_uid varchar(50) DEFAULT NULL,
            fee_category varchar(100) DEFAULT NULL,
            reversed_allocation_id bigint(20) UNSIGNED DEFAULT NULL,
            created_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY payment_id (payment_id),
            KEY invoice_id (invoice_id),
            KEY installment_id (installment_id),
            KEY student_uid (student_uid),
            KEY fee_category (fee_category),
            KEY reversed_allocation_id (reversed_allocation_id)
        ) {$charset};";

        $sql_adjustments = "CREATE TABLE {$wpdb->prefix}olama_invoice_adjustments (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            adjustment_no varchar(50) NOT NULL,
            invoice_id bigint(20) UNSIGNED NOT NULL,
            type varchar(20) NOT NULL,
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            reason varchar(255) NOT NULL DEFAULT '',
            notes text DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'issued',
            created_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            cancelled_by bigint(20) UNSIGNED DEFAULT NULL,
            cancelled_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY adjustment_no (adjustment_no),
            KEY invoice_id (invoice_id),
            KEY type_status (type,status)
        ) {$charset};";

        dbDelta( $sql_allocations );
        dbDelta( $sql_adjustments );
    }

    // ── Create Settlement Receipts Table ───────────────────────────────────────
    private static function create_settlement_receipts_table( $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'olama_settlement_receipts';

        $sql = "CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            receipt_number varchar(50) NOT NULL,
            family_id varchar(50) NOT NULL,
            student_id varchar(50) DEFAULT NULL,
            payment_category varchar(100) NOT NULL,
            original_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            settled_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            remaining_balance decimal(10,2) NOT NULL DEFAULT 0.00,
            payment_method varchar(50) NOT NULL DEFAULT 'cash',
            oracle_receipt_number varchar(150) DEFAULT NULL,
            settlement_date date DEFAULT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending_settlement',
            notes text DEFAULT NULL,
            created_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            updated_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            settled_by bigint(20) UNSIGNED DEFAULT NULL,
            cancelled_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY receipt_number (receipt_number),
            KEY family_id (family_id),
            KEY status (status)
        ) {$charset};";

        dbDelta( $sql );
    }

    // ── Create Agreements Tables ──────────────────────────────────────────────
    public static function create_agreements_tables( $wpdb, string $charset ): void {
        // 1. Agreements
        $sql_agreements = "CREATE TABLE {$wpdb->prefix}olama_agreements (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agreement_number VARCHAR(30) NOT NULL,
            payer_type VARCHAR(20) NOT NULL,
            payer_id VARCHAR(50) NOT NULL,
            participant_type VARCHAR(20) NOT NULL,
            participant_id BIGINT UNSIGNED NOT NULL,
            participant_ids TEXT DEFAULT NULL,
            activity_type VARCHAR(60) NOT NULL,
            template_id BIGINT UNSIGNED DEFAULT NULL,
            academic_year_id INT UNSIGNED DEFAULT NULL,
            start_date DATE NOT NULL,
            end_date DATE DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            total_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
            notes TEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY agreement_number (agreement_number),
            KEY payer (payer_type, payer_id),
            KEY participant (participant_type, participant_id),
            KEY status (status)
        ) {$charset};";

        // 2. Agreement Fees
        $sql_fees = "CREATE TABLE {$wpdb->prefix}olama_agreement_fees (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agreement_id BIGINT UNSIGNED NOT NULL,
            child_id VARCHAR(50) DEFAULT NULL,
            fee_category VARCHAR(60) NOT NULL,
            label VARCHAR(255) NOT NULL,
            amount DECIMAL(12,3) NOT NULL DEFAULT 0,
            discount DECIMAL(12,3) NOT NULL DEFAULT 0,
            net_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
            due_date DATE DEFAULT NULL,
            invoice_id BIGINT UNSIGNED DEFAULT NULL,
            paid_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            cancelled_at DATETIME DEFAULT NULL,
            cancelled_by BIGINT UNSIGNED DEFAULT NULL,
            cancellation_reason TEXT DEFAULT NULL,
            cancellation_amendment_id BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY agreement_id (agreement_id),
            KEY invoice_id (invoice_id)
        ) {$charset};";

        // 3. Agreement Clauses
        $sql_clauses = "CREATE TABLE {$wpdb->prefix}olama_agreement_clauses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agreement_id BIGINT UNSIGNED NOT NULL,
            clause_text TEXT NOT NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY agreement_id (agreement_id)
        ) {$charset};";

        // 4. Agreement Templates
        $sql_templates = "CREATE TABLE {$wpdb->prefix}olama_agreement_templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            activity_type VARCHAR(60) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY activity_type (activity_type)
        ) {$charset};";

        // 5. Template Fees
        $sql_tpl_fees = "CREATE TABLE {$wpdb->prefix}olama_agreement_template_fees (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_id BIGINT UNSIGNED NOT NULL,
            fee_category VARCHAR(60) NOT NULL,
            label VARCHAR(255) NOT NULL,
            amount DECIMAL(12,3) NOT NULL DEFAULT 0,
            discount DECIMAL(12,3) NOT NULL DEFAULT 0,
            net_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY template_id (template_id)
        ) {$charset};";

        // 6. Template Clauses
        $sql_tpl_clauses = "CREATE TABLE {$wpdb->prefix}olama_agreement_template_clauses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_id BIGINT UNSIGNED NOT NULL,
            clause_text TEXT NOT NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY template_id (template_id)
        ) {$charset};";

        // 7. Clause Bank
        $sql_clause_bank = "CREATE TABLE {$wpdb->prefix}olama_agreement_clause_bank (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            clause_text TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id)
        ) {$charset};";

        dbDelta( $sql_agreements );
        dbDelta( $sql_fees );
        dbDelta( $sql_clauses );
        dbDelta( $sql_templates );
        dbDelta( $sql_tpl_fees );
        dbDelta( $sql_tpl_clauses );
        dbDelta( $sql_clause_bank );

        // Safely add child_id column to existing table if not present
        $existing_fees = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}olama_agreement_fees", 0 );
        if ( ! empty( $existing_fees ) && ! in_array( 'child_id', (array) $existing_fees, true ) ) {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}olama_agreement_fees ADD COLUMN `child_id` VARCHAR(50) DEFAULT NULL AFTER `agreement_id`" );
        } else {
            $wpdb->query( "ALTER TABLE {$wpdb->prefix}olama_agreement_fees MODIFY COLUMN `child_id` VARCHAR(50) DEFAULT NULL" );
        }
    }

    private static function create_agreement_amendment_tables( $wpdb, string $charset ): void {
        $sql_amendments = "CREATE TABLE {$wpdb->prefix}olama_agreement_amendments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agreement_id BIGINT UNSIGNED NOT NULL,
            invoice_id BIGINT UNSIGNED DEFAULT NULL,
            amendment_no VARCHAR(50) NOT NULL,
            amendment_type VARCHAR(50) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            effective_date DATE NOT NULL,
            old_total DECIMAL(12,3) NOT NULL DEFAULT 0,
            new_total DECIMAL(12,3) NOT NULL DEFAULT 0,
            difference_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
            reason TEXT NOT NULL,
            admin_notes TEXT DEFAULT NULL,
            before_snapshot LONGTEXT DEFAULT NULL,
            after_snapshot LONGTEXT DEFAULT NULL,
            credit_adjustment_id BIGINT UNSIGNED DEFAULT NULL,
            debit_adjustment_id BIGINT UNSIGNED DEFAULT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            approved_by BIGINT UNSIGNED DEFAULT NULL,
            posted_by BIGINT UNSIGNED DEFAULT NULL,
            cancelled_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            approved_at DATETIME DEFAULT NULL,
            posted_at DATETIME DEFAULT NULL,
            cancelled_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY amendment_no (amendment_no),
            KEY agreement_id (agreement_id),
            KEY invoice_id (invoice_id),
            KEY status (status),
            KEY amendment_type (amendment_type)
        ) {$charset};";

        $sql_lines = "CREATE TABLE {$wpdb->prefix}olama_agreement_amendment_lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            amendment_id BIGINT UNSIGNED NOT NULL,
            agreement_id BIGINT UNSIGNED NOT NULL,
            invoice_id BIGINT UNSIGNED DEFAULT NULL,
            line_type VARCHAR(50) NOT NULL,
            related_fee_id BIGINT UNSIGNED DEFAULT NULL,
            student_id VARCHAR(50) DEFAULT NULL,
            description VARCHAR(255) NOT NULL,
            old_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
            new_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
            difference_amount DECIMAL(12,3) NOT NULL DEFAULT 0,
            before_state LONGTEXT DEFAULT NULL,
            after_state LONGTEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            KEY amendment_id (amendment_id),
            KEY agreement_id (agreement_id),
            KEY invoice_id (invoice_id),
            KEY related_fee_id (related_fee_id),
            KEY line_type (line_type)
        ) {$charset};";

        dbDelta( $sql_amendments );
        dbDelta( $sql_lines );

        $amendments_table = $wpdb->prefix . 'olama_agreement_amendments';
        $existing = $wpdb->get_col( "DESCRIBE {$amendments_table}", 0 );
        $columns = [
            'effective_date'      => "ALTER TABLE {$amendments_table} ADD COLUMN `effective_date` DATE NOT NULL AFTER `status`",
            'old_total'           => "ALTER TABLE {$amendments_table} ADD COLUMN `old_total` DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER `effective_date`",
            'new_total'           => "ALTER TABLE {$amendments_table} ADD COLUMN `new_total` DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER `old_total`",
            'difference_amount'   => "ALTER TABLE {$amendments_table} ADD COLUMN `difference_amount` DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER `new_total`",
            'admin_notes'         => "ALTER TABLE {$amendments_table} ADD COLUMN `admin_notes` TEXT DEFAULT NULL AFTER `reason`",
            'posted_by'           => "ALTER TABLE {$amendments_table} ADD COLUMN `posted_by` BIGINT UNSIGNED DEFAULT NULL AFTER `approved_by`",
            'posted_at'           => "ALTER TABLE {$amendments_table} ADD COLUMN `posted_at` DATETIME DEFAULT NULL AFTER `approved_at`",
            'credit_adjustment_id' => "ALTER TABLE {$amendments_table} ADD COLUMN `credit_adjustment_id` BIGINT UNSIGNED DEFAULT NULL AFTER `after_snapshot`",
            'debit_adjustment_id' => "ALTER TABLE {$amendments_table} ADD COLUMN `debit_adjustment_id` BIGINT UNSIGNED DEFAULT NULL AFTER `credit_adjustment_id`",
        ];
        foreach ( $columns as $column => $sql ) {
            if ( ! in_array( $column, (array) $existing, true ) ) {
                $wpdb->query( $sql );
            }
        }

        $lines_table = $wpdb->prefix . 'olama_agreement_amendment_lines';
        $existing_lines = $wpdb->get_col( "DESCRIBE {$lines_table}", 0 );
        $line_columns = [
            'agreement_id'      => "ALTER TABLE {$lines_table} ADD COLUMN `agreement_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `amendment_id`",
            'invoice_id'        => "ALTER TABLE {$lines_table} ADD COLUMN `invoice_id` BIGINT UNSIGNED DEFAULT NULL AFTER `agreement_id`",
            'related_fee_id'    => "ALTER TABLE {$lines_table} ADD COLUMN `related_fee_id` BIGINT UNSIGNED DEFAULT NULL AFTER `line_type`",
            'student_id'        => "ALTER TABLE {$lines_table} ADD COLUMN `student_id` VARCHAR(50) DEFAULT NULL AFTER `related_fee_id`",
            'old_amount'        => "ALTER TABLE {$lines_table} ADD COLUMN `old_amount` DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER `description`",
            'new_amount'        => "ALTER TABLE {$lines_table} ADD COLUMN `new_amount` DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER `old_amount`",
            'difference_amount' => "ALTER TABLE {$lines_table} ADD COLUMN `difference_amount` DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER `new_amount`",
        ];
        foreach ( $line_columns as $column => $sql ) {
            if ( ! in_array( $column, (array) $existing_lines, true ) ) {
                $wpdb->query( $sql );
            }
        }
    }

    private static function create_agreement_participants_table( $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'olama_agreement_participants';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            agreement_id BIGINT UNSIGNED NOT NULL,
            payer_type VARCHAR(20) NOT NULL DEFAULT 'family',
            family_uid VARCHAR(50) NOT NULL,
            participant_type VARCHAR(20) NOT NULL DEFAULT 'student',
            participant_ref VARCHAR(50) NOT NULL,
            student_uid VARCHAR(50) DEFAULT NULL,
            child_id BIGINT UNSIGNED DEFAULT NULL,
            role VARCHAR(30) NOT NULL DEFAULT 'beneficiary',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY agreement_participant (agreement_id, participant_type, participant_ref),
            KEY family_uid (family_uid),
            KEY student_uid (student_uid),
            KEY child_id (child_id),
            KEY agreement_id (agreement_id),
            KEY payer_type (payer_type)
        ) {$charset};";

        dbDelta( $sql );
    }

    private static function create_financial_snapshots_table( $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'olama_family_financial_snapshots';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_uid VARCHAR(50) NOT NULL,
            academic_year_id INT UNSIGNED NOT NULL DEFAULT 0,
            total_agreements INT UNSIGNED NOT NULL DEFAULT 0,
            total_fees DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            total_discounts DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            gross_invoiced DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            total_paid DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            total_settlements DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            total_credit_adjustments DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            total_debit_adjustments DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            net_adjustments DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            current_balance DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            due_now DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            overdue DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            previous_balance DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            unallocated_payments DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            calculated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
            calculation_hash VARCHAR(64) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY family_year (family_uid, academic_year_id),
            KEY academic_year_id (academic_year_id),
            KEY calculated_at (calculated_at)
        ) {$charset};";

        dbDelta( $sql );
    }

    private static function backfill_agreement_participants( $wpdb ): void {
        $table = $wpdb->prefix . 'olama_agreement_participants';
        $agr_table = $wpdb->prefix . 'olama_agreements';
        $fees_table = $wpdb->prefix . 'olama_agreement_fees';

        // Skip if already backfilled
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) {
            return;
        }

        $agreements = $wpdb->get_results( "SELECT * FROM {$agr_table}" );
        if ( empty( $agreements ) ) {
            return;
        }

        foreach ( $agreements as $agr ) {
            $payer_type = $agr->payer_type;
            $payer_id = $agr->payer_id;
            $is_family = ( $payer_type === 'family' );

            // 1. Collect participant IDs from agreement_fees.child_id first (most accurate)
            $fee_children = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT child_id FROM {$fees_table}
                 WHERE agreement_id = %d AND child_id IS NOT NULL AND child_id != ''",
                $agr->id
            ) );

            // 2. Fallback to participant_ids JSON
            if ( empty( $fee_children ) ) {
                $json_ids = json_decode( $agr->participant_ids, true ) ?: [];
                if ( empty( $json_ids ) && ! empty( $agr->participant_id ) ) {
                    $json_ids = [ $agr->participant_id ];
                }
                $fee_children = array_filter( array_map( 'trim', array_map( 'strval', $json_ids ) ) );
            }

            // 3. Insert into participants table
            foreach ( $fee_children as $pid ) {
                $pid = trim( (string) $pid );
                if ( empty( $pid ) || $pid === '0' ) {
                    continue;
                }

                $participant_type = $is_family ? 'student' : 'child';
                $participant_ref  = $pid; // Always non-null string

                $row = [
                    'agreement_id'     => $agr->id,
                    'payer_type'       => $payer_type,
                    'family_uid'       => (string) $payer_id,
                    'participant_type' => $participant_type,
                    'participant_ref'  => $participant_ref,
                    'student_uid'      => $is_family ? (string) $pid : null,
                    'child_id'         => ( ! $is_family && is_numeric( $pid ) ) ? (int) $pid : null,
                    'role'             => 'beneficiary',
                    'created_by'       => (int) ( $agr->created_by ?? 0 ),
                ];

                if ( $is_family ) {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT IGNORE INTO {$table}
                         (agreement_id, payer_type, family_uid, participant_type, participant_ref, student_uid, child_id, role, created_by)
                         VALUES (%d, %s, %s, %s, %s, %s, NULL, %s, %d)",
                        $row['agreement_id'],
                        $row['payer_type'],
                        $row['family_uid'],
                        $row['participant_type'],
                        $row['participant_ref'],
                        $row['student_uid'],
                        $row['role'],
                        $row['created_by']
                    ) );
                } else {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT IGNORE INTO {$table}
                         (agreement_id, payer_type, family_uid, participant_type, participant_ref, student_uid, child_id, role, created_by)
                         VALUES (%d, %s, %s, %s, %s, NULL, %d, %s, %d)",
                        $row['agreement_id'],
                        $row['payer_type'],
                        $row['family_uid'],
                        $row['participant_type'],
                        $row['participant_ref'],
                        $row['child_id'],
                        $row['role'],
                        $row['created_by']
                    ) );
                }
            }
        }
    }

    private static function upgrade_allocations_table( $wpdb ): void {
        $table = $wpdb->prefix . 'olama_payment_allocations';
        $existing = $wpdb->get_col( "DESCRIBE {$table}", 0 );

        if ( ! in_array( 'student_uid', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `student_uid` varchar(50) DEFAULT NULL AFTER `type`" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY `student_uid` (`student_uid`)" );
        }
        if ( ! in_array( 'fee_category', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `fee_category` varchar(100) DEFAULT NULL AFTER `student_uid`" );
            $wpdb->query( "ALTER TABLE {$table} ADD KEY `fee_category` (`fee_category`)" );
        }

        self::backfill_payment_allocations( $wpdb );
    }

    private static function backfill_payment_allocations( $wpdb ): void {
        $table = $wpdb->prefix . 'olama_payment_allocations';
        $invoices = $wpdb->prefix . 'olama_invoices';
        $fees = $wpdb->prefix . 'olama_agreement_fees';

        // 1. Backfill student_uid from invoices where student_uid is present and valid
        $wpdb->query( "
            UPDATE {$table} a
            INNER JOIN {$invoices} i ON i.id = a.invoice_id
            SET a.student_uid = i.student_uid
            WHERE (a.student_uid IS NULL OR a.student_uid = '') AND i.student_uid IS NOT NULL AND i.student_uid != ''
        " );

        // 2. Backfill student_uid from external customer child column where ext_child_id is set
        $wpdb->query( "
            UPDATE {$table} a
            INNER JOIN {$invoices} i ON i.id = a.invoice_id
            SET a.student_uid = CAST(i.ext_child_id AS CHAR)
            WHERE (a.student_uid IS NULL OR a.student_uid = '') AND i.ext_child_id IS NOT NULL AND i.ext_child_id != 0
        " );

        // 3. Backfill fee_category and student_uid fallback from agreement_fees
        $wpdb->query( "
            UPDATE {$table} a
            INNER JOIN {$fees} f ON f.invoice_id = a.invoice_id
            SET a.fee_category = f.fee_category,
                a.student_uid = IF(a.student_uid IS NULL OR a.student_uid = '', f.child_id, a.student_uid)
            WHERE (a.fee_category IS NULL OR a.fee_category = '') AND f.fee_category IS NOT NULL AND f.fee_category != ''
        " );

        // 4. Fallback for fee_category: set to general if still empty
        $wpdb->query( "
            UPDATE {$table}
            SET fee_category = 'general'
            WHERE fee_category IS NULL OR fee_category = ''
        " );
    }

    private static function upgrade_agreement_fees_table( $wpdb ): void {
        $table = $wpdb->prefix . 'olama_agreement_fees';
        $existing = $wpdb->get_col( "DESCRIBE {$table}", 0 );

        if ( ! in_array( 'status', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `status` varchar(30) NOT NULL DEFAULT 'active' AFTER `sort_order`" );
        }
        if ( ! in_array( 'cancelled_at', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `cancelled_at` datetime DEFAULT NULL AFTER `status`" );
        }
        if ( ! in_array( 'cancelled_by', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `cancelled_by` bigint(20) UNSIGNED DEFAULT NULL AFTER `cancelled_at`" );
        }
        if ( ! in_array( 'cancellation_reason', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `cancellation_reason` text DEFAULT NULL AFTER `cancelled_by`" );
        }
        if ( ! in_array( 'cancellation_amendment_id', (array) $existing, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN `cancellation_amendment_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `cancellation_reason`" );
        }
    }
}
