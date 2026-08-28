<?php
/**
 * Front-end filter/sort bar rendering + asset enqueue.
 *
 * @package Tutor_Course_Search_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCSP_Filters_UI {

	/** @var TCSP_Settings */
	private $settings;

	public function __construct( TCSP_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );

		// Render the filter bar before the Tutor course loop on the archive.
		add_action( 'tutor_course/archive/before_loop', array( $this, 'render_bar' ) );
		// Fallbacks for themes/versions that expose different hooks.
		add_action( 'tutor_course/loop/before', array( $this, 'render_bar' ) );

		// Keep active filters/search on every pagination link.
		add_filter( 'paginate_links', array( $this, 'keep_filters_on_pagination' ) );

		// Shortcode escape hatch: [tcsp_filters]
		add_shortcode( 'tcsp_filters', array( $this, 'shortcode' ) );
	}

	/**
	 * The filter/search query args currently active in the request.
	 *
	 * @return array
	 */
	private function active_args() {
		$scalar_keys = array( 's', 'tcsp_price', 'tcsp_level', 'tcsp_min_rating', 'tcsp_min_students', 'tcsp_sort' );
		$array_keys  = array( 'tcsp_category', 'tcsp_tag' );
		$args        = array();

		foreach ( $scalar_keys as $k ) {
			if ( isset( $_GET[ $k ] ) && '' !== $_GET[ $k ] && ! is_array( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$args[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		// Multi-select filters (checkboxes) arrive as tcsp_category[]/tcsp_tag[].
		foreach ( $array_keys as $k ) {
			if ( ! empty( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$values = TCSP_Query::sanitize_slug_list( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( ! empty( $values ) ) {
					$args[ $k ] = $values;
				}
			}
		}

		return $args;
	}

	/**
	 * Append the active filters to a pagination URL so page 2+ keeps them.
	 *
	 * @param string $link Pagination link.
	 * @return string
	 */
	public function keep_filters_on_pagination( $link ) {
		if ( ! $this->is_course_context() ) {
			return $link;
		}
		$args = $this->active_args();
		if ( empty( $args ) ) {
			return $link;
		}
		// array_map( 'rawurlencode', ... ) can't be used directly here since
		// tcsp_category/tcsp_tag are arrays of values, not scalars.
		return add_query_arg( $this->rawurlencode_deep( $args ), $link );
	}

	/**
	 * rawurlencode() every scalar in a possibly-nested args array, leaving
	 * WP's add_query_arg() to build the tcsp_category[]=a&tcsp_category[]=b
	 * style query string for the array entries.
	 *
	 * @param array $args Query args (scalars and/or arrays of scalars).
	 * @return array
	 */
	private function rawurlencode_deep( $args ) {
		foreach ( $args as $key => $value ) {
			$args[ $key ] = is_array( $value ) ? array_map( 'rawurlencode', $value ) : rawurlencode( $value );
		}
		return $args;
	}

	/**
	 * Is this a page where the filter bar / suggestions should load?
	 */
	private function is_course_context() {
		return is_post_type_archive( TCSP_Plugin::COURSE_CPT )
			|| is_tax( 'course-category' )
			|| is_tax( 'course-tag' )
			|| ( is_search() );
	}

	public function enqueue() {
		// The sticker can be rendered by a Tutor course widget outside the
		// archive, so its small stylesheet is available site-wide. The larger
		// filter/autocomplete script remains archive-only.
		wp_enqueue_style(
			'tcsp-filters',
			TCSP_URL . 'assets/css/tcsp-filters.css',
			array(),
			TCSP_VERSION
		);

		if ( ! $this->is_course_context() ) {
			return;
		}

		wp_enqueue_script(
			'tcsp-filters',
			TCSP_URL . 'assets/js/tcsp-filters.js',
			array( 'jquery' ),
			TCSP_VERSION,
			true
		);

		wp_localize_script(
			'tcsp-filters',
			'tcspConfig',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'suggestAction'      => TCSP_Suggest::ACTION,
				'nonce'              => wp_create_nonce( TCSP_Suggest::ACTION ),
				'replaceSuggestions' => (int) $this->settings->get( 'replace_suggestions' ),
				'minChars'           => 2,
				'i18n'               => array(
					'searching'  => __( 'Searching…', 'tutor-course-search-pro' ),
					'noResults'  => __( 'No matching courses', 'tutor-course-search-pro' ),
					'promoted'   => __( 'Promoted', 'tutor-course-search-pro' ),
					'viewAll'    => __( 'View all results', 'tutor-course-search-pro' ),
				),
			)
		);

		// If replacing suggestions, tell Tutor's default search to stand down.
		if ( $this->settings->get( 'replace_suggestions' ) ) {
			wp_dequeue_script( 'tutor-course-search' );
			wp_dequeue_script( 'tutor-frontend-search' );
		}
	}

	/**
	 * The filter/sort bar markup.
	 */
	public function render_bar() {
		static $rendered = false;
		if ( $rendered || ! $this->is_course_context() ) {
			return;
		}
		$rendered = true;

		$s          = $this->settings;
		$get        = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cur_price  = isset( $get['tcsp_price'] ) ? sanitize_key( $get['tcsp_price'] ) : '';
		$cur_level  = isset( $get['tcsp_level'] ) ? sanitize_key( $get['tcsp_level'] ) : '';
		$cur_cats   = ! empty( $get['tcsp_category'] ) ? TCSP_Query::sanitize_slug_list( $get['tcsp_category'] ) : array();
		$cur_tags   = ! empty( $get['tcsp_tag'] ) ? TCSP_Query::sanitize_slug_list( $get['tcsp_tag'] ) : array();
		$cur_rating = isset( $get['tcsp_min_rating'] ) ? (int) $get['tcsp_min_rating'] : 0;
		$cur_students = isset( $get['tcsp_min_students'] ) ? (int) $get['tcsp_min_students'] : 0;
		$cur_sort   = isset( $get['tcsp_sort'] ) ? sanitize_key( $get['tcsp_sort'] ) : (string) $s->get( 'default_sort' );
		$cur_search = isset( $get['s'] ) ? sanitize_text_field( $get['s'] ) : '';
		$has_active = (bool) array_filter(
			array( $cur_price, $cur_level, $cur_cats, $cur_tags, $cur_rating, $cur_students, $cur_search )
		);
		?>
		<form class="tcsp-filter-bar" method="get" role="search">
			<div class="tcsp-search-wrap">
				<input type="search" name="s" class="tcsp-search-input" autocomplete="off"
					value="<?php echo esc_attr( $cur_search ); ?>"
					placeholder="<?php esc_attr_e( 'Search courses…', 'tutor-course-search-pro' ); ?>">
				<div class="tcsp-suggest-box" hidden></div>
			</div>

			<div class="tcsp-filter-controls">
				<?php if ( $s->get( 'enable_price_filter' ) ) : ?>
					<select name="tcsp_price" class="tcsp-select" aria-label="<?php esc_attr_e( 'Price', 'tutor-course-search-pro' ); ?>">
						<option value=""><?php esc_html_e( 'Any price', 'tutor-course-search-pro' ); ?></option>
						<option value="free" <?php selected( $cur_price, 'free' ); ?>><?php esc_html_e( 'Free', 'tutor-course-search-pro' ); ?></option>
						<option value="paid" <?php selected( $cur_price, 'paid' ); ?>><?php esc_html_e( 'Paid', 'tutor-course-search-pro' ); ?></option>
					</select>
				<?php endif; ?>

				<?php if ( $s->get( 'enable_level_filter' ) ) : ?>
					<select name="tcsp_level" class="tcsp-select" aria-label="<?php esc_attr_e( 'Level', 'tutor-course-search-pro' ); ?>">
						<option value=""><?php esc_html_e( 'Any level', 'tutor-course-search-pro' ); ?></option>
						<?php foreach ( $this->levels() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cur_level, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>

				<?php if ( $s->get( 'enable_category_filter' ) ) : ?>
					<div class="tcsp-multiselect">
						<button type="button" class="tcsp-multiselect-toggle" aria-expanded="false">
							<?php esc_html_e( 'Category', 'tutor-course-search-pro' ); ?>
							<?php if ( ! empty( $cur_cats ) ) : ?>
								<span class="tcsp-multiselect-count"><?php echo esc_html( count( $cur_cats ) ); ?></span>
							<?php endif; ?>
						</button>
						<div class="tcsp-multiselect-panel" hidden>
							<p class="tcsp-multiselect-hint"><?php esc_html_e( 'Courses matching ALL selected categories will be shown.', 'tutor-course-search-pro' ); ?></p>
							<?php foreach ( $this->categories() as $term ) : ?>
								<label class="tcsp-multiselect-option <?php echo $term->parent ? 'tcsp-multiselect-option--child' : ''; ?>" style="--tcsp-depth:<?php echo esc_attr( $this->term_depth( $term ) ); ?>;">
									<input type="checkbox" name="tcsp_category[]" value="<?php echo esc_attr( $term->slug ); ?>" <?php checked( in_array( $term->slug, $cur_cats, true ) ); ?>>
									<?php echo esc_html( $term->name ); ?>
									<span class="tcsp-term-count"><?php echo esc_html( number_format_i18n( (int) $term->count ) ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $s->get( 'enable_tag_filter' ) ) : ?>
					<div class="tcsp-multiselect">
						<button type="button" class="tcsp-multiselect-toggle" aria-expanded="false">
							<?php esc_html_e( 'Tag', 'tutor-course-search-pro' ); ?>
							<?php if ( ! empty( $cur_tags ) ) : ?>
								<span class="tcsp-multiselect-count"><?php echo esc_html( count( $cur_tags ) ); ?></span>
							<?php endif; ?>
						</button>
						<div class="tcsp-multiselect-panel" hidden>
							<p class="tcsp-multiselect-hint"><?php esc_html_e( 'Courses matching ALL selected tags will be shown.', 'tutor-course-search-pro' ); ?></p>
							<?php foreach ( $this->tags() as $term ) : ?>
								<label class="tcsp-multiselect-option">
									<input type="checkbox" name="tcsp_tag[]" value="<?php echo esc_attr( $term->slug ); ?>" <?php checked( in_array( $term->slug, $cur_tags, true ) ); ?>>
									<?php echo esc_html( $term->name ); ?>
									<span class="tcsp-term-count"><?php echo esc_html( number_format_i18n( (int) $term->count ) ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $s->get( 'enable_rating_filter' ) ) : ?>
					<select name="tcsp_min_rating" class="tcsp-select" aria-label="<?php esc_attr_e( 'Minimum rating', 'tutor-course-search-pro' ); ?>">
						<option value="0"><?php esc_html_e( 'Any rating', 'tutor-course-search-pro' ); ?></option>
						<?php for ( $r = 4; $r >= 1; $r-- ) : ?>
							<option value="<?php echo esc_attr( $r ); ?>" <?php selected( $cur_rating, $r ); ?>>
								<?php /* translators: %d: star rating */ printf( esc_html__( '%d★ & up', 'tutor-course-search-pro' ), (int) $r ); ?>
							</option>
						<?php endfor; ?>
					</select>
				<?php endif; ?>

				<?php if ( $s->get( 'enable_students_filter' ) ) : ?>
					<select name="tcsp_min_students" class="tcsp-select" aria-label="<?php esc_attr_e( 'Minimum students', 'tutor-course-search-pro' ); ?>">
						<option value="0"><?php esc_html_e( 'Any number of students', 'tutor-course-search-pro' ); ?></option>
						<?php foreach ( array( 5, 10, 20, 50, 100, 500 ) as $threshold ) : ?>
							<option value="<?php echo esc_attr( $threshold ); ?>" <?php selected( $cur_students, $threshold ); ?>>
								<?php /* translators: %s: formatted student count */ printf( esc_html__( '%s+ students', 'tutor-course-search-pro' ), esc_html( number_format_i18n( $threshold ) ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>

				<select name="tcsp_sort" class="tcsp-select tcsp-sort" aria-label="<?php esc_attr_e( 'Sort by', 'tutor-course-search-pro' ); ?>">
					<?php foreach ( $s->sort_options() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cur_sort, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>

				<button type="submit" class="tcsp-apply-btn"><?php esc_html_e( 'Apply', 'tutor-course-search-pro' ); ?></button>
				<?php if ( $has_active ) : ?>
					<a href="<?php echo esc_url( $this->reset_url() ); ?>" class="tcsp-reset-btn">
						<?php esc_html_e( 'Reset filters', 'tutor-course-search-pro' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</form>
		<?php
	}

	/**
	 * "Reset filters" always sends the visitor back to the site's home
	 * page (with all tcsp_* filters and search text stripped), rather than
	 * back to the course archive/search page they were filtering on.
	 *
	 * @return string
	 */
	private function reset_url() {
		return home_url( '/' );
	}

	public function shortcode() {
		ob_start();
		$this->render_bar();
		return ob_get_clean();
	}

	private function levels() {
		return array(
			'beginner'     => __( 'Beginner', 'tutor-course-search-pro' ),
			'intermediate' => __( 'Intermediate', 'tutor-course-search-pro' ),
			'expert'       => __( 'Expert', 'tutor-course-search-pro' ),
			'all_levels'   => __( 'All levels', 'tutor-course-search-pro' ),
		);
	}

	private function categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'course-category',
				'hide_empty' => true,
				'number'     => 0,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		// Display parents before children while preserving alphabetical order
		// within each branch. Tutor's category taxonomy is hierarchical, but
		// get_terms() returns a flat list by default.
		$by_parent = array();
		foreach ( $terms as $term ) {
			$parent = (int) $term->parent;
			if ( ! isset( $by_parent[ $parent ] ) ) {
				$by_parent[ $parent ] = array();
			}
			$by_parent[ $parent ][] = $term;
		}
		$ordered = array();
		$walk    = function ( $parent ) use ( &$walk, &$ordered, $by_parent ) {
			if ( empty( $by_parent[ $parent ] ) ) {
				return;
			}
			foreach ( $by_parent[ $parent ] as $term ) {
				$ordered[] = $term;
				$walk( (int) $term->term_id );
			}
		};
		$walk( 0 );
		return count( $ordered ) === count( $terms ) ? $ordered : $terms;
	}

	private function tags() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'course-tag',
				'hide_empty' => true,
				'number'     => 0,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Calculate a category's nesting depth for a readable filter tree.
	 */
	private function term_depth( $term ) {
		$depth  = 0;
		$parent = (int) $term->parent;
		while ( $parent && $depth < 20 ) {
			$ancestor = get_term( $parent, 'course-category' );
			if ( ! $ancestor || is_wp_error( $ancestor ) ) {
				break;
			}
			$depth++;
			$parent = (int) $ancestor->parent;
		}
		return $depth;
	}
}
