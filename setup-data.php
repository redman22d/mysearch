<?php
/**
 * Sample data for Tutor Course Search Pro demo.
 * Creates instructors, students, hierarchical categories, tags,
 * 12 courses with varied prices/levels/ratings/enrollments/sponsorship,
 * and the mu-plugin redirect target (course archive).
 *
 * Idempotent: skips creation if courses already exist.
 */

// ── Idempotency ────────────────────────────────────────────────────
$existing = get_posts( array( 'post_type' => 'courses', 'numberposts' => 1, 'post_status' => 'any' ) );
if ( ! empty( $existing ) ) {
	echo "Sample data already exists — skipping.\n";
	return;
}

// ── Helpers ────────────────────────────────────────────────────────
function tcsp_get_or_create_user( $login, $email, $display_name, $role = 'subscriber' ) {
	$user = get_user_by( 'login', $login );
	if ( $user ) {
		return $user->ID;
	}
	$user_id = wp_create_user( $login, 'password123', $email );
	if ( is_wp_error( $user_id ) ) {
		echo "Error creating user $login: " . $user_id->get_error_message() . "\n";
		return 0;
	}
	$update = array( 'ID' => $user_id, 'display_name' => $display_name );
	if ( wp_roles()->is_role( $role ) ) {
		$update['role'] = $role;
	}
	wp_update_user( $update );
	return $user_id;
}

function tcsp_get_or_create_term( $name, $taxonomy, $args = array() ) {
	$term = get_term_by( 'name', $name, $taxonomy );
	if ( $term ) {
		return $term->term_id;
	}
	$result = wp_insert_term( $name, $taxonomy, $args );
	if ( is_wp_error( $result ) ) {
		$term = get_term_by( 'name', $name, $taxonomy );
		return $term ? $term->term_id : 0;
	}
	return $result['term_id'];
}

function tcsp_create_ratings( $course_id, $target_avg, $count = 10 ) {
	$floor     = (int) floor( $target_avg );
	$ceil      = (int) ceil( $target_avg );
	$num_ceil  = (int) round( ( $target_avg - $floor ) * $count );
	for ( $i = 0; $i < $count; $i++ ) {
		$rating     = $i < $num_ceil ? $ceil : $floor;
		$comment_id = wp_insert_comment( array(
			'comment_post_ID'  => $course_id,
			'comment_type'      => 'tutor_course_rating',
			'comment_approved'   => 1,
			'comment_author'    => 'Student ' . ( $i + 1 ),
			'comment_content'   => 'Great course!',
		) );
		if ( $comment_id ) {
			update_comment_meta( $comment_id, 'tutor_rating', $rating );
		}
	}
}

function tcsp_create_enrollments( $course_id, $count, $students ) {
	for ( $i = 0; $i < $count; $i++ ) {
		$student_id = $students[ $i % count( $students ) ];
		wp_insert_post( array(
			'post_type'    => 'tutor_enrolled',
			'post_parent'  => $course_id,
			'post_status'  => 'completed',
			'post_author'  => $student_id,
			'post_title'   => '',
		) );
	}
}

// ── Users ──────────────────────────────────────────────────────────
$instructors = array(
	'sarah_chen'      => tcsp_get_or_create_user( 'sarah_chen', 'sarah@example.com', 'Sarah Chen', 'tutor_instructor' ),
	'marcus_johnson'  => tcsp_get_or_create_user( 'marcus_johnson', 'marcus@example.com', 'Marcus Johnson', 'tutor_instructor' ),
	'elena_rodriguez' => tcsp_get_or_create_user( 'elena_rodriguez', 'elena@example.com', 'Elena Rodriguez', 'tutor_instructor' ),
);

$students = array();
for ( $i = 1; $i <= 25; $i++ ) {
	$students[] = tcsp_get_or_create_user( "student_{$i}", "student_{$i}@example.com", "Student {$i}", 'subscriber' );
}

// ── Categories (hierarchical) ─────────────────────────────────────
$web_dev_id      = tcsp_get_or_create_term( 'Web Development', 'course-category', array( 'slug' => 'web-development' ) );
tcsp_get_or_create_term( 'Frontend', 'course-category', array( 'slug' => 'frontend', 'parent' => $web_dev_id ) );
tcsp_get_or_create_term( 'Backend',  'course-category', array( 'slug' => 'backend',  'parent' => $web_dev_id ) );

$data_science_id = tcsp_get_or_create_term( 'Data Science', 'course-category', array( 'slug' => 'data-science' ) );
tcsp_get_or_create_term( 'Machine Learning', 'course-category', array( 'slug' => 'machine-learning', 'parent' => $data_science_id ) );
tcsp_get_or_create_term( 'Statistics',       'course-category', array( 'slug' => 'statistics',      'parent' => $data_science_id ) );

