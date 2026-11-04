<?php
/**
 * Shortcode [apparel_quote] and front-end assets.
 */

defined( 'ABSPATH' ) || exit;

class IAQ_Frontend {

	public static function init() {
		add_shortcode( 'apparel_quote', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'iaq-form', IAQ_URL . 'assets/iaq-form.css', array(), IAQ_VERSION );
		wp_register_script( 'iaq-form', IAQ_URL . 'assets/iaq-form.js', array(), IAQ_VERSION, true );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'category' => '', // Optional: open on this category.
				'style'    => '', // Optional: open this style directly.
			),
			$atts,
			'apparel_quote'
		);
		if ( ! wp_style_is( 'iaq-form', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'iaq-form' );
		wp_enqueue_script( 'iaq-form' );

		$s = iaq_settings();
		wp_localize_script(
			'iaq-form',
			'IAQ_CFG',
			array(
				'rest'      => esc_url_raw( rest_url( IAQ_REST::NS . '/' ) ),
				'catalogUrl'=> IAQ_Catalog::index_file_url(),
				'maxMB'     => (int) $s['max_upload_mb'],
				'intro'     => $s['intro_text'],
				'startCat'  => $atts['category'],
				'startStyle'=> $atts['style'],
			)
		);

		ob_start();
		?>
		<div id="iaq-app" class="iaq" data-loading="1">
			<noscript>Please enable JavaScript to use the quote form, or call us.</noscript>
			<div class="iaq-loading">Loading products…</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
