<?php
/**
 * Read-only student adapter backed by Olama Core.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Student {

    private static function normalize_core_student( array $row ): object {
        $row['family_id']          = (string) ( $row['oracle_family_id'] ?? '' );
        $row['student_id']         = (string) ( $row['oracle_student_id'] ?? '' );
        $row['national_id']        = (string) ( $row['student_national_no'] ?? '' );
        $row['gender']             = (string) ( $row['student_gender_name'] ?? $row['student_gender'] ?? '' );
        $row['grade_name']         = (string) ( $row['class_name'] ?? '' );
        $row['section_name']       = (string) ( $row['section_name'] ?? '' );
        $row['academic_year_id']   = (string) ( $row['study_year'] ?? '' );
        $row['enrollment_status']  = (string) ( $row['student_year_status'] ?? $row['student_status_name'] ?? $row['student_status'] ?? '' );
        $row['sequence_in_family'] = is_numeric( $row['oracle_student_id'] ?? null )
            ? (int) $row['oracle_student_id']
            : 0;
        $row['is_active'] = isset( $row['is_active'] )
            ? (int) $row['is_active']
            : ( empty( $row['will_not_renew'] ) ? 1 : 0 );

        return (object) $row;
    }

    public static function get_student( string $student_uid ): ?object {
        $row = Olama_Reg_Core_Gateway::student( $student_uid );
        return $row ? self::normalize_core_student( (array) $row ) : null;
    }

    public static function get_family_students( string $family_uid, $study_year = '' ): array {
        $academic_year_id = is_numeric( $study_year ) ? (int) $study_year : 0;
        if ( $study_year !== '' && $academic_year_id <= 0 ) {
            global $wpdb;
            $academic_year_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}olama_academic_years
                 WHERE code = %s OR year_name = %s OR name_ar = %s
                 LIMIT 1",
                $study_year,
                $study_year,
                $study_year
            ) );
        }

        return array_map(
            static fn( object $student ): object => self::normalize_core_student( (array) $student ),
            Olama_Reg_Core_Gateway::students_for_family( $family_uid, $academic_year_id )
        );
    }

    public static function search( string $query, int $limit = 10 ): array {
        return array_map(
            static fn( object $student ): object => self::normalize_core_student( (array) $student ),
            Olama_Reg_Core_Gateway::search_students( $query, $limit )
        );
    }

    public static function get_students_list( array $args = [] ): array {
        return array_map(
            static fn( object $student ): object => self::normalize_core_student( (array) $student ),
            Olama_Reg_Core_Gateway::list_students( $args )
        );
    }

    public static function count_students( array $args = [] ): int {
        return Olama_Reg_Core_Gateway::count_students( $args );
    }

    public static function get_student_photo_url( ?int $attachment_id ): string {
        if ( ! $attachment_id ) {
            return get_avatar_url( 0, [ 'size' => 150 ] );
        }
        return wp_get_attachment_url( $attachment_id ) ?: get_avatar_url( 0, [ 'size' => 150 ] );
    }
}
