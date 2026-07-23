<?php
/**
 * WP_List_Table — Families
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Olama_Reg_Family_Table extends WP_List_Table {

    private int $total_items = 0;

    public function __construct() {
        parent::__construct( [
            'singular' => 'family',
            'plural'   => 'families',
            'ajax'     => false,
        ] );
    }

    public function get_columns(): array {
        return [
            'family_uid'          => __( 'رقم العائلة', 'olama-registration' ),
            'father_name'         => __( 'اسم الأب', 'olama-registration' ),
            'active_student_count'=> __( 'الطلاب النشطون', 'olama-registration' ),
            'is_active'           => __( 'الحالة', 'olama-registration' ),
            'actions_col'         => __( 'إجراءات', 'olama-registration' ),
        ];
    }

    protected function get_sortable_columns(): array {
        return [
            'family_uid' => [ 'family_uid', true ],
            'is_active'  => [ 'is_active', false ],
        ];
    }

    public function prepare_items(): void {
        $per_page = 20;
        $current  = $this->get_pagenum();

        $args = [
            'search'   => sanitize_text_field( $_REQUEST['s'] ?? '' ),
            'status'   => sanitize_text_field( $_REQUEST['status'] ?? 'all' ),
            'per_page' => $per_page,
            'offset'   => ( $current - 1 ) * $per_page,
        ];

        $this->total_items = Olama_Reg_Family::count_families( $args );
        $this->items       = Olama_Reg_Family::get_families_list( $args );

        $this->set_pagination_args( [
            'total_items' => $this->total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $this->total_items / $per_page ),
        ] );

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    protected function column_family_uid( $item ): string {
        $edit_url = add_query_arg( [
            'page'       => 'olama-registration',
            'family_uid' => $item->family_uid,
        ], admin_url( 'admin.php' ) );

        return '<span class="olama-reg-uid-badge">' . esc_html( $item->family_uid ) . '</span>'
             . '<div class="row-actions">'
             . '<span class="edit"><a href="' . esc_url( $edit_url ) . '">' . __( 'فتح', 'olama-registration' ) . '</a></span>'
             . '</div>';
    }

    protected function column_father_name( $item ): string {
        return esc_html( trim( ( $item->father_first_name ?? '' ) . ' ' . ( $item->father_family_name ?? '' ) ) )
            ?: esc_html( $item->family_name ?? '—' );
    }

    protected function column_active_student_count( $item ): string {
        $count = (int) ( $item->active_student_count ?? 0 );
        $total = (int) ( $item->total_student_count ?? 0 );
        return "<strong>{$count}</strong> / {$total}";
    }

    protected function column_is_active( $item ): string {
        if ( $item->is_active ) {
            return '<span class="olama-reg-badge olama-reg-badge--active">' . __( 'نشط', 'olama-registration' ) . '</span>';
        }
        return '<span class="olama-reg-badge olama-reg-badge--inactive">' . __( 'غير نشط', 'olama-registration' ) . '</span>';
    }

    protected function column_actions_col( $item ): string {
        $edit_url  = add_query_arg( [ 'page' => 'olama-registration', 'family_uid' => $item->family_uid ], admin_url( 'admin.php' ) );
        $print_url = add_query_arg( [ 'page' => 'olama-registration-contacts', 'action' => 'print', 'family_uid' => $item->family_uid ], admin_url( 'admin.php' ) );

        return '<a href="' . esc_url( $edit_url ) . '" class="button button-small">' . __( 'فتح', 'olama-registration' ) . '</a> '
             . '<a href="' . esc_url( $print_url ) . '" class="button button-small" target="_blank">' . '🖨️' . '</a>';
    }

    protected function column_default( $item, $column_name ): string {
        return esc_html( $item->$column_name ?? '—' );
    }

    protected function get_views(): array {
        $status  = sanitize_text_field( $_REQUEST['status'] ?? 'all' );
        $base    = admin_url( 'admin.php?page=olama-registration-contacts&view=families' );

        $statuses = [
            'all'      => __( 'الكل',        'olama-registration' ),
            'active'   => __( 'النشطة',      'olama-registration' ),
            'inactive' => __( 'غير النشطة',  'olama-registration' ),
        ];

        $views = [];
        foreach ( $statuses as $key => $label ) {
            $url   = $key === 'all' ? $base : add_query_arg( 'status', $key, $base );
            $class = $status === $key ? ' class="current"' : '';
            $views[ $key ] = "<a href='{$url}'{$class}>{$label}</a>";
        }
        return $views;
    }
}
