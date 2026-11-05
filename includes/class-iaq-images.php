<?php
/**
 * Local copies of the SanMar product photos.
 *
 * Only resized copies are kept (800, 300 and 150 px wide). The full-size SanMar
 * original is downloaded to a temp file, resized, and deleted.
 *
 * A background job downloads the whole catalog in batches. Each batch runs for
 * a limited time, then starts the next batch with a non-blocking request to
 * itself. WP-Cron runs it again every 5 minutes as a fallback in case the chain
 * breaks.
 */

defined( 'ABSPATH' ) || exit;

class IAQ_Images {

	const SIZES      = array( 800, 300, 150 );
	const STATE      = 'iaq_prefetch_state';
	const LOCK       = 'iaq_prefetch_lock';
	const TIME_LIMIT = 20; // Seconds of work per batch.

	public static function init() {
		add_action( 'wp_ajax_nopriv_iaq_prefetch', array( __CLASS__, 'ajax_batch' ) );
		add_action( 'wp_ajax_iaq_prefetch', array( __CLASS__, 'ajax_batch' ) );
		add_action( 'iaq_prefetch_cron', array( __CLASS__, 'run_batch' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
	}

	public static function schedules( $s ) {
		$s['iaq_5min'] = array(
			'interval' => 300,
			'display'  => 'Every 5 minutes (apparel photo download)',
		);
		return $s;
	}

	public static function dir() {
		$up = wp_upload_dir();
		return trailingslashit( $up['basedir'] ) . 'iaq-cache';
	}

	public static function file_for( $path, $w ) {
		return self::dir() . '/' . md5( $path ) . '-' . (int) $w . '.jpg';
	}

	public static function valid_path( $path ) {
		return (bool) preg_match( '#^([0-9]{4}/f[0-9]{1,3}/)?[A-Za-z0-9_\-\.]+\.jpe?g$#i', $path ) && false === strpos( $path, '..' );
	}

	/**
	 * Make sure the given sizes exist locally for a SanMar image path.
	 *
	 * @param string $path  Path under SanMar's image library.
	 * @param array  $sizes Widths to keep.
	 * @return true|WP_Error
	 */
	public static function ensure( $path, $sizes = self::SIZES ) {
		$missing = array();
		foreach ( $sizes as $w ) {
			if ( ! file_exists( self::file_for( $path, $w ) ) ) {
				$missing[] = (int) $w;
			}
		}
		if ( ! $missing ) {
			return true;
		}
		wp_mkdir_p( self::dir() );
		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Reuse a full-size original left over from version 1.0.2, if there is one.
		$legacy = self::dir() . '/' . md5( $path ) . '.jpg';
		if ( file_exists( $legacy ) ) {
			$src     = $legacy;
			$cleanup = true;
		} else {
			$resp = wp_remote_get(
				IAQ_IMG_BASE . $path,
				array(
					'timeout'     => 20,
					'redirection' => 2,
				)
			);
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				return new WP_Error( 'iaq_img_http', 'HTTP ' . wp_remote_retrieve_response_code( $resp ) );
			}
			$type = (string) wp_remote_retrieve_header( $resp, 'content-type' );
			$body = wp_remote_retrieve_body( $resp );
			if ( ! $body || ( $type && 0 !== strpos( $type, 'image/' ) ) ) {
				return new WP_Error( 'iaq_img_type', 'Not an image' );
			}
			$src = wp_tempnam( 'iaq-img.jpg' );
			file_put_contents( $src, $body ); // phpcs:ignore
			unset( $body );
			$cleanup = true;
		}

		rsort( $missing );
		$err = null;
		foreach ( $missing as $w ) {
			$ed = wp_get_image_editor( $src );
			if ( is_wp_error( $ed ) ) {
				$err = $ed;
				break;
			}
			$ed->set_quality( 82 );
			$size = $ed->get_size();
			if ( $size['width'] > $w ) {
				$ed->resize( $w, null, false );
			}
			$saved = $ed->save( self::file_for( $path, $w ), 'image/jpeg' );
			if ( is_wp_error( $saved ) ) {
				$err = $saved;
				break;
			}
		}
		if ( $cleanup ) {
			@unlink( $src ); // phpcs:ignore
		}
		return $err ? $err : true;
	}

	/* ------------------------------------------------------------------ */
	/* Background download                                                 */
	/* ------------------------------------------------------------------ */

	public static function state() {
		$s = get_option( self::STATE );
		return is_array( $s ) ? $s : array( 'status' => 'idle' );
	}

	private static function save_state( $s ) {
		update_option( self::STATE, $s, false );
	}

	/**
	 * Start (or restart) downloading the whole catalog.
	 */
	public static function start() {
		global $wpdb;
		$table = IAQ_Catalog::table();
		$total = 0;
		foreach ( (array) $wpdb->get_col( "SELECT data FROM {$table}" ) as $raw ) { // phpcs:ignore
			$d = json_decode( $raw, true );
			foreach ( (array) $d['col'] as $c ) {
				if ( ! empty( $c['ff'] ) || ! empty( $c['mf'] ) ) {
					$total++;
				}
				if ( ! empty( $c['fb'] ) || ! empty( $c['mb'] ) ) {
					$total++;
				}
			}
		}
		self::save_state(
			array(
				'status'   => 'running',
				'offset'   => 0, // Style row offset (ordered by style).
				'done'     => 0,
				'skipped'  => 0,
				'errors'   => 0,
				'total'    => $total,
				'started'  => time(),
				'updated'  => time(),
				'finished' => 0,
				'key'      => wp_generate_password( 24, false ),
			)
		);
		if ( ! wp_next_scheduled( 'iaq_prefetch_cron' ) ) {
			wp_schedule_event( time() + 300, 'iaq_5min', 'iaq_prefetch_cron' );
		}
		self::delete_legacy_originals();
		self::kick();
	}

	public static function pause() {
		$s           = self::state();
		$s['status'] = 'paused';
		self::save_state( $s );
	}

	public static function resume() {
		$s = self::state();
		if ( isset( $s['offset'] ) ) {
			$s['status'] = 'running';
			self::save_state( $s );
			if ( ! wp_next_scheduled( 'iaq_prefetch_cron' ) ) {
				wp_schedule_event( time() + 300, 'iaq_5min', 'iaq_prefetch_cron' );
			}
			self::kick();
		} else {
			self::start();
		}
	}

	/**
	 * Start the next batch in a separate, non-blocking request.
	 */
	public static function kick() {
		$s = self::state();
		if ( empty( $s['key'] ) ) {
			return;
		}
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => array(
					'action' => 'iaq_prefetch',
					'key'    => $s['key'],
				),
			)
		);
	}

	public static function ajax_batch() {
		$s   = self::state();
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore
		if ( empty( $s['key'] ) || ! hash_equals( $s['key'], $key ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		self::run_batch();
		wp_die( 'ok' );
	}

	/**
	 * Download photos for TIME_LIMIT seconds, then hand over to the next batch.
	 */
	public static function run_batch() {
		$s = self::state();
		if ( empty( $s['status'] ) || 'running' !== $s['status'] ) {
			if ( ! empty( $s['status'] ) && 'done' === $s['status'] ) {
				wp_clear_scheduled_hook( 'iaq_prefetch_cron' );
			}
			return;
		}
		// One batch at a time.
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, self::TIME_LIMIT + 40 );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::TIME_LIMIT + 60 ); // phpcs:ignore
		}

		global $wpdb;
		$table = IAQ_Catalog::table();
		$start = microtime( true );
		$fails = 0; // Failures in a row: SanMar or the network may be down.

		while ( microtime( true ) - $start < self::TIME_LIMIT ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT style, data FROM {$table} ORDER BY style LIMIT %d, 1", (int) $s['offset'] ), ARRAY_A ); // phpcs:ignore
			if ( ! $row ) {
				$s['status']   = 'done';
				$s['finished'] = time();
				wp_clear_scheduled_hook( 'iaq_prefetch_cron' );
				break;
			}
			$d       = json_decode( $row['data'], true );
			$partial = false;
			foreach ( (array) $d['col'] as $c ) {
				// Styles with many colors: stop mid-style and finish it in the next batch
				// (photos already saved are skipped then).
				if ( microtime( true ) - $start > self::TIME_LIMIT ) {
					$partial = true;
					break;
				}
				$jobs = array();
				$front = ! empty( $c['ff'] ) ? $c['ff'] : ( ! empty( $c['mf'] ) ? $c['mf'] : '' );
				$back  = ! empty( $c['fb'] ) ? $c['fb'] : ( ! empty( $c['mb'] ) ? $c['mb'] : '' );
				if ( $front ) {
					$jobs[] = array( $front, self::SIZES );   // Grid, swatches and mockup.
				}
				if ( $back ) {
					$jobs[] = array( $back, array( 800 ) ); // Mockup only.
				}
				foreach ( $jobs as $j ) {
					if ( ! self::valid_path( $j[0] ) ) {
						$s['skipped']++;
						continue;
					}
					$all = true;
					foreach ( $j[1] as $w ) {
						if ( ! file_exists( self::file_for( $j[0], $w ) ) ) {
							$all = false;
							break;
						}
					}
					if ( $all ) {
						$s['skipped']++;
						continue;
					}
					$r = self::ensure( $j[0], $j[1] );
					if ( is_wp_error( $r ) ) {
						$s['errors']++;
						$fails++;
					} else {
						$s['done']++;
						$fails = 0;
					}
				}
			}
			if ( ! $partial ) {
				$s['offset']++;
			}
			$s['updated'] = time();
			// Save progress after every style so a crash loses little.
			$cur = self::state();
			if ( isset( $cur['status'] ) && 'running' !== $cur['status'] ) {
				$s['status'] = $cur['status']; // Paused from the admin meanwhile.
				break;
			}
			self::save_state( $s );
			if ( $fails >= 25 ) {
				break;
			}
		}

		self::save_state( $s );
		delete_transient( self::LOCK );
		// After many failures in a row, wait for the 5-minute cron instead of continuing straight away.
		if ( 'running' === $s['status'] && $fails < 25 ) {
			self::kick();
		}
	}

	/**
	 * Remove full-size originals kept by version 1.0.2 (after making their small copies).
	 */
	public static function delete_legacy_originals() {
		foreach ( (array) glob( self::dir() . '/*.jpg' ) as $f ) {
			if ( preg_match( '/^[a-f0-9]{32}\.jpg$/', basename( $f ) ) ) {
				@unlink( $f ); // phpcs:ignore
			}
		}
	}

	public static function disk_usage() {
		$bytes = 0;
		$n     = 0;
		foreach ( (array) glob( self::dir() . '/*.jpg' ) as $f ) {
			$bytes += filesize( $f );
			$n++;
		}
		return array(
			'files' => $n,
			'bytes' => $bytes,
		);
	}
}
