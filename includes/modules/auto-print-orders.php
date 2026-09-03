<?php
/**
 * Module: Auto-Print New Orders
 *
 * When an order reaches "processing" (payment cleared), it is queued for
 * printing. The office Mac runs a small polling agent (see mac-agent/ in the
 * toolkit repo) that pulls queued orders over an authenticated REST endpoint,
 * renders each one to an A4 PDF and sends it to the office printer, then calls
 * back to mark the order printed.
 *
 * Nothing connects *in* to the Mac — it only makes outbound HTTPS calls, so no
 * router / firewall changes are needed. If the Mac is off or offline, orders
 * stay queued and all print when it next checks in.
 *
 * Setup: WP Admin -> Tools -> Lior Auto-Print shows the REST URL + API key to
 * paste into the Mac agent's config.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register hooks only once WooCommerce is loaded. The toolkit's module loader
 * runs on plugins_loaded:10, which can be before WooCommerce itself, so defer.
 */
add_action( 'plugins_loaded', 'lior_print_init', 20 );

function lior_print_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	// Queue an order for printing when payment clears.
	add_action( 'woocommerce_order_status_processing', 'lior_print_enqueue_order', 10, 2 );

	// REST endpoints the Mac agent talks to.
	add_action( 'rest_api_init', 'lior_print_register_routes' );

	// Admin: status column on the orders list (legacy + HPOS) and a Tools page.
	add_filter( 'manage_edit-shop_order_columns', 'lior_print_add_column' );
	add_action( 'manage_shop_order_posts_custom_column', 'lior_print_render_column_legacy', 20, 2 );
	add_filter( 'manage_woocommerce_page_wc-orders_columns', 'lior_print_add_column' );
	add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'lior_print_render_column_hpos', 20, 2 );
	add_action( 'admin_menu', 'lior_print_admin_menu' );
	add_action( 'admin_post_lior_print_reprint', 'lior_print_handle_reprint' );
	add_action( 'admin_post_lior_print_regen_key', 'lior_print_handle_regen_key' );

	// Watchdog: warn by email if orders pile up unprinted and the agent is quiet.
	if ( ! wp_next_scheduled( 'lior_print_watchdog' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'lior_print_watchdog' );
	}
	add_action( 'lior_print_watchdog', 'lior_print_run_watchdog' );
}

/* -------------------------------------------------------------------------- */
/* API key                                                                    */
/* -------------------------------------------------------------------------- */

/**
 * Shared secret between this site and the Mac agent. Generated on first use and
 * stored in wp_options (never committed to the repo). Regenerate from the
 * Tools -> Lior Auto-Print page.
 */
function lior_print_get_key() {
	$key = get_option( 'lior_print_api_key' );
	if ( ! $key ) {
		$key = wp_generate_password( 40, false, false );
		update_option( 'lior_print_api_key', $key, false );
	}
	return $key;
}

/* -------------------------------------------------------------------------- */
/* Queueing                                                                   */
/* -------------------------------------------------------------------------- */

/**
 * Mark an order as needing to print. Skips orders already queued or printed so
 * bouncing an order in and out of "processing" doesn't reprint it. Use the
 * Reprint link on the orders list to deliberately print again.
 */
function lior_print_enqueue_order( $order_id, $order = null ) {
	if ( ! $order instanceof WC_Order ) {
		$order = wc_get_order( $order_id );
	}
	if ( ! $order ) {
		return;
	}

	$status = $order->get_meta( '_lior_print_status' );
	if ( 'queued' === $status || 'printed' === $status ) {
		return;
	}

	$order->update_meta_data( '_lior_print_status', 'queued' );
	$order->update_meta_data( '_lior_print_queued_at', time() );
	$order->save();
}

/* -------------------------------------------------------------------------- */
/* REST API                                                                   */
/* -------------------------------------------------------------------------- */

