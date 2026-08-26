/**
 * Code Snippets plugin — paste this as a PHP snippet (Run everywhere).
 *
 * Shortcode: [movie_series_rows]
 * Optional:  [movie_series_rows title="Series" limit="10" watch_url="/series-watch/" all_url="/series/"]
 *
 * Requires: Movie Meta plugin (data source).
 * Poster clicks → /series-watch/?id=SERIES_ID (starts on oldest episode)
 * "View all" → /series/  (pair with snippets/series-all-shortcode.php)
 * Pair with snippets/series-watch-shortcode.php → [series_watch]
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('movie_series_rows', 'mmsr_render_series_rows_shortcode');

function mmsr_render_series_rows_shortcode($atts = []) {
    $atts = shortcode_atts(
        [
            'title'     => 'Series',
            'limit'     => '10',
            'new_days'  => '45',
            'api'       => '',
            'watch_url' => '/series-watch/',
            'all_url'   => '/series/',
        ],
        $atts,
        'movie_series_rows'
    );

    $uid = 'mmsr-' . wp_unique_id();
    $limit = max(1, min(40, absint($atts['limit'])));
    $api = $atts['api'] !== ''
        ? esc_url_raw($atts['api'])
        : esc_url_raw(rest_url('movie-meta/v1/movies?type=series'));

    $watch_url = $atts['watch_url'];
    if ($watch_url !== '' && strpos($watch_url, 'http') !== 0) {
        $watch_url = home_url($watch_url);
    }
    $watch_url = esc_url($watch_url);

    $all_url = $atts['all_url'];
    if ($all_url !== '' && strpos($all_url, 'http') !== 0) {
        $all_url = home_url($all_url);
    }
    $all_url = esc_url($all_url);

    $bootstrap = null;
    if (class_exists('MMBA_Storage')) {
        $series = method_exists('MMBA_Storage', 'get_series')
            ? MMBA_Storage::get_series()
            : [];
        if (empty($series) && method_exists('MMBA_Storage', 'get_movies')) {
            foreach (MMBA_Storage::get_movies() as $movie) {
                if (isset($movie['type']) && $movie['type'] === 'series') {
                    $series[] = $movie;
                }
            }
        }
        $enriched = [];
        foreach ($series as $movie) {
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
  class="mmsr"
  data-api="<?php echo esc_attr($api); ?>"
  data-watch-url="<?php echo esc_attr($watch_url); ?>"
  data-all-url="<?php echo esc_attr($all_url); ?>"
  data-limit="<?php echo esc_attr((string) $limit); ?>"
  data-title="<?php echo esc_attr($atts['title']); ?>"
  data-new-days="<?php echo esc_attr($atts['new_days']); ?>"
  <?php if ($bootstrap !== null) : ?>
  data-bootstrap="<?php echo esc_attr(wp_json_encode($bootstrap)); ?>"
  <?php endif; ?>
  aria-live="polite"
>
  <div class="mmsr-loading"><?php echo esc_html__('Loading series…', 'movie-meta-by-aris'); ?></div>
</div>

<style>
  .mmsr, .mmsr *, .mmsr *::before, .mmsr *::after { box-sizing: border-box; }
  .mmsr {
    --mmsr-ink: #12151a;
    --mmsr-muted: #6b7280;
    --mmsr-line: rgba(18, 21, 26, 0.08);
    --mmsr-accent: #2563eb;
    --mmsr-new: #e11d48;
    --mmsr-badge: rgba(15, 18, 22, 0.78);
    --mmsr-radius: 14px;
    --mmsr-card-w: 180px;
    --mmsr-gap: 1.1rem;
    --mmsr-font: "Sora", "Avenir Next", "Segoe UI", system-ui, sans-serif;
    color: var(--mmsr-ink);
    font-family: var(--mmsr-font);
    max-width: 100%;
    width: 100%;
    margin: 0 auto;
    padding: 1.25rem 1rem 2.5rem;
    overflow-x: clip;
  }
  .mmsr-loading, .mmsr-empty, .mmsr-error {
    color: var(--mmsr-muted);
    padding: 2rem 0.25rem;
    font-size: 0.95rem;
  }
  .mmsr-error { color: #b91c1c; }
  .mmsr-row { margin: 0; min-width: 0; }
  .mmsr-row-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem 1rem;
    margin-bottom: 0.95rem;
  }
  .mmsr-row-titles { min-width: 0; flex: 1 1 auto; }
  .mmsr-row-title {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    margin: 0;
    font-size: clamp(1.05rem, 2.8vw, 1.35rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.25;
  }
  .mmsr-row-title::before {
    content: "";
    width: 4px;
    height: 1.05em;
    border-radius: 999px;
    background: var(--mmsr-accent);
    flex: 0 0 auto;
  }
  .mmsr-row-desc {
    margin: 0.35rem 0 0 0.9rem;
    color: var(--mmsr-muted);
    font-size: 0.88rem;
    line-height: 1.45;
  }
  .mmsr-row-controls {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex: 0 0 auto;
    padding-top: 0.15rem;
  }
  .mmsr-viewall {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
    color: var(--mmsr-muted);
    font-size: 0.86rem;
    font-weight: 600;
    white-space: nowrap;
    margin-right: 0.15rem;
    text-decoration: none;
    padding: 0.28rem 0.45rem;
    border-radius: 999px;
    transition: color 0.15s ease, background 0.15s ease;
  }
  .mmsr-viewall:hover {
    color: var(--mmsr-accent);
    background: rgba(37, 99, 235, 0.08);
  }
  .mmsr-nav {
    width: 34px;
    height: 34px;
    min-width: 34px;
    border-radius: 999px;
    border: 1px solid var(--mmsr-line);
    background: #fff;
    color: var(--mmsr-ink);
    display: inline-grid;
    place-items: center;
    cursor: pointer;
    padding: 0;
    -webkit-tap-highlight-color: transparent;
  }
  .mmsr-nav:hover { border-color: var(--mmsr-accent); color: var(--mmsr-accent); }
  .mmsr-nav:disabled { opacity: 0.35; cursor: default; }
  .mmsr-nav svg { width: 16px; height: 16px; display: block; }
  .mmsr-track-wrap { position: relative; margin: 0 -0.15rem; max-width: 100%; }
  .mmsr-track {
    display: flex;
    gap: var(--mmsr-gap);
    overflow-x: auto;
    overflow-y: hidden;
    scroll-snap-type: x mandatory;
    scroll-behavior: smooth;
    padding: 0.15rem 0.15rem 0.65rem;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior-x: contain;
    touch-action: pan-x;
    scrollbar-width: none;
  }
  .mmsr-track::-webkit-scrollbar { display: none; }
  .mmsr-card {
    flex: 0 0 var(--mmsr-card-w);
    width: var(--mmsr-card-w);
    max-width: 72vw;
    scroll-snap-align: start;
    text-decoration: none;
    color: inherit;
    -webkit-tap-highlight-color: transparent;
  }
  .mmsr-poster {
    position: relative;
    aspect-ratio: 2 / 3;
    border-radius: var(--mmsr-radius);
    overflow: hidden;
    box-shadow: 0 10px 24px rgba(18, 21, 26, 0.08);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }
  @media (hover: hover) {
    .mmsr-card:hover .mmsr-poster {
      transform: translateY(-2px);
      box-shadow: 0 14px 28px rgba(18, 21, 26, 0.12);
    }
  }
  .mmsr-poster-img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .mmsr-poster-fallback {
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
  .mmsr-tone-1 { background: linear-gradient(145deg, #1b3a4b 0%, #0f7a6c 55%, #163a34 100%); }
  .mmsr-tone-2 { background: linear-gradient(145deg, #2b2118 0%, #b45309 55%, #3f2a14 100%); }
  .mmsr-tone-3 { background: linear-gradient(145deg, #1e293b 0%, #334155 50%, #0f172a 100%); }
  .mmsr-tone-4 { background: linear-gradient(145deg, #312e81 0%, #0e7490 55%, #164e63 100%); }
  .mmsr-tone-5 { background: linear-gradient(145deg, #3f1d2e 0%, #9f1239 50%, #1f2937 100%); }
  .mmsr-tone-6 { background: linear-gradient(145deg, #14532d 0%, #166534 45%, #052e16 100%); }
  .mmsr-badge {
    position: absolute;
    z-index: 2;
    padding: 0.22rem 0.5rem;
    border-radius: 999px;
    background: var(--mmsr-badge);
    color: #fff;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    line-height: 1.2;
  }
  .mmsr-badge-hd { top: 0.55rem; left: 0.55rem; }
  .mmsr-badge-meta {
    top: 0.55rem;
    right: 0.55rem;
    max-width: 46%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .mmsr-badge-new { bottom: 0.55rem; left: 0.55rem; background: var(--mmsr-new); }
  .mmsr-card-body { padding: 0.7rem 0.15rem 0; }
  .mmsr-card-title {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 700;
    line-height: 1.3;
    letter-spacing: -0.01em;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .mmsr-card-meta {
    margin: 0.35rem 0 0;
    color: var(--mmsr-muted);
    font-size: 0.78rem;
    line-height: 1.35;
  }
  @media (max-width: 900px) {
    .mmsr { --mmsr-card-w: 160px; --mmsr-gap: 0.95rem; }
  }
  @media (max-width: 720px) {
    .mmsr {
      --mmsr-card-w: 142px;
      --mmsr-gap: 0.8rem;
      --mmsr-radius: 12px;
      padding: 1rem max(0.75rem, env(safe-area-inset-right)) 2rem max(0.75rem, env(safe-area-inset-left));
    }
    .mmsr-row-desc { display: none; }
    .mmsr-viewall { font-size: 0.8rem; margin-right: 0; padding: 0.25rem 0.4rem; }
    .mmsr-card-title { font-size: 0.86rem; }
    .mmsr-card-meta { font-size: 0.72rem; }
    .mmsr-badge { font-size: 0.62rem; padding: 0.18rem 0.42rem; }
  }
  @media (max-width: 480px) {
    .mmsr {
      --mmsr-card-w: 126px;
      --mmsr-gap: 0.7rem;
      padding-left: max(0.65rem, env(safe-area-inset-left));
      padding-right: max(0.65rem, env(safe-area-inset-right));
    }
    .mmsr-row-head { flex-wrap: wrap; align-items: center; }
    .mmsr-row-controls { margin-left: auto; padding-top: 0; }
    .mmsr-nav { width: 38px; height: 38px; min-width: 38px; }
    .mmsr-card { max-width: 44vw; }
  }
  @media (prefers-reduced-motion: reduce) {
    .mmsr-track { scroll-behavior: auto; }
    .mmsr-poster { transition: none; }
  }
</style>

<script>
(function () {
  'use strict';
  var root = document.getElementById(<?php echo wp_json_encode($uid); ?>);
  if (!root) return;

  var API = root.getAttribute('data-api') || '';
  var WATCH_URL = root.getAttribute('data-watch-url') || '/series-watch/';
  var ALL_URL = root.getAttribute('data-all-url') || '/series/';
  var LIMIT = parseInt(root.getAttribute('data-limit') || '10', 10) || 10;
  var NEW_DAYS = parseInt(root.getAttribute('data-new-days') || '45', 10) || 45;
  var TITLE = root.getAttribute('data-title') || 'Series';

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function isNew(movie) {
    var t = Date.parse(movie.created_at || movie.updated_at || '');
    if (!t) return false;
    return (Date.now() - t) <= NEW_DAYS * 86400000;
  }
  function tone(title) {
    var sum = 0, s = String(title || '');
    for (var i = 0; i < s.length; i++) sum += s.charCodeAt(i);
    return (sum % 6) + 1;
  }
  function initial(title) {
    var t = String(title || '').trim();
    return t ? t.charAt(0).toUpperCase() : 'S';
  }
  function metaLine(movie) {
    var bits = [];
    if (movie.year) bits.push(movie.year);
    var seasons = parseInt(movie.season_count || 0, 10);
    var eps = parseInt(movie.episode_count || 0, 10);
    if (seasons > 0) bits.push(seasons === 1 ? '1 season' : seasons + ' seasons');
    else if (eps > 0) bits.push(eps === 1 ? '1 episode' : eps + ' episodes');
    return bits.join(' · ');
  }
  function watchHref(movie) {
    var base = WATCH_URL || '/series-watch/';
    var join = base.indexOf('?') === -1 ? '?' : '&';
    return base + join + 'id=' + encodeURIComponent(movie.id || '');
  }
  function cardHtml(movie) {
    var poster = movie.poster_url || '';
    var title = movie.title || 'Untitled';
    var href = watchHref(movie);
    var imgMeta = esc(title) + ' DesiMoviesHub Free Watch';
    var posterInner = poster
      ? '<img class="mmsr-poster-img" src="' + esc(poster) + '" alt="' + imgMeta + '" title="' + imgMeta + '" loading="lazy" onerror="this.remove();var f=this.parentNode.querySelector(\'.mmsr-poster-fallback\');if(f)f.hidden=false;">' +
        '<span class="mmsr-poster-fallback" hidden>' + esc(initial(title)) + '</span>'
      : '<span class="mmsr-poster-fallback">' + esc(initial(title)) + '</span>';
    return (
      '<a class="mmsr-card" href="' + esc(href) + '">' +
        '<div class="mmsr-poster mmsr-tone-' + tone(title) + '">' +
          posterInner +
          '<span class="mmsr-badge mmsr-badge-hd">HD</span>' +
          (movie.year ? '<span class="mmsr-badge mmsr-badge-meta">' + esc(movie.year) + '</span>' : '') +
          (isNew(movie) ? '<span class="mmsr-badge mmsr-badge-new">NEW</span>' : '') +
        '</div>' +
        '<div class="mmsr-card-body">' +
          '<h3 class="mmsr-card-title">' + esc(title) + '</h3>' +
          '<p class="mmsr-card-meta">' + esc(metaLine(movie)) + '</p>' +
        '</div>' +
      '</a>'
    );
  }
  function wireCarousel(section) {
    var track = section.querySelector('.mmsr-track');
    var prev = section.querySelector('.mmsr-prev');
    var next = section.querySelector('.mmsr-next');
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
    var series = (movies || []).filter(function (m) { return (m.type || '') === 'series'; });
    if (!series.length) {
      root.innerHTML = '<div class="mmsr-empty">No series found.</div>';
      return;
    }
    var visible = series.slice(0, LIMIT);
    root.innerHTML =
      '<section class="mmsr-row">' +
        '<div class="mmsr-row-head">' +
          '<div class="mmsr-row-titles">' +
            '<h2 class="mmsr-row-title">' + esc(TITLE) + '</h2>' +
            '<p class="mmsr-row-desc">TV shows and series from the catalog.</p>' +
          '</div>' +
          '<div class="mmsr-row-controls">' +
            '<a class="mmsr-viewall" href="' + esc(ALL_URL) + '">View all</a>' +
            '<button type="button" class="mmsr-nav mmsr-prev" aria-label="Scroll left">' +
              '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>' +
            '</button>' +
            '<button type="button" class="mmsr-nav mmsr-next" aria-label="Scroll right">' +
              '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>' +
            '</button>' +
          '</div>' +
        '</div>' +
        '<div class="mmsr-track-wrap"><div class="mmsr-track" tabindex="0">' +
          visible.map(cardHtml).join('') +
        '</div></div>' +
      '</section>';
    wireCarousel(root.querySelector('.mmsr-row'));
  }

  var boot = root.getAttribute('data-bootstrap');
  if (boot) {
    try {
      var parsed = JSON.parse(boot);
      render(Array.isArray(parsed.movies) ? parsed.movies : []);
      return;
    } catch (e) {}
  }

  fetch(API, { credentials: 'same-origin' })
    .then(function (r) {
      if (!r.ok) throw new Error('API ' + r.status);
      return r.json();
    })
    .then(function (data) {
      render(Array.isArray(data.movies) ? data.movies : []);
    })
    .catch(function (err) {
      root.innerHTML = '<div class="mmsr-error">Could not load series. (' + esc(err.message) + ')</div>';
    });
})();
</script>
    <?php
    return ob_get_clean();
}
