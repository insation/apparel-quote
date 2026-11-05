<?php
/**
 * Quote requests: validation, storage, files and emails.
 */

defined( 'ABSPATH' ) || exit;

class IAQ_Quotes {

	const CPT          = 'iaq_quote';
	const MAX_ITEMS    = 20;
	const MAX_EXTRA    = 3;
	const RATE_LIMIT   = 6; // submissions per IP per hour.
	const MIN_SECONDS  = 8; // faster than this = bot.

	public static $statuses = array(
		'new'     => 'New',
		'quoted'  => 'Quote sent',
		'ordered' => 'Ordered',
		'closed'  => 'Closed',
	);

	public static $logo_ext = array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf', 'ai', 'eps', 'psd' );

	public static function register_post_type() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => 'Quote Requests',
					'singular_name' => 'Quote Request',
					'menu_name'     => 'Quote Requests',
					'all_items'     => 'All Requests',
					'edit_item'     => 'Quote Request',
					'search_items'  => 'Search Requests',
					'not_found'     => 'No quote requests yet.',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-clipboard',
				'menu_position'   => 26,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/**
	 * Private storage folder for uploaded logos and mockups.
	 */
	public static function storage_dir() {
		$up = wp_upload_dir();
		return trailingslashit( $up['basedir'] ) . 'iaq-quotes';
	}