function lior_print_register_routes() {
	register_rest_route(
		'lior/v1',
		'/print-queue',
		array(
			'methods'             => 'GET',
			'callback'            => 'lior_print_rest_queue',
			'permission_callback' => 'lior_print_rest_auth',
		)
	);

	register_rest_route(
		'lior/v1',
		'/print-queue/(?P<id>\d+)/done',
		array(
			'methods'             => 'POST',
			'callback'            => 'lior_print_rest_done',
			'permission_callback' => 'lior_print_rest_auth',
			'args'                => array( 'id' => array( 'validate_callback' => 'is_numeric' ) ),
		)
	);

	register_rest_route(
		'lior/v1',
		'/print-queue/(?P<id>\d+)/failed',
		array(
			'methods'             => 'POST',
			'callback'            => 'lior_print_rest_failed',
			'permission_callback' => 'lior_print_rest_auth',
			'args'                => array( 'id' => array( 'validate_callback' => 'is_numeric' ) ),
		)
	);
}

/**
 * Every route requires the X-Lior-Key header to match the stored key.
 */
function lior_print_rest_auth( WP_REST_Request $request ) {
	$provided = (string) $request->get_header( 'x_lior_key' );
	$expected = lior_print_get_key();

	if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
		return new WP_Error( 'lior_print_forbidden', 'Bad or missing key.', array( 'status' => 403 ) );
	}
	return true;
}

/**
 * GET /wp-json/lior/v1/print-queue
 * Oldest-first list of queued orders, each with ready-to-print receipt HTML.
 */
function lior_print_rest_queue( WP_REST_Request $request ) {
	update_option( 'lior_print_last_poll', time(), false );

	$orders = wc_get_orders(
		array(
			'limit'      => 20,
			'orderby'    => 'date',
			'order'      => 'ASC',
			'return'     => 'objects',
			'meta_query' => array(
				array(
					'key'   => '_lior_print_status',
					'value' => 'queued',
				),
			),
		)
	);

	$out = array();
	foreach ( $orders as $order ) {
		$created = $order->get_date_created();
		$out[]   = array(
			'id'           => $order->get_id(),
			'number'       => $order->get_order_number(),
			'created'      => $created ? $created->date( 'c' ) : '',
			'receipt_html' => lior_print_render_receipt( $order ),
		);
	}

	return rest_ensure_response( $out );
}

/**
 * POST /wp-json/lior/v1/print-queue/{id}/done
 */
function lior_print_rest_done( WP_REST_Request $request ) {
	$order = wc_get_order( (int) $request['id'] );
	if ( ! $order ) {
		return new WP_Error( 'lior_print_no_order', 'No such order.', array( 'status' => 404 ) );
	}

	$order->update_meta_data( '_lior_print_status', 'printed' );
	$order->update_meta_data( '_lior_printed_at', time() );
	$order->save();

	return rest_ensure_response( array( 'ok' => true ) );
}

/**
 * POST /wp-json/lior/v1/print-queue/{id}/failed  (optional body: error=...)
 * Marks the order "failed" after 5 attempts so the watchdog / admin can see it.
 */
function lior_print_rest_failed( WP_REST_Request $request ) {
	$order = wc_get_order( (int) $request['id'] );
	if ( ! $order ) {
		return new WP_Error( 'lior_print_no_order', 'No such order.', array( 'status' => 404 ) );
	}

	$attempts = (int) $order->get_meta( '_lior_print_attempts' ) + 1;
	$order->update_meta_data( '_lior_print_attempts', $attempts );
	$order->update_meta_data( '_lior_print_last_error', sanitize_text_field( (string) $request->get_param( 'error' ) ) );

	if ( $attempts >= 5 ) {
		$order->update_meta_data( '_lior_print_status', 'failed' );
	}
	$order->save();

	return rest_ensure_response(
		array(
			'ok'       => true,
			'attempts' => $attempts,
		)
	);
}

/* -------------------------------------------------------------------------- */
/* Receipt template (A4)                                                      */
/* -------------------------------------------------------------------------- */

