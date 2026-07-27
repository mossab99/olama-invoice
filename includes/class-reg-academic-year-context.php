<?php
/**
 * Canonical bridge between the local academic-year row and Olama Core study_year.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Academic_Year_Context {

    public static function normalize_code( string $value ): string {
        $value = trim( str_replace( [ '/', '\\', '–', '—', '_' ], '-', $value ) );
        $value = preg_replace( '/\s+/u', '', $value );

        if ( preg_match( '/(\d{4})\D*(\d{4})/', $value, $matches ) ) {
            return $matches[1] . '-' . $matches[2];
        }

        return sanitize_text_field( $value );
    }

    public static function get( int $academic_year_id ): ?object {
        if ( $academic_year_id <= 0 ) {
            return null;
        }

        $row = function_exists( 'olama_core' ) && method_exists( olama_core(), 'academic_calendar' )
            ? olama_core()->academic_calendar()->year( $academic_year_id )
            : null;

        return $row ? self::hydrate( $row ) : null;
    }

    public static function current(): ?object {
        $row = function_exists( 'olama_core' ) && method_exists( olama_core(), 'academic_context' )
            ? olama_core()->academic_context()->current_year()
            : null;

        return $row ? self::hydrate( $row ) : null;
    }

    public static function core_study_year( int $academic_year_id ): string {
        $year = self::get( $academic_year_id );
        return $year ? (string) $year->core_study_year : '';
    }

    public static function assert_writable( int $academic_year_id ) {
        if ( ! function_exists( 'olama_core' ) || ! method_exists( olama_core(), 'academic_context' ) ) {
            return new \WP_Error( 'academic_context_unavailable', __( 'Olama Core academic context is not available.', 'olama-registration' ) );
        }
        return olama_core()->academic_context()->assert_writable_year( $academic_year_id );
    }

    private static function hydrate( object $row ): object {
        global $wpdb;

        $candidate = (string) ( $row->code ?? $row->year_name ?? $row->name_ar ?? '' );
        $canonical = self::normalize_code( $candidate );
        $core_year = '';

        $available = $wpdb->get_col(
            "SELECT DISTINCT study_year
             FROM {$wpdb->prefix}olama_core_student_years
             WHERE study_year IS NOT NULL AND study_year != ''"
        ) ?: [];

        foreach ( $available as $study_year ) {
            if ( self::normalize_code( (string) $study_year ) === $canonical ) {
                $core_year = (string) $study_year;
                break;
            }
        }

        $row->canonical_code  = $canonical;
        $row->core_study_year = $core_year ?: $canonical;

        return $row;
    }
}
