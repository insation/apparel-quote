<?php
/**
 * SanMar catalog storage and lookups.
 *
 * Data comes from a trimmed copy of the SanMar Data Library (SDL) bundled at
 * data/catalog.json.gz. Each row of the table is one style; colours, sizes and
 * image paths are kept as JSON in the `data` column.
 */

defined( 'ABSPATH' ) || exit;

class IAQ_Catalog {

	const DB_VERSION   = '1';
	const INDEX_CACHE  = 'iaq_catalog_index';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'iaq_styles';
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			style varchar(40) NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			brand varchar(100) NOT NULL DEFAULT '',
			category varchar(100) NOT NULL DEFAULT '',
			subcategory varchar(100) NOT NULL DEFAULT '',
			status varchar(30) NOT NULL DEFAULT '',
			thumb varchar(255) NOT NULL DEFAULT '',
			colors smallint(5) unsigned NOT NULL DEFAULT 0,
			data longtext NOT NULL,
			PRIMARY KEY  (style),
			KEY category (category),
			KEY brand (brand)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'iaq_db_version', self::DB_VERSION );
	}

	/**
	 * Import the catalog file bundled with the plugin.
	 *
	 * @return int|WP_Error Number of styles imported.
	 */
	public static function import_bundled() {
		return self::import_file( IAQ_DIR . 'data/catalog.json.gz' );
	}

	/**
	 * Import a catalog file (.json or .json.gz) produced from the SanMar SDL.
	 *
	 * @param string $path File path.
	 * @return int|WP_Error
	 */
	public static function import_file( $path ) {
		global $wpdb;
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'iaq_missing', 'Catalog file not found: ' . $path );
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '.gz' === substr( $path, -3 ) ) {
			$raw = gzdecode( $raw );
		}
		if ( ! $raw ) {
			return new WP_Error( 'iaq_read', 'Could not read the catalog file.' );
		}
		$json = json_decode( $raw, true );
		unset( $raw );
		if ( empty( $json['styles'] ) || ! is_array( $json['styles'] ) ) {
			return new WP_Error( 'iaq_format', 'Catalog file has no styles.' );
		}

		self::create_table();
		$table = self::table();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$count  = 0;
		$values = array();
		$params = array();
		foreach ( $json['styles'] as $st ) {
			if ( empty( $st['s'] ) || empty( $st['col'] ) ) {
				continue;
			}
			$first = $st['col'][0];
			$thumb = ! empty( $first['ff'] ) ? $first['ff'] : ( isset( $first['mf'] ) ? $first['mf'] : '' );
			$data  = array(
				'd'   => isset( $st['d'] ) ? $st['d'] : '',
				'dec' => isset( $st['dec'] ) ? $st['dec'] : '',
				'sz'  => isset( $st['sz'] ) ? $st['sz'] : array(),
				'col' => $st['col'],
			);
			$values[] = '(%s,%s,%s,%s,%s,%s,%s,%d,%s)';
			array_push(
				$params,
				substr( $st['s'], 0, 40 ),
				substr( (string) $st['t'], 0, 255 ),
				substr( (string) $st['b'], 0, 100 ),
				substr( (string) $st['c'], 0, 100 ),
				substr( (string) $st['sc'], 0, 100 ),
				substr( (string) $st['st'], 0, 30 ),
				substr( $thumb, 0, 255 ),
				count( $st['col'] ),
				wp_json_encode( $data )
			);
			$count++;
			if ( count( $values ) >= 200 ) {
				self::flush_insert( $values, $params );
				$values = array();
				$params = array();
			}
		}
		if ( $values ) {
			self::flush_insert( $values, $params );
		}
		update_option(
			'iaq_catalog_meta',
			array(
				'version'  => isset( $json['v'] ) ? $json['v'] : '',
				'styles'   => $count,
				'imported' => current_time( 'mysql' ),
			)
		);
		self::clear_cache();
		return $count;
	}

	private static function flush_insert( $values, $params ) {
		global $wpdb;
		$table = self::table();
		$sql   = "INSERT INTO {$table} (style,title,brand,category,subcategory,status,thumb,colors,data) VALUES " . implode( ',', $values );
		$wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Import straight from a SanMar Data Library export (SanMar_SDL_*.zip or .csv).
	 * Streams the CSV, drops discontinued items and keeps only what the form needs.
	 *
	 * @param string $path Path to the .zip or .csv.
	 * @return int|WP_Error
	 */
	public static function import_sdl( $path ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore
		}
		@ini_set( 'memory_limit', '512M' ); // phpcs:ignore
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp_csv = '';
		if ( preg_match( '/\.zip$/i', $path ) ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				return new WP_Error( 'iaq_zip', 'This server cannot open zip files. Unzip it and upload the .csv instead.' );
			}
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				return new WP_Error( 'iaq_zip', 'Could not open the zip file.' );
			}
			$name = '';
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$n = $zip->getNameIndex( $i );
				if ( preg_match( '/\.csv$/i', $n ) && false === strpos( $n, '..' ) ) {
					$name = $n;
					break;
				}
			}
			if ( ! $name ) {
				$zip->close();
				return new WP_Error( 'iaq_zip', 'No .csv file inside the zip.' );
			}
			$tmp_csv = wp_tempnam( 'iaq-sdl.csv' );
			$in      = $zip->getStream( $name );
			$out     = fopen( $tmp_csv, 'wb' ); // phpcs:ignore
			if ( ! $in || ! $out ) {
				$zip->close();
				return new WP_Error( 'iaq_zip', 'Could not extract the csv.' );
			}
			stream_copy_to_stream( $in, $out );
			fclose( $in ); // phpcs:ignore
			fclose( $out ); // phpcs:ignore
			$zip->close();
			$path = $tmp_csv;
		}

		$fh = fopen( $path, 'rb' ); // phpcs:ignore
		if ( ! $fh ) {
			return new WP_Error( 'iaq_read', 'Could not read the csv.' );
		}
		$head = fgetcsv( $fh, 0, ',', '"', '\\' );
		if ( ! $head ) {
			fclose( $fh ); // phpcs:ignore
			return new WP_Error( 'iaq_format', 'The csv is empty.' );
		}
		$head[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $head[0] );
		$col     = array_flip( array_map( 'trim', $head ) );
		foreach ( array( 'STYLE#', 'COLOR_NAME', 'SIZE', 'PRODUCT_STATUS', 'FRONT_FLAT_IMAGE_URL' ) as $need ) {
			if ( ! isset( $col[ $need ] ) ) {
				fclose( $fh ); // phpcs:ignore
				return new WP_Error( 'iaq_format', 'This does not look like a SanMar Data Library csv (missing ' . $need . ').' );
			}
		}
		$g = function ( $row, $k ) use ( $col ) {
			return isset( $col[ $k ], $row[ $col[ $k ] ] ) ? trim( $row[ $col[ $k ] ] ) : '';
		};
		$pfx   = IAQ_IMG_BASE;
		$short = function ( $u ) use ( $pfx ) {
			$u = trim( (string) $u );
			if ( 0 === strpos( $u, $pfx ) ) {
				return substr( $u, strlen( $pfx ) );
			}
			return 0 === strpos( $u, 'http' ) ? $u : '';
		};

		$styles = array();
		while ( false !== ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) ) {
			$status = $g( $row, 'PRODUCT_STATUS' );
			$s      = $g( $row, 'STYLE#' );
			if ( '' === $s || 'Discontinued' === $status ) {
				continue;
			}
			if ( ! isset( $styles[ $s ] ) ) {
				$brand = $g( $row, 'MILL' );
				$t     = $g( $row, 'PRODUCT_TITLE' );
				$t     = preg_replace( '/\.?\s+' . preg_quote( $s, '/' ) . '\s*$/', '', $t );
				$t     = preg_replace( '/^' . preg_quote( $brand, '/' ) . '\s*-\s*/i', '', $t );
				$t     = trim( preg_replace( '/\s+/', ' ', $t ), ' .-' );
				$d     = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $g( $row, 'PRODUCT_DESCRIPTION' ) ) ) );
				$d     = preg_replace( '/(?<=[a-z0-9\)%])(?=[A-Z][a-z])/', '. ', $d );
				$styles[ $s ] = array(
					's'   => $s,
					't'   => $t,
					'b'   => $brand,
					'c'   => $g( $row, 'CATEGORY_NAME' ),
					'sc'  => $g( $row, 'SUBCATEGORY_NAME' ),
					'd'   => function_exists( 'mb_substr' ) ? mb_substr( $d, 0, 500 ) : substr( $d, 0, 500 ),
					'st'  => $status,
					'dec' => $g( $row, 'DECORATION_SPEC_SHEET' ),
					'sz'  => array(),
					'col' => array(),
				);
			}
			$size = $g( $row, 'SIZE' );
			$si   = (int) $g( $row, 'SIZE_INDEX' );
			if ( '' !== $size && ( ! isset( $styles[ $s ]['sz'][ $size ] ) || $si < $styles[ $s ]['sz'][ $size ] ) ) {
				$styles[ $s ]['sz'][ $size ] = $si;
			}
			$cn = $g( $row, 'COLOR_NAME' );
			if ( ! isset( $styles[ $s ]['col'][ $cn ] ) ) {
				$styles[ $s ]['col'][ $cn ] = array_filter(
					array(
						'n'  => $cn,
						'ff' => $short( $g( $row, 'FRONT_FLAT_IMAGE_URL' ) ),
						'fb' => $short( $g( $row, 'BACK_FLAT_IMAGE_URL' ) ),
						'mf' => $short( $g( $row, 'FRONT_MODEL_IMAGE_URL' ) ),
						'mb' => $short( $g( $row, 'BACK_MODEL_IMAGE_URL' ) ),
						'z'  => array(),
					),
					function ( $v ) {
						return '' !== $v;
					}
				);
				$styles[ $s ]['col'][ $cn ]['z'] = array();
			}
			if ( '' !== $size ) {
				$styles[ $s ]['col'][ $cn ]['z'][ $size ] = true;
			}
		}
		fclose( $fh ); // phpcs:ignore
		if ( $tmp_csv ) {
			@unlink( $tmp_csv ); // phpcs:ignore
		}

		$out = array();
		foreach ( $styles as $st ) {
			$sz    = $st['sz'];
			$order = array_keys( $sz );
			usort(
				$order,
				function ( $a, $b ) use ( $sz ) {
					$ra = self::size_rank( $a );
					$rb = self::size_rank( $b );
					return $ra === $rb ? $sz[ $a ] - $sz[ $b ] : $ra - $rb;
				}
			);
			$cols = array();
			foreach ( $st['col'] as $c ) {
				if ( empty( $c['ff'] ) && empty( $c['mf'] ) ) {
					continue;
				}
				$have   = $c['z'];
				$c['z'] = array_values(
					array_filter(
						$order,
						function ( $z ) use ( $have ) {
							return isset( $have[ $z ] );
						}
					)
				);
				$cols[] = $c;
			}
			if ( ! $cols ) {
				continue;
			}
			$st['sz']  = $order;
			$st['col'] = $cols;
			$out[]     = $st;
		}
		unset( $styles );
		if ( ! $out ) {
			return new WP_Error( 'iaq_format', 'No current products found in the file.' );
		}

		$tmp = wp_tempnam( 'iaq-catalog.json' );
		file_put_contents( $tmp, wp_json_encode( array( 'v' => current_time( 'Y-m-d' ), 'styles' => $out ) ) ); // phpcs:ignore
		unset( $out );
		$res = self::import_file( $tmp );
		@unlink( $tmp ); // phpcs:ignore
		return $res;
	}

	public static function size_rank( $z ) {
		static $canon = null;
		if ( null === $canon ) {
			$canon = array_flip( array( 'NB', '0-3M', '3-6M', '6M', '6-12M', '12M', '18M', '24M', '2T', '3T', '4T', '5T', '5/6', '6T', 'XXS', '2XS', 'XS', 'S', 'S/M', 'M', 'M/L', 'L', 'L/XL', 'XL', '2XL', 'XXL', '3XL', '4XL', '5XL', '6XL', 'LT', 'XLT', '2XLT', '3XLT', '4XLT', 'OSFA' ) );
		}
		return isset( $canon[ $z ] ) ? $canon[ $z ] : 1000;
	}

	public static function count_styles() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
	}

	public static function clear_cache() {
		delete_transient( self::INDEX_CACHE );
		$old = get_option( 'iaq_index_file' );
		if ( $old && ! empty( $old['path'] ) && file_exists( $old['path'] ) ) {
			@unlink( $old['path'] ); // phpcs:ignore
		}
		delete_option( 'iaq_index_file' );
	}

	/**
	 * Compact product list for the browser:
	 * { img, cats:[...], counts:[...], brands:[...], rows:[[style,title,brandIdx,catIdx,subcat,isNew,thumb,colours],...] }
	 */
	public static function compact_index() {
		$ix     = self::index();
		$cats   = array_keys( $ix['categories'] );
		$cmap   = array_flip( $cats );
		$brands = array();
		$bmap   = array();
		$rows   = array();
		foreach ( $ix['styles'] as $s ) {
			if ( ! isset( $bmap[ $s['b'] ] ) ) {
				$bmap[ $s['b'] ] = count( $brands );
				$brands[]        = $s['b'];
			}
			$rows[] = array( $s['s'], $s['t'], $bmap[ $s['b'] ], $cmap[ $s['c'] ], $s['sc'], $s['n'], $s['i'], $s['k'] );
		}
		return array(
			'img'    => $ix['img'],
			'cats'   => $cats,
			'counts' => array_values( $ix['categories'] ),
			'brands' => $brands,
			'rows'   => $rows,
		);
	}

	/**
	 * Static JSON copy of the compact list in uploads, served by the web server
	 * directly (compressed and cacheable, no PHP). Returns its URL, or '' on failure.
	 */
	public static function index_file_url() {
		$saved = get_option( 'iaq_index_file' );
		if ( $saved && ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
			return $saved['url'];
		}
		$json = wp_json_encode( self::compact_index() );
		if ( ! $json ) {
			return '';
		}
		$up   = wp_upload_dir();
		$dir  = trailingslashit( $up['basedir'] ) . 'iaq-cache';
		wp_mkdir_p( $dir );
		$name = 'catalog-' . substr( md5( $json ), 0, 12 ) . '.json';
		if ( false === file_put_contents( $dir . '/' . $name, $json ) ) { // phpcs:ignore
			return '';
		}
		$url = trailingslashit( $up['baseurl'] ) . 'iaq-cache/' . $name;
		update_option( 'iaq_index_file', array( 'path' => $dir . '/' . $name, 'url' => $url ), false );
		return $url;
	}

	private static function hidden_styles() {
		$s = iaq_settings();
		$list = preg_split( '/[\s,]+/', strtoupper( (string) $s['hidden_styles'] ), -1, PREG_SPLIT_NO_EMPTY );
		return $list ? $list : array();
	}

	/**
	 * Light list of every visible style, used by the product browser.
	 *
	 * @return array
	 */
	public static function index() {
		$cached = get_transient( self::INDEX_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$table  = self::table();
		$rows   = $wpdb->get_results( "SELECT style,title,brand,category,subcategory,status,thumb,colors FROM {$table} ORDER BY category, brand, style", ARRAY_A ); // phpcs:ignore
		$set    = iaq_settings();
		$hidden_cats   = (array) $set['hidden_categories'];
		$hidden_styles = self::hidden_styles();
		$styles = array();
		$cats   = array();
		foreach ( (array) $rows as $r ) {
			if ( in_array( $r['category'], $hidden_cats, true ) || in_array( strtoupper( $r['style'] ), $hidden_styles, true ) ) {
				continue;
			}
			$styles[] = array(
				's'  => $r['style'],
				't'  => $r['title'],
				'b'  => $r['brand'],
				'c'  => $r['category'],
				'sc' => $r['subcategory'],
				'n'  => ( 'New' === $r['status'] || 'Coming soon' === $r['status'] ) ? 1 : 0,
				'i'  => $r['thumb'],
				'k'  => (int) $r['colors'],
			);
			$cats[ $r['category'] ] = isset( $cats[ $r['category'] ] ) ? $cats[ $r['category'] ] + 1 : 1;
		}
		$out = array(
			'img'        => IAQ_IMG_BASE,
			'categories' => $cats,
			'styles'     => $styles,
		);
		set_transient( self::INDEX_CACHE, $out, DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * Full detail for one style, or null if missing/hidden.
	 *
	 * @param string $style Style number.
	 * @return array|null
	 */
	public static function get_style( $style ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE style = %s", $style ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return null;
		}
		$set = iaq_settings();
		if ( in_array( $row['category'], (array) $set['hidden_categories'], true ) || in_array( strtoupper( $row['style'] ), self::hidden_styles(), true ) ) {
			return null;
		}
		$data = json_decode( $row['data'], true );
		return array(
			's'   => $row['style'],
			't'   => $row['title'],
			'b'   => $row['brand'],
			'c'   => $row['category'],
			'sc'  => $row['subcategory'],
			'd'   => isset( $data['d'] ) ? $data['d'] : '',
			'dec' => isset( $data['dec'] ) ? $data['dec'] : '',
			'sz'  => isset( $data['sz'] ) ? $data['sz'] : array(),
			'col' => isset( $data['col'] ) ? $data['col'] : array(),
		);
	}

	/**
	 * All category names in the catalog (including hidden ones), for settings.
	 *
	 * @return array name => count
	 */
	public static function all_categories() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT category, COUNT(*) n FROM {$table} GROUP BY category ORDER BY category", ARRAY_A ); // phpcs:ignore
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['category'] ] = (int) $r['n'];
		}
		return $out;
	}
}
