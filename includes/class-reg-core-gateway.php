<?php
/**
 * The only Olama Invoice boundary for Core-owned data.
 *
 * Core owns enrolled families, enrolled students, academic placement,
 * transportation, and synchronized Oracle financial records. Invoice treats
 * all of that data as read-only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Core_Gateway {

    public static function available(): bool {
        if ( ! function_exists( 'olama_core' ) ) {
            return false;
        }

        $core = olama_core();
        foreach ( [ 'families', 'students', 'knowledge', 'financial', 'transportation' ] as $service ) {
            if ( ! is_object( $core ) || ! method_exists( $core, $service ) ) {
                return false;
            }
        }

        return true;
    }

    public static function family( string $reference ): ?object {
        if ( ! self::available() || $reference === '' ) {
            return null;
        }

        $families = olama_core()->families();
        $row = strpos( $reference, 'ORA-FAM-' ) === 0
            ? $families->get_by_uid( $reference )
            : $families->get_by_oracle_id( $reference );

        if ( ! $row ) {
            $row = $families->get_by_uid( $reference );
        }

        return $row ? self::family_object( $row ) : null;
    }

    public static function family_by_id( int $id ): ?object {
        if ( ! self::available() || $id <= 0 ) {
            return null;
        }

        $row = olama_core()->families()->get_by_id( $id );

        return $row ? self::family_object( $row ) : null;
    }

    public static function student( string $student_uid ): ?object {
        if ( ! self::available() || $student_uid === '' ) {
            return null;
        }

        $row = olama_core()->students()->get_by_uid( $student_uid );

        return $row ? self::student_object( $row ) : null;
    }

    public static function students_for_family( string $family_reference, int $academic_year_id = 0 ): array {
        $family = self::family( $family_reference );
        if ( ! $family ) {
            return [];
        }

        $study_year = $academic_year_id > 0
            ? Olama_Reg_Academic_Year_Context::core_study_year( $academic_year_id )
            : '';

        $card = olama_core()->knowledge()->get_family_card(
            $family->oracle_family_id,
            $study_year
        );

        if ( ! $card || empty( $card['students'] ) ) {
            return [];
        }

        return array_map( [ self::class, 'student_object' ], $card['students'] );
    }

    public static function search_families( string $query, int $limit = 20 ): array {
        global $wpdb;

        $like  = '%' . $wpdb->esc_like( sanitize_text_field( $query ) ) . '%';
        $limit = max( 1, min( 100, $limit ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT f.*, COUNT(s.id) AS resolved_students_count
             FROM " . olama_core()->read_models()->table( 'families' ) . " f
             LEFT JOIN " . olama_core()->read_models()->table( 'students' ) . " s ON s.family_uid = f.family_uid
             WHERE f.oracle_family_id LIKE %s
                OR f.family_uid LIKE %s
                OR f.sponsor_full_name LIKE %s
                OR f.father_name LIKE %s
                OR f.mother_name LIKE %s
                OR f.father_mobile LIKE %s
                OR f.mother_mobile LIKE %s
                OR f.primary_mobile LIKE %s
             GROUP BY f.id
             ORDER BY COALESCE(NULLIF(f.sponsor_full_name, ''), NULLIF(f.father_name, ''), f.oracle_family_id)
             LIMIT %d",
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $like,
            $limit
        ), ARRAY_A ) ?: [];

        return array_map( [ self::class, 'family_object' ], $rows );
    }

    public static function search_students( string $query, int $limit = 20 ): array {
        global $wpdb;

        $like  = '%' . $wpdb->esc_like( sanitize_text_field( $query ) ) . '%';
        $limit = max( 1, min( 100, $limit ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*
             FROM " . olama_core()->read_models()->table( 'students' ) . " s
             WHERE s.student_uid LIKE %s
                OR s.oracle_student_id LIKE %s
                OR s.student_name LIKE %s
                OR s.student_national_no LIKE %s
             ORDER BY s.student_name
             LIMIT %d",
            $like,
            $like,
            $like,
            $like,
            $limit
        ), ARRAY_A ) ?: [];

        return array_map( [ self::class, 'student_object' ], $rows );
    }

    public static function list_families( array $args = [] ): array {
        global $wpdb;

        $search   = sanitize_text_field( $args['search'] ?? '' );
        $status   = sanitize_key( $args['status'] ?? 'all' );
        $per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
        $offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );
        $where    = [ '1=1' ];
        $params   = [];

        if ( $search !== '' ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(f.oracle_family_id LIKE %s OR f.family_uid LIKE %s OR f.sponsor_full_name LIKE %s OR f.father_name LIKE %s)';
            array_push( $params, $like, $like, $like, $like );
        }
        if ( $status === 'active' ) {
            $where[] = 'COALESCE(f.is_active, 1) = 1';
        } elseif ( $status === 'inactive' ) {
            $where[] = 'f.is_active = 0';
        }

        array_push( $params, $per_page, $offset );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT f.*, COUNT(s.id) AS resolved_students_count
             FROM " . olama_core()->read_models()->table( 'families' ) . " f
             LEFT JOIN " . olama_core()->read_models()->table( 'students' ) . " s ON s.family_uid = f.family_uid
             WHERE " . implode( ' AND ', $where ) . "
             GROUP BY f.id
             ORDER BY CAST(f.oracle_family_id AS UNSIGNED) DESC
             LIMIT %d OFFSET %d",
            ...$params
        ), ARRAY_A ) ?: [];

        return array_map( [ self::class, 'family_object' ], $rows );
    }

    public static function count_families( array $args = [] ): int {
        global $wpdb;

        $search = sanitize_text_field( $args['search'] ?? '' );
        $status = sanitize_key( $args['status'] ?? 'all' );
        $where  = [ '1=1' ];
        $params = [];

        if ( $search !== '' ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(oracle_family_id LIKE %s OR family_uid LIKE %s OR sponsor_full_name LIKE %s OR father_name LIKE %s)';
            array_push( $params, $like, $like, $like, $like );
        }
        if ( $status === 'active' ) {
            $where[] = 'COALESCE(is_active, 1) = 1';
        } elseif ( $status === 'inactive' ) {
            $where[] = 'is_active = 0';
        }

        $sql = "SELECT COUNT(*) FROM " . olama_core()->read_models()->table( 'families' ) . " WHERE " . implode( ' AND ', $where );
        return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_var( $sql ) );
    }

    public static function list_students( array $args = [] ): array {
        global $wpdb;

        $search   = sanitize_text_field( $args['search'] ?? '' );
        $per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
        $offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );
        $where    = [ '1=1' ];
        $params   = [];

        if ( $search !== '' ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(s.student_uid LIKE %s OR s.oracle_student_id LIKE %s OR s.student_name LIKE %s OR s.student_national_no LIKE %s)';
            array_push( $params, $like, $like, $like, $like );
        }

        array_push( $params, $per_page, $offset );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, y.class_name, y.section_name, y.study_year,
                    y.student_year_status
             FROM " . olama_core()->read_models()->table( 'students' ) . " s
             LEFT JOIN " . olama_core()->read_models()->table( 'student_years' ) . " y
                ON y.id = (
                    SELECT y2.id
                    FROM " . olama_core()->read_models()->table( 'student_years' ) . " y2
                    WHERE y2.student_uid = s.student_uid
                    ORDER BY y2.study_year DESC, y2.id DESC
                    LIMIT 1
                )
             WHERE " . implode( ' AND ', $where ) . "
             ORDER BY CAST(s.oracle_family_id AS UNSIGNED) DESC,
                      CAST(s.oracle_student_id AS UNSIGNED) ASC
             LIMIT %d OFFSET %d",
            ...$params
        ), ARRAY_A ) ?: [];

        return array_map( [ self::class, 'student_object' ], $rows );
    }

    public static function count_students( array $args = [] ): int {
        global $wpdb;

        $search = sanitize_text_field( $args['search'] ?? '' );
        $where  = [ '1=1' ];
        $params = [];

        if ( $search !== '' ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(student_uid LIKE %s OR oracle_student_id LIKE %s OR student_name LIKE %s OR student_national_no LIKE %s)';
            array_push( $params, $like, $like, $like, $like );
        }

        $sql = "SELECT COUNT(*) FROM " . olama_core()->read_models()->table( 'students' ) . " WHERE " . implode( ' AND ', $where );
        return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_var( $sql ) );
    }

    public static function oracle_financial( string $family_reference, int $academic_year_id ): array {
        $family = self::family( $family_reference );
        if ( ! $family ) {
            return [
                'summary'      => null,
                'dues'         => [],
                'transactions' => [],
                'study_year'   => '',
            ];
        }

        $study_year = Olama_Reg_Academic_Year_Context::core_study_year( $academic_year_id );
        if ( $study_year === '' ) {
            return [
                'summary'      => null,
                'dues'         => [],
                'transactions' => [],
                'study_year'   => '',
            ];
        }

        $financial = olama_core()->financial();
        return [
            'summary'      => $financial->get_summary( $family->oracle_family_id, $study_year ),
            'dues'         => $financial->get_dues( $family->oracle_family_id, $study_year ),
            'transactions' => $financial->get_transactions( $family->oracle_family_id, $study_year ),
            'study_year'   => $study_year,
        ];
    }

    public static function transportation( string $family_reference, int $academic_year_id ): array {
        $family = self::family( $family_reference );
        $study_year = Olama_Reg_Academic_Year_Context::core_study_year( $academic_year_id );

        if ( ! $family || $study_year === '' ) {
            return [];
        }

        return olama_core()->transportation()->get_family(
            $family->oracle_family_id,
            $study_year
        );
    }

    public static function family_object( array $row ): object {
        $name = self::first_value( $row, [
            'sponsor_full_name',
            'father_name',
            'mother_name',
            'oracle_family_id',
            'family_uid',
        ] );

        return (object) array_merge( $row, [
            'family_uid'       => (string) ( $row['family_uid'] ?? '' ),
            'oracle_family_id'  => (string) ( $row['oracle_family_id'] ?? '' ),
            'display_name'      => $name,
            'display_phone'     => self::first_value( $row, [ 'primary_mobile', 'father_mobile', 'mother_mobile' ] ),
            'display_address'   => self::first_value( $row, [ 'family_address', 'address' ] ),
            'is_active'         => isset( $row['is_active'] ) && $row['is_active'] !== null ? (int) $row['is_active'] : 1,
            'students_count'    => (int) ( $row['resolved_students_count'] ?? $row['students_count'] ?? 0 ),
        ] );
    }

    public static function student_object( array $row ): object {
        return (object) array_merge( $row, [
            'student_uid'       => (string) ( $row['student_uid'] ?? '' ),
            'family_uid'        => (string) ( $row['family_uid'] ?? '' ),
            'oracle_family_id'   => (string) ( $row['oracle_family_id'] ?? '' ),
            'oracle_student_id'  => (string) ( $row['oracle_student_id'] ?? '' ),
            'display_name'       => (string) ( $row['student_name'] ?? '' ),
            'national_id'        => (string) ( $row['student_national_no'] ?? '' ),
            'gender'             => self::first_value( $row, [ 'student_gender_name', 'student_gender' ] ),
            'grade_id'           => (string) ( $row['class_id'] ?? $row['grade_id'] ?? '' ),
            'grade_name'         => (string) ( $row['class_name'] ?? $row['grade_name'] ?? '' ),
            'section_id'         => (string) ( $row['section_id'] ?? '' ),
            'section_name'       => (string) ( $row['section_name'] ?? '' ),
            'study_year'         => (string) ( $row['study_year'] ?? '' ),
            'status_name'        => self::first_value( $row, [ 'student_year_status', 'student_status_name', 'student_status' ] ),
            'blacklist'          => (int) ( $row['black_list'] ?? $row['blacklist'] ?? 0 ),
            'is_active'          => empty( $row['will_not_renew'] ) ? 1 : 0,
        ] );
    }

    private static function first_value( array $row, array $keys ): string {
        foreach ( $keys as $key ) {
            if ( isset( $row[ $key ] ) && trim( (string) $row[ $key ] ) !== '' ) {
                return trim( (string) $row[ $key ] );
            }
        }

        return '';
    }
}
