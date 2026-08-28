# Tutor Course Search Pro

Marketplace-style search, filtering, and sorting for the **Tutor LMS** course archive — plus course-level sponsorship tiers, visible sponsored stickers, and an enhanced search-suggestion autocomplete.

## Features

- **Advanced archive filters** — Price (Free/Paid), Difficulty level, Category (multi-select, AND), Tag (multi-select, AND), Minimum rating, and Minimum number of students. Category filters show parent/child nesting and WordPress term counts; tags show counts too. Each filter can be toggled on/off from settings.
- **Reset filters** — a "Reset filters" button appears whenever any search/filter is active, clearing everything and returning to page 1.
- **Rich sorting** — Relevance, Newest/Oldest, Title A–Z / Z–A, Price low→high / high→low, Top rated, and Most students.
- **Course sponsorship tiers** — Assign Platinum, Gold, or Featured sponsorship to individual courses. Configure each tier's label, color, and ranking weight from a visual admin panel. Paid courses can receive a secondary boost.
- **Sponsored course stickers** — Sponsored courses display a tier-colored `Sponsored · Tier` sticker on Tutor course cards through Tutor's card hooks.
- **Full search index** — Search matches course name, description/content, category names, child categories, tag names, and tutor display name in both the archive and autocomplete.
- **Enhanced search suggestions** — Replaces the default Tutor autocomplete with a dropdown that shows thumbnails, price, instructor, and the sponsorship tier. Keyboard navigable.

## How the ranking works

Ranking is injected at the SQL layer via a `posts_clauses` filter so it composes with whatever secondary sort the visitor picks. Each course gets a score:

```
score = (course sponsorship tier weight)
      + (course is paid ? paid_course_weight : 0)
```

Results are ordered by `score DESC`, then by the chosen sort as a tiebreaker. A course tier is stored in `_tcsp_promotion_tier`; the legacy `_tcsp_promoted_course` flag is still recognized as the Featured tier. Paid state is read from Tutor's `_tutor_course_price_type` meta.

## Settings

**Tutor LMS → Course Search Pro** (admin):

- Toggle sponsorship ranking and configure the Platinum, Gold, and Featured tier labels, colors, and weights.
- Toggle paid-course boosting and set its weight.
- Assign a sponsorship tier to individual published courses with searchable course/tutor rows.
- Enable/disable each filter and set the default sort.
- Toggle the enhanced suggestions and set how many appear.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Tutor LMS (free or Pro) active

## Installation

1. Copy the `tutor-course-search-pro` folder into `/wp-content/plugins/`.
2. Activate **Tutor Course Search Pro** in *Plugins*.
3. Go to **Tutor LMS → Course Search Pro** and configure tiers, course assignments, filters, and sorting.

## Notes / theme compatibility

- The filter bar renders on `tutor_course/archive/before_loop` (with `tutor_course/loop/before` as a fallback). If your theme uses a custom archive, drop the `[tcsp_filters]` shortcode where you want the bar.
- Sponsored stickers are added through Tutor card hooks (`before_thumbnail`, `after_thumbnail`, `before_title`, and `after_title`) so they work with the standard Tutor templates and most Tutor-compatible themes.
- If replacing suggestions is enabled, the plugin dequeues Tutor's default search scripts (`tutor-course-search`, `tutor-frontend-search`) on course pages.
- Query params used: `s`, `tcsp_price`, `tcsp_level`, `tcsp_category[]`, `tcsp_tag[]`, `tcsp_min_rating`, `tcsp_min_students`, `tcsp_sort` (Tutor's own `tutor_course_filter` is also respected).
- The filter bar is resilient to AJAX-based pagination: if a theme/Tutor version swaps out the course loop container over AJAX and takes the bar with it, `tcsp-filters.js` keeps a copy and re-inserts it automatically.

## Changelog

### 2.0.0

- Replaced the single promoted-course checkbox with Platinum, Gold, and Featured sponsorship tiers, configurable labels/colors/weights, and a searchable tier-assignment admin interface.
- Added course-level sponsored stickers on Tutor card hook locations, with tier colors and accessible labels.
- Expanded archive and autocomplete indexing to tutor display name as well as course title, description/content, categories, tags, and child categories.
- Category filter options now render hierarchical parent/child categories with term counts; tag options show term counts.

### 1.2.0

- Fixed explicit sorting and category/tag search matching.
- Category and Tag multi-select now use AND.
- Added a minimum number of students filter and a companion Most students sort option.

### 1.1.0

- Fixed fatal errors caused by invalid nested taxonomy query shapes.
- Added multi-select category and tag filters, reset filters, and safer price sorting.