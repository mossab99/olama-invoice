<?php
/**
 * Agreements Core Header class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Olama_Reg_Agreement {

    /**
     * Create a new agreement
     */
    public static function create( array $data ): int|false {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_agreements';

        $number = Olama_Reg_ID_Generator::generate_id( 'AGR' );

        $defaults = [
            'agreement_number' => $number,
            'payer_type'       => 'customer',
            'payer_id'         => 0,
            'participant_type' => 'child',
            'participant_id'   => 0, // kept for backward compatibility
            'participant_ids'  => null,
            'activity_type'    => 'kindergarten',
            'template_id'      => null,
            'academic_year_id' => null,
            'start_date'       => current_time( 'Y-m-d' ),
            'end_date'         => null,
            'status'           => 'draft',
            'total_amount'     => 0,
            'notes'            => '',
            'created_by'       => get_current_user_id(),
            'created_at'       => current_time( 'mysql' ),
            'updated_at'       => current_time( 'mysql' ),
        ];

        $insert_data = wp_parse_args( $data, $defaults );
        $insert_data = self::normalize_identity_fields( $insert_data );
        if ( $insert_data === false ) {
            return false;
        }

        // Ensure we don't pass null to not-null string fields if they are missing
        if ( empty( $insert_data['payer_type'] ) ) return false;

        if ( isset( $insert_data['participant_ids'] ) && is_array( $insert_data['participant_ids'] ) ) {
            $insert_data['participant_ids'] = wp_json_encode( array_values( array_filter( array_map(
                'sanitize_text_field',
                $insert_data['participant_ids']
            ) ) ) );
        } elseif ( empty( $insert_data['participant_ids'] ) && ! empty( $insert_data['participant_id'] ) ) {
            $insert_data['participant_ids'] = wp_json_encode( [ (int) $insert_data['participant_id'] ] );
        }

        $inserted = $wpdb->insert( $table, $insert_data );

        if ( $inserted ) {
            $id = (int) $wpdb->insert_id;
            if ( class_exists( 'Olama_Reg_Agreement_Participants' ) ) {
                Olama_Reg_Agreement_Participants::sync_from_fees( $id );
            }
            $new_agreement = self::get( $id );
            self::log_audit( $id, 'created', null, $new_agreement );
            return $id;
        }

        return false;
    }

    /**
     * Update header fields
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_agreements';

        if ( empty( $data ) ) return true;

        $before = self::get( $id );

        // Remove fields that shouldn't be updated here
        unset( $data['id'], $data['agreement_number'], $data['created_at'], $data['created_by'] );
        $data['updated_at'] = current_time( 'mysql' );
        $identity_keys = [ 'payer_type', 'payer_id', 'payer_ref', 'family_uid', 'customer_id', 'participant_ids' ];
        if ( array_intersect( $identity_keys, array_keys( $data ) ) ) {
            $identity_input = array_merge( [
                'payer_type'       => $before->payer_type ?? '',
                'payer_id'         => $before->payer_id ?? '',
                'payer_ref'        => $before->payer_ref ?? '',
                'family_uid'       => $before->family_uid ?? '',
                'customer_id'      => $before->customer_id ?? null,
                'participant_ids'  => $before->participant_ids_array ?? [],
                'academic_year_id' => $before->academic_year_id ?? 0,
            ], $data );
            $data = self::normalize_identity_fields( $identity_input );
            if ( $data === false ) {
                return false;
            }
        }

        if ( isset( $data['participant_ids'] ) && is_array( $data['participant_ids'] ) ) {
            $data['participant_ids'] = wp_json_encode( array_values( array_filter( array_map(
                'sanitize_text_field',
                $data['participant_ids']
            ) ) ) );
        }

        $updated = $wpdb->update( $table, $data, [ 'id' => $id ] );
        
        if ( $updated !== false ) {
            if ( class_exists( 'Olama_Reg_Agreement_Participants' ) ) {
                Olama_Reg_Agreement_Participants::sync_from_fees( $id );
            }
            $after = self::get( $id );
            $action = 'updated';
            if ( $before && isset( $data['status'] ) && $before->status !== $data['status'] ) {
                if ( $data['status'] === 'completed' ) {
                    $action = 'completed';
                } elseif ( $data['status'] === 'cancelled' ) {
                    $action = 'cancelled';
                } else {
                    $action = 'status_changed';
                }
            }
            self::log_audit( $id, $action, $before, $after );
        }
        
        return $updated !== false;
    }

    /**
     * Get single agreement row
     */
    public static function get( int $id ): object|null {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_agreements';

        if ( class_exists( 'Olama_Reg_Agreement_Amendment' ) ) {
            Olama_Reg_Agreement_Amendment::sync_posted_amendment_fees( $id );
        }

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
        
        if ( $row ) {
            $fees_table = $wpdb->prefix . 'olama_agreement_fees';
            $pids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT child_id FROM {$fees_table} WHERE agreement_id = %d AND child_id IS NOT NULL AND child_id != ''",
                $id
            ) );
            if ( ! empty( $pids ) ) {
                $row->participant_ids_array = array_map( function( $val ) {
                    return is_numeric( $val ) ? (int) $val : $val;
                }, $pids );
            } elseif ( ! empty( $row->participant_ids ) ) {
                $row->participant_ids_array = json_decode( $row->participant_ids, true ) ?: [];
            } else {
                $row->participant_ids_array = [ is_numeric( $row->participant_id ) ? (int) $row->participant_id : $row->participant_id ];
            }
        }
        
        if ( ! $row ) return null;

        if ( empty( $row->participant_type ) ) {
            $row->participant_type = ( $row->payer_type === 'family' ) ? 'student' : 'child';
        }

        // Resolve display names
        $row->payer_name = self::resolve_payer_name( $row->payer_type, $row->payer_id );
        
        $row->participant_names = [];
        foreach ( $row->participant_ids_array as $pid ) {
            $row->participant_names[ $pid ] = self::resolve_participant_name( $row->participant_type, (string)$pid );
        }
        $row->participant_name = implode( ' ، ', $row->participant_names );

        return $row;
    }

    /**
     * List agreements with filters
     */
    public static function get_list( array $args = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_agreements';

        $where = ["1=1"];
        $values = [];

        if ( ! empty( $args['status'] ) ) {
            $where[] = "status = %s";
            $values[] = $args['status'];
        }

        if ( ! empty( $args['payer_type'] ) ) {
            $where[] = "payer_type = %s";
            $values[] = $args['payer_type'];
        }

        if ( ! empty( $args['payer_id'] ) ) {
            $where[] = "payer_id = %s";
            $values[] = $args['payer_id'];
        }

        if ( ! empty( $args['activity_type'] ) ) {
            $where[] = "activity_type = %s";
            $values[] = $args['activity_type'];
        }

        if ( ! empty( $args['academic_year_id'] ) ) {
            $where[] = "academic_year_id = %d";
            $values[] = (int) $args['academic_year_id'];
        }

        $where_sql = implode( ' AND ', $where );
        
        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC";
        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare( $sql, ...$values );
        }

        $results = $wpdb->get_results( $sql );
        
        if ( empty( $results ) ) {
            return [];
        }

        // Gather all agreement IDs and payer details
        $agreement_ids = [];
        $family_payer_ids = [];
        $customer_payer_ids = [];

        foreach ( $results as $row ) {
            $agreement_ids[] = (int) $row->id;
            if ( $row->payer_type === 'family' ) {
                $family_payer_ids[] = $row->payer_id;
            } elseif ( $row->payer_type === 'customer' ) {
                $customer_payer_ids[] = $row->payer_id;
            }
        }

        // Query all agreement fees in bulk to get child_ids
        $agreement_child_ids = []; // agreement_id => array of child_ids
        if ( ! empty( $agreement_ids ) ) {
            $fees_table = $wpdb->prefix . 'olama_agreement_fees';
            $ids_placeholder = implode( ',', array_fill( 0, count( $agreement_ids ), '%d' ) );
            $fees_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT agreement_id, child_id FROM {$fees_table} WHERE agreement_id IN ($ids_placeholder) AND child_id IS NOT NULL AND child_id != ''",
                ...$agreement_ids
            ) );
            foreach ( $fees_rows as $f_row ) {
                $agreement_child_ids[ $f_row->agreement_id ][] = $f_row->child_id;
            }
        }

        // Collect participant ids and types
        $student_participant_ids = [];
        $child_participant_ids = [];

        foreach ( $results as $row ) {
            // Determine participant type
            if ( empty( $row->participant_type ) ) {
                $row->participant_type = ( $row->payer_type === 'family' ) ? 'student' : 'child';
            }

            // Resolve participant IDs array
            $agr_id = (int) $row->id;
            if ( ! empty( $agreement_child_ids[ $agr_id ] ) ) {
                $pids = array_unique( $agreement_child_ids[ $agr_id ] );
                $row->participant_ids_array = array_map( function( $val ) {
                    return is_numeric( $val ) ? (int) $val : $val;
                }, $pids );
            } elseif ( ! empty( $row->participant_ids ) ) {
                $row->participant_ids_array = json_decode( $row->participant_ids, true ) ?: [];
            } else {
                $row->participant_ids_array = [ is_numeric( $row->participant_id ) ? (int) $row->participant_id : $row->participant_id ];
            }

            // Group by participant type
            foreach ( $row->participant_ids_array as $pid ) {
                if ( ! empty( $pid ) ) {
                    if ( $row->participant_type === 'student' ) {
                        $student_participant_ids[] = $pid;
                    } elseif ( $row->participant_type === 'child' ) {
                        $child_participant_ids[] = $pid;
                    }
                }
            }
            // Add fallback participant_id to the collection as well
            if ( ! empty( $row->participant_id ) ) {
                if ( $row->participant_type === 'student' ) {
                    $student_participant_ids[] = $row->participant_id;
                } elseif ( $row->participant_type === 'child' ) {
                    $child_participant_ids[] = $row->participant_id;
                }
            }
        }

        // Batch query names for families
        $family_names = [];
        if ( ! empty( $family_payer_ids ) ) {
            $family_payer_ids = array_unique( $family_payer_ids );
            $families_table = $wpdb->prefix . 'olama_core_families';
            $uids_only = [];
            $ids_only = [];
            foreach ( $family_payer_ids as $fid ) {
                $uids_only[] = (string) $fid;
            }
            $prepare_args = [];
            $where_parts = [];
            if ( ! empty( $uids_only ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $uids_only ), '%s' ) );
                $where_parts[] = "family_uid IN ($placeholders)";
                $prepare_args = array_merge( $prepare_args, $uids_only );
            }
            if ( ! empty( $ids_only ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $ids_only ), '%d' ) );
                $where_parts[] = "id IN ($placeholders)";
                $prepare_args = array_merge( $prepare_args, $ids_only );
            }
            if ( ! empty( $where_parts ) ) {
                $where_clause = implode( ' OR ', $where_parts );
                $f_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, family_uid, oracle_family_id,
                            COALESCE(NULLIF(sponsor_full_name, ''), NULLIF(father_name, ''), oracle_family_id) AS family_name
                     FROM {$families_table}
                     WHERE {$where_clause}",
                    ...$prepare_args
                ) );
                foreach ( $f_rows as $f_row ) {
                    $family_names[ $f_row->family_uid ] = $f_row->family_name;
                    $family_names[ $f_row->oracle_family_id ] = $f_row->family_name;
                }
            }
        }

        // Batch query names for customers
        $customer_names = [];
        if ( ! empty( $customer_payer_ids ) ) {
            $customer_payer_ids = array_unique( $customer_payer_ids );
            $customers_table = $wpdb->prefix . 'olama_customers';
            $uids_only = [];
            $ids_only = [];
            foreach ( $customer_payer_ids as $cid ) {
                if ( is_numeric( $cid ) ) {
                    $ids_only[] = (int) $cid;
                }
                $uids_only[] = (string) $cid;
            }
            $prepare_args = [];
            $where_parts = [];
            if ( ! empty( $uids_only ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $uids_only ), '%s' ) );
                $where_parts[] = "customer_uid IN ($placeholders)";
                $prepare_args = array_merge( $prepare_args, $uids_only );
            }
            if ( ! empty( $ids_only ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $ids_only ), '%d' ) );
                $where_parts[] = "id IN ($placeholders)";
                $prepare_args = array_merge( $prepare_args, $ids_only );
            }
            if ( ! empty( $where_parts ) ) {
                $where_clause = implode( ' OR ', $where_parts );
                $c_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, customer_uid, customer_name FROM {$customers_table} WHERE {$where_clause}",
                    ...$prepare_args
                ) );
                foreach ( $c_rows as $c_row ) {
                    $customer_names[ $c_row->id ] = $c_row->customer_name;
                    $customer_names[ $c_row->customer_uid ] = $c_row->customer_name;
                }
            }
        }

        // Batch query names for students (participants)
        $student_names = [];
        if ( ! empty( $student_participant_ids ) ) {
            $student_participant_ids = array_unique( $student_participant_ids );
            $students_table = $wpdb->prefix . 'olama_core_students';
            $uids_only = [];
            $ids_only = [];
            foreach ( $student_participant_ids as $sid ) {
                $uids_only[] = (string) $sid;
            }
            $prepare_args = [];
            $where_parts = [];
            if ( ! empty( $uids_only ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $uids_only ), '%s' ) );
                $where_parts[] = "student_uid IN ($placeholders)";
                $prepare_args = array_merge( $prepare_args, $uids_only );
            }
            if ( ! empty( $ids_only ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $ids_only ), '%d' ) );
                $where_parts[] = "id IN ($placeholders)";
                $prepare_args = array_merge( $prepare_args, $ids_only );
            }
            if ( ! empty( $where_parts ) ) {
                $where_clause = implode( ' OR ', $where_parts );
                $s_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, student_uid, oracle_student_id, student_name
                     FROM {$students_table}
                     WHERE {$where_clause}",
                    ...$prepare_args
                ) );
                foreach ( $s_rows as $s_row ) {
                    $student_names[ $s_row->student_uid ] = $s_row->student_name;
                }
            }
        }

        // Batch query names for children (participants)
        $child_names = [];
        if ( ! empty( $child_participant_ids ) ) {
            $child_participant_ids = array_unique( $child_participant_ids );
            $children_table = $wpdb->prefix . 'olama_customer_children';
            $uids_only = [];
            $ids_only = [];
            foreach ( $child_participant_ids as $cid ) {
                if ( is_numeric( $cid ) ) {
                    $ids_only[] = (int) $cid;
                }
                $uids_only[] = (string) $cid;
            }
            $prepare_args = [];
            $where_parts = [];
            if ( ! empty( $uids_only ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $uids_only ), '%s' ) );
                $where_parts[] = "child_uid IN ($placeholders)";
                $prepare_args = array_merge( $prepare_args, $uids_only );
            }
            if ( ! empty( $ids_only ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $ids_only ), '%d' ) );
                $where_parts[] = "id IN ($placeholders)";
                $prepare_args = array_merge( $prepare_args, $ids_only );
            }
            if ( ! empty( $where_parts ) ) {
                $where_clause = implode( ' OR ', $where_parts );
                $ch_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, child_uid, child_name FROM {$children_table} WHERE {$where_clause}",
                    ...$prepare_args
                ) );
                foreach ( $ch_rows as $ch_row ) {
                    $child_names[ $ch_row->id ] = $ch_row->child_name;
                    $child_names[ $ch_row->child_uid ] = $ch_row->child_name;
                }
            }
        }

        // Populate rows with names
        foreach ( $results as $row ) {
            // Payer name
            if ( $row->payer_type === 'family' ) {
                $row->payer_name = isset( $family_names[ $row->payer_id ] ) ? $family_names[ $row->payer_id ] : 'Unknown Family';
            } elseif ( $row->payer_type === 'customer' ) {
                $row->payer_name = isset( $customer_names[ $row->payer_id ] ) ? $customer_names[ $row->payer_id ] : 'Unknown Customer';
            } else {
                $row->payer_name = '';
            }

            // Participant names
            $names = [];
            foreach ( $row->participant_ids_array as $pid ) {
                if ( ! empty( $pid ) ) {
                    if ( $row->participant_type === 'student' ) {
                        $names[] = isset( $student_names[ $pid ] ) ? $student_names[ $pid ] : 'Unknown Student';
                    } elseif ( $row->participant_type === 'child' ) {
                        $names[] = isset( $child_names[ $pid ] ) ? $child_names[ $pid ] : 'Unknown Child';
                    }
                }
            }

            if ( ! empty( $names ) ) {
                $row->participant_name = implode( ' ، ', $names );
            } else {
                // Fallback to participant_id
                $pid = $row->participant_id;
                if ( $row->participant_type === 'student' ) {
                    $row->participant_name = isset( $student_names[ $pid ] ) ? $student_names[ $pid ] : 'Unknown Student';
                } elseif ( $row->participant_type === 'child' ) {
                    $row->participant_name = isset( $child_names[ $pid ] ) ? $child_names[ $pid ] : 'Unknown Child';
                } else {
                    $row->participant_name = '';
                }
            }
        }

        return $results;
    }

    /**
     * Change status with validation
     */
    public static function change_status( int $id, string $new_status ): bool|WP_Error {
        $allowed_statuses = [ 'draft', 'completed', 'cancelled' ];
        if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
            return new WP_Error( 'invalid_status', 'Invalid status provided.' );
        }

        $agreement = self::get( $id );
        if ( ! $agreement ) {
            return new WP_Error( 'not_found', 'Agreement not found.' );
        }

        if ( $new_status === 'completed' ) {
            if ( ! class_exists( 'Olama_Reg_Agreement_Invoice' ) ) {
                return new WP_Error( 'missing_accounting', 'Accounting module is not loaded.' );
            }

            return Olama_Reg_Agreement_Invoice::complete_agreement( $id );
        }

        if ( $new_status === 'cancelled' && class_exists( 'Olama_Reg_Agreement_Invoice' ) ) {
            return Olama_Reg_Agreement_Invoice::cancel_agreement( $id );
        }

        return self::update( $id, [ 'status' => $new_status ] );
    }

    /**
     * Recalculate total_amount from sum of all net_amount in agreement_fees
     */
    public static function recalculate_total( int $id ): void {
        global $wpdb;
        $fees_table = $wpdb->prefix . 'olama_agreement_fees';
        $agr_table  = $wpdb->prefix . 'olama_agreements';

        $total = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(net_amount) FROM {$fees_table} WHERE agreement_id = %d",
            $id
        ) );

        $wpdb->update(
            $agr_table,
            [ 'total_amount' => $total, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ],
            [ '%f', '%s' ],
            [ '%d' ]
        );
    }

    private static function normalize_identity_fields( array $data ): array|false {
        $payer_type = sanitize_key( $data['payer_type'] ?? '' );
        if ( ! in_array( $payer_type, [ 'family', 'customer' ], true ) ) {
            return false;
        }

        $data['payer_type'] = $payer_type;

        if ( $payer_type === 'family' ) {
            $reference = sanitize_text_field(
                $data['family_uid'] ?? $data['payer_ref'] ?? $data['payer_id'] ?? ''
            );
            $family = Olama_Reg_Core_Gateway::family( $reference );
            if ( ! $family ) {
                return false;
            }

            $data['payer_ref']       = $family->family_uid;
            $data['payer_id']        = $family->family_uid; // Transitional read compatibility.
            $data['family_uid']      = $family->family_uid;
            $data['oracle_family_id'] = $family->oracle_family_id;
            $data['customer_id']     = null;
            $data['participant_type'] = 'student';
            $data['participant_id']  = 0;

            $participant_ids = is_array( $data['participant_ids'] ?? null )
                ? $data['participant_ids']
                : [];
            $canonical_participant_ids = [];
            foreach ( $participant_ids as $student_uid ) {
                $student = Olama_Reg_Core_Gateway::student( sanitize_text_field( $student_uid ) );
                if ( ! $student || $student->family_uid !== $family->family_uid ) {
                    return false;
                }
                $canonical_participant_ids[] = $student->student_uid;
            }
            $data['participant_ids'] = array_values( array_unique( $canonical_participant_ids ) );
        } else {
            $reference = $data['customer_id'] ?? $data['payer_ref'] ?? $data['payer_id'] ?? '';
            $customer = is_numeric( $reference )
                ? Olama_Reg_Customer::get( (int) $reference )
                : Olama_Reg_Customer::get_by_uid( sanitize_text_field( $reference ) );
            if ( ! $customer ) {
                return false;
            }

            $data['payer_ref']        = $customer->customer_uid;
            $data['payer_id']         = (string) $customer->id; // Transitional read compatibility.
            $data['family_uid']       = null;
            $data['oracle_family_id'] = null;
            $data['customer_id']      = (int) $customer->id;
            $data['participant_type'] = 'child';
        }

        $academic_year_id = absint( $data['academic_year_id'] ?? 0 );
        $data['study_year'] = $academic_year_id > 0
            ? Olama_Reg_Academic_Year_Context::core_study_year( $academic_year_id )
            : null;

        return $data;
    }

    // ── Helper Resolvers ───────────────────────────────────────────────────

    private static function resolve_payer_name( string $type, string $id ): string {
        global $wpdb;
        if ( $type === 'customer' ) {
            $table = $wpdb->prefix . 'olama_customers';
            $name = $wpdb->get_var( $wpdb->prepare( "SELECT customer_name FROM {$table} WHERE customer_uid = %s OR id = %d", $id, (int)$id ) );
            return $name ? $name : 'Unknown Customer';
        } elseif ( $type === 'family' ) {
            $family = Olama_Reg_Core_Gateway::family( $id );
            return $family ? $family->display_name : 'Unknown Family';
        }
        return '';
    }

    public static function resolve_participant_name( string $type, string $id ): string {
        global $wpdb;
        if ( $type === 'child' ) {
            $table = $wpdb->prefix . 'olama_customer_children';
            $name = $wpdb->get_var( $wpdb->prepare( "SELECT child_name FROM {$table} WHERE child_uid = %s OR id = %d", $id, (int)$id ) );
            return $name ? $name : 'Unknown Child';
        } elseif ( $type === 'student' ) {
            $student = Olama_Reg_Core_Gateway::student( $id );
            return $student ? $student->display_name : 'Unknown Student';
        }
        return '';
    }

    public static function log_audit( int $entity_id, string $action, ?object $before, ?object $after ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'olama_billing_audit', [
            'entity_type'  => 'agreement',
            'entity_id'    => $entity_id,
            'action'       => $action,
            'actor_id'     => get_current_user_id(),
            'before_state' => $before ? wp_json_encode( $before ) : null,
            'after_state'  => $after ? wp_json_encode( $after ) : null,
            'ip_address'   => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
        ] );
    }
}
