<?php
/**
 * Code Snippets plugin — paste this as a PHP snippet (Run everywhere).
 *
 * Pretty genre URLs (match SEO GSheet):
 *   /Genre/Action   /Genre/Drama   /Genre/Sci-fi   /Genre/lgbtq
 *   /Teen           (special path from sheet)
 *
 * Requires WP page slug `genre` with shortcode [movie_genre].
 * Pair with:
 *   snippets/movie-genre-page-shortcode.php
 *   snippets/genre-rows-shortcode.php
 *
 * Also: 301 /genre/?genre=Action → /Genre/Action
 *        per-genre meta title/description (EN from SEO sheet)
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Bump to flush rewrite rules once after deploy. */
define('MMBA_GENRE_SEO_REWRITE_VER', '1');

/**
 * Canonical URL slug per genre (sheet path segment).
 * Keys are lowercase catalog labels.
 *
 * @return array<string, string>
 */
function mmba_genre_url_slug_map() {
    return [
        'horror'        => 'Horror',
        'animation'     => 'Animation',
        'comedy'        => 'Comedy',
        'action'        => 'Action',
        'romance'       => 'Romance',
        'teen'          => 'Teen',
        'sci-fi'        => 'Sci-fi',
        'scifi'         => 'Sci-fi',
        'sci fi'        => 'Sci-fi',
        'adventure'     => 'Adventure',
        'drama'         => 'Drama',
        'family'        => 'Family',
        'western'       => 'Western',
        'war'           => 'War',
        'lgbtq'         => 'lgbtq',
        'lgbtq+'        => 'lgbtq',
        'lgbt'          => 'lgbtq',
        'fantasy'       => 'Fantasy',
        'thriller'      => 'Thriller',
        'crime'         => 'Crime',
        'documentary'   => 'Documentary',
        'mystery'       => 'Mystery',
    ];
}

/**
 * Filter label used against movie genre strings (catalog form).
 *
 * @return array<string, string>
 */
function mmba_genre_filter_alias_map() {
    return [
        'sci-fi'  => 'Sci-Fi',
        'scifi'   => 'Sci-Fi',
        'lgbtq'   => 'LGBTQ+',
        'lgbtq+'  => 'LGBTQ+',
        'lgbt'    => 'LGBTQ+',
    ];
}

/**
 * SEO meta from GSheet (EN). Keyed by URL slug (case-sensitive as in sheet).
 *
 * @return array<string, array{title: string, description: string}>
 */
function mmba_genre_seo_map() {
    return [
        'Horror' => [
            'title'       => 'Heart pumping Horror and Paranormal| Genre | DesiMoviesHub',
            'description' => 'Watch with over 100+ Horror, psychological and true-crime movies and TV Shows with DesiMoviesHub! Watch free today',
        ],
        'Animation' => [
            'title'       => 'Awesome Movie and TV Shows | Genre | DesiMoviesHub',
            'description' => 'Kids Animation and awesome animated movies! Watch free Movies and TV Shows with DesiMoviesHub Today!',
        ],
        'Comedy' => [
            'title'       => 'Funny Comedy Movie and TV Shows | Genre | DesiMoviesHub',
            'description' => 'Top 100 Comedy Films, movies, TVs and more! Watch free Movies and TV Shows with DesiMoviesHub Today!',
        ],
        'Action' => [
            'title'       => 'Heart Racing TV and Movie Shows | Genre | DesiMoviesHub',
            'description' => 'Heart Racing Action Films that will keep your heart pumping by just watching! Click here to watch free action movies with desimovieshub!',
        ],
        'Romance' => [
            'title'       => 'All About Romance TV and Movie Shows | Genre | DesiMoviesHub',
            'description' => 'Watch All Free Romantic Films with Deshimovieshub! Watch Romantic Bollywood and Hollywood movies for free',
        ],
        'Teen' => [
            'title'       => 'Teen TV and Movie Shows | Genre | DesiMoviesHub',
            'description' => 'Teen Romance, teen thriller and psychological shows TV movies and films! Watch free with desimovieshub.com today, No login, sign up required',
        ],
        'Sci-fi' => [
            'title'       => 'Amazing Sci-fi Movie and TV Shows | Genre | DesiMoviesHub',
            'description' => 'Space Sci-Fi and Fantasy Movies and TV Shows for kids, teens and adults! Watch free Scifi films and TV with Desimovieshub.com',
        ],
        'Adventure' => [
            'title'       => 'Adventure Themed Movie and TV Shows | Genre | DesiMoviesHub',
            'description' => 'Spike up your adrenaline with these awesome Adventure films and movies! Watch for free with Desimovieshub! No Credit card required',
        ],
        'Drama' => [
            'title'       => 'Drama Movie and TV Shows | Genre | DesiMoviesHub',
            'description' => 'Get emotional with Desimovieshub Drama Movies and TV Shows! Watch Movies for free Bangladesh',
        ],
        'Family' => [
            'title'       => 'All About Family Movie and TV Shows | Genre | DesiMoviesHub',
            'description' => 'Family Movies perfect for Movie Nights with DesiMoviesHub! Watch free Movies, No sign up required',
        ],
        'Western' => [
            'title'       => 'Western Hollywood Movie and TV Shows | Genre | DesiMoviesHub',
            'description' => 'Hollywood and Western Movies and TV Shows for free! Watch with over 1000+ choices today! No Sign up no payment required',
        ],
        'War' => [
            'title'       => 'Action and War Movies and TV Shows | Genre | DesiMoviesHub',
            'description' => 'Watch War Movies and Historical accurate war series with DesiMoviesHub.com! Watch movies like Saving private ryan and more!',
        ],
        'lgbtq' => [
            'title'       => 'Pride LGBTQ new Movies and TV Shows | Genre | DesiMoviesHub',
            'description' => 'Pride Movies and LGBTQ TV Shows watch for free with DesiMoviesHub Bangladesh! No Sign up required',
        ],
        'Fantasy' => [
            'title'       => 'Fantasy Movies and TV Shows | Genre | DesiMoviesHub',
            'description' => 'New Fantasy Movies 2026! Click here to watch free fantasy movies like final fantasy and more with desimovieshub',
        ],
        'Thriller' => [
            'title'       => 'Brand New Thriller Movies and TV Shows | Genre | DesiMoviesHub',
            'description' => 'Thriller, psychological, mind bending TV and  Movies from DesiMovieHub! Click here and watch free movies',
        ],
    ];
}

