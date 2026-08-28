<?php
/**
 * Settings: admin page to configure sponsored course tiers, ranking weights,
 * and which filters / sort options are exposed on the archive.
 *
 * @package Tutor_Course_Search_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCSP_Settings {

	const OPTION = 'tcsp_settings';
	const NONCE  = 'tcsp_settings_save';

	/** @var array Cached settings. */
	private $cache = null;

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 100 );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
	}

	/**
	 * Defaults for every setting.
	 */
	public function defaults() {
		return array(
			'promote_courses'      => 1,       // Boost individually promoted courses.
			'promote_paid_courses' => 1,       // Secondary boost for paid courses.
			'promoted_weight'      => 100,     // Ranking boost applied to promoted courses.
			'paid_course_weight'   => 25,      // Ranking boost applied to paid courses.
			'promotion_tiers'      => array(
				'platinum' => array( 'label' => 'Platinum', 'weight' => 300, 'color' => '#7c3aed' ),
				'gold'     => array( 'label' => 'Gold', 'weight' => 180, 'color' => '#b7791f' ),
				'featured' => array( 'label' => 'Featured', 'weight' => 80, 'color' => '#2563eb' ),
			),
			'enable_price_filter' => 1,
			'enable_level_filter' => 1,
			'enable_category_filter' => 1,
			'enable_tag_filter'   => 1,
			'enable_rating_filter' => 1,
			'enable_students_filter' => 1,
			'default_sort'        => 'relevance',
			'replace_suggestions' => 1,
			'suggest_limit'       => 8,
		);
	}

	/**
	 * Get all settings merged with defaults.
	 */
	public function all() {
		if ( null === $this->cache ) {
			$saved       = get_option( self::OPTION, array() );
			$this->cache = wp_parse_args( is_array( $saved ) ? $saved : array(), $this->defaults() );
		}
		return $this->cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public function add_menu() {
		add_submenu_page(
			'tutor',
			__( 'Course Search Pro', 'tutor-course-search-pro' ),
			__( 'Course Search Pro', 'tutor-course-search-pro' ),
			'manage_options',
			'tcsp-settings',
			array( $this, 'render_page' )
		);
	}

	public function maybe_save() {
		if ( ! isset( $_POST['tcsp_settings_submit'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = isset( $_POST['tcsp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tcsp_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}

		$in  = wp_unslash( $_POST );
		$default_sort = isset( $in['default_sort'] ) && ! is_array( $in['default_sort'] ) ? sanitize_key( $in['default_sort'] ) : 'relevance';
		if ( ! array_key_exists( $default_sort, $this->sort_options() ) ) {
			$default_sort = 'relevance';
		}
		$out = array(
			'promote_courses'        => isset( $in['promote_courses'] ) ? 1 : 0,
			'promote_paid_courses'   => isset( $in['promote_paid_courses'] ) ? 1 : 0,
			'promoted_weight'        => isset( $in['promoted_weight'] ) ? absint( $in['promoted_weight'] ) : 100,
			'paid_course_weight'     => isset( $in['paid_course_weight'] ) ? absint( $in['paid_course_weight'] ) : 25,
			'promotion_tiers'        => $this->sanitize_promotion_tiers( isset( $in['promotion_tiers'] ) ? $in['promotion_tiers'] : array() ),
			'enable_price_filter'    => isset( $in['enable_price_filter'] ) ? 1 : 0,
			'enable_level_filter'    => isset( $in['enable_level_filter'] ) ? 1 : 0,
			'enable_category_filter' => isset( $in['enable_category_filter'] ) ? 1 : 0,
			'enable_tag_filter'      => isset( $in['enable_tag_filter'] ) ? 1 : 0,
			'enable_rating_filter'   => isset( $in['enable_rating_filter'] ) ? 1 : 0,
			'enable_students_filter' => isset( $in['enable_students_filter'] ) ? 1 : 0,
			'default_sort'           => $default_sort,
			'replace_suggestions'    => isset( $in['replace_suggestions'] ) ? 1 : 0,
			'suggest_limit'          => isset( $in['suggest_limit'] ) ? max( 1, min( 20, absint( $in['suggest_limit'] ) ) ) : 8,
		);

		update_option( self::OPTION, $out );
		$this->cache = null;

		// Save each course's selected tier. An empty value removes sponsorship.
		$assignments = isset( $in['tcsp_course_tiers'] ) && is_array( $in['tcsp_course_tiers'] )
			? $in['tcsp_course_tiers']
			: array();
		$this->sync_promoted_courses( $assignments );

		add_settings_error( 'tcsp', 'saved', __( 'Settings saved.', 'tutor-course-search-pro' ), 'updated' );
	}

	/**
	 * Persist tier assignments to post meta so ranking and card badges can read
	 * them without rebuilding the admin configuration on every request.
	 *
	 * @param array $assignments Course ID => tier key.
	 */
	private function sync_promoted_courses( $assignments ) {
		$courses      = $this->get_courses();
		$tiers        = $this->promotion_tiers();
		foreach ( $courses as $course ) {
			$course_id = (int) $course->ID;
			$raw_tier  = isset( $assignments[ $course_id ] ) && ! is_array( $assignments[ $course_id ] ) ? $assignments[ $course_id ] : '';
			$tier      = sanitize_key( $raw_tier );
			if ( $tier && isset( $tiers[ $tier ] ) ) {
				update_post_meta( $course_id, TCSP_Plugin::PROMOTION_TIER_META, $tier );
				update_post_meta( $course->ID, TCSP_Plugin::PROMOTED_META, '1' );
			} else {
				delete_post_meta( $course_id, TCSP_Plugin::PROMOTION_TIER_META );
				delete_post_meta( $course_id, TCSP_Plugin::PROMOTED_META );
			}
		}
	}

	/**
	 * Tier definitions used by the ranking query and front-end labels.
	 *
	 * @return array
	 */
	public function promotion_tiers() {
		$saved    = $this->all();
		$defaults = $this->defaults()['promotion_tiers'];
		$tiers    = isset( $saved['promotion_tiers'] ) && is_array( $saved['promotion_tiers'] ) ? $saved['promotion_tiers'] : array();
		return $this->sanitize_promotion_tiers( wp_parse_args( $tiers, $defaults ) );
	}

	/**
	 * Keep tier settings predictable and safe to use in SQL/UI output.
	 */
	private function sanitize_promotion_tiers( $raw ) {
		$defaults = array(
			'platinum' => array( 'label' => 'Platinum', 'weight' => 300, 'color' => '#7c3aed' ),
			'gold'     => array( 'label' => 'Gold', 'weight' => 180, 'color' => '#b7791f' ),
			'featured' => array( 'label' => 'Featured', 'weight' => 80, 'color' => '#2563eb' ),
		);
		$out = array();
		foreach ( $defaults as $key => $default ) {
			$value = isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? $raw[ $key ] : array();
			$label_raw = isset( $value['label'] ) && ! is_array( $value['label'] ) ? $value['label'] : '';
			$color_raw = isset( $value['color'] ) && ! is_array( $value['color'] ) ? $value['color'] : '';
			$label     = '' !== $label_raw ? sanitize_text_field( $label_raw ) : $default['label'];
			$out[ $key ] = array(
				'label'  => '' !== $label ? $label : $default['label'],
				'weight' => isset( $value['weight'] ) && ! is_array( $value['weight'] ) ? min( 1000, absint( $value['weight'] ) ) : $default['weight'],
				'color'  => preg_match( '/^#[0-9a-fA-F]{6}$/', $color_raw ) ? $color_raw : $default['color'],
			);
		}
		return $out;
	}

	/**
	 * All published courses (with their author name for the picker).
	 *
	 * @return WP_Post[]
	 */
	public function get_courses() {
		return get_posts(
			array(
				'post_type'      => TCSP_Plugin::COURSE_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = $this->all();
		$courses = $this->get_courses();
		settings_errors( 'tcsp' );
		?>
		<style>
			.tcsp-settings .tcsp-tier-grid { display:flex; flex-wrap:wrap; gap:12px; max-width:900px; }
			.tcsp-settings .tcsp-tier-card { box-sizing:border-box; width:220px; padding:14px; border:1px solid #dcdcde; border-top:4px solid; border-radius:6px; background:#fff; }
			.tcsp-settings .tcsp-tier-card > strong { display:block; font-size:15px; margin-bottom:2px; }
			.tcsp-settings .tcsp-tier-card code { display:block; color:#646970; margin-bottom:10px; }
			.tcsp-settings .tcsp-tier-card label { display:block; margin-top:8px; font-size:12px; font-weight:600; }
			.tcsp-settings .tcsp-tier-card input { display:block; width:100%; margin-top:3px; }
			.tcsp-settings .tcsp-course-list { max-height:440px; overflow:auto; border:1px solid #ccd0d4; background:#fff; max-width:900px; }
			.tcsp-settings .tcsp-course-row { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:10px 12px; border-bottom:1px solid #f0f0f1; }
			.tcsp-settings .tcsp-course-row:last-child { border-bottom:0; }
			.tcsp-settings .tcsp-course-row > div { min-width:0; }
			.tcsp-settings .tcsp-course-row strong { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
			.tcsp-settings .tcsp-course-row .description { display:block; margin-top:2px; }
			.tcsp-settings .tcsp-course-row select { min-width:175px; }
			@media (max-width:782px) { .tcsp-settings .tcsp-course-row { align-items:flex-start; flex-direction:column; } .tcsp-settings .tcsp-course-row select { width:100%; } }
		</style>
		<div class="wrap tcsp-settings">
			<h1><?php esc_html_e( 'Tutor Course Search Pro', 'tutor-course-search-pro' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure marketplace-style ranking, archive filters, sorting, and search suggestions.', 'tutor-course-search-pro' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( self::NONCE, 'tcsp_nonce' ); ?>

				<h2 class="title"><?php esc_html_e( 'Sponsored Course Ranking', 'tutor-course-search-pro' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Boost sponsored courses', 'tutor-course-search-pro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="promote_courses" value="1" <?php checked( $s['promote_courses'], 1 ); ?>>
								<?php esc_html_e( 'Rank sponsored courses using their tier weight on relevance searches.', 'tutor-course-search-pro' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sponsorship tiers', 'tutor-course-search-pro' ); ?></th>
						<td>
							<div class="tcsp-tier-grid">
								<?php foreach ( $this->promotion_tiers() as $key => $tier ) : ?>
									<div class="tcsp-tier-card" style="border-top-color:<?php echo esc_attr( $tier['color'] ); ?>">
										<strong><?php echo esc_html( $tier['label'] ); ?></strong>
										<code><?php echo esc_html( $key ); ?></code>
										<label>
											<?php esc_html_e( 'Label', 'tutor-course-search-pro' ); ?>
											<input type="text" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][label]" value="<?php echo esc_attr( $tier['label'] ); ?>" class="regular-text">
										</label>
										<label>
											<?php esc_html_e( 'Weight', 'tutor-course-search-pro' ); ?>
											<input type="number" min="0" max="1000" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][weight]" value="<?php echo esc_attr( $tier['weight'] ); ?>" class="small-text">
										</label>
										<label>
											<?php esc_html_e( 'Badge color', 'tutor-course-search-pro' ); ?>
											<input type="text" name="promotion_tiers[<?php echo esc_attr( $key ); ?>][color]" value="<?php echo esc_attr( $tier['color'] ); ?>" class="tcsp-color-value" pattern="#[0-9a-fA-F]{6}">
										</label>
									</div>
								<?php endforeach; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Higher weights appear first. Assign a tier below to show a sponsored sticker on that course card.', 'tutor-course-search-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Boost paid courses', 'tutor-course-search-pro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="promote_paid_courses" value="1" <?php checked( $s['promote_paid_courses'], 1 ); ?>>
								<?php esc_html_e( 'Give paid courses a secondary boost over free ones.', 'tutor-course-search-pro' ); ?>
							</label>
							<p>
								<input type="number" min="0" max="1000" name="paid_course_weight" value="<?php echo esc_attr( $s['paid_course_weight'] ); ?>" class="small-text">
								<span class="description"><?php esc_html_e( 'Paid course weight', 'tutor-course-search-pro' ); ?></span>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Assign Sponsorship Tiers', 'tutor-course-search-pro' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Choose a tier for each course. Courses without a tier are not sponsored. This is course-level promotion; it does not promote every course from an instructor.', 'tutor-course-search-pro' ); ?></p>
				<p><input type="search" class="tcsp-course-search regular-text" placeholder="<?php esc_attr_e( 'Filter this list…', 'tutor-course-search-pro' ); ?>" style="max-width:640px;"></p>
				<div class="tcsp-course-list">
					<?php if ( empty( $courses ) ) : ?>
						<p><?php esc_html_e( 'No published courses found.', 'tutor-course-search-pro' ); ?></p>
					<?php else : ?>
						<?php foreach ( $courses as $course ) : ?>
							<?php $author = get_the_author_meta( 'display_name', $course->post_author ); ?>
							<?php $assigned_tier = TCSP_Plugin::get_promotion_tier( $course->ID ); ?>
							<div class="tcsp-course-row" data-title="<?php echo esc_attr( strtolower( $course->post_title . ' ' . $author ) ); ?>">
								<div>
									<strong><?php echo esc_html( $course->post_title ); ?></strong>
									<span class="description"><?php echo esc_html( $author ); ?></span>
								</div>
								<select name="tcsp_course_tiers[<?php echo esc_attr( $course->ID ); ?>]" aria-label="<?php echo esc_attr( sprintf( __( 'Sponsorship tier for %s', 'tutor-course-search-pro' ), $course->post_title ) ); ?>">
									<option value=""><?php esc_html_e( 'Not sponsored', 'tutor-course-search-pro' ); ?></option>
									<?php foreach ( $this->promotion_tiers() as $key => $tier ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $assigned_tier, $key ); ?>><?php echo esc_html( $tier['label'] ); ?> (<?php echo esc_html( $tier['weight'] ); ?>)</option>
									<?php endforeach; ?>
								</select>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<script>
					( function () {
						var box = document.querySelector( '.tcsp-course-search' );
						if ( ! box ) { return; }
						box.addEventListener( 'input', function () {
							var q = this.value.toLowerCase();
							document.querySelectorAll( '.tcsp-course-row' ).forEach( function ( row ) {
								row.style.display = row.getAttribute( 'data-title' ).indexOf( q ) !== -1 ? '' : 'none';
							} );
						} );
					}() );
				</script>

				<h2 class="title"><?php esc_html_e( 'Archive Filters & Sorting', 'tutor-course-search-pro' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enabled filters', 'tutor-course-search-pro' ); ?></th>
						<td>
							<label style="display:block;"><input type="checkbox" name="enable_price_filter" value="1" <?php checked( $s['enable_price_filter'], 1 ); ?>> <?php esc_html_e( 'Price (Free / Paid)', 'tutor-course-search-pro' ); ?></label>
							<label style="display:block;"><input type="checkbox" name="enable_level_filter" value="1" <?php checked( $s['enable_level_filter'], 1 ); ?>> <?php esc_html_e( 'Difficulty level', 'tutor-course-search-pro' ); ?></label>
							<label style="display:block;"><input type="checkbox" name="enable_category_filter" value="1" <?php checked( $s['enable_category_filter'], 1 ); ?>> <?php esc_html_e( 'Category (multi-select)', 'tutor-course-search-pro' ); ?></label>
							<label style="display:block;"><input type="checkbox" name="enable_tag_filter" value="1" <?php checked( $s['enable_tag_filter'], 1 ); ?>> <?php esc_html_e( 'Tag (multi-select)', 'tutor-course-search-pro' ); ?></label>
							<label style="display:block;"><input type="checkbox" name="enable_rating_filter" value="1" <?php checked( $s['enable_rating_filter'], 1 ); ?>> <?php esc_html_e( 'Minimum rating', 'tutor-course-search-pro' ); ?></label>
							<label style="display:block;"><input type="checkbox" name="enable_students_filter" value="1" <?php checked( $s['enable_students_filter'], 1 ); ?>> <?php esc_html_e( 'Minimum number of students', 'tutor-course-search-pro' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tcsp_default_sort"><?php esc_html_e( 'Default sort', 'tutor-course-search-pro' ); ?></label></th>
						<td>
							<select id="tcsp_default_sort" name="default_sort">
								<?php foreach ( $this->sort_options() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['default_sort'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Search Suggestions', 'tutor-course-search-pro' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Replace default suggestions', 'tutor-course-search-pro' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="replace_suggestions" value="1" <?php checked( $s['replace_suggestions'], 1 ); ?>>
								<?php esc_html_e( 'Use the enhanced autocomplete (matches course details, categories, tags, and tutor name; sponsored courses first; thumbnails and price) instead of the default.', 'tutor-course-search-pro' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tcsp_suggest_limit"><?php esc_html_e( 'Suggestions shown', 'tutor-course-search-pro' ); ?></label></th>
						<td>
							<input type="number" min="1" max="20" id="tcsp_suggest_limit" name="suggest_limit" value="<?php echo esc_attr( $s['suggest_limit'] ); ?>" class="small-text">
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Changes', 'tutor-course-search-pro' ), 'primary', 'tcsp_settings_submit' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sort options exposed in the UI. Keyed by query value.
	 */
	public function sort_options() {
		return array(
			'relevance'      => __( 'Relevance (recommended)', 'tutor-course-search-pro' ),
			'newest_first'   => __( 'Newest first', 'tutor-course-search-pro' ),
			'oldest_first'   => __( 'Oldest first', 'tutor-course-search-pro' ),
			'course_title_az' => __( 'Title A–Z', 'tutor-course-search-pro' ),
			'course_title_za' => __( 'Title Z–A', 'tutor-course-search-pro' ),
			'price_low_high' => __( 'Price: low to high', 'tutor-course-search-pro' ),
			'price_high_low' => __( 'Price: high to low', 'tutor-course-search-pro' ),
			'top_rated'      => __( 'Top rated', 'tutor-course-search-pro' ),
			'most_students'  => __( 'Most students', 'tutor-course-search-pro' ),
		);
	}
}
