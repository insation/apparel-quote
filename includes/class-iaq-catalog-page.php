<?php
/**
 * Shortcode [apparel_catalog]: browsable product catalogue (server-rendered).
 *
 *   No category      -> category tiles
 *   ?cat=t-shirts    -> products in that category (search, brand filter, pages)
 *
 * Each product links to the quote form page with ?style=STYLE so the customer
 * lands straight on that product to pick colour, sizes and logo.
 */

defined( 'ABSPATH' ) || exit;

class IAQ_Catalog_Page {

	const PER_PAGE = 48;

	// Most-used categories first (same order as the form).
	private static $order = array( 'T-Shirts', 'Polos/Knits', 'Sweatshirts/Fleece', 'Caps', 'Outerwear', 'Activewear', 'Woven Shirts', "Women's", 'Youth', 'Tall', 'Bags', 'Workwear', 'Bottoms', 'Infant & Toddler', 'Accessories', 'Personal Protection' );

	// Best-known blank brands first in category lists; the rest follow alphabetically.
	private static $brand_order = array( 'Port & Co', 'Gildan', 'District', 'Sport-Tek', 'Port Authority', 'Bella + Canvas', 'Next Level Apparel', 'Comfort Colors', 'Jerzees', 'Nike', 'Carhartt', 'The North Face', 'Richardson', 'New Era', 'Champion', 'CornerStone' );

	private static function brand_rank( $b ) {
		$i = array_search( $b, self::$brand_order, true );
		return false === $i ? 100 : $i;
	}

	public static function init() {
		add_shortcode( 'apparel_catalog', array( __CLASS__, 'shortcode' ) );
	}

	private static function rank( $cat ) {
		$i = array_search( $cat, self::$order, true );
		return false === $i ? 50 : $i;
	}

	/**
	 * URL of the page holding the quote form (found automatically, cached).
	 */
	public static function quote_url() {
		$id = (int) get_option( 'iaq_quote_page_id' );
		if ( ! $id || 'publish' !== get_post_status( $id ) || false === strpos( (string) get_post_field( 'post_content', $id ), '[apparel_quote' ) ) {
			global $wpdb;
			$id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND post_content LIKE '%[apparel_quote%' ORDER BY ID LIMIT 1" ); // phpcs:ignore
			update_option( 'iaq_quote_page_id', $id, false );
		}
		return $id ? get_permalink( $id ) : home_url( '/apparel-quote/' );
	}

	/**
	 * Thumbnail URL: the stored local copy when there is one, otherwise the image endpoint.
	 */
	public static function thumb_url( $path, $w = 300 ) {
		if ( ! $path ) {
			return '';
		}
		if ( 0 === strpos( $path, 'http' ) ) {
			return $path;
		}
		$file = IAQ_Images::file_for( $path, $w );
		if ( file_exists( $file ) ) {
			$up = wp_upload_dir();
			return trailingslashit( $up['baseurl'] ) . 'iaq-cache/' . basename( $file );
		}
		return add_query_arg(
			array(
				'p' => rawurlencode( $path ),
				'w' => $w,
			),
			rest_url( IAQ_REST::NS . '/img' )
		);
	}

	public static function shortcode() {
		if ( ! wp_style_is( 'iaq-form', 'registered' ) ) {
			IAQ_Frontend::register_assets();
		}
		wp_enqueue_style( 'iaq-form' );

		$ix   = IAQ_Catalog::index();
		$cats = array_keys( $ix['categories'] );
		usort(
			$cats,
			function ( $a, $b ) {
				$r = self::rank( $a ) - self::rank( $b );
				return $r ? $r : strcmp( $a, $b );
			}
		);
		$slugs = array();
		foreach ( $cats as $c ) {
			$slugs[ sanitize_title( $c ) ] = $c;
		}

		$cat_slug = isset( $_GET['cat'] ) ? sanitize_title( wp_unslash( $_GET['cat'] ) ) : ''; // phpcs:ignore
		$q        = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore
		$cat      = isset( $slugs[ $cat_slug ] ) ? $slugs[ $cat_slug ] : '';

		ob_start();
		echo '<div class="iaq iaq-catalog" id="iaq-catalog">';
		if ( ! $cat && '' === $q ) {
			self::render_categories( $ix, $cats );
		} else {
			self::render_products( $ix, $cats, $cat, $q );
		}
		echo '</div>';
		return ob_get_clean();
	}