/**
 * @param string $genre Catalog or URL genre label.
 * @return string Sheet-style URL slug (e.g. Sci-fi, lgbtq).
 */
function mmba_genre_url_slug($genre) {
    $genre = trim((string) $genre);
    if ($genre === '') {
        return '';
    }
    $map = mmba_genre_url_slug_map();
    $key = strtolower($genre);
    if (isset($map[$key])) {
        return $map[$key];
    }
    // Preserve first-letter style for unknown genres.
    return $genre;
}

/**
 * Catalog filter string for MMBA_Storage::genre_matches().
 *
 * @param string $slug_or_label URL slug or display label.
 * @return string
 */
function mmba_genre_filter_label($slug_or_label) {
    $raw = trim((string) $slug_or_label);
    if ($raw === '') {
        return '';
    }
    $aliases = mmba_genre_filter_alias_map();
    $key = strtolower($raw);
    if (isset($aliases[$key])) {
        return $aliases[$key];
    }
    return $raw;
}

/**
 * Pretty public URL for a genre (absolute).
 *
 * @param string $genre Catalog genre label.
 * @return string
 */
function mmba_genre_pretty_url($genre) {
    $slug = mmba_genre_url_slug($genre);
    if ($slug === '') {
        return home_url('/Genre/');
    }
    // Sheet uses /Teen (not /Genre/Teen).
    if (strcasecmp($slug, 'Teen') === 0) {
        return home_url('/Teen/');
    }
    return home_url('/Genre/' . rawurlencode($slug) . '/');
}

/**
 * Current genre from rewrite / query string.
 *
 * @return string Filter label (may be empty).
 */
function mmba_genre_from_request() {
    $slug = get_query_var('mmba_genre');
    if (!is_string($slug) || $slug === '') {
        if (isset($_GET['genre'])) {
            $slug = sanitize_text_field(wp_unslash((string) $_GET['genre']));
        }
    } else {
        $slug = sanitize_text_field(rawurldecode($slug));
    }
    if ($slug === '') {
        return '';
    }
    return mmba_genre_filter_label($slug);
}

/**
 * @param string $genre Filter or slug.
 * @return array{title: string, description: string}|null
 */
function mmba_genre_seo_for($genre) {
    $slug = mmba_genre_url_slug($genre);
    $map = mmba_genre_seo_map();
    if ($slug !== '' && isset($map[$slug])) {
        return $map[$slug];
    }
    // Case-insensitive slug lookup.
    foreach ($map as $key => $meta) {
        if (strcasecmp((string) $key, $slug) === 0) {
            return $meta;
        }
    }
    $label = mmba_genre_filter_label($genre);
    if ($label === '') {
        return null;
    }
    return [
        'title'       => $label . ' Movie and TV Shows | Genre | DesiMoviesHub',
        'description' => 'Watch free ' . $label . ' movies and TV shows on DesiMoviesHub. No login required.',
    ];
}

