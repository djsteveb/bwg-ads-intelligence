<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BWG_AI_Sessions_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( [
			'singular' => 'session',
			'plural'   => 'sessions',
			'ajax'     => false,
		] );
	}

	public function get_columns() {
		return [
			'cb'               => '<input type="checkbox">',
			'id'               => 'ID',
			'email'            => 'Email',
			'website_url'      => 'URL',
			'status'           => 'Status',
			'step_completed'   => 'Step',
			'compliance_flags' => 'Flags',
			'created_at'       => 'Created',
		];
	}

	protected function get_sortable_columns() {
		return [
			'id'             => [ 'id', true ],
			'email'          => [ 'email', false ],
			'status'         => [ 'status', false ],
			'step_completed' => [ 'step_completed', false ],
			'created_at'     => [ 'created_at', true ],
		];
	}

	protected function get_bulk_actions() {
		return [ 'delete' => 'Delete' ];
	}

	protected function column_default( $item, $col ) {
		return esc_html( $item[ $col ] ?? '' );
	}

	protected function column_cb( $item ) {
		return '<input type="checkbox" name="session_ids[]" value="' . absint( $item['id'] ) . '">';
	}

	protected function column_id( $item ) {
		$url = admin_url( 'admin.php?page=bwg-ai&action=detail&session=' . absint( $item['id'] ) );
		return '<a href="' . esc_url( $url ) . '"><strong>#' . absint( $item['id'] ) . '</strong></a>';
	}

	protected function column_email( $item ) {
		return esc_html( $item['email'] );
	}

	protected function column_website_url( $item ) {
		$url = esc_url( $item['website_url'] );
		return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item['website_url'] ) . '</a>';
	}

	protected function column_status( $item ) {
		$cls = 'bwg-ai-status-' . sanitize_html_class( $item['status'] );
		return '<span class="' . esc_attr( $cls ) . '">' . esc_html( $item['status'] ) . '</span>';
	}

	protected function column_step_completed( $item ) {
		return absint( $item['step_completed'] ) . ' / 5';
	}

	protected function column_compliance_flags( $item ) {
		$n = absint( $item['compliance_flags'] );
		if ( $n === 0 ) {
			return '<span style="color:#2d6a4f;">0</span>';
		}
		$cls = $n >= 5 ? 'bwg-ai-flag-high' : ( $n >= 2 ? 'bwg-ai-flag-medium' : 'bwg-ai-flag-low' );
		return '<span class="' . esc_attr( $cls ) . '">' . esc_html( $n ) . '</span>';
	}

	protected function column_created_at( $item ) {
		return esc_html( $item['created_at'] );
	}

	public function prepare_items() {
		global $wpdb;
		$p = $wpdb->prefix . 'bwg_ai_';

		$per_page = 20;
		$page     = $this->get_pagenum();
		$offset   = ( $page - 1 ) * $per_page;

		$status = isset( $_GET['status_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['status_filter'] ) ) : '';
		$search = isset( $_GET['s'] )             ? sanitize_text_field( wp_unslash( $_GET['s'] ) )             : '';

		$allowed_ob = [ 'id', 'email', 'status', 'step_completed', 'created_at' ];
		$orderby    = 'id';
		$order      = 'DESC';
		if ( isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], $allowed_ob, true ) ) {
			$orderby = sanitize_text_field( wp_unslash( $_GET['orderby'] ) );
		}
		if ( isset( $_GET['order'] ) && in_array( strtoupper( wp_unslash( $_GET['order'] ) ), [ 'ASC', 'DESC' ], true ) ) {
			$order = strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ) ) );
		}

		$where  = [ '1=1' ];
		$values = [];

		if ( $status ) {
			$where[]  = 's.status = %s';
			$values[] = $status;
		}
		if ( $search ) {
			$where[]  = '(s.email LIKE %s OR s.website_url LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// Ads flagged count: number of ads rows with non-trivial compliance_flags JSON.
		$data_sql = "
			SELECT s.id, s.email, s.website_url, s.status, s.step_completed, s.created_at,
			       COUNT(a.id) AS compliance_flags
			FROM `{$p}sessions` s
			LEFT JOIN `{$p}ads` a
				ON a.session_id = s.id
				AND a.compliance_flags IS NOT NULL
				AND a.compliance_flags NOT IN ('', '[]')
			WHERE {$where_sql}
			GROUP BY s.id
			ORDER BY {$orderby} {$order}
			LIMIT %d OFFSET %d
		";

		$count_sql = "SELECT COUNT(DISTINCT s.id) FROM `{$p}sessions` s WHERE {$where_sql}";

		if ( $values ) {
			$data_args  = array_merge( $values, [ $per_page, $offset ] );
			$this->items = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			$total       = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$values ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$this->items = $wpdb->get_results( $wpdb->prepare( $data_sql, $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$p}sessions`" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		$this->set_pagination_args( [
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		] );

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
	}

	protected function no_items() {
		esc_html_e( 'No sessions found.', 'bwg-ads-intel' );
	}
}


function bwg_ai_render_sessions_list() {
	global $wpdb;
	$p = $wpdb->prefix . 'bwg_ai_';

	// Handle bulk delete.
	if (
		isset( $_POST['action'] ) &&
		'delete' === $_POST['action'] &&
		! empty( $_POST['session_ids'] ) &&
		check_admin_referer( 'bulk-sessions' )
	) {
		$tables = [ 'sessions', 'discovered', 'ads', 'access', 'reports', 'audit_log' ];
		$ids    = array_filter( array_map( 'absint', (array) $_POST['session_ids'] ) );

		foreach ( $ids as $id ) {
			foreach ( $tables as $table ) {
				$col = ( 'sessions' === $table ) ? 'id' : 'session_id';
				$wpdb->delete( "{$p}{$table}", [ $col => $id ], [ '%d' ] );
			}
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( count( $ids ) . ' session(s) deleted.' ) . '</p></div>';
	}

	// Status counts for filter tabs.
	$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM `{$p}sessions` GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	$counts = [ 'all' => 0 ];
	foreach ( $rows as $row ) {
		$counts[ $row['status'] ] = (int) $row['cnt'];
		$counts['all']           += (int) $row['cnt'];
	}

	$current = isset( $_GET['status_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['status_filter'] ) ) : '';
	$base    = admin_url( 'admin.php?page=bwg-ai' );

	$table = new BWG_AI_Sessions_List_Table();
	$table->prepare_items();
	?>
	<div class="wrap bwg-ai-wrap">
		<h1 class="wp-heading-inline">Ads Intelligence — Sessions</h1>
		<hr class="wp-header-end">

		<ul class="subsubsub">
			<li>
				<a href="<?php echo esc_url( $base ); ?>" class="<?php echo '' === $current ? 'current' : ''; ?>">
					All <span class="count">(<?php echo esc_html( $counts['all'] ); ?>)</span>
				</a>
			</li>
			<?php foreach ( [ 'active', 'complete', 'archived' ] as $s ) :
				if ( empty( $counts[ $s ] ) ) continue; ?>
				<li> | <a href="<?php echo esc_url( add_query_arg( 'status_filter', $s, $base ) ); ?>"
						class="<?php echo $current === $s ? 'current' : ''; ?>">
						<?php echo esc_html( ucfirst( $s ) ); ?> <span class="count">(<?php echo esc_html( $counts[ $s ] ); ?>)</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<form method="get">
			<input type="hidden" name="page" value="bwg-ai">
			<?php if ( $current ) : ?>
				<input type="hidden" name="status_filter" value="<?php echo esc_attr( $current ); ?>">
			<?php endif; ?>
			<?php $table->search_box( 'Search', 'bwg-ai-search' ); ?>
		</form>

		<form method="post">
			<?php wp_nonce_field( 'bulk-sessions' ); ?>
			<?php $table->display(); ?>
		</form>
	</div>
	<?php
}
