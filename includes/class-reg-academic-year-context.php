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
        global $wpdb;

        if ( $academic_year_id <= 0 ) {
            return null;
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_academic_years WHERE id = %d LIMIT 1",
            $academic_year_id
        ) );

        return $row ? self::hydrate( $row ) : null;
    }

    public static function current(): ?object {
        global $wpdb;

        $row = $wpdb->get_row(
            "SELECT * FROM {$wpdb->prefix}olama_academic_years
             WHERE is_current = 1 OR is_active = 1
             ORDER BY is_current DESC, is_active DESC, id DESC
             LIMIT 1"
        );

        return $row ? self::hydrate( $row ) : null;
    }

    public static function core_study_year( int $academic_year_id ): string {
        $year = self::get( $academic_year_id );
        return $year ? (string) $year->core_study_year : '';
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
