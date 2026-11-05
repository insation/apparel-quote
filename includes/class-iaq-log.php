<?php
/**
 * Problem log for the quote form.
 *
 * Every failed submission (with the exact reason), every crash and every
 * email that could not be sent is recorded here, so the shop can see what
 * went wrong instead of guessing. Shown on Quote Requests > Settings, with a
 * dashboard notice when something new happens.
 *
 * @package Insation_Apparel_Quote
 */

defined( 'ABSPATH' ) || exit;

class IAQ_Log {

	const OPTION = 'iaq_error_log';
	const SEEN   = 'iaq_error_log_seen';
	const MAX    = 60;

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_iaq_clear_log', array( __CLASS__, 'clear' ) );
		add_filter( 'manage_' . IAQ_Quotes::CPT . '_posts_columns', array( __CLASS__, 'column_head' ), 20 );
		add_action( 'manage_' . IAQ_Quotes::CPT . '_posts_custom_column', array( __CLASS__, 'column_body' ), 20, 2 );
		add_action( 'add_meta_boxes_' . IAQ_Quotes::CPT, array( __CLASS__, 'meta_box' ) );
	}

	/**
	 * Record a problem. Returns a short ID the customer can quote on the phone.
	 */
	public static function add( $type, $message, $status = 0, $ctx = array() ) {
		$id  = strtoupper( wp_generate_password( 6, false, false ) );
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				'id'     => $id,
				'time'   => time(),
				'type'   => sanitize_key( $type ),
				'status' => (int) $status,
				'msg'    => wp_strip_all_tags( (string) $message ),
				'ctx'    => array_map( 'sanitize_text_field', array_filter( (array) $ctx, 'is_scalar' ) ),
			)
		);
		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
		return $id;
	}

	public static function entries() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	private static function unseen() {
		$seen = (int) get_option( self::SEEN, 0 );
		$n    = 0;
		foreach ( self::entries() as $e ) {
			if ( $e['time'] > $seen && in_array( $e['type'], array( 'crash', 'mail', 'server', 'upload' ), true ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function label( $type ) {
		$map = array(
			'crash'      => 'Website error',
			'server'     => 'Website error',
			'mail'       => 'Email not sent',
			'upload'     => 'Upload problem',
			'validation' => 'Customer input',
			'session'    => 'Session expired',
			'limit'      => 'Rate limit',
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : ucfirst( $type );
	}

	/**
	 * Dashboard notice when real problems (not customer typos) happened.
	 */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$n = self::unseen();
		if ( ! $n ) {
			return;
		}
		$url = admin_url( 'edit.php?post_type=' . IAQ_Quotes::CPT . '&page=iaq-settings#iaq-problems' );
		printf(
			'<div class="notice notice-error"><p><strong>Apparel quote form:</strong> %d problem%s since you last looked. <a href="%s">See what happened</a></p></div>',
			(int) $n,
			1 === $n ? '' : 's',
			esc_url( $url )
		);
	}

	/**
	 * Section on the settings page.
	 */
	public static function render_section() {
		$log = self::entries();
		update_option( self::SEEN, time(), false );
		echo '<h2 id="iaq-problems">Recent problems</h2>';
		echo '<p class="description">Every quote request that failed, and why. "Customer input" rows are normal (for example a missing email); "Website error" and "Email not sent" rows need attention.</p>';
		if ( ! $log ) {
			echo '<p>No problems recorded. 👍</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:150px">When</th><th style="width:130px">Type</th><th>What happened</th><th style="width:220px">Who</th><th style="width:70px">ID</th></tr></thead><tbody>';
		foreach ( array_slice( $log, 0, 40 ) as $e ) {
			$bad = in_array( $e['type'], array( 'crash', 'mail', 'server', 'upload' ), true );
			$who = trim( ( isset( $e['ctx']['name'] ) ? $e['ctx']['name'] : '' ) . ' ' . ( isset( $e['ctx']['email'] ) ? '<' . $e['ctx']['email'] . '>' : '' ) );
			$det = $e['msg'];
			if ( ! empty( $e['ctx']['detail'] ) ) {
				$det .= ' — ' . $e['ctx']['detail'];
			}
			if ( ! empty( $e['ctx']['quote'] ) ) {
				$det .= ' (request #' . $e['ctx']['quote'] . ')';
			}
			printf(
				'<tr><td>%s</td><td>%s%s</td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
				esc_html( wp_date( 'M j, Y g:i a', $e['time'] ) ),
				$bad ? '<span style="color:#b32d2e;font-weight:600">' : '<span>',
				esc_html( self::label( $e['type'] ) ) . '</span>',
				esc_html( $det ),
				esc_html( $who ? $who : '—' ),
				esc_html( $e['id'] )
			);
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
		wp_nonce_field( 'iaq_clear_log' );
		echo '<input type="hidden" name="action" value="iaq_clear_log"><button class="button">Clear this list</button></form>';
	}

	public static function clear() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'iaq_clear_log' ) ) {
			wp_die( 'Not allowed' );
		}
		delete_option( self::OPTION );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . IAQ_Quotes::CPT . '&page=iaq-settings#iaq-problems' ) );
		exit;
	}

	/* ---- Email status on each quote request ---- */

	public static function column_head( $cols ) {
		$cols['iaq_mail'] = 'Email';
		return $cols;
	}

	public static function column_body( $col, $post_id ) {
		if ( 'iaq_mail' !== $col ) {
			return;
		}
		echo wp_kses_post( self::mail_badge( $post_id ) );
	}

	public static function mail_badge( $post_id ) {
		$m = get_post_meta( $post_id, '_iaq_mail', true );
		if ( ! is_array( $m ) || ! isset( $m['shop'] ) ) {
			return '<span style="color:#888">—</span>';
		}
		if ( true === $m['shop'] ) {
			return '<span style="color:#008a20">✔ Sent</span>';
		}
		return '<span style="color:#b32d2e;font-weight:600">✖ Not sent</span><br><small>' . esc_html( (string) $m['shop'] ) . '</small>';
	}

	public static function meta_box() {
		add_meta_box(
			'iaq_mail_status',
			'Email delivery',
			function ( $post ) {
				$m = get_post_meta( $post->ID, '_iaq_mail', true );
				echo '<p><strong>To the shop:</strong> ' . wp_kses_post( self::mail_badge( $post->ID ) ) . '</p>';
				if ( is_array( $m ) && isset( $m['customer'] ) ) {
					echo '<p><strong>Copy to customer:</strong> ' . ( true === $m['customer'] ? '<span style="color:#008a20">✔ Sent</span>' : '<span style="color:#b32d2e">✖ ' . esc_html( (string) $m['customer'] ) . '</span>' ) . '</p>';
				}
				echo '<p class="description">Full email history: WP Mail SMTP &rarr; Email Log.</p>';
			},
			IAQ_Quotes::CPT,
			'side',
			'high'
		);
	}
}