tcsp_get_or_create_term( 'Design', 'course-category', array( 'slug' => 'design' ) );

// ── Tags ───────────────────────────────────────────────────────────
foreach ( array( 'javascript', 'python', 'react', 'ui', 'career', 'hands-on' ) as $slug ) {
	tcsp_get_or_create_term( ucfirst( $slug ), 'course-tag', array( 'slug' => $slug ) );
}

// ── Courses ────────────────────────────────────────────────────────
$courses = array(
	array(
		'title'       => 'Complete JavaScript Mastery',
		'content'     => 'Master JavaScript from fundamentals to advanced concepts including closures, prototypes, async/await, and modern ES6+ features. This comprehensive course covers everything you need to become a proficient JavaScript developer.',
		'author'      => 'sarah_chen',
		'price_type'  => 'paid',
		'price'       => 89,
		'level'       => 'intermediate',
		'categories'  => array( 'web-development', 'frontend' ),
		'tags'        => array( 'javascript', 'hands-on' ),
		'tier'        => 'platinum',
		'rating'      => 4.8,
		'students'    => 120,
	),
	array(
		'title'       => 'React for Production',
		'content'     => 'Learn to build production-ready React applications with best practices for state management, performance optimization, testing, and deployment.',
		'author'      => 'sarah_chen',
		'price_type'  => 'paid',
		'price'       => 79,
		'level'       => 'expert',
		'categories'  => array( 'web-development', 'frontend' ),
		'tags'        => array( 'react', 'javascript' ),
		'tier'        => 'gold',
		'rating'      => 4.5,
		'students'    => 85,
	),
	array(
		'title'       => 'Python Data Analysis',
		'content'     => 'Dive into data analysis with Python using pandas, NumPy, and matplotlib. Learn to clean, analyze, and visualize data effectively for real-world projects.',
		'author'      => 'marcus_johnson',
		'price_type'  => 'paid',
		'price'       => 99,
		'level'       => 'beginner',
		'categories'  => array( 'data-science', 'statistics' ),
		'tags'        => array( 'python', 'hands-on' ),
		'tier'        => 'featured',
		'rating'      => 4.2,
		'students'    => 200,
	),
	array(
		'title'       => 'Machine Learning Foundations',
		'content'     => 'Understand the core concepts of machine learning including supervised and unsupervised learning, model evaluation, and practical implementation with scikit-learn.',
		'author'      => 'marcus_johnson',
		'price_type'  => 'paid',
		'price'       => 129,
		'level'       => 'intermediate',
		'categories'  => array( 'data-science', 'machine-learning' ),
		'tags'        => array( 'python' ),
		'tier'        => '',
		'rating'      => 4.0,
		'students'    => 150,
	),
	array(
		'title'       => 'UI Design Principles',
		'content'     => 'Learn the fundamental principles of user interface design including layout, typography, color theory, and accessibility. Perfect for developers and aspiring designers.',
		'author'      => 'elena_rodriguez',
		'price_type'  => 'free',
		'price'       => 0,
		'level'       => 'all_levels',
		'categories'  => array( 'design' ),
		'tags'        => array( 'ui', 'career' ),
		'tier'        => '',
		'rating'      => 4.7,
		'students'    => 300,
	),
	array(
		'title'       => 'Backend with Node.js',
		'content'     => 'Build scalable backend services with Node.js, Express, and MongoDB. Covers REST APIs, authentication, error handling, and deployment strategies.',
		'author'      => 'sarah_chen',
		'price_type'  => 'paid',
		'price'       => 69,
		'level'       => 'intermediate',
		'categories'  => array( 'web-development', 'backend' ),
		'tags'        => array( 'javascript', 'hands-on' ),
		'tier'        => '',
		'rating'      => 3.8,
		'students'    => 60,
	),
	array(
		'title'       => 'CSS Grid & Flexbox',
		'content'     => 'Master modern CSS layout techniques with Grid and Flexbox. Learn to create responsive, beautiful layouts that work across all devices and breakpoints.',
		'author'      => 'elena_rodriguez',
		'price_type'  => 'free',
		'price'       => 0,
		'level'       => 'beginner',
		'categories'  => array( 'web-development', 'frontend' ),
		'tags'        => array( 'ui', 'hands-on' ),
		'tier'        => '',
		'rating'      => 4.3,
		'students'    => 180,
	),
	array(
		'title'       => 'Statistics for Data Science',
		'content'     => 'A practical introduction to statistics for data science. Covers descriptive statistics, probability, hypothesis testing, and regression analysis with hands-on examples.',
		'author'      => 'marcus_johnson',
		'price_type'  => 'paid',
		'price'       => 59,
		'level'       => 'beginner',
		'categories'  => array( 'data-science', 'statistics' ),
		'tags'        => array( 'python' ),
		'tier'        => '',
		'rating'      => 3.5,
		'students'    => 90,
	),
	array(
		'title'       => 'Advanced React Patterns',
		'content'     => 'Deep dive into advanced React patterns including compound components, render props, custom hooks, and performance optimization techniques used by top teams.',
		'author'      => 'sarah_chen',
		'price_type'  => 'paid',
		'price'       => 119,
		'level'       => 'expert',
		'categories'  => array( 'web-development', 'frontend' ),
		'tags'        => array( 'react', 'javascript' ),
		'tier'        => '',
		'rating'      => 4.9,
		'students'    => 45,
	),
	array(
		'title'       => 'Design Systems at Scale',
		'content'     => 'Learn to build and maintain design systems that scale across teams and products. Covers component libraries, design tokens, and documentation strategies.',
		'author'      => 'elena_rodriguez',
		'price_type'  => 'free',
		'price'       => 0,
		'level'       => 'intermediate',
		'categories'  => array( 'design' ),
		'tags'        => array( 'ui', 'career' ),
		'tier'        => '',
		'rating'      => 4.1,
		'students'    => 75,
	),
	array(
		'title'       => 'Deep Learning with Python',
		'content'     => 'Explore deep learning techniques using TensorFlow and Keras. Build neural networks for image classification, natural language processing, and more.',
		'author'      => 'marcus_johnson',
		'price_type'  => 'paid',
		'price'       => 149,
		'level'       => 'expert',
		'categories'  => array( 'data-science', 'machine-learning' ),
		'tags'        => array( 'python', 'hands-on' ),
		'tier'        => '',
		'rating'      => 4.6,
		'students'    => 110,
	),
	array(
		'title'       => 'Career in Web Development',
		'content'     => 'A guide to building a successful career in web development. Covers portfolio building, interview prep, job searching, and career growth strategies.',
		'author'      => 'sarah_chen',
		'price_type'  => 'free',
		'price'       => 0,
		'level'       => 'all_levels',
		'categories'  => array( 'web-development' ),
		'tags'        => array( 'career' ),
		'tier'        => '',
		'rating'      => 3.9,
		'students'    => 250,
	),
);

