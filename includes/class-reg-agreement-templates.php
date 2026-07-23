<?php
/**
 * Agreement Templates CRUD
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Olama_Reg_Agreement_Templates {

    public static function get( int $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}olama_agreement_templates WHERE id = %d", $id ) );
    }

    public static function get_list( array $args = [] ) {
        global $wpdb;
        $where = "WHERE 1=1";
        
        if ( isset( $args['activity_type'] ) && $args['activity_type'] !== '' ) {
            $where .= $wpdb->prepare( " AND activity_type = %s", $args['activity_type'] );
        }
        
        if ( isset( $args['is_active'] ) ) {
            $where .= $wpdb->prepare( " AND is_active = %d", $args['is_active'] );
        }

        return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}olama_agreement_templates {$where} ORDER BY is_default DESC, name ASC" );
    }

    public static function get_default(): object|null {
        global $wpdb;
        return $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}olama_agreement_templates
             WHERE is_active = 1
             ORDER BY is_default DESC, id ASC
             LIMIT 1"
        );
    }

    public static function create( array $data ): int|WP_Error {
        global $wpdb;
        $template_key = sanitize_key( $data['template_key'] ?? '' );
        if ( $template_key === '' ) {
            $template_key = 'contract-' . strtolower( wp_generate_password( 10, false, false ) );
        }

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'olama_agreement_templates',
            [
                'template_key'  => $template_key,
                'activity_type' => sanitize_text_field( $data['activity_type'] ?? '' ),
                'name'          => sanitize_text_field( $data['name'] ?? '' ),
                'description'   => sanitize_textarea_field( $data['description'] ?? '' ),
                'contract_content' => wp_kses_post( $data['contract_content'] ?? '' ),
                'version'       => 1,
                'is_default'    => ! empty( $data['is_default'] ) ? 1 : 0,
                'is_active'     => isset( $data['is_active'] ) ? (int) $data['is_active'] : 1,
                'created_at'    => current_time( 'mysql' ),
                'updated_at'    => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return new WP_Error( 'db_error', 'Could not insert template.' );
        }

        $id = (int) $wpdb->insert_id;
        if ( ! empty( $data['is_default'] ) ) {
            self::make_default( $id );
        }
        return $id;
    }

    public static function update( int $id, array $data ): bool|\WP_Error {
        global $wpdb;

        $before = self::get( $id );
        if ( ! $before ) {
            return new \WP_Error( 'template_not_found', __( 'نموذج العقد غير موجود.', 'olama-registration' ) );
        }

        $content = wp_kses_post( $data['contract_content'] ?? $before->contract_content );
        $template_key = sanitize_key( $data['template_key'] ?? $before->template_key );
        if ( $template_key === '' ) {
            $template_key = (string) $before->template_key ?: 'contract-' . strtolower( wp_generate_password( 10, false, false ) );
        }
        $version = (int) $before->version;
        if ( $content !== (string) $before->contract_content ) {
            $version++;
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'olama_agreement_templates',
            [
                'template_key'     => $template_key,
                'activity_type'    => sanitize_text_field( $data['activity_type'] ?? $before->activity_type ),
                'name'             => sanitize_text_field( $data['name'] ?? $before->name ),
                'description'      => sanitize_textarea_field( $data['description'] ?? $before->description ),
                'contract_content' => $content,
                'version'          => $version,
                'is_default'       => ! empty( $data['is_default'] ) ? 1 : 0,
                'is_active'        => ! empty( $data['is_active'] ) ? 1 : 0,
                'updated_at'       => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            return new \WP_Error( 'template_update_failed', __( 'تعذر تحديث نموذج العقد.', 'olama-registration' ) );
        }

        if ( ! empty( $data['is_default'] ) ) {
            self::make_default( $id );
        }
        return true;
    }

    private static function make_default( int $id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_agreement_templates';
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_default = IF(id = %d, 1, 0)", $id ) );
    }

    public static function get_fees( int $template_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}olama_agreement_template_fees WHERE template_id = %d ORDER BY sort_order ASC", $template_id ) );
    }

    public static function get_clauses( int $template_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}olama_agreement_template_clauses WHERE template_id = %d ORDER BY sort_order ASC", $template_id ) );
    }

    public static function save_template_relations( int $template_id, array $fees, array $clauses = [] ) {
        global $wpdb;

        // Clear existing
        $wpdb->delete( $wpdb->prefix . 'olama_agreement_template_fees', [ 'template_id' => $template_id ] );
        $wpdb->delete( $wpdb->prefix . 'olama_agreement_template_clauses', [ 'template_id' => $template_id ] );

        // Insert fees
        if ( ! empty( $fees['category'] ) && is_array( $fees['category'] ) ) {
            $sort = 1;
            foreach ( $fees['category'] as $i => $cat ) {
                $category = sanitize_text_field( $cat );
                $label    = sanitize_text_field( $fees['label'][$i] ?? '' );
                $amount   = floatval( $fees['amount'][$i] ?? 0 );
                $discount = floatval( $fees['discount'][$i] ?? 0 );
                $net      = $amount - $discount;

                if ( ! empty( $label ) ) {
                    $wpdb->insert(
                        $wpdb->prefix . 'olama_agreement_template_fees',
                        [
                            'template_id'  => $template_id,
                            'fee_category' => $category,
                            'label'        => $label,
                            'amount'       => $amount,
                            'discount'     => $discount,
                            'net_amount'   => $net,
                            'sort_order'   => $sort++
                        ],
                        [ '%d', '%s', '%s', '%f', '%f', '%f', '%d' ]
                    );
                }
            }
        }

        // Legal content now lives in agreement_templates.contract_content.
    }

    /**
     * Apply template to an agreement
     */
    public static function apply_to_agreement( int $template_id, int $agreement_id ): bool {
        $fees    = self::get_fees( $template_id );
        // Add fees
        foreach ( $fees as $fee ) {
            Olama_Reg_Agreement_Fees::add( $agreement_id, [
                'fee_category' => $fee->fee_category,
                'label'        => $fee->label,
                'amount'       => $fee->amount,
                'discount'     => $fee->discount,
            ] );
        }

        return true;
    }
}
