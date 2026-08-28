<?php
/**
 * Search suggestions: a custom AJAX autocomplete that matches courses by
 * title, description, category name and tag name, ranked with promoted
 * courses first, and disables Tutor's default suggestions.
 *
 * @package Tutor_Course_Search_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TCSP_Suggest {

	const ACTION = 'tcsp_suggest';

	/** @var TCSP_Settings */
	private $settings;

	public function __construct( TCSP_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register() {
		if ( ! $this->settings->get( 'replace_suggestions' ) ) {
			return;
		}
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * AJAX handler. Returns JSON list of suggestions.
	 */
	public function handle() {
		check_ajax_referer( self::ACTION, 'nonce' );

		$raw_term = isset( $_GET['term'] ) && ! is_array( $_GET['term'] ) ? $_GET['term'] : '';
		$term     = sanitize_text_field( wp_unslash( $raw_term ) );
		$term = trim( $term );
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$limit    = (int) $this->settings->get( 'suggest_limit' );
		$term_ids = TCSP_Query::term_ids_matching( $term );

		$args = array(
			'post_type'           => TCSP_Plugin::COURSE_CPT,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tcsp_is_suggest'     => true,
			'tcsp_suggest_term'   => $term,
		);

		if ( ! empty( $term_ids ) ) {
			$args['tcsp_suggest_terms'] = $term_ids;
		}

		add_filter( 'posts_clauses', array( $this, 'suggest_clauses' ), 20, 2 );
		$q = new WP_Query( $args );
		remove_filter( 'posts_clauses', array( $this, 'suggest_clauses' ), 20 );

		$items = array();
		$tiers = $this->settings->promotion_tiers();
		foreach ( $q->posts as $post ) {
			$author_id = (int) $post->post_author;
			$tier      = TCSP_Plugin::get_promotion_tier( $post->ID );
			$items[]   = array(
				'id'       => $post->ID,
				'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'url'      => get_permalink( $post ),
				'thumb'    => get_the_post_thumbnail_url( $post, 'thumbnail' ),
				'author'   => get_the_author_meta( 'display_name', $author_id ),
				'promoted' => TCSP_Plugin::is_promoted_course( $post->ID ),
				'tier'     => $tier,
				'tierLabel' => isset( $tiers[ $tier ]['label'] ) ? $tiers[ $tier ]['label'] : __( 'Sponsored', 'tutor-course-search-pro' ),
				'paid'     => TCSP_Plugin::is_paid_course( $post->ID ),
				'price'    => $this->format_price( $post->ID ),
			);
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * Widen the suggest search to term names and rank promoted courses first.
	 */
	public function suggest_clauses( $clauses, $query ) {
		global $wpdb;
		if ( ! $query->get( 'tcsp_is_suggest' ) ) {
			return $clauses;
		}

		$term_ids = $query->get( 'tcsp_suggest_terms' );

		// Search course name, description, category, tag, and tutor name.
		$search = trim( wp_strip_all_tags( (string) $query->get( 'tcsp_suggest_term' ) ) );
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
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
				$clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} tr_tcsp_search ON tr_tcsp_search.object_id = {$wpdb->posts}.ID ";
				$clauses['join'] .= " LEFT JOIN {$wpdb->term_taxonomy} tt_tcsp_search ON tt_tcsp_search.term_taxonomy_id = tr_tcsp_search.term_taxonomy_id AND tt_tcsp_search.term_id IN ({$ids_csv}) AND tt_tcsp_search.taxonomy IN ('course-category','course-tag') ";
				$term_match = 'tt_tcsp_search.term_id IS NOT NULL';
			}
			$clauses['where']  .= " AND ( {$text} OR {$term_match} ) ";
			$clauses['groupby'] = empty( $clauses['groupby'] ) ? "{$wpdb->posts}.ID" : $clauses['groupby'];
		}

		// Sponsored tier (per course) + paid ranking.
		$tiers      = $this->settings->promotion_tiers();
		$paid_weight = (int) $this->settings->get( 'paid_course_weight' );

		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} tcsp_tier ON tcsp_tier.post_id = {$wpdb->posts}.ID AND tcsp_tier.meta_key = %s ",
			TCSP_Plugin::PROMOTION_TIER_META
		);
		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} tcsp_legacy_pm ON tcsp_legacy_pm.post_id = {$wpdb->posts}.ID AND tcsp_legacy_pm.meta_key = %s ",
			TCSP_Plugin::PROMOTED_META
		);
		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} tcsp_price ON tcsp_price.post_id = {$wpdb->posts}.ID AND tcsp_price.meta_key = %s ",
			TCSP_Plugin::PRICE_TYPE_META
		);

		$score_parts = array();
		if ( $this->settings->get( 'promote_courses' ) ) {
			foreach ( $tiers as $key => $tier ) {
				if ( (int) $tier['weight'] > 0 ) {
					$legacy = 'featured' === $key
						? " OR ( tcsp_tier.meta_value IS NULL AND tcsp_legacy_pm.meta_value = '1' )"
						: '';
					$score_parts[] = $wpdb->prepare( "( CASE WHEN tcsp_tier.meta_value = %s{$legacy} THEN %d ELSE 0 END )", $key, (int) $tier['weight'] );
				}
			}
		}
		if ( $this->settings->get( 'promote_paid_courses' ) && $paid_weight > 0 ) {
			$score_parts[] = $wpdb->prepare(
				'( CASE WHEN tcsp_price.meta_value = %s THEN %d ELSE 0 END )',
				TCSP_Plugin::PRICE_TYPE_PAID,
				$paid_weight
			);
		}
		$score = ! empty( $score_parts ) ? '( ' . implode( ' + ', $score_parts ) . ' )' : '0';

		$existing           = trim( (string) $clauses['orderby'] );
		$clauses['orderby'] = $score . ' DESC' . ( '' !== $existing ? ', ' . $existing : '' );
		if ( empty( $clauses['groupby'] ) ) {
			$clauses['groupby'] = "{$wpdb->posts}.ID";
		}
		return $clauses;
	}

	/**
	 * Human-readable price string.
	 */
	private function format_price( $course_id ) {
		if ( ! TCSP_Plugin::is_paid_course( $course_id ) ) {
			return __( 'Free', 'tutor-course-search-pro' );
		}
		$price = get_post_meta( $course_id, TCSP_Plugin::PRICE_META, true );
		if ( '' === $price ) {
			return '';
		}
		if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'tutor_price' ) ) {
			return wp_strip_all_tags( tutor_utils()->tutor_price( $price ) );
		}
		return $price;
	}
}
