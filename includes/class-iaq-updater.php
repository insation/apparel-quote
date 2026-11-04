<?php
/**
 * Updates from GitHub releases, through the normal WordPress Plugins screen.
 *
 * Publish a release on the repo with a tag like v1.0.1 (matching the Version
 * header). If the release has a .zip asset it is used; otherwise GitHub's
 * source zip for the tag is used.
 *
 * Private repo: add to wp-config.php
 *   define( 'IAQ_GITHUB_TOKEN', 'github_pat_...' );   // read-only, Contents
 * Different repo:
 *   define( 'IAQ_GITHUB_REPO', 'owner/repo' );
 */

defined( 'ABSPATH' ) || exit;

class IAQ_Updater {

	const CACHE = 'iaq_gh_release';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'http_request_args', array( __CLASS__, 'auth_download' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
		add_filter( 'plugin_action_links_' . plugin_basename( IAQ_FILE ), array( __CLASS__, 'check_link' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_force_check' ) );
	}

	public static function repo() {
		return defined( 'IAQ_GITHUB_REPO' ) ? IAQ_GITHUB_REPO : 'insation/apparel-quote';
	}

	private static function token() {
		return defined( 'IAQ_GITHUB_TOKEN' ) ? IAQ_GITHUB_TOKEN : '';
	}

	private static function headers() {
		$h = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'insation-apparel-quote/' . IAQ_VERSION,
		);
		if ( self::token() ) {
			$h['Authorization'] = 'Bearer ' . self::token();
		}
		return $h;
	}

	/**
	 * Latest release from GitHub (cached 6 hours).
	 *
	 * @return array|null { version, package, url, notes, published }
	 */
	public static function latest( $force = false ) {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}
		$resp = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => self::headers(),
			)
		);
		$data = null;
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$rel = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! empty( $rel['tag_name'] ) ) {
				$package = '';
				if ( ! empty( $rel['assets'] ) ) {
					foreach ( $rel['assets'] as $a ) {
						if ( preg_match( '/\.zip$/i', $a['name'] ) ) {
							// Private repos need the API asset URL (with Accept: octet-stream).
							$package = self::token() ? $a['url'] : $a['browser_download_url'];
							break;
						}
					}
				}
				if ( ! $package ) {
					$package = $rel['zipball_url'];
				}
				$data = array(
					'version'   => ltrim( $rel['tag_name'], 'vV' ),
					'package'   => $package,
					'url'       => $rel['html_url'],
					'notes'     => isset( $rel['body'] ) ? (string) $rel['body'] : '',
					'published' => isset( $rel['published_at'] ) ? $rel['published_at'] : '',
				);
			}
		}
		set_site_transient( self::CACHE, $data ? $data : array( 'version' => '' ), $data ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $data;
	}

	public static function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$rel    = self::latest();
		$plugin = plugin_basename( IAQ_FILE );
		$item   = (object) array(
			'id'          => self::repo(),
			'slug'        => 'insation-apparel-quote',
			'plugin'      => $plugin,
			'new_version' => $rel ? $rel['version'] : IAQ_VERSION,
			'url'         => 'https://github.com/' . self::repo(),
			'package'     => $rel ? $rel['package'] : '',
			'tested'      => get_bloginfo( 'version' ),
			'icons'       => array(),
			'banners'     => array(),
		);
		if ( $rel && version_compare( $rel['version'], IAQ_VERSION, '>' ) ) {
			$transient->response[ $plugin ] = $item;
		} else {
			$transient->no_update[ $plugin ] = $item;
		}
		return $transient;
	}

	public static function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'insation-apparel-quote' !== $args->slug ) {
			return $res;
		}
		$rel = self::latest();
		return (object) array(
			'name'          => 'Apparel Quote & Mockup (SanMar)',
			'slug'          => 'insation-apparel-quote',
			'version'       => $rel ? $rel['version'] : IAQ_VERSION,
			'author'        => '<a href="https://insationtech.com">Insation Technologies</a>',
			'homepage'      => 'https://github.com/' . self::repo(),
			'download_link' => $rel ? $rel['package'] : '',
			'last_updated'  => $rel ? $rel['published'] : '',
			'sections'      => array(
				'description' => 'Customers pick a SanMar product, colour and sizes, upload their logo, see a live mockup and send a quote request.',
				'changelog'   => $rel && $rel['notes'] ? wpautop( esc_html( $rel['notes'] ) ) : 'See GitHub releases.',
			),
		);
	}

	/**
	 * GitHub zips extract to "owner-repo-hash/". Rename to our plugin folder.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		$ours = plugin_basename( IAQ_FILE );
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $ours ) {
			return $source;
		}
		global $wp_filesystem;
		$want = trailingslashit( $remote_source ) . 'insation-apparel-quote/';
		if ( trailingslashit( $source ) === $want ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $want, true ) ) {
			return $want;
		}
		return new WP_Error( 'iaq_rename', 'Could not rename the update folder.' );
	}

	/**
	 * Send the token when WordPress downloads the package from a private repo.
	 */
	public static function auth_download( $args, $url ) {
		if ( ! self::token() ) {
			return $args;
		}
		$repo = self::repo();
		if ( 0 === strpos( $url, 'https://api.github.com/repos/' . $repo . '/' ) ) {
			$args['headers'] = isset( $args['headers'] ) ? (array) $args['headers'] : array();
			$args['headers']['Authorization'] = 'Bearer ' . self::token();
			$args['headers']['User-Agent']    = 'insation-apparel-quote/' . IAQ_VERSION;
			if ( false !== strpos( $url, '/releases/assets/' ) ) {
				$args['headers']['Accept'] = 'application/octet-stream';
			}
		}
		return $args;
	}

	public static function flush() {
		delete_site_transient( self::CACHE );
	}

	public static function check_link( $links ) {
		if ( current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'plugins.php?iaq_check_update=1' ), 'iaq_check_update' ) ) . '">Check for updates</a>';
		}
		return $links;
	}

	public static function maybe_force_check() {
		if ( empty( $_GET['iaq_check_update'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore
			return;
		}
		check_admin_referer( 'iaq_check_update' );
		self::latest( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}
}