/* -------------------------------------------------------------------------
 * Rewrites
 * ---------------------------------------------------------------------- */

add_filter('query_vars', static function ($vars) {
    $vars[] = 'mmba_genre';
    return $vars;
});

add_action('init', static function () {
    // Capital-G path matches GSheet; lowercase alias for safety.
    add_rewrite_rule(
        '^Genre/([^/]+)/?$',
        'index.php?pagename=genre&mmba_genre=$matches[1]',
        'top'
    );
    add_rewrite_rule(
        '^genre/([^/]+)/?$',
        'index.php?pagename=genre&mmba_genre=$matches[1]',
        'top'
    );
    // Special sheet path for Teen.
    add_rewrite_rule(
        '^Teen/?$',
        'index.php?pagename=genre&mmba_genre=Teen',
        'top'
    );
    add_rewrite_rule(
        '^teen/?$',
        'index.php?pagename=genre&mmba_genre=Teen',
        'top'
    );

    $stored = get_option('mmba_genre_seo_rewrite_ver');
    if ($stored !== MMBA_GENRE_SEO_REWRITE_VER) {
        flush_rewrite_rules(false);
        update_option('mmba_genre_seo_rewrite_ver', MMBA_GENRE_SEO_REWRITE_VER, false);
    }
});

/* -------------------------------------------------------------------------
 * Redirect ?genre= → pretty URL
 * ---------------------------------------------------------------------- */

add_action('template_redirect', static function () {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    // Old query-string links → pretty.
    if (isset($_GET['genre']) && (string) $_GET['genre'] !== '') {
        // Only redirect when still on query form (not already rewritten).
        $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $is_pretty = (bool) preg_match('#/(?:Genre|genre)/[^/]+#', $req_uri)
            || (bool) preg_match('#/(?:Teen|teen)/?(\?|$)#', $req_uri);
        if (!$is_pretty) {
            $genre = sanitize_text_field(wp_unslash((string) $_GET['genre']));
            $target = mmba_genre_pretty_url($genre);
            wp_safe_redirect($target, 301);
            exit;
        }
    }
}, 1);

/* -------------------------------------------------------------------------
 * Meta title / description + canonical
 * ---------------------------------------------------------------------- */

add_filter('pre_get_document_title', static function ($title) {
    $genre = mmba_genre_from_request();
    if ($genre === '') {
        return $title;
    }
    $meta = mmba_genre_seo_for($genre);
    return ($meta && !empty($meta['title'])) ? $meta['title'] : $title;
}, 20);

add_filter('document_title_parts', static function ($parts) {
    $genre = mmba_genre_from_request();
    if ($genre === '') {
        return $parts;
    }
    $meta = mmba_genre_seo_for($genre);
    if ($meta && !empty($meta['title'])) {
        $parts['title'] = $meta['title'];
        unset($parts['site'], $parts['tagline'], $parts['page']);
    }
    return $parts;
}, 20);

// Rank Math (if active).
add_filter('rank_math/frontend/title', static function ($title) {
    $genre = mmba_genre_from_request();
    if ($genre === '') {
        return $title;
    }
    $meta = mmba_genre_seo_for($genre);
    return ($meta && !empty($meta['title'])) ? $meta['title'] : $title;
}, 20);

add_filter('rank_math/frontend/description', static function ($desc) {
    $genre = mmba_genre_from_request();
    if ($genre === '') {
        return $desc;
    }
    $meta = mmba_genre_seo_for($genre);
    return ($meta && !empty($meta['description'])) ? $meta['description'] : $desc;
}, 20);

add_filter('rank_math/frontend/canonical', static function ($canonical) {
    $genre = mmba_genre_from_request();
    if ($genre === '') {
        return $canonical;
    }
    return mmba_genre_pretty_url($genre);
}, 20);

add_filter('get_canonical_url', static function ($canonical, $post) {
    $genre = mmba_genre_from_request();
    if ($genre === '') {
        return $canonical;
    }
    return mmba_genre_pretty_url($genre);
}, 20, 2);

// Core / Yoast-less sites: print meta description when Rank Math is absent.
add_action('wp_head', static function () {
    if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
        return;
    }
    $genre = mmba_genre_from_request();
    if ($genre === '') {
        return;
    }
    $meta = mmba_genre_seo_for($genre);
    if (!$meta || empty($meta['description'])) {
        return;
    }
    echo '<meta name="description" content="' . esc_attr($meta['description']) . "\" />\n";
}, 1);
