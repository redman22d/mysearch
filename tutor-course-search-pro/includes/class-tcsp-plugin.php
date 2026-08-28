<?php
/**
 * Main bootstrap: wires up all modules and shared constants.
 *
 * @package Tutor_Course_Search_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TCSP_Plugin {

	/**
	 * Tutor LMS course custom post type.
	 */
	const COURSE_CPT = 'courses';

	/**
	 * Tutor LMS price-type meta + values (from Tutor\Models\CourseModel).
	 */
	const PRICE_TYPE_META = '_tutor_course_price_type';
	const PRICE_META      = 'tutor_course_price';
	const SALE_PRICE_META = 'tutor_course_sale_price';
	const PRICE_TYPE_PAID = 'paid';
	const PRICE_TYPE_FREE = 'free';

	/**
	 * Post meta values used for sponsored course assignments.
	 */
	const PROMOTED_META = '_tcsp_promoted_course';
	const PROMOTION_TIER_META = '_tcsp_promotion_tier';

	/**
	 * Comment type + rating meta keys used by Tutor LMS course reviews.
	 */
	const RATING_COMMENT_TYPE = 'tutor_course_rating';
	const RATING_META_KEY     = 'tutor_rating';

	/**
	 * Tutor LMS stores each enrollment as its own post (post_type =
	 * tutor_enrolled, post_parent = the course ID, post_author = the
	 * student). A confirmed/active enrollment has post_status 'completed'.
	 * If your Tutor LMS setup uses different enrollment statuses (e.g. you
	 * also want to count 'pending' or a custom LMS enrollment flow), adjust
	 * ENROLLMENT_STATUS accordingly.
	 */
	const ENROLLMENT_CPT    = 'tutor_enrolled';
	const ENROLLMENT_STATUS = 'completed';

	private static $instance = null;

	/** @var TCSP_Settings */
	public $settings;

	/** @var TCSP_Query */
	public $query;

	/** @var TCSP_Filters_UI */
	public $filters_ui;

	/** @var TCSP_Suggest */
	public $suggest;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_notices', array( $this, 'missing_tutor_notice' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
	}

	public function init() {
		$this->settings   = new TCSP_Settings();
		$this->query      = new TCSP_Query( $this->settings );
		$this->filters_ui = new TCSP_Filters_UI( $this->settings );
		$this->suggest    = new TCSP_Suggest( $this->settings );

		$this->settings->register();

		if ( ! self::tutor_active() ) {
			return;
		}

		$this->query->register();
		$this->filters_ui->register();
		$this->suggest->register();
		$this->register_sponsored_card_hooks();
	}

	/**
	 * Tutor exposes slightly different card hooks across its themes/templates.
	 * Register on the thumbnail and title boundaries so the sticker remains
	 * visible without requiring a theme override.
	 */
	private function register_sponsored_card_hooks() {
		$hooks = array(
			'tutor_course/loop/before_thumbnail',
			'tutor_course/loop/after_thumbnail',
			'tutor_course/loop/before_title',
			'tutor_course/loop/after_title',
		);
		foreach ( $hooks as $hook ) {
			add_action( $hook, array( $this, 'render_sponsored_sticker' ), 10, 1 );
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'tutor-course-search-pro', false, dirname( plugin_basename( TCSP_FILE ) ) . '/languages' );
	}

	public static function tutor_active() {
		return function_exists( 'tutor' ) || function_exists( 'tutor_utils' ) || defined( 'TUTOR_VERSION' );
	}

	public function missing_tutor_notice() {
		if ( self::tutor_active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning is-dismissible"><p>'
			. esc_html__( 'Tutor Course Search Pro requires Tutor LMS to be active.', 'tutor-course-search-pro' )
			. '</p></div>';
	}

	/**
	 * Whether a course is paid.
	 *
	 * @param int $course_id Course post ID.
	 * @return bool
	 */
	public static function is_paid_course( $course_id ) {
		$type = get_post_meta( absint( $course_id ), self::PRICE_TYPE_META, true );
		return self::PRICE_TYPE_PAID === $type;
	}

	/**
	 * Whether an individual course is promoted (boosted on its own).
	 *
	 * @param int $course_id Course post ID.
	 * @return bool
	 */
	public static function is_promoted_course( $course_id ) {
		return '' !== self::get_promotion_tier( $course_id );
	}

	/**
	 * Return the assigned promotion tier key, if any.
	 */
	public static function get_promotion_tier( $course_id ) {
		$course_id = absint( $course_id );
		$tier      = sanitize_key( (string) get_post_meta( $course_id, self::PROMOTION_TIER_META, true ) );

		// Courses promoted by versions before the tier system remain sponsored
		// until an administrator re-saves the assignments.
		if ( '' === $tier && '1' === (string) get_post_meta( $course_id, self::PROMOTED_META, true ) ) {
			$tier = 'featured';
		}
		return $tier;
	}

	/**
	 * Render a sponsored badge inside Tutor course cards.
	 *
	 * @param int $course_id Optional course ID supplied by some Tutor hooks.
	 */
	public function render_sponsored_sticker( $course_id = 0 ) {
		if ( is_object( $course_id ) && isset( $course_id->ID ) ) {
			$course_id = $course_id->ID;
		}
		if ( is_array( $course_id ) ) {
			$course_id = 0;
		}
		$course_id = absint( $course_id );
		if ( ! $course_id || 'courses' !== get_post_type( $course_id ) ) {
			$course_id = get_the_ID();
		}
		$tier = self::get_promotion_tier( $course_id );
		if ( ! $tier || 'courses' !== get_post_type( $course_id ) ) {
			return;
		}

		static $rendered = array();
		if ( isset( $rendered[ $course_id ] ) ) {
			return;
		}
		$rendered[ $course_id ] = true;

		$label = $tier;
		if ( isset( self::$instance->settings ) && self::$instance->settings instanceof TCSP_Settings ) {
			$tiers = self::$instance->settings->promotion_tiers();
			if ( isset( $tiers[ $tier ]['label'] ) ) {
				$label = $tiers[ $tier ]['label'];
			}
		}
		?>
		<span class="tcsp-sponsored-sticker tcsp-sponsored-sticker--<?php echo esc_attr( $tier ); ?>" aria-label="<?php esc_attr_e( 'Sponsored course', 'tutor-course-search-pro' ); ?>">
			<span class="tcsp-sponsored-icon" aria-hidden="true">★</span>
			<?php esc_html_e( 'Sponsored', 'tutor-course-search-pro' ); ?> · <?php echo esc_html( $label ); ?>
		</span>
		<?php
	}
}