/**
 * Full standalone HTML document for one order. Rendered to PDF by headless
 * Chrome on the Mac, so all styling is inline and self-contained.
 *
 * Switching to an 80mm thermal printer later: change the @page size and the
 * two-column blocks to a single narrow column here — nothing else changes.
 */
function lior_print_render_receipt( $order ) {
	$shop    = get_bloginfo( 'name' );
	$created = $order->get_date_created();

	ob_start();
	?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
	@page { size: A4; margin: 16mm; }
	* { box-sizing: border-box; }
	body { font-family: -apple-system, Helvetica, Arial, sans-serif; color: #111; font-size: 12px; line-height: 1.45; }
	h1 { font-size: 20px; margin: 0 0 2px; }
	.muted { color: #555; }
	.meta { margin: 2px 0 16px; }
	.cols { display: flex; gap: 20px; }
	.box { border: 1px solid #ddd; padding: 10px 12px; width: 50%; }
	.box h3 { margin: 0 0 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #666; }
	table { width: 100%; border-collapse: collapse; margin-top: 16px; }
	th, td { text-align: left; padding: 7px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
	th { background: #f4f4f4; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
	td.num, th.num { text-align: right; white-space: nowrap; }
	tfoot td { border-bottom: none; }
	tfoot tr:last-child td { border-top: 2px solid #111; font-weight: bold; font-size: 13px; }
	.note { margin-top: 16px; border-left: 3px solid #111; padding: 7px 10px; background: #fafafa; }
</style>
</head>
<body>
	<h1><?php echo esc_html( $shop ); ?></h1>
	<div class="meta muted">
		Order #<?php echo esc_html( $order->get_order_number() ); ?>
		&nbsp;&middot;&nbsp; <?php echo esc_html( $created ? wc_format_datetime( $created, 'M j, Y g:i a' ) : '' ); ?>
		&nbsp;&middot;&nbsp; <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
		&nbsp;&middot;&nbsp; <?php echo esc_html( $order->get_payment_method_title() ); ?>
	</div>

	<div class="cols">
		<div class="box">
			<h3>Billing</h3>
			<?php echo wp_kses_post( $order->get_formatted_billing_address( '&mdash;' ) ); ?><br>
			<?php echo esc_html( $order->get_billing_phone() ); ?><br>
			<?php echo esc_html( $order->get_billing_email() ); ?>
		</div>
		<div class="box">
			<h3>Shipping</h3>
			<?php echo wp_kses_post( $order->get_formatted_shipping_address( $order->get_formatted_billing_address( '&mdash;' ) ) ); ?>
		</div>
	</div>

	<table>
		<thead>
			<tr>
				<th>Item</th>
				<th>SKU</th>
				<th class="num">Qty</th>
				<th class="num">Total</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $order->get_items() as $item ) : ?>
				<?php
				$product = $item->get_product();
				$sku     = $product ? $product->get_sku() : '';
				$meta    = wc_display_item_meta( $item, array( 'echo' => false ) );
				?>
				<tr>
					<td>
						<?php echo esc_html( $item->get_name() ); ?>
						<?php if ( $meta ) : ?>
							<div class="muted"><?php echo wp_kses_post( $meta ); ?></div>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $sku ? $sku : '—' ); ?></td>
					<td class="num"><?php echo esc_html( $item->get_quantity() ); ?></td>
					<td class="num"><?php echo wp_kses_post( wc_price( $order->get_line_total( $item, true ), array( 'currency' => $order->get_currency() ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
		<tfoot>
			<?php foreach ( $order->get_order_item_totals() as $total_row ) : ?>
				<tr>
					<td colspan="3" class="num"><?php echo esc_html( $total_row['label'] ); ?></td>
					<td class="num"><?php echo wp_kses_post( $total_row['value'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tfoot>
	</table>

	<?php if ( $order->get_customer_note() ) : ?>
		<div class="note"><strong>Customer note:</strong> <?php echo esc_html( $order->get_customer_note() ); ?></div>
	<?php endif; ?>
</body>
</html>
	<?php
	return ob_get_clean();
}

/* -------------------------------------------------------------------------- */
/* Admin: orders-list column                                                  */
/* -------------------------------------------------------------------------- */

function lior_print_add_column( $columns ) {
	$columns['lior_print'] = __( 'Print', 'lior-toolkit' );
	return $columns;
}

function lior_print_render_column_legacy( $column, $post_id ) {
	if ( 'lior_print' === $column ) {
		echo lior_print_column_html( wc_get_order( $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts below.
	}
}

function lior_print_render_column_hpos( $column, $order ) {
	if ( 'lior_print' === $column ) {
		echo lior_print_column_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts below.
	}
}

function lior_print_column_html( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return '';
	}

	$status = $order->get_meta( '_lior_print_status' );
	if ( ! $status ) {
		return '<span style="color:#999;">&mdash;</span>';
	}

	$colors = array(
		'queued'  => '#b26f00',
		'printed' => '#1a7f37',
		'failed'  => '#cf222e',
	);
	$color = isset( $colors[ $status ] ) ? $colors[ $status ] : '#555';

	$when = '';
	if ( 'printed' === $status && $order->get_meta( '_lior_printed_at' ) ) {
		$when = ' ' . human_time_diff( (int) $order->get_meta( '_lior_printed_at' ) ) . ' ago';
	} elseif ( 'queued' === $status && $order->get_meta( '_lior_print_queued_at' ) ) {
		$when = ' ' . human_time_diff( (int) $order->get_meta( '_lior_print_queued_at' ) ) . ' ago';
	}

	$reprint_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=lior_print_reprint&order_id=' . $order->get_id() ),
		'lior_print_reprint_' . $order->get_id()
	);

	$html  = '<strong style="color:' . esc_attr( $color ) . ';">' . esc_html( ucfirst( $status ) ) . '</strong>';
	$html .= '<span style="color:#999;">' . esc_html( $when ) . '</span>';
	if ( 'failed' === $status && $order->get_meta( '_lior_print_last_error' ) ) {
		$html .= '<br><span style="color:#999;">' . esc_html( $order->get_meta( '_lior_print_last_error' ) ) . '</span>';
	}
	$html .= '<br><a href="' . esc_url( $reprint_url ) . '">' . esc_html__( 'Reprint', 'lior-toolkit' ) . '</a>';

	return $html;
}

/* -------------------------------------------------------------------------- */
/* Admin: actions                                                             */
/* -------------------------------------------------------------------------- */

function lior_print_handle_reprint() {
	$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

	if ( ! $order_id || ! current_user_can( 'edit_shop_orders' ) || ! check_admin_referer( 'lior_print_reprint_' . $order_id ) ) {
		wp_die( 'Not allowed.' );
	}

	$order = wc_get_order( $order_id );
	if ( $order ) {
		$order->update_meta_data( '_lior_print_status', 'queued' );
		$order->update_meta_data( '_lior_print_queued_at', time() );
		$order->update_meta_data( '_lior_print_attempts', 0 );
		$order->delete_meta_data( '_lior_print_last_error' );
		$order->save();
	}

	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}

function lior_print_handle_regen_key() {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'lior_print_regen_key' ) ) {
		wp_die( 'Not allowed.' );
	}

	update_option( 'lior_print_api_key', wp_generate_password( 40, false, false ), false );
	wp_safe_redirect( admin_url( 'tools.php?page=lior-auto-print&regenerated=1' ) );
	exit;
}

/* -------------------------------------------------------------------------- */
/* Admin: Tools page                                                          */
/* -------------------------------------------------------------------------- */

function lior_print_admin_menu() {
	add_management_page(
		'Lior Auto-Print',
		'Lior Auto-Print',
		'manage_woocommerce',
		'lior-auto-print',
		'lior_print_admin_page'
	);
}

function lior_print_count_by_status( $status ) {
	return count(
		wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => array(
					array(
						'key'   => '_lior_print_status',
						'value' => $status,
					),
				),
			)
		)
	);
}

function lior_print_admin_page() {
	$key       = lior_print_get_key();
	$base      = rest_url( 'lior/v1/' );
	$last_poll = (int) get_option( 'lior_print_last_poll', 0 );
	?>
	<div class="wrap">
		<h1>Lior Auto-Print</h1>

		<?php if ( isset( $_GET['regenerated'] ) ) : ?>
			<div class="notice notice-success"><p>API key regenerated &mdash; update the Mac agent's config with the new key.</p></div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">REST base URL</th>
				<td><code><?php echo esc_html( $base ); ?></code></td>
			</tr>
			<tr>
				<th scope="row">API key</th>
				<td>
					<input type="text" readonly value="<?php echo esc_attr( $key ); ?>" style="width:32em;font-family:monospace;" onclick="this.select();">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<?php wp_nonce_field( 'lior_print_regen_key' ); ?>
						<input type="hidden" name="action" value="lior_print_regen_key">
						<button type="submit" class="button" onclick="return confirm('Regenerate the key? The Mac agent stops printing until its config is updated.');">Regenerate</button>
					</form>
				</td>
			</tr>
			<tr>
				<th scope="row">Queued</th>
				<td><?php echo (int) lior_print_count_by_status( 'queued' ); ?></td>
			</tr>
			<tr>
				<th scope="row">Printed</th>
				<td><?php echo (int) lior_print_count_by_status( 'printed' ); ?></td>
			</tr>
			<tr>
				<th scope="row">Failed</th>
				<td><?php echo (int) lior_print_count_by_status( 'failed' ); ?></td>
			</tr>
			<tr>
				<th scope="row">Last agent poll</th>
				<td><?php echo $last_poll ? esc_html( human_time_diff( $last_poll ) . ' ago' ) : 'never'; ?></td>
			</tr>
		</table>

		<p class="description">Mac agent install steps: see <code>mac-agent/README.md</code> in the toolkit repo.</p>
	</div>
	<?php
}

/* -------------------------------------------------------------------------- */
/* Watchdog                                                                   */
/* -------------------------------------------------------------------------- */

/**
 * Hourly: if the agent hasn't polled in 10+ minutes AND an order has been
 * waiting to print for 20+ minutes, email the admin. Throttled to once per 2h.
 */
function lior_print_run_watchdog() {
	$last_poll = (int) get_option( 'lior_print_last_poll', 0 );
	if ( $last_poll && ( time() - $last_poll ) < 600 ) {
		return;
	}

	$stuck = wc_get_orders(
		array(
			'limit'      => 1,
			'orderby'    => 'date',
			'order'      => 'ASC',
			'return'     => 'objects',
			'meta_query' => array(
				array(
					'key'   => '_lior_print_status',
					'value' => 'queued',
				),
			),
		)
	);
	if ( empty( $stuck ) ) {
		return;
	}

	$order    = $stuck[0];
	$queued_at = (int) $order->get_meta( '_lior_print_queued_at' );
	if ( ! $queued_at || ( time() - $queued_at ) < 1200 ) {
		return;
	}

	$last_alert = (int) get_option( 'lior_print_last_alert', 0 );
	if ( ( time() - $last_alert ) < 7200 ) {
		return;
	}
	update_option( 'lior_print_last_alert', time(), false );

	wp_mail(
		get_option( 'admin_email' ),
		'[Lior] Order print agent may be offline',
		"An order has been waiting to print for over 20 minutes and the office Mac has not checked in.\n\n"
		. "Check that the Mac is powered on, logged in, and online.\n\n"
		. admin_url( 'tools.php?page=lior-auto-print' )
	);
}
