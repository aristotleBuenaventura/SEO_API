<?php
/**
 * Code Snippets plugin — paste this as a PHP snippet (Run everywhere).
 *
 * Shortcode: [movie_genre]
 * Optional:  [movie_genre home_url="/" watch_url="/watch/"]
 *            [movie_genre lang="bn"]  → /bn links + Bengali UI
 *
 * Create a WP page at /genre/ (slug: genre) and put [movie_genre] in the content.
 * Pretty URLs (via genre-pretty-urls-seo.php): /Genre/Horror  /Genre/Action  /Teen
 * BN page: /bn/Genre/ with [movie_genre lang="bn"] (same ?genre= or pretty slug)
 * Legacy query still works (301 → pretty): /genre/?genre=Horror
 *
 * Requires: Movie Meta plugin (data source).
 * Pair with snippets/genre-rows-shortcode.php → [movie_genre_rows]
 * Pair with snippets/genre-pretty-urls-seo.php (rewrites + meta)
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('movie_genre', 'mmgg_render_genre_page_shortcode');

function mmgg_render_genre_page_shortcode($atts = []) {
    $raw = is_array($atts) ? $atts : [];
    $atts = shortcode_atts(
        [
            'genre'     => '',
            'home_url'  => '/',
            'watch_url' => '/watch/',
            'lang'      => '',
        ],
        $raw,
        'movie_genre'
    );

    $lang = function_exists('mmba_snip_normalize_lang')
        ? mmba_snip_normalize_lang($atts['lang'])
        : (in_array(strtolower(trim((string) $atts['lang'])), ['bn', 'bengali', 'bangla'], true) ? 'bn' : '');

    if (function_exists('mmba_snip_apply_bn_url_defaults')) {
        $atts = mmba_snip_apply_bn_url_defaults($raw, $atts, [
            'home_url'  => '/bn',
            'watch_url' => '/bn/watch/',
        ]);
    } elseif ($lang === 'bn') {
        if (!array_key_exists('home_url', $raw) || trim((string) $raw['home_url']) === '') {
            $atts['home_url'] = '/bn';
        }
        if (!array_key_exists('watch_url', $raw) || trim((string) $raw['watch_url']) === '') {
            $atts['watch_url'] = '/bn/watch/';
        }
    }

    $bn_ui = [
        'No genre selected.' => 'কোনো ধরণ নির্বাচিত হয়নি।',
        'Back to movies' => 'মুভিতে ফিরে যান',
        'Movie Meta plugin is required.' => 'Movie Meta প্লাগইন প্রয়োজন।',
        'Genre navigation' => 'ধরণ নেভিগেশন',
        'Category' => 'ক্যাটাগরি',
        'No movies found in this genre.' => 'এই ধরণের কোনো মুভি পাওয়া যায়নি।',
        'Untitled' => 'শিরোনামহীন',
    ];
    $t = static function ($text) use ($lang, $bn_ui) {
        if ($lang !== 'bn') {
            return (string) $text;
        }
        $key = (string) $text;
        if (isset($bn_ui[$key])) {
            return $bn_ui[$key];
        }
        return function_exists('mmba_snip_t') ? mmba_snip_t($key, 'bn') : $key;
    };

    $genre = $atts['genre'] !== '' ? sanitize_text_field($atts['genre']) : '';
    if ($genre === '' && function_exists('mmba_genre_from_request')) {
        $genre = mmba_genre_from_request();
    }
    if ($genre === '') {
        $qv = get_query_var('mmba_genre');
        if (is_string($qv) && $qv !== '') {
            $genre = sanitize_text_field(rawurldecode($qv));
        }
    }
    if ($genre === '' && isset($_GET['genre'])) {
        $genre = sanitize_text_field(wp_unslash((string) $_GET['genre']));
    }
    if ($genre !== '' && function_exists('mmba_genre_filter_label')) {
        $genre = mmba_genre_filter_label($genre);
    }

    $genre_display = $genre;
    if ($lang === 'bn' && $genre !== '' && function_exists('mmba_snip_genre')) {
        $genre_display = mmba_snip_genre($genre, 'bn');
    }

    $home_url = $atts['home_url'];
    if ($home_url !== '' && strpos($home_url, 'http') !== 0) {
        $home_url = home_url($home_url);
    }
    $home_url = esc_url($home_url);

    $watch_url = $atts['watch_url'];
    if ($watch_url !== '' && strpos($watch_url, 'http') !== 0) {
        $watch_url = home_url($watch_url);
    }
    $watch_url = esc_url($watch_url);

    if ($genre === '') {
        return '<div class="mmgg mmgg-empty"' . ($lang === 'bn' ? ' lang="bn"' : '') . '>' . esc_html($t('No genre selected.')) .
            ' <a class="mmgg-link" href="' . esc_url($home_url) . '">' . esc_html($t('Back to movies')) . '</a></div>';
    }

    if (!class_exists('MMBA_Storage')) {
        return '<div class="mmgg mmgg-error"' . ($lang === 'bn' ? ' lang="bn"' : '') . '>' . esc_html($t('Movie Meta plugin is required.')) . '</div>';
    }

    $movies = [];
    foreach (MMBA_Storage::get_movies() as $movie) {
        $g = isset($movie['genre']) ? (string) $movie['genre'] : '';
        if (MMBA_Storage::genre_matches($g, $genre)) {
            $movies[] = $movie;
        }
    }

    usort($movies, static function ($a, $b) {
        return strcmp(
            isset($a['title']) ? (string) $a['title'] : '',
            isset($b['title']) ? (string) $b['title'] : ''
        );
    });

    $count = count($movies);
    // BN: Bengali genre only (e.g. অ্যাকশন). EN: "Action Movies".
    $heading = $lang === 'bn' ? $genre_display : ($genre . ' Movies');

    ob_start();
    ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&display=swap">
<div class="mmgg"<?php echo $lang === 'bn' ? ' lang="bn"' : ''; ?>>
  <div class="mmgg-shell">
    <nav class="mmgg-nav" aria-label="<?php echo esc_attr($t('Genre navigation')); ?>">
      <a class="mmgg-back" href="<?php echo esc_url($home_url); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
        <span><?php echo esc_html($t('Back to movies')); ?></span>
      </a>
    </nav>

    <header class="mmgg-header">
      <p class="mmgg-kicker"><?php echo esc_html($t('Category')); ?></p>
      <h1 class="mmgg-title"><?php echo esc_html($heading); ?></h1>
      <p class="mmgg-count">
        <?php
        if ($lang === 'bn') {
            $n = function_exists('mmba_snip_bn_digits') ? mmba_snip_bn_digits((string) $count) : (string) $count;
            echo esc_html($n . 'টি শিরোনাম');
        } else {
            echo esc_html(
                sprintf(
                    /* translators: %d: movie count */
                    _n('%d title', '%d titles', $count, 'movie-meta-by-aris'),
                    $count
                )
            );
        }
        ?>
      </p>
    </header>

    <?php if ($count === 0) : ?>
      <div class="mmgg-empty-state"><?php echo esc_html($t('No movies found in this genre.')); ?></div>
    <?php else : ?>
      <div class="mmgg-grid">
        <?php foreach ($movies as $movie) :
            $id = isset($movie['id']) ? (string) $movie['id'] : '';
            $title = isset($movie['title']) ? (string) $movie['title'] : '';
            $year = isset($movie['year']) ? (string) $movie['year'] : '';
            $link = isset($movie['movie_link']) ? (string) $movie['movie_link'] : '';
            $poster = MMBA_Storage::movie_poster_url($movie);
            $href = $watch_url . (strpos($watch_url, '?') === false ? '?' : '&') . 'id=' . rawurlencode($id);
            $initial = $title !== '' ? strtoupper(substr($title, 0, 1)) : 'M';
            $tone = mmgg_poster_tone($title);
            $display = $title !== '' ? $title : $t('Untitled');
            $meta_bits = array_filter([$year, $genre_display]);
            $img_meta = method_exists('MMBA_Storage', 'poster_image_meta')
                ? MMBA_Storage::poster_image_meta($display)
                : ($display . ' DesiMoviesHub Free Watch');
            ?>
          <a class="mmgg-card" href="<?php echo esc_url($href); ?>">
            <div class="mmgg-poster mmgg-tone-<?php echo (int) $tone; ?>">
              <?php if ($poster !== '') : ?>
                <img class="mmgg-poster-img" src="<?php echo esc_url($poster); ?>" alt="<?php echo esc_attr($img_meta); ?>" title="<?php echo esc_attr($img_meta); ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                  onerror="this.style.display='none';var f=this.parentNode.querySelector('.mmgg-poster-fallback');if(f){f.hidden=false;f.removeAttribute('hidden');}">
                <div class="mmgg-poster-fallback" hidden aria-hidden="true">
                  <span class="mmgg-poster-letter"><?php echo esc_html($initial); ?></span>
                </div>
              <?php else : ?>
                <div class="mmgg-poster-fallback" aria-hidden="true">
                  <span class="mmgg-poster-letter"><?php echo esc_html($initial); ?></span>
                </div>
              <?php endif; ?>
              <span class="mmgg-badge mmgg-badge-hd">HD</span>
              <?php if ($year !== '') : ?>
                <span class="mmgg-badge mmgg-badge-year"><?php echo esc_html($year); ?></span>
              <?php endif; ?>
            </div>
            <div class="mmgg-card-body">
              <h2 class="mmgg-card-title"><?php echo esc_html($display); ?></h2>
              <?php if (!empty($meta_bits)) : ?>
                <p class="mmgg-card-meta"><?php echo esc_html(implode(' · ', $meta_bits)); ?></p>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
  .mmgg, .mmgg *, .mmgg *::before, .mmgg *::after { box-sizing: border-box; }
  .mmgg,
  .mmgg h1, .mmgg h2, .mmgg p, .mmgg span, .mmgg a, .mmgg div {
    color: inherit;
  }
  .mmgg {
    --mmgg-ink: #12151a;
    --mmgg-muted: #4b5563;
    --mmgg-accent: #1d4ed8;
    --mmgg-font: "Sora", "Avenir Next", "Segoe UI", system-ui, sans-serif;
    width: 100%;
    max-width: 100%;
    overflow-x: clip;
    color: #12151a !important;
    font-family: var(--mmgg-font);
    padding: 1rem max(0.55rem, env(safe-area-inset-right)) 3rem max(0.55rem, env(safe-area-inset-left));
    
  }
  .mmgg-shell {
    max-width: 100%;
    width: 100%;
    margin: 0 auto;
    padding: 1.25rem 1.15rem 1.75rem;
    background: #ffffff !important;
    border: 1px solid rgba(18, 21, 26, 0.08);
    border-radius: 18px;
    box-shadow: 0 12px 36px rgba(18, 21, 26, 0.06);
    color: #12151a !important;
  }
  .mmgg-nav { margin: 0 0 1.15rem; }
  .mmgg-back {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    text-decoration: none !important;
    color: #4b5563 !important;
    font-size: 0.9rem;
    font-weight: 500;
    padding: 0.35rem 0.55rem 0.35rem 0.25rem;
    border-radius: 999px;
  }
  .mmgg-back svg { width: 18px; height: 18px; display: block; stroke: currentColor; }
  .mmgg-back:hover { color: #12151a !important; background: #eef1f5; }
  .mmgg-header { margin: 0 0 1.35rem; }
  .mmgg-kicker {
    margin: 0 0 0.4rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #1d4ed8 !important;
  }
  .mmgg-title {
    margin: 0;
    font-size: clamp(1.45rem, 4vw, 2.2rem);
    font-weight: 700;
    letter-spacing: -0.03em;
    line-height: 1.15;
    color: #12151a !important;
  }
  .mmgg-count {
    margin: 0.45rem 0 0;
    color: #4b5563 !important;
    font-size: 0.92rem;
    font-weight: 500;
  }
  .mmgg-empty-state {
    padding: 2rem 0.5rem;
    color: #6b7280 !important;
    font-size: 0.95rem;
  }
  .mmgg-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(148px, 1fr));
    gap: 1.15rem 0.95rem;
  }
  .mmgg-card {
    text-decoration: none !important;
    color: inherit;
    min-width: 0;
    -webkit-tap-highlight-color: transparent;
    transition: transform 0.2s ease;
  }
  @media (hover: hover) {
    .mmgg-card:hover { transform: translateY(-3px); }
  }
  .mmgg-poster {
    position: relative;
    aspect-ratio: 2 / 3;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 10px 24px rgba(18, 21, 26, 0.1);
    background-color: #1e293b;
  }
  .mmgg-poster-img {
    position: absolute;
    inset: 0;
    z-index: 1;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .mmgg-poster-fallback {
    position: absolute;
    inset: 0;
    z-index: 0;
    display: grid;
    place-items: center;
  }
  .mmgg-poster-fallback[hidden] { display: none !important; }
  .mmgg-poster-letter {
    font-size: clamp(2rem, 8vw, 3rem);
    font-weight: 700;
    color: #ffffff !important;
    opacity: 0.88;
    text-shadow: 0 6px 24px rgba(0, 0, 0, 0.35);
  }
  .mmgg-tone-1 { background: linear-gradient(145deg, #1b3a4b 0%, #0f7a6c 55%, #163a34 100%) !important; }
  .mmgg-tone-2 { background: linear-gradient(145deg, #2b2118 0%, #b45309 55%, #3f2a14 100%) !important; }
  .mmgg-tone-3 { background: linear-gradient(145deg, #1e293b 0%, #64748b 50%, #0f172a 100%) !important; }
  .mmgg-tone-4 { background: linear-gradient(145deg, #312e81 0%, #0e7490 55%, #164e63 100%) !important; }
  .mmgg-tone-5 { background: linear-gradient(145deg, #3f1d2e 0%, #e11d48 50%, #1f2937 100%) !important; }
  .mmgg-tone-6 { background: linear-gradient(145deg, #14532d 0%, #22c55e 45%, #052e16 100%) !important; }
  .mmgg-badge {
    position: absolute;
    z-index: 3;
    padding: 0.2rem 0.48rem;
    border-radius: 999px;
    background: rgba(15, 18, 22, 0.88) !important;
    color: #ffffff !important;
    font-size: 0.66rem;
    font-weight: 700;
    border: 1px solid rgba(255, 255, 255, 0.18);
  }
  .mmgg-badge-hd { top: 0.5rem; left: 0.5rem; }
  .mmgg-badge-year { bottom: 0.5rem; left: 0.5rem; font-weight: 600; }
  .mmgg-card-body { padding: 0.65rem 0.1rem 0; }
  .mmgg-card-title {
    margin: 0;
    font-size: 0.9rem;
    font-weight: 600;
    line-height: 1.3;
    color: #12151a !important;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .mmgg-card-meta {
    margin: 0.3rem 0 0;
    color: #4b5563 !important;
    font-size: 0.76rem;
  }
  .mmgg-empty, .mmgg-error {
    max-width: 1120px;
    margin: 0 auto;
    padding: 2rem 1rem;
    color: #4b5563 !important;
    font-family: var(--mmgg-font, system-ui, sans-serif);
    background: #fff;
    border-radius: 12px;
  }
  .mmgg-error { color: #b91c1c !important; }
  .mmgg-link { color: #1d4ed8 !important; }
  @media (max-width: 720px) {
    .mmgg-shell { padding: 1rem 0.85rem 1.25rem; border-radius: 14px; }
    .mmgg-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.9rem 0.65rem;
    }
    .mmgg-card-title { font-size: 0.8rem; }
    .mmgg-poster { border-radius: 12px; }
  }
  @media (max-width: 420px) {
    .mmgg-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
  @media (prefers-reduced-motion: reduce) {
    .mmgg-card { transition: none; }
  }
</style>
    <?php
    return ob_get_clean();
}

function mmgg_poster_tone($title) {
    $sum = 0;
    $s = (string) $title;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $sum += ord($s[$i]);
    }
    return ($sum % 6) + 1;
}

/** Local BN helpers if other snippets are not loaded. */
if (!function_exists('mmba_snip_normalize_lang')) {
    function mmba_snip_normalize_lang($lang) {
        $lang = strtolower(trim((string) $lang));
        if ($lang === 'bn' || $lang === 'bengali' || $lang === 'bangla') {
            return 'bn';
        }
        return '';
    }
}

if (!function_exists('mmba_snip_apply_bn_url_defaults')) {
    function mmba_snip_apply_bn_url_defaults(array $raw, array $atts, array $bn_urls) {
        $lang = mmba_snip_normalize_lang(isset($atts['lang']) ? $atts['lang'] : (isset($raw['lang']) ? $raw['lang'] : ''));
        if ($lang !== 'bn') {
            return $atts;
        }
        foreach ($bn_urls as $key => $path) {
            if (!array_key_exists($key, $raw) || trim((string) $raw[$key]) === '') {
                $atts[$key] = $path;
            }
        }
        return $atts;
    }
}

if (!function_exists('mmba_snip_bn_digits')) {
    function mmba_snip_bn_digits($text) {
        return strtr((string) $text, [
            '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪',
            '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯',
        ]);
    }
}

if (!function_exists('mmba_snip_genre')) {
    function mmba_snip_genre($genre, $lang = '') {
        $genre = trim((string) $genre);
        if ($genre === '' || mmba_snip_normalize_lang($lang) !== 'bn') {
            return $genre;
        }
        $map = [
            'horror' => 'হরর',
            'action' => 'অ্যাকশন',
            'drama' => 'ড্রামা',
            'comedy' => 'কমেডি',
            'thriller' => 'থ্রিলার',
            'romance' => 'রোমান্স',
            'crime' => 'ক্রাইম',
            'animation' => 'অ্যানিমেশন',
            'adventure' => 'অ্যাডভেঞ্চার',
            'sci-fi' => 'সায়েন্স ফিকশন',
            'scifi' => 'সায়েন্স ফিকশন',
            'sci fi' => 'সায়েন্স ফিকশন',
            'science fiction' => 'সায়েন্স ফিকশন',
            'war' => 'যুদ্ধ',
            'western' => 'ওয়েস্টার্ন',
            'documentary' => 'ডকুমেন্টারি',
            'mystery' => 'মিস্ট্রি',
            'fantasy' => 'ফ্যান্টাসি',
            'family' => 'ফ্যামিলি',
            'teen' => 'টিন',
            'lgbtq' => 'এলজিবিটিকিউ',
            'lgbtq+' => 'এলজিবিটিকিউ',
            'lgbt' => 'এলজিবিটিকিউ',
            'other' => 'অন্যান্য',
        ];
        $key = strtolower($genre);
        return isset($map[$key]) ? $map[$key] : $genre;
    }
}
