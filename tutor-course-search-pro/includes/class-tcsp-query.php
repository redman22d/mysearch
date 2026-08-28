<?php
/**
 * Query engine: applies archive filters, sorting, and marketplace-style
 * ranking (promoted courses first, then paid courses) to Tutor course queries.
 *
 * @package Tutor_Course_Search_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCSP_Query {

	/** @var TCSP_Settings */
	private $settings;

	/** @var bool Marks the query we are shaping so posts_clauses only touches it. */
	private $active = false;

	/** @var int Minimum average rating requested (0 = off) for the active query. */
	private $min_rating = 0;

	/** @var int Minimum enrolled-student count requested (0 = off) for the active query. */
	private $min_students = 0;

	/** @var string The tcsp_sort value driving the active query. */
	private $current_sort_value = 'relevance';

	public function __construct( TCSP_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register() {
		add_action( 'pre_get_posts', array( $this, 'apply_query' ), 100 );
		add_filter( 'posts_clauses', array( $this, 'apply_clauses' ), 20, 2 );
	}

	/**
	 * Whether this query targets the Tutor course archive / search.
	 */
	private function is_target_query( $query ) {
		if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return false;
		}
		$post_type = $query->get( 'post_type' );
		$is_course = ( TCSP_Plugin::COURSE_CPT === $post_type )
			|| ( is_array( $post_type ) && in_array( TCSP_Plugin::COURSE_CPT, $post_type, true ) );

		$is_course_archive = $query->is_post_type_archive( TCSP_Plugin::COURSE_CPT )
			|| $query->is_tax( 'course-category' )
			|| $query->is_tax( 'course-tag' );

		$is_course_search = $query->is_search() && $is_course;

		return $is_course || $is_course_archive || $is_course_search;
	}

	/**
	 * Read the current sort value from the request (falls back to default).
	 */
	public function current_sort() {
		if ( isset( $_GET['tcsp_sort'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( wp_unslash( $_GET['tcsp_sort'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $_GET['tutor_course_filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( wp_unslash( $_GET['tutor_course_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return (string) $this->settings->get( 'default_sort' );
	}

	/**
	 * Apply filters + base sorting to the main query.
	 */
	public function apply_query( $query ) {
		if ( ! $this->is_target_query( $query ) ) {
			return;
		}

		$this->active       = true;
		$this->min_rating   = 0;
		$this->min_students = 0;
		$this->current_sort_value = $this->current_sort();

		// NOTE: $query->get() returns '' (a scalar) for unset query vars, and
		// (array) '' produces array( 0 => '' ) rather than an empty array —
		// that stray scalar entry is what was corrupting tax_query/meta_query.
		// Always normalize through is_array() instead of blindly (array)-casting.
		$meta_query = $query->get( 'meta_query' );
		$meta_query = is_array( $meta_query ) ? $meta_query : array();
		$tax_query  = $query->get( 'tax_query' );
		$tax_query  = is_array( $tax_query ) ? $tax_query : array();

		// --- Price filter (free / paid) ---
		if ( $this->settings->get( 'enable_price_filter' ) && ! empty( $_GET['tcsp_price'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$price = sanitize_key( wp_unslash( $_GET['tcsp_price'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $price, array( TCSP_Plugin::PRICE_TYPE_FREE, TCSP_Plugin::PRICE_TYPE_PAID ), true ) ) {
				$meta_query[] = array(
					'key'   => TCSP_Plugin::PRICE_TYPE_META,
					'value' => $price,
				);
			}
		}

		// --- Difficulty level filter ---
		if ( $this->settings->get( 'enable_level_filter' ) && ! empty( $_GET['tcsp_level'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$level        = sanitize_key( wp_unslash( $_GET['tcsp_level'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$meta_query[] = array(
				'key'   => '_tutor_course_level',
				'value' => $level,
			);
		}

		// --- Minimum rating filter (handled in SQL via avg-rating subquery) ---
		if ( $this->settings->get( 'enable_rating_filter' ) && ! empty( $_GET['tcsp_min_rating'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->min_rating = min( 5, max( 1, (int) $_GET['tcsp_min_rating'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// --- Category filter (multi-select: course must have ALL selected categories) ---
		if ( $this->settings->get( 'enable_category_filter' ) && ! empty( $_GET['tcsp_category'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$cats = self::sanitize_slug_list( wp_unslash( $_GET['tcsp_category'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $cats ) ) {
				$tax_query[] = array(
					'taxonomy' => 'course-category',
					'field'    => 'slug',
					'terms'    => $cats,
					'operator' => 'AND',
				);
			}
		}

		// --- Tag filter (multi-select: course must have ALL selected tags) ---
		if ( $this->settings->get( 'enable_tag_filter' ) && ! empty( $_GET['tcsp_tag'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tags = self::sanitize_slug_list( wp_unslash( $_GET['tcsp_tag'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $tags ) ) {
				$tax_query[] = array(
					'taxonomy' => 'course-tag',
					'field'    => 'slug',
					'terms'    => $tags,
					'operator' => 'AND',
				);
			}
		}

		// --- Minimum student (enrollment) count filter ---
		if ( $this->settings->get( 'enable_students_filter' ) && ! empty( $_GET['tcsp_min_students'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->min_students = max( 0, (int) $_GET['tcsp_min_students'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// --- Free-text search: title, description, category, tag, tutor ---
		// IMPORTANT: this branch intentionally does NOT touch tax_query. Adding
		// a tax_query clause here means core's WP_Tax_Query decides its own JOIN
		// aliases (which shift depending on how many other tax_query clauses —
		// e.g. the category/tag filters above — are already present), and any
		// hand-written SQL in apply_clauses() trying to strip/rewrite that JOIN
		// by regex breaks the moment the alias isn't what it expects (this is
		// what caused "search bar cat/tag returns nothing"). Instead we handle
		// the whole "text OR matching term" match ourselves in apply_clauses()
		// with our own uniquely-aliased JOINs, fully independent of tax_query.
		$raw_search = $query->get( 's' );
		$search     = is_array( $raw_search ) ? '' : trim( wp_strip_all_tags( (string) $raw_search ) );
		if ( '' !== $search ) {
			$term_ids = self::term_ids_matching( $search );
			$query->set( 'tcsp_search_term', $search );
			$query->set( 'tcsp_term_ids', $term_ids );
			// Core's search WHERE only covers post fields. Clear the SQL
			// search var and build one complete OR index in apply_clauses().
			$query->set( 's', '' );
		}

		if ( count( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}
		if ( count( $tax_query ) ) {
			$query->set( 'tax_query', $tax_query );
		}

		$this->apply_sort( $query, $this->current_sort() );
	}

	/**
	 * Normalize a $_GET value that may be a single slug or an array of slugs
	 * (multi-select checkboxes use tcsp_category[]/tcsp_tag[]) into a clean
	 * array of sanitized, unique slugs.
	 *
	 * @param mixed $raw Raw (already wp_unslash()'d) request value.
	 * @return string[]
	 */
	public static function sanitize_slug_list( $raw ) {
		$values = is_array( $raw ) ? $raw : array( $raw );
		$clean  = array();
		foreach ( $values as $value ) {
			if ( is_array( $value ) ) {
				continue; // Ignore unexpected nested arrays.
			}
			$slug = sanitize_title( (string) $value );
			if ( '' !== $slug ) {
				$clean[] = $slug;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Translate a sort key into WP_Query orderby args.
	 */
	private function apply_sort( $query, $sort ) {
		switch ( $sort ) {
			case 'newest_first':
				$query->set( 'orderby', 'date' );
				$query->set( 'order', 'DESC' );
				break;
			case 'oldest_first':
				$query->set( 'orderby', 'date' );
				$query->set( 'order', 'ASC' );
				break;
			case 'course_title_az':
				$query->set( 'orderby', 'title' );
				$query->set( 'order', 'ASC' );
				break;
			case 'course_title_za':
				$query->set( 'orderby', 'title' );
				$query->set( 'order', 'DESC' );
				break;
			case 'price_low_high':
				// Deliberately NOT using orderby=meta_value_num + meta_key here:
				// WP builds that as an INNER JOIN on wp_postmeta for that key, so
				// any course with no price row at all (typically free courses)
				// gets silently dropped from the results. We sort via a LEFT JOIN
				// + COALESCE in apply_clauses() instead, so missing rows count as 0.
				$query->set( 'tcsp_order_by_price', 'ASC' );
				break;
			case 'price_high_low':
				$query->set( 'tcsp_order_by_price', 'DESC' );
				break;
			case 'top_rated':
				// Ordering by real average rating happens in the clauses filter.
				$query->set( 'tcsp_order_by_rating', 1 );
				break;
			case 'most_students':
				// Ordering by real enrollment count happens in the clauses filter.
				$query->set( 'tcsp_order_by_students', 1 );
				break;
			case 'relevance':
			default:
				break;
		}
	}

	/**
	 * Category/tag term IDs whose name or slug matches the search string.
	 *
	 * @param string $search Raw search string.
	 * @return int[]
	 */
	public static function term_ids_matching( $search ) {
		$search = trim( wp_strip_all_tags( (string) $search ) );
		if ( strlen( $search ) < 2 ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => array( 'course-category', 'course-tag' ),
				'hide_empty' => false,
				'search'     => $search, // matches name + slug, substring.
				'number'     => 200,
				'fields'     => 'ids',
			)
		);
		return is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
	}

	/**
	 * Single ORDER BY / WHERE mutator: OR-search across text + taxonomy,
	 * minimum-rating filter, top-rated sort, and promoted/paid ranking.
	 */
	public function apply_clauses( $clauses, $query ) {
		global $wpdb;

		if ( ! $this->active || ! $this->is_target_query( $query ) ) {
			return $clauses;
		}
		$this->active = false;

		$need_group = false;

		// ------------------------------------------------------------------
		// (A) Search across course name, description, categories, tags, and
		// tutor display name. Fully self-contained so it composes with any
		// category/tag filters already present in the query.
		// ------------------------------------------------------------------
		$term_ids = $query->get( 'tcsp_term_ids' );
		$search   = trim( wp_strip_all_tags( (string) $query->get( 'tcsp_search_term' ) ) );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$text = $wpdb->prepare(
				"( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR tcsp_author.display_name LIKE %s )",
				$like,
				$like,
				$like,
				$like
			);
			$clauses['join'] .= " LEFT JOIN {$wpdb->users} tcsp_author ON tcsp_author.ID = {$wpdb->posts}.post_author ";
			$term_match = '0=1';
			if ( ! empty( $term_ids ) ) {
				$ids_csv = implode( ',', array_map( 'absint', (array) $term_ids ) );
				// Own uniquely-aliased JOINs keep this independent of WP's
				// aliases for explicit category/tag filters.
				$clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} tr_tcsp_search ON tr_tcsp_search.object_id = {$wpdb->posts}.ID ";
				$clauses['join'] .= " LEFT JOIN {$wpdb->term_taxonomy} tt_tcsp_search ON tt_tcsp_search.term_taxonomy_id = tr_tcsp_search.term_taxonomy_id AND tt_tcsp_search.term_id IN ({$ids_csv}) AND tt_tcsp_search.taxonomy IN ('course-category','course-tag') ";
				$term_match = 'tt_tcsp_search.term_id IS NOT NULL';
			}
			$clauses['where'] .= " AND ( {$text} OR {$term_match} ) ";
			$need_group = true;
		}

		// ------------------------------------------------------------------
		// (B) Average-rating subquery, reused by the rating filter and sort.
		// ------------------------------------------------------------------
		$needs_rating = $this->min_rating > 0 || $query->get( 'tcsp_order_by_rating' );
		$avg_sql      = '';
		if ( $needs_rating ) {
			$avg_sql = $wpdb->prepare(
				"( SELECT AVG( CAST( cm.meta_value AS DECIMAL(10,2) ) )
				   FROM {$wpdb->comments} c
				   INNER JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID
				   WHERE c.comment_post_ID = {$wpdb->posts}.ID
				     AND c.comment_type = %s
				     AND cm.meta_key = %s )",
				TCSP_Plugin::RATING_COMMENT_TYPE,
				TCSP_Plugin::RATING_META_KEY
			);

			if ( $this->min_rating > 0 ) {
				$clauses['where'] .= $wpdb->prepare( " AND COALESCE( {$avg_sql}, 0 ) >= %f ", (float) $this->min_rating );
			}
		}

		// ------------------------------------------------------------------
		// (B2) Price sort — LEFT JOIN so free courses (no price meta row) are
		// treated as price 0 instead of being excluded from the result set.
		// Ranks by the course's *effective* (currently-charged) price: the
		// sale price when one is set, falling back to the regular price
		// otherwise — so "Price: low to high/high to low" reflects what a
		// student would actually pay, not the pre-discount list price.
		// ------------------------------------------------------------------
		$price_dir = $query->get( 'tcsp_order_by_price' );
		$price_sql = '';
		if ( 'ASC' === $price_dir || 'DESC' === $price_dir ) {
			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN {$wpdb->postmeta} tcsp_price_sort ON tcsp_price_sort.post_id = {$wpdb->posts}.ID AND tcsp_price_sort.meta_key = %s ",
				TCSP_Plugin::PRICE_META
			);
			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN {$wpdb->postmeta} tcsp_sale_price_sort ON tcsp_sale_price_sort.post_id = {$wpdb->posts}.ID AND tcsp_sale_price_sort.meta_key = %s ",
				TCSP_Plugin::SALE_PRICE_META
			);
			$price_sql  = "COALESCE(
				NULLIF( CAST( tcsp_sale_price_sort.meta_value AS DECIMAL(10,2) ), 0 ),
				CAST( tcsp_price_sort.meta_value AS DECIMAL(10,2) ),
				0
			)";
			$need_group = true;
		}

		// ------------------------------------------------------------------
		// (B3) Enrolled-student count — minimum filter and/or "Most students"
		// sort, both driven by a correlated subquery counting Tutor's
		// enrollment records for the course.
		// ------------------------------------------------------------------
		$needs_students = $this->min_students > 0 || $query->get( 'tcsp_order_by_students' );
		$students_sql   = '';
		if ( $needs_students ) {
			$students_sql = $wpdb->prepare(
				"( SELECT COUNT(*) FROM {$wpdb->posts} tcsp_e
				   WHERE tcsp_e.post_type = %s
				     AND tcsp_e.post_parent = {$wpdb->posts}.ID
				     AND tcsp_e.post_status = %s )",
				TCSP_Plugin::ENROLLMENT_CPT,
				TCSP_Plugin::ENROLLMENT_STATUS
			);

			if ( $this->min_students > 0 ) {
				$clauses['where'] .= $wpdb->prepare( " AND COALESCE( {$students_sql}, 0 ) >= %d ", $this->min_students );
			}
		}

		// ------------------------------------------------------------------
		// (C) Sponsored-tier + paid-course ranking (per course, not per tutor).
		//
		// This boost is only applied for the default "Relevance" sort. If a
		// visitor explicitly picks Top rated / Price / Newest / etc., that
		// choice used to always be overridden — the promotion score was
		// prepended to every ORDER BY unconditionally, so e.g. "Price: low
		// to high" only ever sorted *within* the promoted/paid tier, which is
		// why free courses (no paid-boost) always landed at the very end
		// regardless of price, and "Top rated" barely seemed to do anything.
		// ------------------------------------------------------------------
		$is_relevance_sort = ( 'relevance' === $this->current_sort_value );
		$score_parts        = array();
		$promote_courses    = (int) $this->settings->get( 'promote_courses' );
		$promote_paid       = (int) $this->settings->get( 'promote_paid_courses' );

		if ( $is_relevance_sort && $promote_courses ) {
			$tiers = $this->settings->promotion_tiers();
			if ( ! empty( $tiers ) ) {
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->postmeta} tcsp_tier ON tcsp_tier.post_id = {$wpdb->posts}.ID AND tcsp_tier.meta_key = %s ",
					TCSP_Plugin::PROMOTION_TIER_META
				);
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->postmeta} tcsp_legacy_pm ON tcsp_legacy_pm.post_id = {$wpdb->posts}.ID AND tcsp_legacy_pm.meta_key = %s ",
					TCSP_Plugin::PROMOTED_META
				);
				foreach ( $tiers as $key => $tier ) {
					$weight = (int) $tier['weight'];
					if ( $weight < 1 ) {
						continue;
					}
					$legacy = 'featured' === $key
						? " OR ( tcsp_tier.meta_value IS NULL AND tcsp_legacy_pm.meta_value = '1' )"
						: '';
					$score_parts[] = $wpdb->prepare(
						"( CASE WHEN tcsp_tier.meta_value = %s{$legacy} THEN %d ELSE 0 END )",
						$key,
						$weight
					);
				}
				$need_group = true;
			}
		}

		if ( $is_relevance_sort && $promote_paid ) {
			$weight = (int) $this->settings->get( 'paid_course_weight' );
			if ( $weight > 0 ) {
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->postmeta} tcsp_price ON tcsp_price.post_id = {$wpdb->posts}.ID AND tcsp_price.meta_key = %s ",
					TCSP_Plugin::PRICE_TYPE_META
				);
				$score_parts[] = $wpdb->prepare( '( CASE WHEN tcsp_price.meta_value = %s THEN %d ELSE 0 END )', TCSP_Plugin::PRICE_TYPE_PAID, $weight );
				$need_group    = true;
			}
		}

		// ------------------------------------------------------------------
		// (D) Compose ORDER BY: promotion score first (relevance sort only),
		// then whichever explicit metric the visitor picked, then whatever
		// WP already built as the tiebreaker.
		// ------------------------------------------------------------------
		$order_prefix = array();
		if ( ! empty( $score_parts ) ) {
			$order_prefix[] = '( ' . implode( ' + ', $score_parts ) . ' ) DESC';
		}
		if ( $query->get( 'tcsp_order_by_rating' ) && $avg_sql ) {
			$order_prefix[] = 'COALESCE( ' . $avg_sql . ', 0 ) DESC';
		}
		if ( $price_sql ) {
			$order_prefix[] = $price_sql . ' ' . $price_dir;
		}
		if ( $query->get( 'tcsp_order_by_students' ) && $students_sql ) {
			$order_prefix[] = 'COALESCE( ' . $students_sql . ', 0 ) DESC';
		}

		if ( ! empty( $order_prefix ) ) {
			$existing           = trim( (string) $clauses['orderby'] );
			$clauses['orderby'] = implode( ', ', $order_prefix ) . ( '' !== $existing ? ', ' . $existing : '' );
		}

		if ( $need_group && empty( $clauses['groupby'] ) ) {
			$clauses['groupby'] = "{$wpdb->posts}.ID";
		}

		return $clauses;
	}
}
