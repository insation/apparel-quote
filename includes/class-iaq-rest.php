<?php
/**
 * REST endpoints used by the quote form.
 *
 *   GET  /iaq/v1/catalog        light list of all visible styles
 *   GET  /iaq/v1/style/{style}  colours, sizes and image paths for one style
 *   GET  /iaq/v1/img?p=...      same-origin copy of a SanMar product image (lets the
 *                               browser export the mockup canvas as a PNG)
 *   GET  /iaq/v1/token          fresh form token (works behind page caching)
 *   POST /iaq/v1/submit         quote request
 */

defined( 'ABSPATH' ) || exit;

class IAQ_REST {

	const NS = 'iaq/v1';

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/catalog',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'catalog' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/style/(?P<style>[A-Za-z0-9_\-\.]{1,40})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'style' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/img',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'image' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'p' => array(
						'required' => true,
						'type'     => 'string',
					),
					'w' => array(
						'required' => false,
						'type'     => 'integer',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/token',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'token' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'IAQ_Quotes', 'handle_submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function catalog() {
		$res = rest_ensure_response( IAQ_Catalog::compact_index() );
		$res->header( 'Cache-Control', 'public, max-age=3600' );
		return $res;
	}

	public static function style( WP_REST_Request $req ) {
		$st = IAQ_Catalog::get_style( $req['style'] );
		if ( ! $st ) {
			return new WP_Error( 'iaq_not_found', 'Product not found.', array( 'status' => 404 ) );
		}
		$res = rest_ensure_response( $st );
		$res->header( 'Cache-Control', 'public, max-age=3600' );
		return $res;
	}

	public static function token() {
		$res = rest_ensure_response(
			array(
				'token' => wp_create_nonce( 'iaq_submit' ),
				'ts'    => time(),
			)
		);
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/**
	 * Proxy + cache a SanMar product image so the mockup canvas stays same-origin.
	 * Only paths under SanMar's product image library are allowed.
	 */
	public static function image( WP_REST_Request $req ) {
		$p = (string) $req['p'];
		if ( ! preg_match( '#^([0-9]{4}/f[0-9]{1,3}/)?[A-Za-z0-9_\-\.]+\.jpe?g$#i', $p ) || false !== strpos( $p, '..' ) ) {
			return new WP_Error( 'iaq_bad_path', 'Invalid image path.', array( 'status' => 400 ) );
		}
		$up   = wp_upload_dir();
		$dir  = trailingslashit( $up['basedir'] ) . 'iaq-cache';
		$file = $dir . '/' . md5( $p ) . '.jpg';

		if ( ! file_exists( $file ) ) {
			// Only fetch images that belong to a product in the catalog.
			global $wpdb;
			$table = IAQ_Catalog::table();
			$known = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE data LIKE %s LIMIT 1", '%' . $wpdb->esc_like( wp_json_encode( $p ) ) . '%' ) ); // phpcs:ignore
			if ( ! $known ) {
				return new WP_Error( 'iaq_unknown_img', 'Unknown image.', array( 'status' => 404 ) );
			}
			wp_mkdir_p( $dir );
			$resp = wp_remote_get(
				IAQ_IMG_BASE . $p,
				array(
					'timeout'     => 20,
					'redirection' => 2,
				)
			);
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				return new WP_Error( 'iaq_img_fetch', 'Image not available.', array( 'status' => 502 ) );
			}
			$body = wp_remote_retrieve_body( $resp );
			$type = wp_remote_retrieve_header( $resp, 'content-type' );
			if ( ! $body || ( $type && 0 !== strpos( $type, 'image/' ) ) ) {
				return new WP_Error( 'iaq_img_type', 'Not an image.', array( 'status' => 502 ) );
			}
			file_put_contents( $file, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		// Optional smaller copy (thumbnails, on-screen preview). Cached next to the original.
		$w = (int) $req['w'];
		if ( in_array( $w, array( 150, 300, 800 ), true ) ) {
			$small = $dir . '/' . md5( $p ) . '-' . $w . '.jpg';
			if ( ! file_exists( $small ) ) {
				$ed = wp_get_image_editor( $file );
				if ( ! is_wp_error( $ed ) ) {
					$ed->set_quality( 82 );
					$size = $ed->get_size();
					if ( $size['width'] > $w ) {
						$ed->resize( $w, null, false );
					}
					$saved = $ed->save( $small, 'image/jpeg' );
					if ( is_wp_error( $saved ) ) {
						$small = '';
					}
				} else {
					$small = '';
				}
			}
			if ( $small && file_exists( $small ) ) {
				$file = $small;
			}
		}

		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Cache-Control: public, max-age=2592000' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