	public static function ensure_upload_dirs() {
		$dir = self::storage_dir();
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore
		}
	}

	private static function client_ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return md5( $ip . wp_salt( 'nonce' ) );
	}

	private static function err( $msg, $code = 400 ) {
		return new WP_Error( 'iaq_invalid', $msg, array( 'status' => $code ) );
	}

	/**
	 * POST /iaq/v1/submit
	 *
	 * Wraps the real handler so every failure (and every crash) is logged with
	 * its reason, and the customer gets a clear message plus a short error ID.
	 */
	public static function handle_submit( WP_REST_Request $req ) {
		$p   = $req->get_body_params();
		$ctx = array(
			'name'  => isset( $p['name'] ) ? $p['name'] : '',
			'email' => isset( $p['email'] ) ? $p['email'] : '',
			'ip'    => substr( self::client_ip_hash(), 0, 10 ),
			'agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 120 ) : '',
		);

		// Upload bigger than the server accepts: PHP silently drops the whole form.
		$len = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		$max = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		if ( $len && $max && $len > $max ) {
			$msg = sprintf( 'Your files are too large to send together (%.1f MB; the limit is %d MB). Please use smaller files or send fewer at once.', $len / MB_IN_BYTES, (int) ( $max / MB_IN_BYTES ) );
			$id  = IAQ_Log::add( 'upload', $msg, 413, $ctx );
			return new WP_Error( 'iaq_too_large', $msg, array( 'status' => 413, 'error_id' => $id ) );
		}

		try {
			$res = self::process_submit( $req );
		} catch ( \Throwable $e ) {
			$ctx['detail'] = $e->getMessage() . ' in ' . basename( $e->getFile() ) . ':' . $e->getLine();
			$id            = IAQ_Log::add( 'crash', 'The website hit an error while saving the request.', 500, $ctx );
			return new WP_Error( 'iaq_crash', 'Sorry, the website hit an error while sending your request. Please call us and mention error ID ' . $id . '.', array( 'status' => 500, 'error_id' => $id ) );
		}

		if ( is_wp_error( $res ) ) {
			$data   = (array) $res->get_error_data();
			$status = isset( $data['status'] ) ? (int) $data['status'] : 400;
			$msg    = $res->get_error_message();
			$type   = 'validation';
			if ( 403 === $status ) {
				$type = 'session';
			} elseif ( 429 === $status ) {
				$type = 'limit';
			} elseif ( $status >= 500 ) {
				$type = 'server';
			} elseif ( preg_match( '/upload|too large|file type|valid image|PDF/i', $msg ) ) {
				$type = 'upload';
			}
			$id               = IAQ_Log::add( $type, $msg, $status, $ctx );
			$data['error_id'] = $id;
			$res->add_data( $data );
			return $res;
		}
		return $res;
	}

	private static function process_submit( WP_REST_Request $req ) {
		$p = $req->get_body_params();

		// Spam checks.
		if ( ! empty( $p['website'] ) ) {
			return rest_ensure_response( array( 'ok' => true ) ); // Honeypot: pretend success.
		}
		if ( empty( $p['token'] ) || ! wp_verify_nonce( $p['token'], 'iaq_submit' ) ) {
			return self::err( 'Your session expired. Please reload the page and try again.', 403 );
		}
		$started = isset( $p['started'] ) ? (int) $p['started'] : 0;
		if ( $started && ( time() - $started ) < self::MIN_SECONDS ) {
			return self::err( 'Please take a moment to review your request and try again.' );
		}
		$rl_key = 'iaq_rl_' . self::client_ip_hash();
		$hits   = (int) get_transient( $rl_key );
		if ( $hits >= self::RATE_LIMIT ) {
			return self::err( 'Too many requests from your connection. Please call us instead.', 429 );
		}

		// Contact details.
		$c = array(
			'name'      => sanitize_text_field( isset( $p['name'] ) ? $p['name'] : '' ),
			'company'   => sanitize_text_field( isset( $p['company'] ) ? $p['company'] : '' ),
			'email'     => sanitize_email( isset( $p['email'] ) ? $p['email'] : '' ),
			'phone'     => sanitize_text_field( isset( $p['phone'] ) ? $p['phone'] : '' ),
			'needed_by' => sanitize_text_field( isset( $p['needed_by'] ) ? $p['needed_by'] : '' ),
			'notes'     => sanitize_textarea_field( isset( $p['notes'] ) ? $p['notes'] : '' ),
		);
		if ( '' === $c['name'] ) {
			return self::err( 'Please enter your name.' );
		}
		if ( ! is_email( $c['email'] ) ) {
			return self::err( 'Please enter a valid email address.' );
		}
		if ( $c['needed_by'] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $c['needed_by'] ) ) {
			$c['needed_by'] = '';
		}

		// Items.
		$items_raw = json_decode( isset( $p['items'] ) ? wp_unslash( $p['items'] ) : '', true );
		if ( ! is_array( $items_raw ) || ! $items_raw ) {
			return self::err( 'Please add at least one product to your request.' );
		}
		if ( count( $items_raw ) > self::MAX_ITEMS ) {
			return self::err( 'Too many products in one request. Please split it up.' );
		}
		$items = array();
		foreach ( $items_raw as $i => $it ) {
			$item = self::clean_item( $it );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			$items[] = $item;
		}

		// Files.
		$files   = $req->get_file_params();
		$set     = iaq_settings();
		$max     = max( 1, (int) $set['max_upload_mb'] ) * MB_IN_BYTES;
		$checked = array();

		if ( ! empty( $files['logo'] ) && UPLOAD_ERR_NO_FILE !== (int) $files['logo']['error'] ) {
			$f = self::check_file( $files['logo'], self::$logo_ext, $max );
			if ( is_wp_error( $f ) ) {
				return $f;
			}
			$checked[] = array( 'role' => 'logo', 'label' => 'Logo', 'file' => $f );
		}
		for ( $x = 0; $x < self::MAX_EXTRA; $x++ ) {
			$k = 'extra_' . $x;
			if ( ! empty( $files[ $k ] ) && UPLOAD_ERR_NO_FILE !== (int) $files[ $k ]['error'] ) {
				$f = self::check_file( $files[ $k ], self::$logo_ext, $max );
				if ( is_wp_error( $f ) ) {
					return $f;
				}
				$checked[] = array( 'role' => 'extra', 'label' => 'Extra artwork ' . ( $x + 1 ), 'file' => $f );
			}
		}
		foreach ( $items as $i => $item ) {
			foreach ( array( 'front', 'back' ) as $view ) {
				$k = 'mockup_' . $i . '_' . $view;
				if ( ! empty( $files[ $k ] ) && UPLOAD_ERR_OK === (int) $files[ $k ]['error'] ) {
					$f = self::check_file( $files[ $k ], array( 'png', 'jpg', 'jpeg' ), 8 * MB_IN_BYTES, true );
					if ( is_wp_error( $f ) ) {
						return $f;
					}
					$checked[] = array(
						'role'  => 'mockup',
						'item'  => $i,
						'view'  => $view,
						'label' => sprintf( 'Mockup %d - %s %s (%s)', $i + 1, $item['style'], $item['color'], $view ),
						'file'  => $f,
					);
				}
			}
		}

		// Save.
		$title   = $c['name'] . ( $c['company'] ? ' (' . $c['company'] . ')' : '' );
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return self::err( 'Could not save your request. Please call us.', 500 );
		}

		self::ensure_upload_dirs();
		$folder = $post_id . '-' . wp_generate_password( 12, false );
		$dest   = self::storage_dir() . '/' . $folder;
		wp_mkdir_p( $dest );
		$stored = array();
		foreach ( $checked as $n => $cf ) {
			$f    = $cf['file'];
			$base = sanitize_file_name( $n . '-' . pathinfo( $f['name'], PATHINFO_FILENAME ) );
			$name = substr( $base, 0, 80 ) . '.' . $f['ext'];
			if ( ! @move_uploaded_file( $f['tmp_name'], $dest . '/' . $name ) ) { // phpcs:ignore
				if ( ! @copy( $f['tmp_name'], $dest . '/' . $name ) ) { // phpcs:ignore
					continue;
				}
			}
			$row = array(
				'role'  => $cf['role'],
				'label' => $cf['label'],
				'path'  => $folder . '/' . $name,
				'orig'  => sanitize_file_name( $f['name'] ),
				'ext'   => $f['ext'],
			);
			if ( isset( $cf['item'] ) ) {
				$row['item'] = $cf['item'];
				$row['view'] = $cf['view'];
			}
			$stored[] = $row;
		}

		update_post_meta( $post_id, '_iaq_contact', $c );
		update_post_meta( $post_id, '_iaq_items', $items );
		update_post_meta( $post_id, '_iaq_files', $stored );
		update_post_meta( $post_id, '_iaq_status', 'new' );
		update_post_meta( $post_id, '_iaq_ref', self::reference( $post_id ) );

		set_transient( $rl_key, $hits + 1, HOUR_IN_SECONDS );

		self::send_emails( $post_id );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'ref'     => self::reference( $post_id ),
				'message' => $set['thank_you_text'],
			)
		);
	}

	public static function reference( $post_id ) {
		return 'Q' . str_pad( (string) $post_id, 5, '0', STR_PAD_LEFT );
	}

	private static function clean_item( $it ) {
		if ( ! is_array( $it ) || empty( $it['style'] ) ) {
			return self::err( 'One of the products is missing its style.' );
		}
		$style = IAQ_Catalog::get_style( sanitize_text_field( $it['style'] ) );
		if ( ! $style ) {
			return self::err( 'One of the products is no longer available. Please remove it and try again.' );
		}
		$color = sanitize_text_field( isset( $it['color'] ) ? $it['color'] : '' );
		$col   = null;
		foreach ( $style['col'] as $cc ) {
			if ( $cc['n'] === $color ) {
				$col = $cc;
				break;
			}
		}
		if ( ! $col ) {
			return self::err( sprintf( 'Please choose a color for %s.', $style['s'] ) );
		}
		$sizes = array();
		$total = 0;
		$valid = isset( $col['z'] ) ? $col['z'] : $style['sz'];
		if ( ! empty( $it['sizes'] ) && is_array( $it['sizes'] ) ) {
			foreach ( $valid as $z ) {
				if ( isset( $it['sizes'][ $z ] ) ) {
					$q = max( 0, min( 100000, (int) $it['sizes'][ $z ] ) );
					if ( $q > 0 ) {
						$sizes[ $z ] = $q;
						$total      += $q;
					}
				}
			}
		}
		if ( $total < 1 ) {
			return self::err( sprintf( 'Please enter at least one size quantity for %s.', $style['s'] ) );
		}
		$methods = array(
			'embroidery' => 'Embroidery',
			'dtf'        => 'DTF / heat transfer',
			'dtg'        => 'DTG / direct-to-garment',
			'unsure'     => 'Not sure - recommend',
		);
		$method = isset( $it['method'], $methods[ $it['method'] ] ) ? $it['method'] : 'unsure';

		$placements = array();
		if ( ! empty( $it['placements'] ) && is_array( $it['placements'] ) ) {
			foreach ( array_slice( $it['placements'], 0, 6 ) as $pl ) {
				$placements[] = sanitize_text_field( $pl );
			}
		}
		return array(
			'style'      => $style['s'],
			'title'      => $style['t'],
			'brand'      => $style['b'],
			'category'   => $style['c'],
			'color'      => $color,
			'sizes'      => $sizes,
			'total'      => $total,
			'method'     => $method,
			'method_txt' => $methods[ $method ],
			'placements' => $placements,
			'colors_in_logo' => isset( $it['logo_colors'] ) ? sanitize_text_field( $it['logo_colors'] ) : '',
			'notes'      => sanitize_textarea_field( isset( $it['notes'] ) ? $it['notes'] : '' ),
		);
	}

	/**
	 * Validate one uploaded file.
	 */
	private static function check_file( $f, $allowed_ext, $max, $must_be_image = false ) {
		if ( UPLOAD_ERR_OK !== (int) $f['error'] ) {
			return self::err( 'A file failed to upload. Please try again.' );
		}
		if ( (int) $f['size'] > $max ) {
			return self::err( sprintf( '%s is too large (max %d MB).', sanitize_file_name( $f['name'] ), (int) ( $max / MB_IN_BYTES ) ) );
		}
		$ext = strtolower( pathinfo( $f['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed_ext, true ) ) {
			return self::err( sprintf( '%s is not an allowed file type.', sanitize_file_name( $f['name'] ) ) );
		}
		if ( $must_be_image || in_array( $ext, array( 'png', 'jpg', 'jpeg', 'gif', 'webp' ), true ) ) {
			$info = @getimagesize( $f['tmp_name'] ); // phpcs:ignore
			if ( ! $info ) {
				return self::err( sprintf( '%s does not look like a valid image.', sanitize_file_name( $f['name'] ) ) );
			}
		}
		if ( 'pdf' === $ext ) {
			$fh   = fopen( $f['tmp_name'], 'rb' ); // phpcs:ignore
			$head = $fh ? fread( $fh, 5 ) : ''; // phpcs:ignore
			if ( $fh ) {
				fclose( $fh ); // phpcs:ignore
			}
			if ( '%PDF-' !== $head ) {
				return self::err( 'The PDF file looks damaged.' );
			}
		}
		$f['ext'] = $ext;
		return $f;
	}

	public static function abs_path( $rel ) {
		$rel = str_replace( array( '..', '\\' ), '', (string) $rel );
		return self::storage_dir() . '/' . ltrim( $rel, '/' );
	}

	/**
	 * Admin-only URL to view/download a stored file.
	 */
	public static function file_url( $post_id, $index, $inline = false ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'iaq_file',
					'post'   => (int) $post_id,
					'f'      => (int) $index,
					'inline' => $inline ? 1 : 0,
				),
				admin_url( 'admin-post.php' )
			),
			'iaq_file_' . (int) $post_id
		);
	}

	/* ------------------------------------------------------------------ */
	/* Emails                                                              */
	/* ------------------------------------------------------------------ */

	public static function send_emails( $post_id ) {
		$set   = iaq_settings();
		$c     = get_post_meta( $post_id, '_iaq_contact', true );
		$items = get_post_meta( $post_id, '_iaq_items', true );
		$files = (array) get_post_meta( $post_id, '_iaq_files', true );
		$ref   = self::reference( $post_id );
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$mail  = array();

		$all_attach  = array();
		$mock_attach = array();
		foreach ( $files as $f ) {
			$abs = self::abs_path( $f['path'] );
			if ( ! file_exists( $abs ) ) {
				continue;
			}
			$all_attach[] = $abs;
			if ( 'mockup' === $f['role'] ) {
				$mock_attach[] = $abs;
			}
		}

		$summary = self::summary_html( $c, $items );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// To the shop.
		$to = array_filter( array_map( 'trim', explode( ',', (string) $set['notify_email'] ) ), 'is_email' );
		if ( $to ) {
			$h   = $headers;
			$h[] = 'Reply-To: ' . self::header_safe( $c['name'] ) . ' <' . $c['email'] . '>';
			$body  = '<p style="font:15px/1.5 Arial,sans-serif">New quote request <strong>' . esc_html( $ref ) . '</strong> from the website.</p>';
			$body .= $summary;
			$body .= '<p style="font:13px/1.5 Arial,sans-serif;color:#666">The logo and mockups are attached. Reply to this email to answer the customer directly.<br>';
			$body .= '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' ) ) . '">Open this request in WordPress</a></p>';
			$subject = sprintf( 'Quote request %s - %s%s', $ref, $c['name'], $c['company'] ? ' (' . $c['company'] . ')' : '' );
			$mail['shop'] = self::mail_tracked( $to, self::header_safe( $subject ), self::wrap( $body ), $h, $all_attach );
		} else {
			$mail['shop'] = 'No valid notification email is set in Quote Requests > Settings.';
		}

		// Copy to the customer.
		if ( ! empty( $set['customer_copy'] ) ) {
			$body  = '<p style="font:15px/1.5 Arial,sans-serif">Hi ' . esc_html( $c['name'] ) . ',</p>';
			$body .= '<p style="font:15px/1.5 Arial,sans-serif">' . esc_html( $set['thank_you_text'] ) . ' Your reference is <strong>' . esc_html( $ref ) . '</strong>.</p>';
			$body .= '<p style="font:15px/1.5 Arial,sans-serif">Here is a copy of what you sent us. Your mockups are attached. They are a preview only; we will confirm sizing and placement with you before anything is produced.</p>';
			$body .= $summary;
			$body .= '<p style="font:15px/1.5 Arial,sans-serif">' . esc_html( $site ) . '</p>';
			$subject = sprintf( 'Your quote request %s - %s', $ref, $site );
			$mail['customer'] = self::mail_tracked( $c['email'], self::header_safe( $subject ), self::wrap( $body ), $headers, $mock_attach );
		}

		update_post_meta( $post_id, '_iaq_mail', $mail );
		foreach ( $mail as $who => $result ) {
			if ( true !== $result ) {
				IAQ_Log::add(
					'mail',
					'shop' === $who ? 'The quote email to the shop could not be sent.' : 'The copy to the customer could not be sent.',
					0,
					array( 'name' => $c['name'], 'email' => $c['email'], 'detail' => $result, 'quote' => $ref )
				);
			}
		}
	}

	/**
	 * wp_mail() that returns true, or the reason it failed.
	 */
	private static function mail_tracked( $to, $subject, $body, $headers, $attach ) {
		$reason = '';
		$catch  = function ( $err ) use ( &$reason ) {
			if ( is_wp_error( $err ) ) {
				$reason = $err->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $catch );
		try {
			$ok = wp_mail( $to, $subject, $body, $headers, $attach );
		} catch ( \Throwable $e ) {
			$ok     = false;
			$reason = $e->getMessage();
		}
		remove_action( 'wp_mail_failed', $catch );
		return $ok ? true : ( $reason ? $reason : 'The mail server rejected the message (no reason given).' );
	}

	private static function header_safe( $s ) {
		return trim( preg_replace( '/[\r\n]+/', ' ', (string) $s ) );
	}

	private static function wrap( $inner ) {
		return '<div style="max-width:680px;margin:0 auto;padding:16px;font:15px/1.5 Arial,sans-serif;color:#222">' . $inner . '</div>';
	}

	public static function summary_html( $c, $items ) {
		$td  = 'style="padding:6px 10px;border-bottom:1px solid #eee;vertical-align:top;font:14px/1.4 Arial,sans-serif"';
		$th  = 'style="padding:6px 10px;border-bottom:1px solid #eee;text-align:left;vertical-align:top;font:bold 14px/1.4 Arial,sans-serif;width:130px"';
		$out = '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:12px 0">';
		$rows = array(
			'Name'      => $c['name'],
			'Company'   => $c['company'],
			'Email'     => $c['email'],
			'Phone'     => $c['phone'],
			'Needed by' => $c['needed_by'] ? mysql2date( 'F j, Y', $c['needed_by'] ) : '',
			'Notes'     => $c['notes'],
		);
		foreach ( $rows as $k => $v ) {
			if ( '' === (string) $v ) {
				continue;
			}
			$out .= '<tr><th ' . $th . '>' . esc_html( $k ) . '</th><td ' . $td . '>' . nl2br( esc_html( $v ) ) . '</td></tr>';
		}
		$out .= '</table>';

		$grand = 0;
		foreach ( (array) $items as $i => $it ) {
			$grand += (int) $it['total'];
			$sizes  = array();
			foreach ( $it['sizes'] as $z => $q ) {
				$sizes[] = esc_html( $z ) . ': <strong>' . (int) $q . '</strong>';
			}
			$out .= '<div style="border:1px solid #ddd;border-radius:8px;padding:12px 14px;margin:0 0 12px">';
			$out .= '<div style="font:bold 15px/1.4 Arial,sans-serif">' . ( $i + 1 ) . '. ' . esc_html( $it['brand'] . ' ' . $it['style'] ) . ' - ' . esc_html( $it['title'] ) . '</div>';
			$out .= '<div style="font:14px/1.6 Arial,sans-serif">Color: <strong>' . esc_html( $it['color'] ) . '</strong><br>';
			$out .= 'Sizes: ' . implode( ' &nbsp; ', $sizes ) . ' &nbsp; (total <strong>' . (int) $it['total'] . '</strong>)<br>';
			$out .= 'Decoration: ' . esc_html( $it['method_txt'] );
			if ( $it['placements'] ) {
				$out .= ' - ' . esc_html( implode( ', ', $it['placements'] ) );
			}
			if ( ! empty( $it['colors_in_logo'] ) ) {
				$out .= '<br>Colors in logo: ' . esc_html( $it['colors_in_logo'] );
			}
			if ( $it['notes'] ) {
				$out .= '<br>Notes: ' . nl2br( esc_html( $it['notes'] ) );
			}
			$out .= '</div></div>';
		}
		$out .= '<p style="font:14px/1.5 Arial,sans-serif">Total pieces: <strong>' . (int) $grand . '</strong></p>';
		return $out;
	}
}
