<?php
/**
 * Admin: settings, catalog import, quote request screens, private file access.
 */

defined( 'ABSPATH' ) || exit;

class IAQ_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_iaq_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_iaq_import', array( __CLASS__, 'import' ) );
		add_action( 'admin_post_iaq_file', array( __CLASS__, 'serve_file' ) );
		add_action( 'admin_post_iaq_photos', array( __CLASS__, 'photos_action' ) );
		add_action( 'add_meta_boxes_' . IAQ_Quotes::CPT, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . IAQ_Quotes::CPT, array( __CLASS__, 'save_status' ) );
		add_filter( 'manage_' . IAQ_Quotes::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . IAQ_Quotes::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . IAQ_Quotes::CPT,
			'Quote Form Settings',
			'Settings',
			'manage_options',
			'iaq-settings',
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function notices() {
		$meta = get_option( 'iaq_catalog_meta', array() );
		if ( empty( $meta['styles'] ) && current_user_can( 'manage_options' ) && empty( $_GET['iaq_msg'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-warning"><p><strong>Apparel Quote:</strong> the SanMar catalog is not loaded yet. <a href="' . esc_url( admin_url( 'edit.php?post_type=' . IAQ_Quotes::CPT . '&page=iaq-settings' ) ) . '">Import the SanMar Data Library file</a>.</p></div>';
		}
		if ( empty( $_GET['iaq_msg'] ) ) { // phpcs:ignore
			return;
		}
		$msg  = sanitize_text_field( wp_unslash( $_GET['iaq_msg'] ) ); // phpcs:ignore
		$type = ( ! empty( $_GET['iaq_err'] ) ) ? 'error' : 'success'; // phpcs:ignore
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	private static function back( $msg, $err = false ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => IAQ_Quotes::CPT,
					'page'      => 'iaq-settings',
					'iaq_msg'   => rawurlencode( $msg ),
					'iaq_err'   => $err ? 1 : 0,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = iaq_settings();
		$cats = IAQ_Catalog::all_categories();
		$meta = get_option( 'iaq_catalog_meta', array() );
		?>
		<div class="wrap">
			<h1>Apparel Quote Form</h1>
			<p>Put the form on any page with the shortcode <code>[apparel_quote]</code> (or an Elementor Shortcode widget).</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'iaq_save_settings' ); ?>
				<input type="hidden" name="action" value="iaq_save_settings">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="iaq_notify">Send requests to</label></th>
						<td><input type="text" class="regular-text" id="iaq_notify" name="notify_email" value="<?php echo esc_attr( $s['notify_email'] ); ?>">
						<p class="description">One or more email addresses, separated by commas.</p></td>
					</tr>
					<tr>
						<th scope="row">Customer copy</th>
						<td><label><input type="checkbox" name="customer_copy" value="1" <?php checked( ! empty( $s['customer_copy'] ) ); ?>> Email the customer a copy of their request with the mockups</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="iaq_intro">Intro text</label></th>
						<td><textarea class="large-text" rows="3" id="iaq_intro" name="intro_text"><?php echo esc_textarea( $s['intro_text'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="iaq_thanks">Thank-you message</label></th>
						<td><textarea class="large-text" rows="2" id="iaq_thanks" name="thank_you_text"><?php echo esc_textarea( $s['thank_you_text'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="iaq_max">Max logo file size</label></th>
						<td><input type="number" min="1" max="64" id="iaq_max" name="max_upload_mb" value="<?php echo (int) $s['max_upload_mb']; ?>"> MB
						<p class="description">The server's own upload limit is <?php echo esc_html( size_format( wp_max_upload_size() ) ); ?>.</p></td>
					</tr>
					<tr>
						<th scope="row">Categories to show</th>
						<td>
							<?php if ( ! $cats ) : ?>
								<p>The catalog is empty. Import it below.</p>
							<?php endif; ?>
							<?php foreach ( $cats as $name => $n ) : ?>
								<label style="display:inline-block;min-width:220px;margin:0 12px 6px 0">
									<input type="checkbox" name="show_categories[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( ! in_array( $name, (array) $s['hidden_categories'], true ) ); ?>>
									<?php echo esc_html( $name ); ?> <span style="color:#888">(<?php echo (int) $n; ?>)</span>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="iaq_hidden_styles">Hide these styles</label></th>
						<td><textarea class="large-text" rows="2" id="iaq_hidden_styles" name="hidden_styles" placeholder="e.g. PC54Y, 112"><?php echo esc_textarea( $s['hidden_styles'] ); ?></textarea>
						<p class="description">SanMar style numbers, separated by commas or spaces.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>

			<hr>
			<?php IAQ_Log::render_section(); ?>

			<hr>
			<h2>Product photos</h2>
			<?php
			$ps    = IAQ_Images::state();
			$usage = IAQ_Images::disk_usage();
			$status_txt = array(
				'idle'    => 'Not started',
				'running' => 'Downloading',
				'paused'  => 'Paused',
				'done'    => 'Complete',
			);
			$st = isset( $ps['status'], $status_txt[ $ps['status'] ] ) ? $ps['status'] : 'idle';
			?>
			<p>All photos are stored on this site in <code>wp-content/uploads/iaq-cache</code> (800, 300 and 150 px copies only).</p>
			<p><strong><?php echo esc_html( $status_txt[ $st ] ); ?></strong>
			<?php if ( ! empty( $ps['total'] ) ) : ?>
				&middot; <?php echo (int) $ps['offset']; ?> of <?php echo (int) IAQ_Catalog::count_styles(); ?> styles checked
				&middot; <?php echo (int) $ps['done']; ?> photos downloaded, <?php echo (int) $ps['skipped']; ?> already here, <?php echo (int) $ps['errors']; ?> failed
			<?php endif; ?>
			<br><?php echo (int) $usage['files']; ?> image files, <?php echo esc_html( size_format( $usage['bytes'], 1 ) ); ?> on disk.
			<?php if ( 'running' === $st && ! empty( $ps['updated'] ) ) : ?>
				<br><span class="description">Last activity <?php echo esc_html( human_time_diff( (int) $ps['updated'] ) ); ?> ago. Refresh this page to see progress.</span>
			<?php endif; ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'iaq_photos' ); ?>
				<input type="hidden" name="action" value="iaq_photos">
				<?php if ( 'running' === $st ) : ?>
					<button class="button" name="do" value="pause">Pause</button>
				<?php elseif ( 'paused' === $st ) : ?>
					<button class="button button-primary" name="do" value="resume">Resume</button>
				<?php endif; ?>
				<button class="button" name="do" value="start"><?php echo 'idle' === $st ? 'Download all photos' : 'Check all photos again'; ?></button>
			</form>

			<hr>
			<h2>SanMar catalog</h2>
			<p>
				<?php if ( ! empty( $meta['styles'] ) ) : ?>
					<strong><?php echo (int) $meta['styles']; ?></strong> current styles loaded
					(SanMar data from <?php echo esc_html( $meta['version'] ); ?>, imported <?php echo esc_html( $meta['imported'] ); ?>).
				<?php else : ?>
					No catalog loaded yet.
				<?php endif; ?>
			</p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'iaq_import' ); ?>
				<input type="hidden" name="action" value="iaq_import">
				<p><label>Update from a new SanMar Data Library download (<code>SanMar_SDL_*.zip</code> or <code>.csv</code>):<br>
				<input type="file" name="sdl" accept=".zip,.csv"></label></p>
				<p><label>…or a download link (e.g. a Dropbox share link to the zip). The server downloads it directly:<br>
				<input type="url" class="regular-text" name="sdl_url" placeholder="https://www.dropbox.com/…/SanMar_SDL_D.zip?dl=0"></label></p>
				<p><label>…or a file already on the server (full path):<br>
				<input type="text" class="regular-text" name="sdl_path" placeholder="/home/user/sanmar/SanMar_SDL_D.zip"></label></p>
				<p class="description">Discontinued products are skipped automatically. Importing takes up to a minute.</p>
				<p>
					<button class="button button-primary" name="mode" value="sdl">Import SanMar file</button>
					<button class="button" name="mode" value="bundled">Reload the catalog bundled with the plugin</button>
				</p>
			</form>
		</div>
		<?php
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'iaq_save_settings' );
		$all   = array_keys( IAQ_Catalog::all_categories() );
		$show  = isset( $_POST['show_categories'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['show_categories'] ) ) : array();
		$emails = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( isset( $_POST['notify_email'] ) ? $_POST['notify_email'] : '' ) ) ) ), 'is_email' );
		$new   = array(
			'notify_email'      => implode( ', ', $emails ),
			'customer_copy'     => empty( $_POST['customer_copy'] ) ? 0 : 1,
			'intro_text'        => sanitize_textarea_field( wp_unslash( isset( $_POST['intro_text'] ) ? $_POST['intro_text'] : '' ) ),
			'thank_you_text'    => sanitize_textarea_field( wp_unslash( isset( $_POST['thank_you_text'] ) ? $_POST['thank_you_text'] : '' ) ),
			'max_upload_mb'     => max( 1, min( 64, (int) ( isset( $_POST['max_upload_mb'] ) ? $_POST['max_upload_mb'] : 15 ) ) ),
			'hidden_categories' => $all ? array_values( array_diff( $all, $show ) ) : iaq_settings()['hidden_categories'],
			'hidden_styles'     => sanitize_textarea_field( wp_unslash( isset( $_POST['hidden_styles'] ) ? $_POST['hidden_styles'] : '' ) ),
		);
		update_option( 'iaq_settings', $new );
		IAQ_Catalog::clear_cache();
		self::back( 'Settings saved.' );
	}

	public static function photos_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'iaq_photos' );
		$do = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		if ( 'pause' === $do ) {
			IAQ_Images::pause();
			self::back( 'Photo download paused.' );
		} elseif ( 'resume' === $do ) {
			IAQ_Images::resume();
			self::back( 'Photo download resumed.' );
		}
		IAQ_Images::start();
		self::back( 'Photo download started. It runs in the background; refresh to see progress.' );
	}

	public static function import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'iaq_import' );
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';
		if ( 'bundled' === $mode ) {
			if ( ! file_exists( IAQ_DIR . 'data/catalog.json.gz' ) ) {
				self::back( 'This copy of the plugin has no bundled catalog. Import a SanMar file instead.', true );
			}
			$res = IAQ_Catalog::import_bundled();
		} elseif ( ! empty( $_POST['sdl_url'] ) && empty( $_FILES['sdl']['tmp_name'] ) ) {
			$url = esc_url_raw( wp_unslash( $_POST['sdl_url'] ) );
			if ( false !== strpos( $url, 'dropbox.com' ) ) {
				$url = remove_query_arg( 'dl', $url );
				$url = add_query_arg( 'dl', '1', $url );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$tmp = download_url( $url, 300 );
			if ( is_wp_error( $tmp ) ) {
				self::back( 'Download failed: ' . $tmp->get_error_message(), true );
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
		} else {
			$path = '';
			if ( ! empty( $_FILES['sdl']['tmp_name'] ) && UPLOAD_ERR_OK === (int) $_FILES['sdl']['error'] ) {
				$name = sanitize_file_name( wp_unslash( $_FILES['sdl']['name'] ) );
				if ( ! preg_match( '/\.(zip|csv)$/i', $name ) ) {
					self::back( 'Please upload the .zip or .csv from the SanMar Data Library.', true );
				}
				$path = wp_tempnam( $name );
				move_uploaded_file( $_FILES['sdl']['tmp_name'], $path ); // phpcs:ignore
				$path_with_ext = $path . '.' . strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
				rename( $path, $path_with_ext ); // phpcs:ignore
				$path = $path_with_ext;
				$res  = IAQ_Catalog::import_sdl( $path );
				@unlink( $path ); // phpcs:ignore
			} elseif ( ! empty( $_POST['sdl_path'] ) ) {
				$path = wp_unslash( $_POST['sdl_path'] ); // phpcs:ignore
				$path = realpath( $path );
				if ( ! $path || ! preg_match( '/\.(zip|csv)$/i', $path ) || ! is_readable( $path ) ) {
					self::back( 'That file was not found on the server.', true );
				}
				$res = IAQ_Catalog::import_sdl( $path );
			} else {
				self::back( 'Choose a file to import.', true );
			}
		}
		if ( is_wp_error( $res ) ) {
			self::back( 'Import failed: ' . $res->get_error_message(), true );
		}
		IAQ_Images::start();
		self::back( sprintf( 'Catalog imported: %d current styles. Downloading any new photos in the background.', (int) $res ) );
	}

	/**
	 * Admin-only download/view of a stored quote file.
	 */
	public static function serve_file() {
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		$idx     = isset( $_GET['f'] ) ? (int) $_GET['f'] : -1;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'iaq_file_' . $post_id );
		$files = (array) get_post_meta( $post_id, '_iaq_files', true );
		if ( ! isset( $files[ $idx ] ) ) {
			wp_die( 'File not found.' );
		}
		$f   = $files[ $idx ];
		$abs = IAQ_Quotes::abs_path( $f['path'] );
		if ( ! file_exists( $abs ) ) {
			wp_die( 'File not found.' );
		}
		$types  = array(
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'pdf'  => 'application/pdf',
		);
		$inline = ! empty( $_GET['inline'] ) && isset( $types[ $f['ext'] ] );
		$type   = $inline ? $types[ $f['ext'] ] : 'application/octet-stream';
		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . filesize( $abs ) );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . basename( $f['orig'] ? $f['orig'] : $abs ) . '"' );
		readfile( $abs ); // phpcs:ignore
		exit;
	}

	public static function meta_boxes( $post ) {
		remove_meta_box( 'submitdiv', IAQ_Quotes::CPT, 'side' );
		remove_meta_box( 'slugdiv', IAQ_Quotes::CPT, 'normal' );
		add_meta_box( 'iaq_details', 'Request', array( __CLASS__, 'box_details' ), IAQ_Quotes::CPT, 'normal', 'high' );
		add_meta_box( 'iaq_status', 'Status', array( __CLASS__, 'box_status' ), IAQ_Quotes::CPT, 'side', 'high' );
	}

	public static function box_status( $post ) {
		$status = get_post_meta( $post->ID, '_iaq_status', true );
		$c      = get_post_meta( $post->ID, '_iaq_contact', true );
		wp_nonce_field( 'iaq_status', 'iaq_status_nonce' );
		echo '<p><strong>' . esc_html( IAQ_Quotes::reference( $post->ID ) ) . '</strong><br>' . esc_html( get_the_date( 'M j, Y g:i a', $post ) ) . '</p>';
		echo '<p><select name="iaq_status" style="width:100%">';
		foreach ( IAQ_Quotes::$statuses as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $status, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		if ( ! empty( $c['email'] ) ) {
			$subject = rawurlencode( 'Your quote ' . IAQ_Quotes::reference( $post->ID ) . ' - ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
			echo '<p><a class="button" style="width:100%;text-align:center" href="mailto:' . esc_attr( $c['email'] ) . '?subject=' . esc_attr( $subject ) . '">Email customer</a></p>';
		}
		submit_button( 'Update', 'primary', 'submit', false, array( 'style' => 'width:100%' ) );
		echo '<p style="margin-top:12px"><a class="submitdelete" style="color:#b32d2e" href="' . esc_url( get_delete_post_link( $post->ID ) ) . '">Move to Trash</a></p>';
	}

	public static function save_status( $post_id ) {
		if ( ! isset( $_POST['iaq_status_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['iaq_status_nonce'] ) ), 'iaq_status' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$st = isset( $_POST['iaq_status'] ) ? sanitize_key( $_POST['iaq_status'] ) : '';
		if ( isset( IAQ_Quotes::$statuses[ $st ] ) ) {
			update_post_meta( $post_id, '_iaq_status', $st );
		}
	}

	public static function box_details( $post ) {
		$c     = get_post_meta( $post->ID, '_iaq_contact', true );
		$items = get_post_meta( $post->ID, '_iaq_items', true );
		$files = (array) get_post_meta( $post->ID, '_iaq_files', true );
		if ( ! $c ) {
			echo '<p>No data.</p>';
			return;
		}
		echo '<style>#iaq_details .iaq-mocks{display:flex;flex-wrap:wrap;gap:12px;margin:10px 0}#iaq_details .iaq-mocks a{display:block;border:1px solid #ddd;border-radius:6px;padding:4px;background:#fff}#iaq_details .iaq-mocks img{display:block;max-width:240px;height:auto}</style>';
		echo IAQ_Quotes::summary_html( $c, $items ); // phpcs:ignore -- escaped inside.

		$mocks = array();
		$other = array();
		foreach ( $files as $i => $f ) {
			if ( 'mockup' === $f['role'] ) {
				$mocks[ $i ] = $f;
			} else {
				$other[ $i ] = $f;
			}
		}
		if ( $mocks ) {
			echo '<h3>Mockups</h3><div class="iaq-mocks">';
			foreach ( $mocks as $i => $f ) {
				echo '<a href="' . esc_url( IAQ_Quotes::file_url( $post->ID, $i, true ) ) . '" target="_blank" title="' . esc_attr( $f['label'] ) . '"><img src="' . esc_url( IAQ_Quotes::file_url( $post->ID, $i, true ) ) . '" alt="' . esc_attr( $f['label'] ) . '"></a>';
			}
			echo '</div>';
		}
		if ( $other ) {
			echo '<h3>Customer files</h3><ul>';
			foreach ( $other as $i => $f ) {
				echo '<li><a href="' . esc_url( IAQ_Quotes::file_url( $post->ID, $i ) ) . '">' . esc_html( $f['label'] . ': ' . $f['orig'] ) . '</a></li>';
			}
			echo '</ul>';
		} else {
			echo '<p><em>No logo uploaded.</em></p>';
		}
	}

	public static function columns( $cols ) {
		return array(
			'cb'         => $cols['cb'],
			'title'      => 'Customer',
			'iaq_ref'    => 'Ref',
			'iaq_email'  => 'Email / phone',
			'iaq_items'  => 'Products',
			'iaq_pcs'    => 'Pieces',
			'iaq_status' => 'Status',
			'date'       => 'Received',
		);
	}

	public static function column( $col, $post_id ) {
		$c     = get_post_meta( $post_id, '_iaq_contact', true );
		$items = (array) get_post_meta( $post_id, '_iaq_items', true );
		switch ( $col ) {
			case 'iaq_ref':
				echo esc_html( IAQ_Quotes::reference( $post_id ) );
				break;
			case 'iaq_email':
				if ( $c ) {
					echo '<a href="mailto:' . esc_attr( $c['email'] ) . '">' . esc_html( $c['email'] ) . '</a><br>' . esc_html( $c['phone'] );
				}
				break;
			case 'iaq_items':
				$names = array();
				foreach ( $items as $it ) {
					if ( is_array( $it ) ) {
						$names[] = $it['style'] . ' ' . $it['color'];
					}
				}
				echo esc_html( implode( ', ', $names ) );
				break;
			case 'iaq_pcs':
				$n = 0;
				foreach ( $items as $it ) {
					$n += is_array( $it ) ? (int) $it['total'] : 0;
				}
				echo (int) $n;
				break;
			case 'iaq_status':
				$st = get_post_meta( $post_id, '_iaq_status', true );
				echo esc_html( isset( IAQ_Quotes::$statuses[ $st ] ) ? IAQ_Quotes::$statuses[ $st ] : '' );
				break;
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( IAQ_Quotes::CPT === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'], $actions['view'] );
			if ( isset( $actions['edit'] ) ) {
				$actions['edit'] = str_replace( '>Edit<', '>Open<', $actions['edit'] );
			}
		}
		return $actions;
	}
}
