<?php
/**
 * Plugin Name: Tutor Course Search Pro
 * Description: Adds full-text Tutor LMS course search, hierarchical taxonomy counts, advanced filters, sponsorship tiers, sponsored card stickers, and smarter autocomplete.
 * Version:     2.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      Ronin Mohammed
 * License:     GPL-2.0-or-later
 * Text Domain: tutor-course-search-pro
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TCSP_VERSION', '2.0.1' );
define( 'TCSP_FILE', __FILE__ );
define( 'TCSP_DIR', plugin_dir_path( __FILE__ ) );
define( 'TCSP_URL', plugin_dir_url( __FILE__ ) );

require_once TCSP_DIR . 'includes/class-tcsp-settings.php';
require_once TCSP_DIR . 'includes/class-tcsp-query.php';
require_once TCSP_DIR . 'includes/class-tcsp-filters-ui.php';
require_once TCSP_DIR . 'includes/class-tcsp-suggest.php';
require_once TCSP_DIR . 'includes/class-tcsp-plugin.php';

TCSP_Plugin::instance();
