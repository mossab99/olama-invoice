<?php
/**
 * Olama Registration Agreement Participants Model.
 *
 * @package Olama_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Olama_Reg_Agreement_Participants {

    /**
     * Get all participants for an agreement.
     *
     * @param int $agreement_id
     * @return object[]
     */
    public static function get_by_agreement( int $agreement_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_agreement_participants';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE agreement_id = %d ORDER BY id ASC",
            $agreement_id
        ) ) ?: [];
    }

    /**
     * Get student UIDs for a family agreement.
     *
     * @param int $agreement_id
     * @return string[]
     */
    public static function get_student_uids( int $agreement_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_agreement_participants';

        return $wpdb->get_col( $wpdb->prepare(
            "SELECT student_uid FROM {$table} WHERE agreement_id = %d AND participant_type = 'student' AND student_uid IS NOT NULL",
            $agreement_id
        ) ) ?: [];
    }

    /**
     * Get child IDs for a customer agreement.
     *
     * @param int $agreement_id
     * @return int[]
     */
    public static function get_child_ids( int $agreement_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_agreement_participants';

        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT child_id FROM {$table} WHERE agreement_id = %d AND participant_type = 'child' AND child_id IS NOT NULL",
            $agreement_id
        ) ) ?: [] );
    }

    /**
     * Add a participant to an agreement.
     *
     * @param int $agreement_id
     * @param array $data
     * @return int|false
     */
    public static function add( int $agreement_id, array $data ): int|false {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_agreement_participants';

        $payer_type       = sanitize_key( $data['payer_type'] ?? 'family' );
        $family_uid       = sanitize_text_field( $data['family_uid'] ?? '' );
        $participant_type = sanitize_key( $data['participant_type'] ?? 'student' );
        $participant_ref  = sanitize_text_field( $data['participant_ref'] ?? '' );
        $student_uid      = ! empty( $data['student_uid'] ) ? sanitize_text_field( $data['student_uid'] ) : null;
        $oracle_family_id = ! empty( $data['oracle_family_id'] ) ? sanitize_text_field( $data['oracle_family_id'] ) : null;
        $oracle_student_id = ! empty( $data['oracle_student_id'] ) ? sanitize_text_field( $data['oracle_student_id'] ) : null;
        $name_snapshot    = ! empty( $data['participant_name_snapshot'] ) ? sanitize_text_field( $data['participant_name_snapshot'] ) : null;
        $child_id         = ! empty( $data['child_id'] ) ? absint( $data['child_id'] ) : null;
        $role             = sanitize_key( $data['role'] ?? 'beneficiary' );
        $created_by       = get_current_user_id();

        if ( $payer_type === 'family' ) {
            $inserted = $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                 (agreement_id, payer_type, family_uid, oracle_family_id, participant_type, participant_ref, student_uid, oracle_student_id, participant_name_snapshot, child_id, role, created_by)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, NULL, %s, %d)",
                $agreement_id,
                $payer_type,
                $family_uid,
                $oracle_family_id,
                $participant_type,
                $participant_ref,
                $student_uid,
                $oracle_student_id,
                $name_snapshot,
                $role,
                $created_by
            ) );
        } else {
            $inserted = $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                 (agreement_id, payer_type, family_uid, participant_type, participant_ref, student_uid, child_id, role, created_by)
                 VALUES (%d, %s, %s, %s, %s, NULL, %d, %s, %d)",
                $agreement_id,
                $payer_type,
                $family_uid,
                $participant_type,
                $participant_ref,
                $child_id,
                $role,
                $created_by
            ) );
        }

        if ( $inserted ) {
            return (int) $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Remove a participant.
     *
     * @param int $id
     * @return bool
     */
    public static function remove( int $id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_agreement_participants';

        $deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

        return $deleted !== false;
    }

    /**
     * Sync participants from the agreement's current fee rows.
     * Called after fees are saved/updated/deleted to keep participants in sync.
     *
     * @param int $agreement_id
     */
    public static function sync_from_fees( int $agreement_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_agreement_participants';
        $agr_table = $wpdb->prefix . 'olama_agreements';
        $fees_table = $wpdb->prefix . 'olama_agreement_fees';

        $agreement = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$agr_table} WHERE id = %d",
            $agreement_id
        ) );

        if ( ! $agreement ) {
            return;
        }

        $payer_type = $agreement->payer_type;
        $payer_id   = $agreement->payer_id;
        $is_family  = ( $payer_type === 'family' );

        // 1. Get distinct child_id values from the fees table for this agreement
        $fee_children = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT COALESCE(NULLIF(student_uid, ''), NULLIF(participant_ref, ''), child_id)
             FROM {$fees_table}
             WHERE agreement_id = %d
               AND COALESCE(NULLIF(student_uid, ''), NULLIF(participant_ref, ''), child_id) IS NOT NULL",
            $agreement_id
        ) ) ?: [];

        // 2. If no fees are present, fallback to the agreement's participant_ids JSON
        if ( empty( $fee_children ) ) {
            $json_ids = json_decode( $agreement->participant_ids, true ) ?: [];
            if ( empty( $json_ids ) && ! empty( $agreement->participant_id ) ) {
                $json_ids = [ $agreement->participant_id ];
            }
            $fee_children = array_filter( array_map( 'trim', array_map( 'strval', $json_ids ) ) );
        }

        $active_refs = [];

        // 3. Insert or update active participants
        foreach ( $fee_children as $pid ) {
            $pid = trim( (string) $pid );
            if ( empty( $pid ) || $pid === '0' ) {
                continue;
            }

            $participant_type = $is_family ? 'student' : 'child';
            $participant_ref  = $pid;
            $active_refs[]    = $participant_ref;

            $student_uid = $is_family ? (string) $pid : null;
            $child_id    = ( ! $is_family && is_numeric( $pid ) ) ? (int) $pid : null;

            if ( $is_family ) {
                $student = Olama_Reg_Core_Gateway::student( $student_uid );
                if ( ! $student ) {
                    continue;
                }
                $family_uid = (string) ( $agreement->family_uid ?: $student->family_uid );
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$table}
                     (agreement_id, payer_type, family_uid, oracle_family_id, participant_type, participant_ref, student_uid, oracle_student_id, participant_name_snapshot, child_id, role, created_by)
                     VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, NULL, 'beneficiary', %d)",
                    $agreement_id,
                    $payer_type,
                    $family_uid,
                    $student->oracle_family_id,
                    $participant_type,
                    $participant_ref,
                    $student_uid,
                    $student->oracle_student_id,
                    $student->display_name,
                    (int) ( $agreement->created_by ?? 0 )
                ) );
            } else {
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$table}
                     (agreement_id, payer_type, family_uid, participant_type, participant_ref, student_uid, child_id, role, created_by)
                     VALUES (%d, %s, %s, %s, %s, NULL, %d, 'beneficiary', %d)",
                    $agreement_id,
                    $payer_type,
                    $payer_id,
                    $participant_type,
                    $participant_ref,
                    $child_id,
                    (int) ( $agreement->created_by ?? 0 )
                ) );
            }
        }

        // 4. Delete participants that are no longer associated with any fees/agreement config
        if ( ! empty( $active_refs ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $active_refs ), '%s' ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE agreement_id = %d AND participant_ref NOT IN ({$placeholders})",
                $agreement_id,
                ...$active_refs
            ) );
        } else {
            $wpdb->delete( $table, [ 'agreement_id' => $agreement_id ], [ '%d' ] );
        }
    }

    /**
     * Get all agreements for a specific student.
     *
     * @param string $student_uid
     * @return object[]
     */
    public static function get_agreements_for_student( string $student_uid ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_agreement_participants';
        $agr_table = $wpdb->prefix . 'olama_agreements';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT a.* FROM {$agr_table} a
             INNER JOIN {$table} ap ON ap.agreement_id = a.id
             WHERE ap.participant_type = 'student' AND ap.student_uid = %s
             ORDER BY a.created_at DESC",
            $student_uid
        ) ) ?: [];
    }

    /**
     * Get all agreements for a family.
     *
     * @param string $family_uid
     * @param int|null $academic_year_id
     * @return object[]
     */
    public static function get_agreements_for_family( string $family_uid, ?int $academic_year_id = null ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_agreement_participants';
        $agr_table = $wpdb->prefix . 'olama_agreements';

        $where = "ap.family_uid = %s";
        $args = [ $family_uid ];

        if ( $academic_year_id !== null && $academic_year_id > 0 ) {
            $where .= " AND a.academic_year_id = %d";
            $args[] = $academic_year_id;
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT a.* FROM {$agr_table} a
             INNER JOIN {$table} ap ON ap.agreement_id = a.id
             WHERE {$where}
             ORDER BY a.created_at DESC",
            ...$args
        ) ) ?: [];
    }
}
