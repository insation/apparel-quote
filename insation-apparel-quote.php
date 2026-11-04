<?php
/**
 * Plugin Name:       Apparel Quote & Mockup (SanMar)
 * Description:       Customers pick a SanMar product, colour and size quantities, upload their logo, see a live mockup and send a quote request. No prices are shown. Shortcode: [apparel_quote]
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Insation Technologies
 * Author URI:        https://insationtech.com
 * License:           GPL-2.0-or-later
 * Text Domain:       insation-apparel-quote
 */

defined( 'ABSPATH' ) || exit;

define( 'IAQ_VERSION', '1.0.2' );
define( 'IAQ_FILE', __FILE__ );
define( 'IAQ_DIR', plugin_dir_path( __FILE__ ) );
define( 'IAQ_URL', plugin_dir_url( __FILE__ ) );
define( 'IAQ_IMG_BASE', 'https://cdnm.sanmar.com/imglib/mresjpg/' );

require_once IAQ_DIR . 'includes/class-iaq-catalog.php';
require_once IAQ_DIR . 'includes/class-iaq-rest.php';
require_once IAQ_DIR . 'includes/class-iaq-quotes.php';
require_once IAQ_DIR . 'includes/class-iaq-admin.php';
require_once IAQ_DIR . 'includes/class-iaq-frontend.php';
require_once IAQ_DIR . 'includes/class-iaq-updater.php';

/**
 * Plugin settings with defaults.
 */
function iaq_settings() {
	$defaults = array(
		'notify_email'      => get_option( 'admin_email' ),
		'customer_copy'     => 1,
		'hidden_categories' => array( 'Personal Protection' ),
		'hidden_styles'     => '',
		'max_upload_mb'     => 15,
		'intro_text'        => 'Pick a product, choose your colour and sizes, upload your logo to see how it looks, and send us your request. We will get back to you with a quote.',
		'thank_you_text'    => 'Thanks! We received your request and will get back to you with a quote shortly.',
	);
	$saved = get_option( 'iaq_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

register_activation_hook( __FILE__, 'iaq_activate' );
function iaq_activate() {
	IAQ_Catalog::create_table();
	$meta = get_option( 'iaq_catalog_meta', array() );
	if ( empty( $meta['styles'] ) && file_exists( IAQ_DIR . 'data/catalog.json.gz' ) ) {
		IAQ_Catalog::import_bundled();
	}
	IAQ_Quotes::register_post_type();
	IAQ_Quotes::ensure_upload_dirs();
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );

add_action( 'init', array( 'IAQ_Quotes', 'register_post_type' ) );
add_action( 'rest_api_init', array( 'IAQ_REST', 'register_routes' ) );
add_action( 'init', array( 'IAQ_Frontend', 'init' ) );
if ( is_admin() ) {
	IAQ_Admin::init();
}
IAQ_Updater::init();

/**
 * Background catalog import from a URL (used by the admin "download link" option and
 * for remote setup). Runs in WP-Cron so large SanMar files are not cut off by request limits.
 */
add_action( 'iaq_cron_import', 'iaq_run_url_import' );
function iaq_run_url_import( $url ) {
	update_option( 'iaq_import_status', array( 'state' => 'running', 'time' => current_time( 'mysql' ) ), false );
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 900 ); // phpcs:ignore
	}
	$tmp = download_url( $url, 600 );
	if ( is_wp_error( $tmp ) ) {
		update_option( 'iaq_import_status', array( 'state' => 'error', 'message' => 'Download failed: ' . $tmp->get_error_message(), 'time' => current_time( 'mysql' ) ), false );
		return;
	}
	$fh   = fopen( $tmp, 'rb' ); // phpcs:ignore
	$head = $fh ? fread( $fh, 2 ) : ''; // phpcs:ignore
	if ( $fh ) {
		fclose( $fh ); // phpcs:ignore
	}
	$path = $tmp . ( 'PK' === $head ? '.zip' : '.csv' );
	rename( $tmp, $path ); // phpcs:ignore
	$res = IAQ_Catalog::import_sdl( $path );
	@unlink( $path ); // phpcs:ignore
	update_option(
		'iaq_import_status',
		is_wp_error( $res )
			? array( 'state' => 'error', 'message' => $res->get_error_message(), 'time' => current_time( 'mysql' ) )
			: array( 'state' => 'done', 'styles' => (int) $res, 'time' => current_time( 'mysql' ) ),
		false
	);
}
