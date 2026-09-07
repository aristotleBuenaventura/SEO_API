/**
 * Code Snippets plugin — paste this as a PHP snippet (Run everywhere).
 *
 * Shortcode: [movie_just_added]
 * Optional:  [movie_just_added title="Just Added" limit="10" watch_url="/watch/"]
 *            [movie_just_added lang="bn"]  → links to /bn/watch/
 *
 * Requires: Movie Meta plugin (data source).
 * Shows the 10 most recently added movies. Card clicks → /watch/?id=MOVIE_ID
 * (or /bn/watch/ when lang="bn")
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('movie_just_added', 'mmja_render_just_added_shortcode');

function mmja_render_just_added_shortcode($atts = []) {
    $raw = is_array($atts) ? $atts : [];
    $atts = shortcode_atts(
        [
            'title'     => 'Just Added',
            'limit'     => '10',
            'api'       => '',
            'watch_url' => '/watch/',
            'lang'      => '',
        ],
        $raw,
        'movie_just_added'
    );

    $lang = function_exists('mmba_snip_normalize_lang')
        ? mmba_snip_normalize_lang($atts['lang'])
        : (in_array(strtolower(trim((string) $atts['lang'])), ['bn', 'bengali', 'bangla'], true) ? 'bn' : '');

    if (function_exists('mmba_snip_apply_bn_url_defaults')) {
        $atts = mmba_snip_apply_bn_url_defaults($raw, $atts, [
            'watch_url' => '/bn/watch/',
        ]);
    } elseif ($lang === 'bn') {
        if (!array_key_exists('watch_url', $raw) || trim((string) $raw['watch_url']) === '') {
            $atts['watch_url'] = '/bn/watch/';
        }
    }

    $bn_ui = [
        'Just Added' => 'এইমাত্র যোগ',
        'Loading new movies…' => 'নতুন মুভি লোড হচ্ছে…',
        'No movies found.' => 'কোনো মুভি পাওয়া যায়নি।',
        'Could not load new movies.' => 'নতুন মুভি লোড করা যায়নি।',
        'Untitled' => 'শিরোনামহীন',
        'New Release' => 'নতুন রিলিজ',
        'Watch Now' => 'এখনই দেখুন',
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

    if ($lang === 'bn' && (!array_key_exists('title', $raw) || trim((string) $raw['title']) === '')) {
        $atts['title'] = $t('Just Added');
    }

    $uid = 'mmja-' . wp_unique_id();
    $limit = max(1, min(20, absint($atts['limit'])));
    $api = $atts['api'] !== ''
        ? esc_url_raw($atts['api'])
        : esc_url_raw(rest_url('movie-meta/v1/recent?limit=' . $limit));

    $watch_url = $atts['watch_url'];
    if ($watch_url !== '' && strpos($watch_url, 'http') !== 0) {
        $watch_url = home_url($watch_url);
    }
    $watch_url = esc_url($watch_url);

    $bn_genre_labels = [];
    if ($lang === 'bn' && function_exists('mmba_snip_genre')) {
        foreach (['Horror', 'Action', 'Drama', 'Comedy', 'Thriller', 'Romance', 'Crime', 'Animation', 'Adventure', 'Sci-Fi', 'War', 'Western', 'Documentary', 'Mystery', 'Fantasy', 'Family', 'Teen', 'Other'] as $gk) {
            $bn_genre_labels[$gk] = mmba_snip_genre($gk, 'bn');
        }
    }

    $bn_details_map = [];
    if ($lang === 'bn' && function_exists('mmba_snip_bn_details_map')) {
        $bn_details_map = mmba_snip_bn_details_map();
        if (!is_array($bn_details_map)) {
            $bn_details_map = [];
        }
    }

    $bootstrap = null;
    if (class_exists('MMBA_Storage')) {
        $movies = method_exists('MMBA_Storage', 'get_recent_movies')
            ? MMBA_Storage::get_recent_movies($limit)
            : MMBA_Storage::get_movies();
        if (!method_exists('MMBA_Storage', 'get_recent_movies')) {
            usort($movies, static function ($a, $b) {
                $ta = isset($a['created_at']) ? (string) $a['created_at'] : '';
                $tb = isset($b['created_at']) ? (string) $b['created_at'] : '';
                return strcmp($tb, $ta);
            });
            $movies = array_slice(array_values($movies), 0, $limit);
        }
        $enriched = [];
        foreach ($movies as $movie) {
            $link = isset($movie['movie_link']) ? (string) $movie['movie_link'] : '';
            $type = MMBA_Storage::get_movie_link_type($link);
            $movie['link_type']  = $type;
            $movie['embed_url']  = $type === 'embed' ? MMBA_Storage::get_embed_url($link) : $link;
            $movie['poster_url'] = MMBA_Storage::get_poster_url($link);
            // BN: prefer sheet column K for card synopsis.
            if ($lang === 'bn') {
                $mid = isset($movie['id']) ? (string) $movie['id'] : '';
                if ($mid !== '' && !empty($bn_details_map[$mid])) {
                    $movie['details'] = (string) $bn_details_map[$mid];
                    $movie['details_bn'] = (string) $bn_details_map[$mid];
                } elseif (function_exists('mmba_snip_details_for_lang')) {
                    $bn = mmba_snip_details_for_lang($movie, 'bn');
                    if ($bn !== '') {
                        $movie['details'] = $bn;
                        $movie['details_bn'] = $bn;
                    }
                }
            }
            $enriched[] = $movie;
        }
        $bootstrap = [
            'count'  => count($enriched),
            'movies' => $enriched,
        ];
    }

    // Slim id→BN details map for API-fetched cards.
    $bn_details_slim = [];
    if ($lang === 'bn' && !empty($bn_details_map) && !empty($bootstrap['movies'])) {
        foreach ($bootstrap['movies'] as $m) {
            $mid = isset($m['id']) ? (string) $m['id'] : '';
            if ($mid !== '' && !empty($bn_details_map[$mid])) {
                $bn_details_slim[$mid] = (string) $bn_details_map[$mid];
            }
        }
    }

    ob_start();
    ?>
<div
  id="<?php echo esc_attr($uid); ?>"
  class="mmja"
  data-api="<?php echo esc_attr($api); ?>"
  data-watch-url="<?php echo esc_attr($watch_url); ?>"
  data-limit="<?php echo esc_attr((string) $limit); ?>"
  data-title="<?php echo esc_attr($atts['title']); ?>"
  data-lang="<?php echo esc_attr($lang); ?>"
  data-i18n-empty="<?php echo esc_attr($t('No movies found.')); ?>"
  data-i18n-error="<?php echo esc_attr($t('Could not load new movies.')); ?>"
  data-i18n-untitled="<?php echo esc_attr($t('Untitled')); ?>"
  data-i18n-badge="<?php echo esc_attr($t('New Release')); ?>"
  data-i18n-watch="<?php echo esc_attr($t('Watch Now')); ?>"
  <?php if ($lang === 'bn' && !empty($bn_genre_labels)) : ?>
  data-genre-labels="<?php echo esc_attr(wp_json_encode($bn_genre_labels)); ?>"
  <?php endif; ?>
  <?php if ($lang === 'bn' && !empty($bn_details_slim)) : ?>
  data-bn-details="<?php echo esc_attr(wp_json_encode($bn_details_slim)); ?>"
  <?php endif; ?>
  <?php if ($bootstrap !== null) : ?>
  data-bootstrap="<?php echo esc_attr(wp_json_encode($bootstrap)); ?>"
  <?php endif; ?>
  aria-live="polite"
>
  <div class="mmja-loading"><?php echo esc_html($t('Loading new movies…')); ?></div>
</div>

<style>
  .mmja, .mmja *, .mmja *::before, .mmja *::after { box-sizing: border-box; }
  .mmja {
    --mmja-ink: #12151a;
    --mmja-muted: #6b7280;
    --mmja-line: rgba(18, 21, 26, 0.08);
    --mmja-accent: #2563eb;
    --mmja-radius: 20px;
    --mmja-cols: 3;
    --mmja-gap: 1.15rem;
    --mmja-font: "Sora", "Avenir Next", "Segoe UI", system-ui, sans-serif;
    color: var(--mmja-ink);
    font-family: var(--mmja-font);
    max-width: 100%;
    width: 100%;
    margin: 0 auto;
    padding: 1.25rem 1rem 2.5rem;
    overflow-x: clip;
    padding-bottom: 0;
  }
  .mmja-loading, .mmja-empty, .mmja-error {
    color: var(--mmja-muted);
    padding: 2rem 0.25rem;
    font-size: 0.95rem;
  }
  .mmja-error { color: #b91c1c; }
  .mmja-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem 1rem;
    margin-bottom: 1.1rem;
  }
  .mmja-title {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    margin: 0;
    font-size: clamp(1.05rem, 2.8vw, 1.35rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.25;
  }
  .mmja-title::before {
    content: "";
    width: 4px;
    height: 1.05em;
    border-radius: 999px;
    background: var(--mmja-accent);
    flex: 0 0 auto;
  }
  .mmja-controls {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex: 0 0 auto;
    padding-top: 0.15rem;
  }
  .mmja-nav {
    width: 34px;
    height: 34px;
    min-width: 34px;
    border-radius: 999px;
    border: 1px solid var(--mmja-line);
    background: #fff;
    color: var(--mmja-ink);
    display: inline-grid;
    place-items: center;
    cursor: pointer;
    padding: 0;
    -webkit-tap-highlight-color: transparent;
  }
  .mmja-nav:hover { border-color: var(--mmja-accent); color: var(--mmja-accent); }
  .mmja-nav:disabled { opacity: 0.35; cursor: default; }
  .mmja-nav svg { width: 16px; height: 16px; display: block; }
  .mmja-track-wrap { position: relative; margin: 0 -0.15rem; max-width: 100%; }
  .mmja-track {
    display: flex;
    gap: var(--mmja-gap);
    overflow-x: auto;
    overflow-y: hidden;
    scroll-snap-type: x mandatory;
    scroll-behavior: smooth;
    padding: 0.15rem 0.15rem 0.65rem;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior-x: contain;
    touch-action: pan-x;
    scrollbar-width: none;
    padding-bottom: 0;
  }
  .mmja-track::-webkit-scrollbar { display: none; }
  .mmja-card {
    flex: 0 0 calc((100% - (var(--mmja-gap) * (var(--mmja-cols) - 1))) / var(--mmja-cols));
    width: calc((100% - (var(--mmja-gap) * (var(--mmja-cols) - 1))) / var(--mmja-cols));
    height: 390px;
    min-height: 390px;
    max-width: none;
    scroll-snap-align: start;
    position: relative;
    display: flex;
    align-items: flex-end;
    overflow: hidden;
    border-radius: var(--mmja-radius);
    text-decoration: none;
    color: #fff;
    isolation: isolate;
    box-shadow: 0 10px 24px rgba(18, 21, 26, 0.12);
    -webkit-tap-highlight-color: transparent;
  }
  .mmja-bg {
    position: absolute;
    inset: 0;
    z-index: 0;
    background-size: cover;
    background-position: center;
  }
  .mmja-bg-img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .mmja-bg::after {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(8, 10, 14, 0.08) 20%, rgba(8, 10, 14, 0.82) 100%);
  }
  .mmja-tone-1 { background: linear-gradient(165deg, #22c55e 0%, #14532d 42%, #052e16 100%); }
  .mmja-tone-2 { background: linear-gradient(165deg, #3b82f6 0%, #1e3a8a 48%, #0b1220 100%); }
  .mmja-tone-3 { background: linear-gradient(165deg, #e11d48 0%, #7f1d1d 48%, #1c1014 100%); }
  .mmja-tone-4 { background: linear-gradient(165deg, #8b5cf6 0%, #4c1d95 48%, #140b22 100%); }
  .mmja-tone-5 { background: linear-gradient(165deg, #f59e0b 0%, #9a3412 48%, #1c1208 100%); }
  .mmja-tone-6 { background: linear-gradient(165deg, #14b8a6 0%, #115e59 48%, #042f2e 100%); }
  .mmja-body {
    position: relative;
    z-index: 1;
    width: 100%;
    padding: 1.25rem 1.25rem 1.2rem;
    display: grid;
    gap: 0.42rem;
    justify-items: start;
  }
  .mmja-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.22rem 0.55rem;
    border-radius: 6px;
    background: linear-gradient(90deg, #68ad69 0%, #3d7a7a 48%, #2d4c72 100%);
    color: #fff;
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    line-height: 1.2;
  }
  .mmja-card-title {
    margin: 0;
    font-size: clamp(1.15rem, 2.2vw, 1.45rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.2;
    color: #fff;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .mmja-card-desc {
    margin: 0;
    font-size: 0.82rem;
    line-height: 1.4;
    color: rgba(255, 255, 255, 0.92);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .mmja-watch {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin-top: 0.2rem;
    padding: 0.42rem 0.72rem;
    border-radius: 8px;
    background: rgba(12, 14, 18, 0.62);
    color: #fff;
    font-size: 0.8rem;
    font-weight: 600;
    line-height: 1;
  }
  .mmja-watch svg {
    width: 11px;
    height: 11px;
    display: block;
    fill: currentColor;
  }
  @media (hover: hover) {
    .mmja-card:hover { transform: translateY(-2px); }
    .mmja-card { transition: transform 0.2s ease; }
  }
  @media (max-width: 980px) {
    .mmja { --mmja-cols: 2; }
    .mmja-card { min-height: 390px; }
  }
  @media (max-width: 720px) {
    .mmja {
      --mmja-cols: 1;
      --mmja-radius: 16px;
      --mmja-gap: 0.8rem;
      padding: 1rem max(0.75rem, env(safe-area-inset-right)) 2rem max(0.75rem, env(safe-area-inset-left));
    }
    .mmja-card { min-height: 390px; height: 390px; }
    .mmja-card-desc { -webkit-line-clamp: 2; }
  }
  @media (max-width: 480px) {
    .mmja-nav { width: 38px; height: 38px; min-width: 38px; }
    .mmja-card { min-height: 390px; height: 390px; }
  }
  @media (prefers-reduced-motion: reduce) {
    .mmja-track { scroll-behavior: auto; }
    .mmja-card { transition: none; }
  }
</style>

<script>
(function () {
  'use strict';
  var root = document.getElementById(<?php echo wp_json_encode($uid); ?>);
  if (!root) return;

  var API = root.getAttribute('data-api') || '';
  var WATCH_URL = root.getAttribute('data-watch-url') || '/watch/';
  var LIMIT = parseInt(root.getAttribute('data-limit') || '10', 10) || 10;
  var TITLE = root.getAttribute('data-title') || 'Just Added';
  var LANG = root.getAttribute('data-lang') || '';
  var I18N_EMPTY = root.getAttribute('data-i18n-empty') || 'No movies found.';
  var I18N_ERROR = root.getAttribute('data-i18n-error') || 'Could not load new movies.';
  var I18N_UNTITLED = root.getAttribute('data-i18n-untitled') || 'Untitled';
  var I18N_BADGE = root.getAttribute('data-i18n-badge') || 'New Release';
  var I18N_WATCH = root.getAttribute('data-i18n-watch') || 'Watch Now';
  var GENRE_LABELS = {};
  try {
    GENRE_LABELS = JSON.parse(root.getAttribute('data-genre-labels') || '{}') || {};
  } catch (e) { GENRE_LABELS = {}; }
  var BN_DETAILS = {};
  try {
    BN_DETAILS = JSON.parse(root.getAttribute('data-bn-details') || '{}') || {};
  } catch (e) { BN_DETAILS = {}; }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function genreLabel(genre) {
    var g = String(genre || '').trim();
    if (!g) return '';
    if (LANG === 'bn') {
      if (GENRE_LABELS[g]) return GENRE_LABELS[g];
      var keys = Object.keys(GENRE_LABELS);
      for (var i = 0; i < keys.length; i++) {
        if (keys[i].toLowerCase() === g.toLowerCase()) return GENRE_LABELS[keys[i]];
      }
    }
    return g;
  }
  function tone(title) {
    var sum = 0, s = String(title || '');
    for (var i = 0; i < s.length; i++) sum += s.charCodeAt(i);
    return (sum % 6) + 1;
  }
  function watchHref(movie) {
    var base = WATCH_URL || '/watch/';
    var join = base.indexOf('?') === -1 ? '?' : '&';
    return base + join + 'id=' + encodeURIComponent(movie.id || '');
  }
  function blurb(movie) {
    var id = String(movie.id || '');
    // BN: prefer sheet column K (details_bn / BN map), then English details.
    var details = '';
    if (LANG === 'bn') {
      details = String(BN_DETAILS[id] || movie.details_bn || movie.details || '').replace(/\s+/g, ' ').trim();
    } else {
      details = String(movie.details || '').replace(/\s+/g, ' ').trim();
    }
    if (details) return details;
    var bits = [];
    if (movie.genre) {
      var g = String(movie.genre).split(',')[0].trim();
      if (g) bits.push(genreLabel(g));
    }
    if (movie.year) bits.push(movie.year);
    return bits.join(' · ');
  }
  function cardHtml(movie) {
    var title = movie.title || I18N_UNTITLED;
    var poster = movie.poster_url || '';
    var desc = blurb(movie);
    var href = watchHref(movie);
    var imgMeta = esc(title) + ' DesiMoviesHub Free Watch';
    var posterInner = poster
      ? '<img class="mmja-bg-img" src="' + esc(poster) + '" alt="' + imgMeta + '" title="' + imgMeta + '" loading="lazy">'
      : '';
    return (
      '<a class="mmja-card" href="' + esc(href) + '">' +
        '<div class="mmja-bg mmja-tone-' + tone(title) + '">' + posterInner + '</div>' +
        '<div class="mmja-body">' +
          '<span class="mmja-badge">' + esc(I18N_BADGE) + '</span>' +
          '<h3 class="mmja-card-title">' + esc(title) + '</h3>' +
          (desc ? '<p class="mmja-card-desc">' + esc(desc) + '</p>' : '') +
          '<span class="mmja-watch">' +
            '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>' +
            esc(I18N_WATCH) +
          '</span>' +
        '</div>' +
      '</a>'
    );
  }
  function sortRecent(movies) {
    return (movies || []).slice().sort(function (a, b) {
      var ta = Date.parse(a.created_at || a.updated_at || '') || 0;
      var tb = Date.parse(b.created_at || b.updated_at || '') || 0;
      return tb - ta;
    }).slice(0, LIMIT);
  }
  function wireCarousel() {
    var track = root.querySelector('.mmja-track');
    var prev = root.querySelector('.mmja-prev');
    var next = root.querySelector('.mmja-next');
    if (!track || !prev || !next) return;
    function update() {
      var max = track.scrollWidth - track.clientWidth - 2;
      prev.disabled = track.scrollLeft <= 2;
      next.disabled = track.scrollLeft >= max;
    }
    function step(dir) {
      track.scrollBy({ left: dir * Math.max(track.clientWidth * 0.85, 240), behavior: 'smooth' });
    }
    prev.addEventListener('click', function () { step(-1); });
    next.addEventListener('click', function () { step(1); });
    track.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
  }
  function render(movies) {
    movies = sortRecent(movies);
    if (!movies.length) {
      root.innerHTML = '<div class="mmja-empty">' + esc(I18N_EMPTY) + '</div>';
      return;
    }
    root.innerHTML =
      '<div class="mmja-head">' +
        '<h2 class="mmja-title">' + esc(TITLE) + '</h2>' +
        '<div class="mmja-controls">' +
          '<button type="button" class="mmja-nav mmja-prev" aria-label="Scroll left">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>' +
          '</button>' +
          '<button type="button" class="mmja-nav mmja-next" aria-label="Scroll right">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>' +
          '</button>' +
        '</div>' +
      '</div>' +
      '<div class="mmja-track-wrap"><div class="mmja-track" tabindex="0">' +
        movies.map(cardHtml).join('') +
      '</div></div>';
    wireCarousel();
  }

  var boot = root.getAttribute('data-bootstrap');
  if (boot) {
    try {
      var parsed = JSON.parse(boot);
      render(Array.isArray(parsed.movies) ? parsed.movies : []);
    } catch (e) {}
  }

  var live = API + (API.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
  fetch(live, { credentials: 'same-origin', cache: 'no-store' })
    .then(function (r) {
      if (!r.ok) throw new Error('API ' + r.status);
      return r.json();
    })
    .then(function (data) {
      var list = Array.isArray(data.movies) ? data.movies : [];
      render(list);
    })
    .catch(function (err) {
      if (root.querySelector('.mmja-track')) return;
      root.innerHTML = '<div class="mmja-error">' + esc(I18N_ERROR) + ' (' + esc(err.message) + ')</div>';
    });
})();
</script>
    <?php
    return ob_get_clean();
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

if (!function_exists('mmba_snip_details_for_lang')) {
    function mmba_snip_details_for_lang(array $movie, $lang = '') {
        $lang = mmba_snip_normalize_lang($lang);
        $en = isset($movie['details']) ? (string) $movie['details'] : '';
        if ($lang !== 'bn') {
            return $en;
        }
        if (!empty($movie['details_bn'])) {
            return (string) $movie['details_bn'];
        }
        $id = isset($movie['id']) ? (string) $movie['id'] : '';
        $bn = $id !== '' ? mmba_snip_lookup_details_bn($id) : '';
        return $bn !== '' ? $bn : $en;
    }
}

if (!function_exists('mmba_snip_lookup_details_bn')) {
    function mmba_snip_lookup_details_bn($movie_id) {
        $map = mmba_snip_bn_details_map();
        $id = (string) $movie_id;
        return isset($map[$id]) ? (string) $map[$id] : '';
    }
}

if (!function_exists('mmba_snip_bn_details_map')) {
    function mmba_snip_bn_details_map() {
        $cached = get_transient('mmba_snip_bn_details_map');
        if (is_array($cached)) {
            return $cached;
        }
        $map = mmba_snip_fetch_bn_details_map();
        if (!is_array($map)) {
            $map = [];
        }
        set_transient('mmba_snip_bn_details_map', $map, 10 * MINUTE_IN_SECONDS);
        return $map;
    }
}

if (!function_exists('mmba_snip_fetch_bn_details_map')) {
    function mmba_snip_fetch_bn_details_map() {
        $token = mmba_snip_google_access_token();
        if (!is_string($token) || $token === '') {
            return [];
        }
        $sheet_id = (class_exists('MMBA_Sheets') && method_exists('MMBA_Sheets', 'spreadsheet_id'))
            ? MMBA_Sheets::spreadsheet_id()
            : '1g5I-9IPvlWQe72jkDYe4T-UNWWy5XLfEeoDAjHw28B8';
        $range = 'A1:K5000';
        $url = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s',
            rawurlencode($sheet_id),
            rawurlencode($range)
        );
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ]);
        if (is_wp_error($response)) {
            return [];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return [];
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $values = (is_array($body) && isset($body['values']) && is_array($body['values'])) ? $body['values'] : [];
        if (empty($values)) {
            return [];
        }
        $start = 0;
        if (!empty($values[0]) && is_array($values[0])) {
            $first = array_map(static function ($c) {
                return strtolower(trim((string) $c));
            }, $values[0]);
            if (in_array('title', $first, true) || in_array('type', $first, true)) {
                $start = 1;
            }
        }
        $map = [];
        $total = count($values);
        for ($r = $start; $r < $total; $r++) {
            $line = is_array($values[$r]) ? $values[$r] : [];
            $type = strtolower(trim(isset($line[0]) ? (string) $line[0] : ''));
            $title = trim(isset($line[1]) ? (string) $line[1] : '');
            $link_raw = trim(isset($line[4]) ? (string) $line[4] : '');
            $details_bn = trim(isset($line[10]) ? (string) $line[10] : '');
            if ($title === '' || $details_bn === '') {
                continue;
            }
            $is_series = ($type === 'series' || $type === 'tv' || $type === 'show');
            if ($is_series) {
                $seed = strtolower(preg_replace('/\s+/', ' ', $title));
                $id = 's' . substr(md5($seed), 0, 15);
            } else {
                if ($link_raw === '') {
                    continue;
                }
                $link = class_exists('MMBA_Storage') && method_exists('MMBA_Storage', 'sanitize_stream_url')
                    ? MMBA_Storage::sanitize_stream_url($link_raw)
                    : $link_raw;
                if ($link === '') {
                    continue;
                }
                $id = 'm' . substr(md5(strtolower(trim($title . '|' . $link))), 0, 15);
            }
            if (!isset($map[$id]) || $map[$id] === '') {
                $map[$id] = $details_bn;
            }
        }
        return $map;
    }
}

if (!function_exists('mmba_snip_google_access_token')) {
    function mmba_snip_google_access_token() {
        $cached = get_transient('mmba_gs_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        if (!class_exists('MMBA_Sheets') || !method_exists('MMBA_Sheets', 'credentials_path')) {
            return '';
        }
        $path = MMBA_Sheets::credentials_path();
        if (!is_readable($path)) {
            return '';
        }
        $data = null;
        if (substr($path, -4) === '.php') {
            $data = include $path;
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $raw = file_get_contents($path);
            $data = json_decode((string) $raw, true);
        }
        if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            return '';
        }
        $now = time();
        $b64 = static function ($payload) {
            return rtrim(strtr(base64_encode((string) $payload), '+/', '-_'), '=');
        };
        $header = $b64(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64(wp_json_encode([
            'iss'   => $data['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $unsigned = $header . '.' . $claims;
        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $data['private_key'], OPENSSL_ALGO_SHA256);
        if (!$ok || $signature === '') {
            return '';
        }
        $jwt = $unsigned . '.' . $b64($signature);
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ]);
        if (is_wp_error($response)) {
            return '';
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $token = is_array($body) && !empty($body['access_token']) ? (string) $body['access_token'] : '';
        if ($token === '') {
            return '';
        }
        $ttl = isset($body['expires_in']) ? max(60, ((int) $body['expires_in']) - 60) : 3300;
        set_transient('mmba_gs_token', $token, $ttl);
        return $token;
    }
}
