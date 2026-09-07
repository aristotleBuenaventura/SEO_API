/**
 * Code Snippets plugin — paste this as a PHP snippet (Run everywhere).
 *
 * Shortcode: [movie_top10]
 * Optional:  [movie_top10 title="Top 10 Movies" limit="10" watch_url="/watch/"]
 *            [movie_top10 lang="bn"]  → links to /bn/watch/
 *
 * Requires: Movie Meta plugin 1.9.3+ (movies-only top ranking + watch view tracking).
 * Ranked by unique /watch/?id= views. Poster clicks → /watch/?id=MOVIE_ID
 * (or /bn/watch/ when lang="bn")
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('movie_top10', 'mmt10_render_top10_shortcode');

function mmt10_render_top10_shortcode($atts = []) {
    $raw = is_array($atts) ? $atts : [];
    $atts = shortcode_atts(
        [
            'title'     => 'Top 10 Movies',
            'limit'     => '10',
            'api'       => '',
            'watch_url' => '/watch/',
            'lang'      => '',
        ],
        $raw,
        'movie_top10'
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
        'Top 10 Movies' => 'টপ ১০ মুভি',
        'Loading top movies…' => 'টপ মুভি লোড হচ্ছে…',
        'No movies found.' => 'কোনো মুভি পাওয়া যায়নি।',
        'Could not load top movies.' => 'টপ মুভি লোড করা যায়নি।',
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

    if ($lang === 'bn' && (!array_key_exists('title', $raw) || trim((string) $raw['title']) === '')) {
        $atts['title'] = $t('Top 10 Movies');
    }

    $uid = 'mmt10-' . wp_unique_id();
    $limit = max(1, min(20, absint($atts['limit'])));
    $api = $atts['api'] !== ''
        ? esc_url_raw($atts['api'])
        : esc_url_raw(rest_url('movie-meta/v1/top?type=movie&limit=' . $limit));

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

    $bootstrap = null;
    if (class_exists('MMBA_Storage') && method_exists('MMBA_Storage', 'get_top_movies')) {
        $movies = MMBA_Storage::get_top_movies($limit, 'movie');
        if (!is_array($movies)) {
            $movies = [];
        }
        $movies = array_values(array_filter($movies, static function ($movie) {
            if (!is_array($movie)) {
                return false;
            }
            $type = isset($movie['type']) ? (string) $movie['type'] : '';
            if ($type !== '') {
                return strcasecmp($type, 'series') !== 0;
            }
            return empty($movie['episodes']) && empty($movie['season_count']) && empty($movie['episode_count']);
        }));
        $movies = array_slice($movies, 0, $limit);
        $enriched = [];
        foreach ($movies as $movie) {
            $link = isset($movie['movie_link']) ? (string) $movie['movie_link'] : '';
            $type = MMBA_Storage::get_movie_link_type($link);
            $movie['link_type']  = $type;
            $movie['embed_url']  = $type === 'embed' ? MMBA_Storage::get_embed_url($link) : $link;
            $movie['poster_url'] = MMBA_Storage::movie_poster_url($movie);
            $enriched[] = $movie;
        }
        $bootstrap = [
            'count'  => count($enriched),
            'movies' => $enriched,
        ];
    }

    ob_start();
    ?>
<div
  id="<?php echo esc_attr($uid); ?>"
  class="mmt10"
  data-api="<?php echo esc_attr($api); ?>"
  data-watch-url="<?php echo esc_attr($watch_url); ?>"
  data-limit="<?php echo esc_attr((string) $limit); ?>"
  data-title="<?php echo esc_attr($atts['title']); ?>"
  data-lang="<?php echo esc_attr($lang); ?>"
  data-i18n-empty="<?php echo esc_attr($t('No movies found.')); ?>"
  data-i18n-error="<?php echo esc_attr($t('Could not load top movies.')); ?>"
  data-i18n-untitled="<?php echo esc_attr($t('Untitled')); ?>"
  <?php if ($lang === 'bn' && !empty($bn_genre_labels)) : ?>
  data-genre-labels="<?php echo esc_attr(wp_json_encode($bn_genre_labels)); ?>"
  <?php endif; ?>
  <?php if ($bootstrap !== null) : ?>
  data-bootstrap="<?php echo esc_attr(wp_json_encode($bootstrap)); ?>"
  <?php endif; ?>
  aria-live="polite"
>
  <div class="mmt10-loading"><?php echo esc_html($t('Loading top movies…')); ?></div>
</div>

<style>
  .mmt10, .mmt10 *, .mmt10 *::before, .mmt10 *::after { box-sizing: border-box; }
  .mmt10 {
    --mmt10-ink: #12151a;
    --mmt10-muted: #6b7280;
    --mmt10-line: rgba(18, 21, 26, 0.08);
    --mmt10-accent: #2563eb;
    --mmt10-rank-from: #68ad69;
    --mmt10-rank-mid: #3d7a7a;
    --mmt10-rank-to: #2d4c72;
    --mmt10-radius: 14px;
    --mmt10-card-w: 168px;
    --mmt10-gap: 0.35rem;
    --mmt10-font: "Sora", "Avenir Next", "Segoe UI", system-ui, sans-serif;
    color: var(--mmt10-ink);
    font-family: var(--mmt10-font);
    max-width: 100%;
    width: 100%;
    margin: 0 auto;
    padding: 1.25rem 1rem 2.5rem;
    overflow-x: clip;
    background: url(/wp-content/uploads/2026/08/top-mov-bg.jpg) no-repeat 0 0;
    background-size: cover;
    border-radius: 20px;
  }
  .mmt10-loading, .mmt10-empty, .mmt10-error {
    color: var(--mmt10-muted);
    padding: 2rem 0.25rem;
    font-size: 0.95rem;
  }
  .mmt10-error { color: #b91c1c; }
  .mmt10-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem 1rem;
    margin-bottom: 1.1rem;
  }
  .mmt10-title {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    margin: 0;
    font-size: clamp(1.05rem, 2.8vw, 1.35rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.25;
    color: #fff;
  }
  .mmt10-title::before {
    content: "";
    width: 4px;
    height: 1.05em;
    border-radius: 999px;
    background: var(--mmt10-accent);
    flex: 0 0 auto;
  }
  .mmt10-controls {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex: 0 0 auto;
    padding-top: 0.15rem;
  }
  .mmt10-nav {
    width: 34px;
    height: 34px;
    min-width: 34px;
    border-radius: 999px;
    border: 1px solid var(--mmt10-line);
    background: #fff;
    color: var(--mmt10-ink);
    display: inline-grid;
    place-items: center;
    cursor: pointer;
    padding: 0;
    -webkit-tap-highlight-color: transparent;
  }
  .mmt10-nav:hover { border-color: var(--mmt10-accent); color: var(--mmt10-accent); }
  .mmt10-nav:disabled { opacity: 0.35; cursor: default; }
  .mmt10-nav svg { width: 16px; height: 16px; display: block; }
  .mmt10-track-wrap { position: relative; margin: 0 -0.15rem; max-width: 100%; }
  .mmt10-track {
    display: flex;
    gap: var(--mmt10-gap);
    overflow-x: auto;
    overflow-y: hidden;
    scroll-snap-type: x mandatory;
    scroll-behavior: smooth;
    padding: 0.15rem 0.15rem 0.85rem;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior-x: contain;
    touch-action: pan-x;
    scrollbar-width: none;
  }
  .mmt10-track::-webkit-scrollbar { display: none; }
  .mmt10-card {
    flex: 0 0 auto;
    width: calc(var(--mmt10-card-w) + 3.1rem);
    max-width: 78vw;
    scroll-snap-align: start;
    text-decoration: none;
    color: inherit;
    background: transparent;
    border: 0;
    padding: 0;
    text-align: left;
    cursor: pointer;
    font: inherit;
    -webkit-tap-highlight-color: transparent;
  }
  .mmt10-visual {
    position: relative;
    display: block;
    min-height: calc(var(--mmt10-card-w) * 1.5);
  }
  .mmt10-rank {
    position: absolute;
    left: 0;
    bottom: -0.35em;
    z-index: 3;
    margin: 0;
    font-size: clamp(5.4rem, 16vw, 7.1rem);
    font-weight: 800;
    line-height: normal;
    letter-spacing: -0.08em;
    font-variant-numeric: lining-nums;
    -webkit-text-fill-color: transparent;
    filter: drop-shadow(0 4px 10px rgba(18, 21, 26, 0.28));
    pointer-events: none;
    user-select: none;
    font-family: "Poppins", sans-serif;
    width: 100%;
    background: url(/wp-content/uploads/2026/08/texture.jpg);
    -webkit-background-clip: text;
    -moz-background-clip: text;
    background-clip: text;
    color: transparent;
  }
  .mmt10-poster {
    position: relative;
    z-index: 1;
    width: var(--mmt10-card-w);
    max-width: 58vw;
    margin-left: 2.55rem;
    aspect-ratio: 2 / 3;
    border-radius: var(--mmt10-radius);
    overflow: hidden;
    box-shadow: 0 10px 24px rgba(18, 21, 26, 0.1);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    border: 1px #fff solid;
  }
  @media (hover: hover) {
    .mmt10-card:hover .mmt10-poster {
      transform: translateY(-2px);
      box-shadow: 0 14px 28px rgba(18, 21, 26, 0.14);
    }
  }
  .mmt10-poster-img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .mmt10-poster-fallback {
    position: absolute;
    inset: 0;
    display: grid;
    place-items: center;
    font-size: clamp(2rem, 10vw, 3.2rem);
    font-weight: 700;
    color: rgba(255, 255, 255, 0.18);
    letter-spacing: -0.04em;
    user-select: none;
  }
  .mmt10-tone-1 { background: linear-gradient(145deg, #1b3a4b 0%, #0f7a6c 55%, #163a34 100%); }
  .mmt10-tone-2 { background: linear-gradient(145deg, #2b2118 0%, #b45309 55%, #3f2a14 100%); }
  .mmt10-tone-3 { background: linear-gradient(145deg, #1e293b 0%, #334155 50%, #0f172a 100%); }
  .mmt10-tone-4 { background: linear-gradient(145deg, #312e81 0%, #0e7490 55%, #164e63 100%); }
  .mmt10-tone-5 { background: linear-gradient(145deg, #3f1d2e 0%, #9f1239 50%, #1f2937 100%); }
  .mmt10-tone-6 { background: linear-gradient(145deg, #14532d 0%, #166534 45%, #052e16 100%); }
  .mmt10-body { padding: 0.7rem 0.15rem 0 2.55rem; }
  .mmt10-card-title {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 700;
    line-height: 1.3;
    letter-spacing: -0.01em;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    color: #fff;
  }
  .mmt10-card-meta {
    margin: 0.35rem 0 0;
    color: var(--mmt10-muted);
    font-size: 0.78rem;
    line-height: 1.35;
    color: #fff;
  }
  @media (max-width: 900px) {
    .mmt10 { --mmt10-card-w: 150px; }
  }
  @media (max-width: 720px) {
    .mmt10 {
      --mmt10-card-w: 132px;
      --mmt10-radius: 12px;
      padding: 1rem max(0.75rem, env(safe-area-inset-right)) 2rem max(0.75rem, env(safe-area-inset-left));
    }
    .mmt10-card { width: calc(var(--mmt10-card-w) + 2.6rem); }
    .mmt10-poster { margin-left: 2.15rem; }
    .mmt10-body { padding-left: 2.15rem; }
    .mmt10-card-title { font-size: 0.86rem; }
    .mmt10-card-meta { font-size: 0.72rem; }
  }
  @media (max-width: 480px) {
    .mmt10 { --mmt10-card-w: 118px; }
    .mmt10-nav { width: 38px; height: 38px; min-width: 38px; }
  }
  @media (prefers-reduced-motion: reduce) {
    .mmt10-track { scroll-behavior: auto; }
    .mmt10-poster { transition: none; }
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
  var TITLE = root.getAttribute('data-title') || 'Top 10 Movies';
  var LANG = root.getAttribute('data-lang') || '';
  var I18N_EMPTY = root.getAttribute('data-i18n-empty') || 'No movies found.';
  var I18N_ERROR = root.getAttribute('data-i18n-error') || 'Could not load top movies.';
  var I18N_UNTITLED = root.getAttribute('data-i18n-untitled') || 'Untitled';
  var GENRE_LABELS = {};
  try {
    GENRE_LABELS = JSON.parse(root.getAttribute('data-genre-labels') || '{}') || {};
  } catch (e) { GENRE_LABELS = {}; }

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
  function splitGenres(genre) {
    return String(genre || '').split(',').map(function (g) { return g.trim(); }).filter(Boolean);
  }
  function primaryGenre(movie) {
    var parts = splitGenres(movie.genre);
    return parts[0] || '';
  }
  function tone(title) {
    var sum = 0, s = String(title || '');
    for (var i = 0; i < s.length; i++) sum += s.charCodeAt(i);
    return (sum % 6) + 1;
  }
  function initial(title) {
    var t = String(title || '').trim();
    return t ? t.charAt(0).toUpperCase() : 'M';
  }
  function metaLine(movie) {
    var bits = [];
    var g = primaryGenre(movie);
    if (g) bits.push(genreLabel(g));
    if (movie.year) bits.push(movie.year);
    return bits.join(' · ');
  }
  function watchHref(movie) {
    var base = WATCH_URL || '/watch/';
    var join = base.indexOf('?') === -1 ? '?' : '&';
    return base + join + 'id=' + encodeURIComponent(movie.id || '');
  }
  function cardHtml(movie, rank) {
    var poster = movie.poster_url || '';
    var title = movie.title || I18N_UNTITLED;
    var href = watchHref(movie);
    var imgMeta = esc(title) + ' DesiMoviesHub Free Watch';
    var posterInner = poster
      ? '<img class="mmt10-poster-img" src="' + esc(poster) + '" alt="' + imgMeta + '" title="' + imgMeta + '" loading="lazy" onerror="this.remove();var f=this.parentNode.querySelector(\'.mmt10-poster-fallback\');if(f)f.hidden=false;">' +
        '<span class="mmt10-poster-fallback" hidden>' + esc(initial(title)) + '</span>'
      : '<span class="mmt10-poster-fallback">' + esc(initial(title)) + '</span>';
    return (
      '<a class="mmt10-card" href="' + esc(href) + '">' +
        '<div class="mmt10-visual">' +
          '<span class="mmt10-rank" aria-hidden="true">' + rank + '</span>' +
          '<div class="mmt10-poster mmt10-tone-' + tone(title) + '">' + posterInner + '</div>' +
        '</div>' +
        '<div class="mmt10-body">' +
          '<h3 class="mmt10-card-title">' + esc(title) + '</h3>' +
          '<p class="mmt10-card-meta">' + esc(metaLine(movie)) + '</p>' +
        '</div>' +
      '</a>'
    );
  }
  function wireCarousel() {
    var track = root.querySelector('.mmt10-track');
    var prev = root.querySelector('.mmt10-prev');
    var next = root.querySelector('.mmt10-next');
    if (!track || !prev || !next) return;
    function update() {
      var max = track.scrollWidth - track.clientWidth - 2;
      prev.disabled = track.scrollLeft <= 2;
      next.disabled = track.scrollLeft >= max;
    }
    function step(dir) {
      track.scrollBy({ left: dir * Math.max(track.clientWidth * 0.85, 200), behavior: 'smooth' });
    }
    prev.addEventListener('click', function () { step(-1); });
    next.addEventListener('click', function () { step(1); });
    track.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
  }
  function render(movies) {
    movies = (movies || []).filter(function (movie) {
      var type = String((movie && movie.type) || '').toLowerCase();
      if (type) return type !== 'series';
      return !movie || (!movie.episodes && !movie.season_count && !movie.episode_count);
    }).slice(0, LIMIT);
    if (!movies.length) {
      root.innerHTML = '<div class="mmt10-empty">' + esc(I18N_EMPTY) + '</div>';
      return;
    }
    root.innerHTML =
      '<div class="mmt10-head">' +
        '<h2 class="mmt10-title">' + esc(TITLE) + '</h2>' +
        '<div class="mmt10-controls">' +
          '<button type="button" class="mmt10-nav mmt10-prev" aria-label="Scroll left">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>' +
          '</button>' +
          '<button type="button" class="mmt10-nav mmt10-next" aria-label="Scroll right">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>' +
          '</button>' +
        '</div>' +
      '</div>' +
      '<div class="mmt10-track-wrap"><div class="mmt10-track" tabindex="0">' +
        movies.map(function (m, i) { return cardHtml(m, i + 1); }).join('') +
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
      render(Array.isArray(data.movies) ? data.movies : []);
    })
    .catch(function (err) {
      if (root.querySelector('.mmt10-track')) return;
      root.innerHTML = '<div class="mmt10-error">' + esc(I18N_ERROR) + ' (' + esc(err.message) + ')</div>';
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
