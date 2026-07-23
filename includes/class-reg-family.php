<?php
/**
 * Read-only family adapter backed by Olama Core.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Family {

    /**
     * Normalize an Olama Core family for the billing UI.
     *
     * Billing records use the Oracle family number (for example "1163") as
     * family_uid, while Core uses a canonical key such as "ORA-FAM-1163".
     */
    private static function normalize_core_family( array $row ): object {
        $display_name = '';
        foreach ( [ 'sponsor_full_name', 'father_name', 'mother_name', 'oracle_family_id', 'family_uid' ] as $key ) {
            if ( ! empty( $row[ $key ] ) ) {
                $display_name = (string) $row[ $key ];
                break;
            }
        }

        $row['core_family_uid'] = (string) ( $row['family_uid'] ?? '' );
        $row['family_uid']      = (string) ( $row['oracle_family_id'] ?? $row['family_uid'] ?? '' );
        $row['family_name']     = $display_name;
        $row['address']         = (string) ( $row['family_address'] ?? $row['address'] ?? '' );
        $row['active_student_count'] = (int) ( $row['resolved_students_count'] ?? $row['students_count'] ?? 0 );
        $row['total_student_count']  = $row['active_student_count'];
        $row['is_active']       = isset( $row['is_active'] ) && $row['is_active'] !== null
            ? (int) $row['is_active']
            : 1;

        return (object) $row;
    }

    public static function get_family( string $family_uid ): ?object {
        $row = Olama_Reg_Core_Gateway::family( $family_uid );
        return $row ? self::normalize_core_family( (array) $row ) : null;
    }

    public static function get_family_by_id( int $id ): ?object {
        $row = Olama_Reg_Core_Gateway::family_by_id( $id );
        return $row ? self::normalize_core_family( (array) $row ) : null;
    }

    /**
     * Search the Core family directory and return the shape expected by Hub.
     */
    public static function search( string $query, int $limit = 20 ): array {
        return array_map( static function ( object $family ): object {
            return (object) [
                'uid'           => $family->oracle_family_id,
                'core_uid'      => $family->family_uid,
                'name'          => $family->display_name,
                'phone'         => $family->display_phone,
                'is_active'     => $family->is_active,
                'student_count' => $family->students_count,
            ];
        }, Olama_Reg_Core_Gateway::search_families( $query, $limit ) );
    }

    /**
     * Get family list for WP_List_Table.
     */
    public static function get_families_list( array $args = [] ): array {
        return array_map(
            static fn( object $family ): object => self::normalize_core_family( (array) $family ),
            Olama_Reg_Core_Gateway::list_families( $args )
        );
    }

    public static function count_families( array $args = [] ): int {
        return Olama_Reg_Core_Gateway::count_families( $args );
    }
}
