<?php
/**
 * Base44 preview helpers.
 *
 * 1. Force HTTPS — the preview proxy terminates TLS, so WordPress
 *    always receives plain HTTP but must generate https:// URLs.
 * 2. Redirect the site root to the Tutor LMS course archive so the
 *    preview lands on the page where the filter bar is visible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$_SERVER['HTTPS'] = 'on';

add_action(
	'template_redirect',
	function () {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( is_front_page() ) {
			$url = get_post_type_archive_link( 'courses' );
			if ( $url ) {
				wp_safe_redirect( $url, 302 );
				exit;
			}
		}
	}
);
