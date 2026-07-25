<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Central capability catalogue for Olama Family Billing.
 */
class Olama_Reg_Access {

    public static function register_with_olama_users(): void {
        if ( ! function_exists( 'olama_users_register_module' ) ) {
            return;
        }

        olama_users_register_module( [
            'id'           => 'olama_invoice',
            'plugin'       => 'olama-registration',
            'label'        => __( 'Olama Family Billing', 'olama-registration' ),
            'capability'   => 'olama_access_registration',
            'default_grant'=> false,
            'items'        => [
                [
                    'id'         => 'olama_invoice.contacts',
                    'type'       => 'submenu',
                    'label'      => __( 'Families and contacts', 'olama-registration' ),
                    'capability' => 'olama_manage_registration_families',
                    'actions'    => [
                        [
                            'id'         => 'olama_invoice.students',
                            'type'       => 'action',
                            'label'      => __( 'View student records', 'olama-registration' ),
                            'capability' => 'olama_manage_registration_students',
                        ],
                    ],
                ],
                [
                    'id'         => 'olama_invoice.fees',
                    'type'       => 'submenu',
                    'label'      => __( 'Fee templates', 'olama-registration' ),
                    'capability' => 'olama_manage_registration_fees',
                ],
                [
                    'id'         => 'olama_invoice.agreements',
                    'type'       => 'submenu',
                    'label'      => __( 'Agreements', 'olama-registration' ),
                    'capability' => 'olama_manage_registration_agreements',
                    'actions'    => [
                        self::action( 'agreement_admin_fields', __( 'Edit protected agreement fields', 'olama-registration' ), 'olama_edit_agreement_admin_fields' ),
                        self::action( 'agreement_amendment_create', __( 'Create agreement amendments', 'olama-registration' ), 'olama_create_agreement_amendment' ),
                        self::action( 'agreement_amendment_approve', __( 'Approve agreement amendments', 'olama-registration' ), 'olama_approve_agreement_amendment' ),
                        self::action( 'agreement_amendment_post', __( 'Post agreement amendments', 'olama-registration' ), 'olama_post_agreement_amendment' ),
                        self::action( 'agreement_reschedule', __( 'Reschedule agreement installments', 'olama-registration' ), 'olama_reschedule_agreement_installments' ),
                        self::action( 'agreement_cancel', __( 'Cancel financial agreements', 'olama-registration' ), 'olama_cancel_financial_agreement' ),
                        self::action( 'agreement_audit', __( 'View agreement audit trail', 'olama-registration' ), 'olama_view_agreement_audit' ),
                    ],
                ],
                [
                    'id'         => 'olama_invoice.invoices',
                    'type'       => 'submenu',
                    'label'      => __( 'Invoices', 'olama-registration' ),
                    'capability' => 'olama_manage_registration_invoices',
                ],
                [
                    'id'         => 'olama_invoice.payments',
                    'type'       => 'submenu',
                    'label'      => __( 'Payments and receipts', 'olama-registration' ),
                    'capability' => 'olama_manage_registration_payments',
                    'actions'    => [
                        self::action( 'record_payment', __( 'Record payments', 'olama-registration' ), 'olama_record_payments' ),
                        self::action( 'reverse_payment', __( 'Reverse payments', 'olama-registration' ), 'olama_reverse_payments' ),
                        self::action( 'confirm_bank_payment', __( 'Review bank payments', 'olama-registration' ), 'olama_confirm_bank_payments' ),
                        self::action( 'manage_cheques', __( 'Manage cheques', 'olama-registration' ), 'olama_manage_cheques' ),
                        self::action( 'open_cash_session', __( 'Open cash sessions', 'olama-registration' ), 'olama_open_cash_session' ),
                        self::action( 'close_cash_session', __( 'Close cash sessions', 'olama-registration' ), 'olama_close_cash_session' ),
                        self::action( 'review_cash_session', __( 'Review cash sessions', 'olama-registration' ), 'olama_review_cash_session' ),
                        self::action( 'transfer_cash_bank', __( 'Transfer cash to bank', 'olama-registration' ), 'olama_transfer_cash_bank' ),
                    ],
                ],
                [
                    'id'         => 'olama_invoice.accounts',
                    'type'       => 'submenu',
                    'label'      => __( 'Financial accounts', 'olama-registration' ),
                    'capability' => 'olama_manage_financial_accounts',
                ],
                [
                    'id'         => 'olama_invoice.reports',
                    'type'       => 'submenu',
                    'label'      => __( 'Financial reports', 'olama-registration' ),
                    'capability' => 'olama_manage_registration_reports',
                    'actions'    => [
                        self::action( 'cash_reports', __( 'View cash reports', 'olama-registration' ), 'olama_view_cash_reports' ),
                    ],
                ],
            ],
        ] );
    }

    public static function can_any( array $capabilities ): bool {
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        foreach ( array_unique( array_filter( array_map( 'sanitize_key', $capabilities ) ) ) as $capability ) {
            if ( current_user_can( $capability ) ) {
                return true;
            }
        }

        return false;
    }

    private static function action( string $id, string $label, string $capability ): array {
        return [
            'id'         => 'olama_invoice.' . $id,
            'type'       => 'action',
            'label'      => $label,
            'capability' => $capability,
        ];
    }
}