	private static function search_form( $cat, $q, $brand = '', $brands = array() ) {
		$base = remove_query_arg( array( 'cat', 'q', 'brand', 'pg' ) );
		?>
		<form class="iaq-cat-search" method="get" action="<?php echo esc_url( $base . '#iaq-catalog' ); ?>">
			<?php if ( $cat ) : ?>
				<input type="hidden" name="cat" value="<?php echo esc_attr( sanitize_title( $cat ) ); ?>">
			<?php endif; ?>
			<input class="iaq-search" type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( $cat ? 'Search ' . $cat . ' by name, brand or style #' : 'Search all products by name, brand or style #' ); ?>" aria-label="Search products">
			<?php if ( $brands ) : ?>
				<select class="iaq-input iaq-brand-select" name="brand" aria-label="Brand" onchange="this.form.submit()">
					<option value="">All brands</option>
					<?php foreach ( $brands as $b ) : ?>
						<option value="<?php echo esc_attr( $b ); ?>" <?php selected( $brand, $b ); ?>><?php echo esc_html( $b ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<button class="iaq-btn iaq-btn-primary" type="submit">Search</button>
		</form>
		<?php
	}

	private static function render_categories( $ix, $cats ) {
		// First product photo in each category as its tile image.
		$first = array();
		foreach ( $ix['styles'] as $s ) {
			if ( ! isset( $first[ $s['c'] ] ) && $s['i'] ) {
				$first[ $s['c'] ] = $s['i'];
			}
		}
		$base = remove_query_arg( array( 'cat', 'q', 'brand', 'pg' ) );
		echo '<p class="iaq-intro">Browse our apparel catalogue. Pick a product to choose your colour and sizes, add your logo and see it on the product, then send it to us for a quote.</p>';
		self::search_form( '', '' );
		echo '<div class="iaq-grid iaq-cat-grid">';
		foreach ( $cats as $c ) {
			$url = add_query_arg( 'cat', sanitize_title( $c ), $base ) . '#iaq-catalog';
			echo '<a class="iaq-card iaq-cat-card" href="' . esc_url( $url ) . '">';
			echo '<span class="iaq-card-img">';
			if ( ! empty( $first[ $c ] ) ) {
				echo '<img src="' . esc_url( self::thumb_url( $first[ $c ] ) ) . '" alt="' . esc_attr( $c ) . '" loading="lazy" decoding="async">';
			}
			echo '</span>';
			echo '<span class="iaq-card-title">' . esc_html( $c ) . '</span>';
			echo '<span class="iaq-card-meta">' . (int) $ix['categories'][ $c ] . ' products</span>';
			echo '</a>';
		}
		echo '</div>';
	}

	private static function render_products( $ix, $cats, $cat, $q ) {
		$brand = isset( $_GET['brand'] ) ? sanitize_text_field( wp_unslash( $_GET['brand'] ) ) : ''; // phpcs:ignore
		$page  = isset( $_GET['pg'] ) ? max( 1, (int) $_GET['pg'] ) : 1; // phpcs:ignore
		$words = $q ? preg_split( '/\s+/', strtolower( $q ), -1, PREG_SPLIT_NO_EMPTY ) : array();

		$in_cat = array();
		foreach ( $ix['styles'] as $s ) {
			if ( $cat && $s['c'] !== $cat ) {
				continue;
			}
			if ( $words ) {
				$hay = strtolower( $s['s'] . ' ' . $s['t'] . ' ' . $s['b'] . ' ' . $s['sc'] . ' ' . $s['c'] );
				foreach ( $words as $w ) {
					if ( false === strpos( $hay, $w ) ) {
						continue 2;
					}
				}
			}
			$in_cat[] = $s;
		}
		$brands = array_values( array_unique( wp_list_pluck( $in_cat, 'b' ) ) );
		sort( $brands );
		$list = $brand ? array_values( array_filter( $in_cat, function ( $s ) use ( $brand ) { return $s['b'] === $brand; } ) ) : $in_cat;

		// Popular brands first (stable: keeps the catalogue order inside each brand).
		$pos = 0;
		foreach ( $list as &$row ) {
			$row['_o'] = $pos++;
		}
		unset( $row );
		usort(
			$list,
			function ( $a, $b ) {
				$r = self::brand_rank( $a['b'] ) - self::brand_rank( $b['b'] );
				return $r ? $r : $a['_o'] - $b['_o'];
			}
		);

		// Exact style number first when searching.
		if ( 1 === count( $words ) ) {
			$w0 = $words[0];
			usort(
				$list,
				function ( $a, $b ) use ( $w0 ) {
					$ra = strtolower( $a['s'] ) === $w0 ? 0 : ( 0 === strpos( strtolower( $a['s'] ), $w0 ) ? 1 : 2 );
					$rb = strtolower( $b['s'] ) === $w0 ? 0 : ( 0 === strpos( strtolower( $b['s'] ), $w0 ) ? 1 : 2 );
					return $ra - $rb;
				}
			);
		}

		$total = count( $list );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( $page, $pages );
		$shown = array_slice( $list, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$base  = remove_query_arg( array( 'cat', 'q', 'brand', 'pg' ) );
		$quote = self::quote_url();

		echo '<p class="iaq-crumbs"><a href="' . esc_url( $base . '#iaq-catalog' ) . '">All categories</a>';
		if ( $cat ) {
			echo ' &rsaquo; <span>' . esc_html( $cat ) . '</span>';
		}
		echo '</p>';
		echo '<h2 class="iaq-cat-title">' . esc_html( $cat ? $cat : 'Search results' ) . '</h2>';

		// Category pills.
		echo '<div class="iaq-pills">';
		foreach ( $cats as $c ) {
			$on = $c === $cat ? ' is-on' : '';
			echo '<a class="iaq-pill' . esc_attr( $on ) . '" href="' . esc_url( add_query_arg( 'cat', sanitize_title( $c ), $base ) . '#iaq-catalog' ) . '">' . esc_html( $c ) . '</a>';
		}
		echo '</div>';

		self::search_form( $cat, $q, $brand, $brands );
		echo '<div class="iaq-count">' . (int) $total . ( 1 === $total ? ' product' : ' products' ) . '</div>';

		if ( ! $shown ) {
			echo '<p class="iaq-empty">No products match. Try another word or style number.</p>';
			return;
		}
		echo '<div class="iaq-grid">';
		foreach ( $shown as $s ) {
			$url = add_query_arg( 'style', rawurlencode( $s['s'] ), $quote );
			echo '<a class="iaq-card" href="' . esc_url( $url ) . '">';
			echo '<span class="iaq-card-img"><img src="' . esc_url( self::thumb_url( $s['i'] ) ) . '" alt="' . esc_attr( $s['b'] . ' ' . $s['s'] . ' ' . $s['t'] ) . '" loading="lazy" decoding="async"></span>';
			if ( ! empty( $s['n'] ) ) {
				echo '<span class="iaq-badge">New</span>';
			}
			echo '<span class="iaq-card-brand">' . esc_html( $s['b'] . ' · ' . $s['s'] ) . '</span>';
			echo '<span class="iaq-card-title">' . esc_html( $s['t'] ) . '</span>';
			echo '<span class="iaq-card-meta">' . (int) $s['k'] . ( 1 === (int) $s['k'] ? ' colour' : ' colours' ) . '</span>';
			echo '<span class="iaq-btn iaq-btn-primary iaq-btn-sm iaq-card-cta">Customize</span>';
			echo '</a>';
		}
		echo '</div>';

		if ( $pages > 1 ) {
			echo '<nav class="iaq-pager" aria-label="Pages">';
			$args = array_filter(
				array(
					'cat'   => $cat ? sanitize_title( $cat ) : '',
					'q'     => $q,
					'brand' => $brand,
				)
			);
			if ( $page > 1 ) {
				echo '<a class="iaq-btn iaq-btn-sm" href="' . esc_url( add_query_arg( array_merge( $args, array( 'pg' => $page - 1 ) ), $base ) . '#iaq-catalog' ) . '">&larr; Previous</a>';
			}
			echo '<span class="iaq-pager-info">Page ' . (int) $page . ' of ' . (int) $pages . '</span>';
			if ( $page < $pages ) {
				echo '<a class="iaq-btn iaq-btn-sm" href="' . esc_url( add_query_arg( array_merge( $args, array( 'pg' => $page + 1 ) ), $base ) . '#iaq-catalog' ) . '">Next &rarr;</a>';
			}
			echo '</nav>';
		}
	}
}