foreach ( $courses as $c ) {
	$author_id = isset( $instructors[ $c['author'] ] ) ? $instructors[ $c['author'] ] : 1;

	$post_id = wp_insert_post( array(
		'post_type'    => 'courses',
		'post_title'   => $c['title'],
		'post_content' => $c['content'],
		'post_status'  => 'publish',
		'post_author'  => $author_id,
	) );

	if ( is_wp_error( $post_id ) ) {
		echo "Error creating course '{$c['title']}': " . $post_id->get_error_message() . "\n";
		continue;
	}

	// Tutor LMS course meta
	update_post_meta( $post_id, '_tutor_course_price_type', $c['price_type'] );
	if ( 'paid' === $c['price_type'] && $c['price'] > 0 ) {
		update_post_meta( $post_id, 'tutor_course_price', $c['price'] );
	}
	update_post_meta( $post_id, '_tutor_course_level', $c['level'] );

	// Sponsorship tier
	if ( ! empty( $c['tier'] ) ) {
		update_post_meta( $post_id, '_tcsp_promotion_tier', $c['tier'] );
		update_post_meta( $post_id, '_tcsp_promoted_course', '1' );
	}

	// Categories
	$cat_ids = array();
	foreach ( $c['categories'] as $slug ) {
		$term = get_term_by( 'slug', $slug, 'course-category' );
		if ( $term ) {
			$cat_ids[] = $term->term_id;
		}
	}
	if ( $cat_ids ) {
		wp_set_post_terms( $post_id, $cat_ids, 'course-category' );
	}

	// Tags
	$tag_term_ids = array();
	foreach ( $c['tags'] as $slug ) {
		$term = get_term_by( 'slug', $slug, 'course-tag' );
		if ( $term ) {
			$tag_term_ids[] = $term->term_id;
		}
	}
	if ( $tag_term_ids ) {
		wp_set_post_terms( $post_id, $tag_term_ids, 'course-tag' );
	}

	// Ratings
	if ( $c['rating'] > 0 ) {
		tcsp_create_ratings( $post_id, $c['rating'], 10 );
	}

	// Enrollments
	if ( $c['students'] > 0 ) {
		tcsp_create_enrollments( $post_id, $c['students'], $students );
	}

	echo "Created course: {$c['title']} (ID: $post_id)\n";
}

echo "Sample data creation complete.\n";
