<?php
/**
 * Code Snippets plugin — paste this as a PHP snippet (Run everywhere).
 *
 * Shortcode: [movie_genre_rows]
 * Optional:  [movie_genre_rows genre="Action"]
 *            [movie_genre_rows genres="Action,Horror"]
 *            [movie_genre_rows watch_url="/watch/" genre_url="/Genre/" per_row="10"]
 *
 * Requires: Movie Meta plugin (data source).
 * Poster clicks → /watch/?id=MOVIE_ID
 * "View all" → /Genre/Horror  (pair with movie-genre-page + genre-pretty-urls-seo)
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('movie_genre_rows', 'mmgr_render_genre_rows_shortcode');

function mmgr_render_genre_rows_shortcode($atts = []) {
    $raw = is_array($atts) ? $atts : [];
    $genre_filter = isset($raw['genre']) ? trim((string) $raw['genre']) : '';
    $genres_filter = isset($raw['genres']) ? trim((string) $raw['genres']) : '';

    $atts = shortcode_atts(
        [
            'genre'     => '',
            'genres'    => 'Horror,Action,Drama,Comedy,Thriller,Romance,Crime,Animation,Adventure,Sci-Fi,War,Western,Documentary,Mystery,Fantasy,Family',
            'new_days'  => '45',
            'api'       => '',
            'watch_url' => '/watch/',
            'genre_url' => '/Genre/',
            'per_row'   => '10',
        ],
        $raw,
        'movie_genre_rows'
    );

    $filter_list = $genre_filter !== '' ? $genre_filter : $genres_filter;
    $genre_only = $filter_list !== '';
    if ($genre_only) {
        $atts['genres'] = $filter_list;
    }

    $uid = 'mmgr-' . wp_unique_id();
    $api = $atts['api'] !== ''
        ? esc_url_raw($atts['api'])
        : esc_url_raw(rest_url('movie-meta/v1/movies'));

    $watch_url = $atts['watch_url'];
    if ($watch_url !== '' && strpos($watch_url, 'http') !== 0) {
        $watch_url = home_url($watch_url);
    }
    $watch_url = esc_url($watch_url);

    // Prefer absolute pretty base from SEO snippet helpers when present.
    $genre_url = $atts['genre_url'];
    if (function_exists('mmba_genre_pretty_url')) {
        // Base path only; JS appends slug. Strip a sample genre path → /Genre/
        $sample = mmba_genre_pretty_url('Action');
        $genre_url = preg_replace('#/Action/?$#i', '/', $sample);
        $genre_url = untrailingslashit($genre_url) . '/';
    } elseif ($genre_url !== '' && strpos($genre_url, 'http') !== 0) {
        $genre_url = home_url($genre_url);
    }
    $genre_url = esc_url($genre_url);

    $per_row = max(1, absint($atts['per_row']));
    if ($per_row < 1) {
        $per_row = 10;
    }

    // Prefer server-side data when plugin is active (faster, no extra request).
    $bootstrap = null;
    if (class_exists('MMBA_Storage')) {
        $movies = MMBA_Storage::get_movies();
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
  class="mmgr"
  data-api="<?php echo esc_attr($api); ?>"
  data-watch-url="<?php echo esc_attr($watch_url); ?>"
  data-genre-url="<?php echo esc_attr($genre_url); ?>"
  data-per-row="<?php echo esc_attr((string) $per_row); ?>"
  data-genres="<?php echo esc_attr($atts['genres']); ?>"
  data-only="<?php echo $genre_only ? '1' : '0'; ?>"
  data-new-days="<?php echo esc_attr($atts['new_days']); ?>"
  <?php if ($bootstrap !== null) : ?>
  data-bootstrap="<?php echo esc_attr(wp_json_encode($bootstrap)); ?>"
  <?php endif; ?>
  aria-live="polite"
>
  <div class="mmgr-loading"><?php echo esc_html__('Loading movies…', 'movie-meta-by-aris'); ?></div>
</div>

<style>
  .mmgr, .mmgr *, .mmgr *::before, .mmgr *::after { box-sizing: border-box; }
  .mmgr {
    --mmgr-ink: #12151a;
    --mmgr-muted: #6b7280;
    --mmgr-line: rgba(18, 21, 26, 0.08);
    --mmgr-accent: #2563eb;
    --mmgr-new: #e11d48;
    --mmgr-badge: rgba(15, 18, 22, 0.78);
    --mmgr-radius: 14px;
    --mmgr-card-w: 180px;
    --mmgr-gap: 1.1rem;
    --mmgr-font: "Sora", "Avenir Next", "Segoe UI", system-ui, sans-serif;
    color: var(--mmgr-ink);
    font-family: var(--mmgr-font);
    max-width: 1200px;
    width: 100%;
    margin: 0 auto;
    padding: 1.25rem 1rem 2.5rem;
    overflow-x: clip;
  }
  .mmgr-loading, .mmgr-empty, .mmgr-error {
    color: var(--mmgr-muted);
    padding: 2rem 0.25rem;
    font-size: 0.95rem;
  }
  .mmgr-error { color: #b91c1c; }
  .mmgr-row { margin: 0 0 2.35rem; min-width: 0; }
  .mmgr-row-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem 1rem;
    margin-bottom: 0.95rem;
  }
  .mmgr-row-titles { min-width: 0; flex: 1 1 auto; }
  .mmgr-row-title {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    margin: 0;
    font-size: clamp(1.05rem, 2.8vw, 1.35rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.25;
  }
  .mmgr-row-title::before {
    content: "";
    width: 4px;
    height: 1.05em;
    border-radius: 999px;
    background: var(--mmgr-accent);
    flex: 0 0 auto;
  }
  .mmgr-row-desc {
    margin: 0.35rem 0 0 0.9rem;
    color: var(--mmgr-muted);
    font-size: 0.88rem;
    line-height: 1.45;
  }
  .mmgr-row-controls {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex: 0 0 auto;
    padding-top: 0.15rem;
  }
  .mmgr-viewall {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
    color: var(--mmgr-muted);
    font-size: 0.86rem;
    font-weight: 600;
    white-space: nowrap;
    margin-right: 0.15rem;
    text-decoration: none;
    padding: 0.28rem 0.45rem;
    border-radius: 999px;
    transition: color 0.15s ease, background 0.15s ease;
  }
  .mmgr-viewall:hover {
    color: var(--mmgr-accent);
    background: rgba(37, 99, 235, 0.08);
  }
  .mmgr-nav {
    width: 34px;
    height: 34px;
    min-width: 34px;
    border-radius: 999px;
    border: 1px solid var(--mmgr-line);
    background: #fff;
    color: var(--mmgr-ink);
    display: inline-grid;
    place-items: center;
    cursor: pointer;
    padding: 0;
    -webkit-tap-highlight-color: transparent;
  }
  .mmgr-nav:hover { border-color: var(--mmgr-accent); color: var(--mmgr-accent); }
  .mmgr-nav:disabled { opacity: 0.35; cursor: default; }
  .mmgr-nav svg { width: 16px; height: 16px; display: block; }
  .mmgr-track-wrap { position: relative; margin: 0 -0.15rem; max-width: 100%; }
  .mmgr-track {
    display: flex;
    gap: var(--mmgr-gap);
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
  .mmgr-track::-webkit-scrollbar { display: none; }
  .mmgr-card {
    flex: 0 0 var(--mmgr-card-w);
    width: var(--mmgr-card-w);
    max-width: 72vw;
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
  .mmgr-poster {
    position: relative;
    aspect-ratio: 2 / 3;
    border-radius: var(--mmgr-radius);
    overflow: hidden;
    box-shadow: 0 10px 24px rgba(18, 21, 26, 0.08);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }
  @media (hover: hover) {
    .mmgr-card:hover .mmgr-poster {
      transform: translateY(-2px);
      box-shadow: 0 14px 28px rgba(18, 21, 26, 0.12);
    }
  }
  .mmgr-poster-img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .mmgr-poster-fallback {
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
  .mmgr-tone-1 { background: linear-gradient(145deg, #1b3a4b 0%, #0f7a6c 55%, #163a34 100%); }
  .mmgr-tone-2 { background: linear-gradient(145deg, #2b2118 0%, #b45309 55%, #3f2a14 100%); }
  .mmgr-tone-3 { background: linear-gradient(145deg, #1e293b 0%, #334155 50%, #0f172a 100%); }
  .mmgr-tone-4 { background: linear-gradient(145deg, #312e81 0%, #0e7490 55%, #164e63 100%); }
  .mmgr-tone-5 { background: linear-gradient(145deg, #3f1d2e 0%, #9f1239 50%, #1f2937 100%); }
  .mmgr-tone-6 { background: linear-gradient(145deg, #14532d 0%, #166534 45%, #052e16 100%); }
  .mmgr-badge {
    position: absolute;
    z-index: 2;
    padding: 0.22rem 0.5rem;
    border-radius: 999px;
    background: var(--mmgr-badge);
    color: #fff;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    line-height: 1.2;
  }
  .mmgr-badge-hd { top: 0.55rem; left: 0.55rem; }
  .mmgr-badge-meta {
    top: 0.55rem;
    right: 0.55rem;
    max-width: 46%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .mmgr-badge-new { bottom: 0.55rem; left: 0.55rem; background: var(--mmgr-new); }
  .mmgr-card-body { padding: 0.7rem 0.15rem 0; }
  .mmgr-card-title {
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
  .mmgr-card-meta {
    margin: 0.35rem 0 0;
    color: var(--mmgr-muted);
    font-size: 0.78rem;
    line-height: 1.35;
  }
  @media (max-width: 900px) {
    .mmgr { --mmgr-card-w: 160px; --mmgr-gap: 0.95rem; }
  }
  @media (max-width: 720px) {
    .mmgr {
      --mmgr-card-w: 142px;
      --mmgr-gap: 0.8rem;
      --mmgr-radius: 12px;
      padding: 1rem max(0.75rem, env(safe-area-inset-right)) 2rem max(0.75rem, env(safe-area-inset-left));
    }
    .mmgr-row { margin-bottom: 1.75rem; }
    .mmgr-row-desc { display: none; }
    .mmgr-viewall { font-size: 0.8rem; margin-right: 0; padding: 0.25rem 0.4rem; }
    .mmgr-card-title { font-size: 0.86rem; }
    .mmgr-card-meta { font-size: 0.72rem; }
    .mmgr-badge { font-size: 0.62rem; padding: 0.18rem 0.42rem; }
  }
  @media (max-width: 480px) {
    .mmgr {
      --mmgr-card-w: 126px;
      --mmgr-gap: 0.7rem;
      padding-left: max(0.65rem, env(safe-area-inset-left));
      padding-right: max(0.65rem, env(safe-area-inset-right));
    }
    .mmgr-row-head {
      flex-wrap: wrap;
      align-items: center;
    }
    .mmgr-row-controls {
      margin-left: auto;
      padding-top: 0;
    }
    .mmgr-nav {
      width: 38px;
      height: 38px;
      min-width: 38px;
    }
    .mmgr-card { max-width: 44vw; }
  }
  @media (prefers-reduced-motion: reduce) {
    .mmgr-track { scroll-behavior: auto; }
    .mmgr-poster { transition: none; }
  }
</style>

<script>
(function () {
  'use strict';
  var root = document.getElementById(<?php echo wp_json_encode($uid); ?>);
  if (!root) return;

  var API = root.getAttribute('data-api') || '';
  var WATCH_URL = root.getAttribute('data-watch-url') || '/watch/';
  var GENRE_URL = root.getAttribute('data-genre-url') || '/Genre/';
  var PER_ROW = parseInt(root.getAttribute('data-per-row') || '10', 10) || 10;
  var NEW_DAYS = parseInt(root.getAttribute('data-new-days') || '45', 10) || 45;
  var GENRE_ONLY = root.getAttribute('data-only') === '1';
  var GENRE_SLUG = {
    'sci-fi': 'Sci-fi',
    'scifi': 'Sci-fi',
    'sci fi': 'Sci-fi',
    'lgbtq': 'lgbtq',
    'lgbtq+': 'lgbtq',
    'lgbt': 'lgbtq',
    'horror': 'Horror',
    'animation': 'Animation',
    'comedy': 'Comedy',
    'action': 'Action',
    'romance': 'Romance',
    'teen': 'Teen',
    'adventure': 'Adventure',
    'drama': 'Drama',
    'family': 'Family',
    'western': 'Western',
    'war': 'War',
    'fantasy': 'Fantasy',
    'thriller': 'Thriller',
    'crime': 'Crime',
    'documentary': 'Documentary',
    'mystery': 'Mystery'
  };
  var GENRE_ORDER = (root.getAttribute('data-genres') || '')
    .split(',').map(function (s) { return s.trim(); }).filter(Boolean);

  var DESCRIPTIONS = {
    Horror: 'Scary stories, ghosts, and late-night thrills.',
    Action: 'Fights, chases, and high-stakes missions.',
    Drama: 'Character-driven stories with emotional weight.',
    Comedy: 'Laughs, rom-coms, and light entertainment.',
    Thriller: 'Tension, twists, and edge-of-seat plots.',
    Romance: 'Love stories and heartfelt connections.',
    Crime: 'Heists, gangs, and underworld drama.',
    Animation: 'Animated features for all ages.',
    Adventure: 'Journeys, quests, and discovery.',
    'Sci-Fi': 'Futures, tech, and speculative worlds.',
    War: 'Battlefield stories and wartime drama.',
    Western: 'Frontier tales and dusty showdowns.',
    Documentary: 'Real stories from the world.',
    Mystery: 'Secrets, clues, and unanswered questions.',
    Fantasy: 'Magic, myths, and imagined worlds.',
    Family: 'Movies to watch together.'
  };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function splitGenres(genre) {
    return String(genre || '').split(',').map(function (g) { return g.trim(); }).filter(Boolean);
  }
  function primaryGenre(movie) {
    var parts = splitGenres(movie.genre);
    return parts[0] || 'Other';
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
    return t ? t.charAt(0).toUpperCase() : 'M';
  }
  function metaLine(movie) {
    var bits = [];
    if (movie.year) bits.push(movie.year);
    var g = primaryGenre(movie);
    if (g && g !== 'Other') bits.push(g);
    return bits.join(' · ');
  }
  function groupByGenre(movies) {
    var map = {};
    movies.forEach(function (m) {
      var genres = splitGenres(m.genre);
      if (!genres.length) genres = ['Other'];
      genres.forEach(function (g) {
        if (!map[g]) map[g] = [];
        if (map[g].some(function (x) { return x.id === m.id; })) return;
        map[g].push(m);
      });
    });
    return map;
  }
  function findGenreKey(map, wanted) {
    var needle = String(wanted || '').trim().toLowerCase();
    if (!needle) return '';
    var keys = Object.keys(map);
    for (var i = 0; i < keys.length; i++) {
      if (keys[i].toLowerCase() === needle) return keys[i];
    }
    return '';
  }
  function orderedGenreKeys(map) {
    var ordered = [];
    GENRE_ORDER.forEach(function (g) {
      var key = map[g] ? g : findGenreKey(map, g);
      if (key && map[key] && map[key].length && ordered.indexOf(key) === -1) {
        ordered.push(key);
      }
    });
    if (!GENRE_ONLY) {
      Object.keys(map).sort().forEach(function (g) {
        if (ordered.indexOf(g) === -1) ordered.push(g);
      });
    }
    return ordered;
  }
  function watchHref(movie) {
    var base = WATCH_URL || '/watch/';
    var join = base.indexOf('?') === -1 ? '?' : '&';
    return base + join + 'id=' + encodeURIComponent(movie.id || '');
  }
  function genreSlug(genre) {
    var g = String(genre || '').trim();
    if (!g) return '';
    var mapped = GENRE_SLUG[g.toLowerCase()];
    return mapped || g;
  }
  function genreHref(genre) {
    var slug = genreSlug(genre);
    if (!slug) return GENRE_URL || '/Genre/';
    // Sheet special-case: Teen lives at /Teen (not /Genre/Teen).
    if (slug.toLowerCase() === 'teen') {
      try {
        var u = new URL(GENRE_URL || (location.origin + '/Genre/'), location.origin);
        return u.origin + '/Teen/';
      } catch (e) {
        return '/Teen/';
      }
    }
    var base = GENRE_URL || '/Genre/';
    if (base.charAt(base.length - 1) !== '/') base += '/';
    return base + encodeURIComponent(slug) + '/';
  }
  function cardHtml(movie) {
    var poster = movie.poster_url || '';
    var title = movie.title || 'Untitled';
    var href = watchHref(movie);
    var posterInner = poster
      ? '<img class="mmgr-poster-img" src="' + esc(poster) + '" alt="" loading="lazy" onerror="this.remove();var f=this.parentNode.querySelector(\'.mmgr-poster-fallback\');if(f)f.hidden=false;">' +
        '<span class="mmgr-poster-fallback" hidden>' + esc(initial(title)) + '</span>'
      : '<span class="mmgr-poster-fallback">' + esc(initial(title)) + '</span>';
    return (
      '<a class="mmgr-card" href="' + esc(href) + '">' +
        '<div class="mmgr-poster mmgr-tone-' + tone(title) + '">' +
          posterInner +
          '<span class="mmgr-badge mmgr-badge-hd">HD</span>' +
          (movie.year ? '<span class="mmgr-badge mmgr-badge-meta">' + esc(movie.year) + '</span>' : '') +
          (isNew(movie) ? '<span class="mmgr-badge mmgr-badge-new">NEW</span>' : '') +
        '</div>' +
        '<div class="mmgr-card-body">' +
          '<h3 class="mmgr-card-title">' + esc(title) + '</h3>' +
          '<p class="mmgr-card-meta">' + esc(metaLine(movie)) + '</p>' +
        '</div>' +
      '</a>'
    );
  }
  function rowHtml(genre, movies) {
    var desc = DESCRIPTIONS[genre] || ('Browse ' + genre.toLowerCase() + ' titles from the catalog.');
    var visible = movies.slice(0, PER_ROW);
    var allHref = genreHref(genre);
    var showViewAll = movies.length > 0;
    return (
      '<section class="mmgr-row" data-genre="' + esc(genre) + '">' +
        '<div class="mmgr-row-head">' +
          '<div class="mmgr-row-titles">' +
            '<h2 class="mmgr-row-title">' + esc(genre) + ' Movies</h2>' +
            '<p class="mmgr-row-desc">' + esc(desc) + '</p>' +
          '</div>' +
          '<div class="mmgr-row-controls">' +
            (showViewAll
              ? '<a class="mmgr-viewall" href="' + esc(allHref) + '">View all</a>'
              : '') +
            '<button type="button" class="mmgr-nav mmgr-prev" aria-label="Scroll left">' +
              '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>' +
            '</button>' +
            '<button type="button" class="mmgr-nav mmgr-next" aria-label="Scroll right">' +
              '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>' +
            '</button>' +
          '</div>' +
        '</div>' +
        '<div class="mmgr-track-wrap"><div class="mmgr-track" tabindex="0">' +
          visible.map(cardHtml).join('') +
        '</div></div>' +
      '</section>'
    );
  }
  function wireCarousel(section) {
    var track = section.querySelector('.mmgr-track');
    var prev = section.querySelector('.mmgr-prev');
    var next = section.querySelector('.mmgr-next');
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
    if (!movies.length) {
      root.innerHTML = '<div class="mmgr-empty">No movies found.</div>';
      return;
    }
    var grouped = groupByGenre(movies);
    var keys = orderedGenreKeys(grouped);
    if (!keys.length) {
      root.innerHTML = '<div class="mmgr-empty">No movies found for this genre.</div>';
      return;
    }
    root.innerHTML = keys.map(function (g) { return rowHtml(g, grouped[g]); }).join('');
    root.querySelectorAll('.mmgr-row').forEach(wireCarousel);
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
      root.innerHTML = '<div class="mmgr-error">Could not load movies. (' + esc(err.message) + ')</div>';
    });
})();
</script>
    <?php
    return ob_get_clean();
}
