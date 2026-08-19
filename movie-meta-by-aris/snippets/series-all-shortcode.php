<?php
/**
 * Code Snippets plugin — paste this as a PHP snippet (Run everywhere).
 *
 * Shortcode: [movie_series]
 * Optional:  [movie_series home_url="/" watch_url="/series-watch/"]
 *
 * Create a WP page at /series/ and put [movie_series] in the content.
 * Series rows "View all" links here.
 *
 * Requires: Movie Meta plugin (data source).
 * Pair with snippets/series-rows-shortcode.php → [movie_series_rows]
 * Pair with snippets/series-watch-shortcode.php → [series_watch]
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('movie_series', 'mmsa_render_series_all_shortcode');

function mmsa_render_series_all_shortcode($atts = []) {
    $atts = shortcode_atts(
        [
            'home_url'  => '/',
            'watch_url' => '/series-watch/',
        ],
        $atts,
        'movie_series'
    );

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

    if (!class_exists('MMBA_Storage')) {
        return '<div class="mmsa mmsa-error">' . esc_html__('Movie Meta plugin is required.', 'movie-meta-by-aris') . '</div>';
    }

    $series = method_exists('MMBA_Storage', 'get_series')
        ? MMBA_Storage::get_series()
        : [];
    if (empty($series)) {
        foreach (MMBA_Storage::get_movies() as $movie) {
            if (isset($movie['type']) && $movie['type'] === 'series') {
                $series[] = $movie;
            }
        }
    }

    usort($series, static function ($a, $b) {
        return strcmp(
            isset($a['title']) ? (string) $a['title'] : '',
            isset($b['title']) ? (string) $b['title'] : ''
        );
    });

    $count = count($series);

    ob_start();
    ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&display=swap">
<div class="mmsa">
  <div class="mmsa-shell">
    <nav class="mmsa-nav" aria-label="<?php echo esc_attr__('Series navigation', 'movie-meta-by-aris'); ?>">
      <a class="mmsa-back" href="<?php echo esc_url($home_url); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
        <span><?php echo esc_html__('Back to movies', 'movie-meta-by-aris'); ?></span>
      </a>
    </nav>

    <header class="mmsa-header">
      <p class="mmsa-kicker"><?php echo esc_html__('Catalog', 'movie-meta-by-aris'); ?></p>
      <h1 class="mmsa-title"><?php echo esc_html__('Series', 'movie-meta-by-aris'); ?></h1>
      <p class="mmsa-count">
        <?php
        echo esc_html(
            sprintf(
                _n('%d series', '%d series', $count, 'movie-meta-by-aris'),
                $count
            )
        );
        ?>
      </p>
    </header>

    <?php if ($count === 0) : ?>
      <div class="mmsa-empty-state"><?php echo esc_html__('No series found.', 'movie-meta-by-aris'); ?></div>
    <?php else : ?>
      <div class="mmsa-grid">
        <?php foreach ($series as $movie) :
            $id = isset($movie['id']) ? (string) $movie['id'] : '';
            $title = isset($movie['title']) ? (string) $movie['title'] : '';
            $year = isset($movie['year']) ? (string) $movie['year'] : '';
            $poster = MMBA_Storage::movie_poster_url($movie);
            $href = $watch_url . (strpos($watch_url, '?') === false ? '?' : '&') . 'id=' . rawurlencode($id);
            $initial = $title !== '' ? strtoupper(substr($title, 0, 1)) : 'S';
            $tone = mmsa_poster_tone($title);
            $display = $title !== '' ? $title : __('Untitled', 'movie-meta-by-aris');
            $eps = isset($movie['episode_count']) ? (int) $movie['episode_count'] : 0;
            $meta_bits = array_filter([
                $year,
                $eps > 0 ? sprintf(_n('%d episode', '%d episodes', $eps, 'movie-meta-by-aris'), $eps) : '',
            ]);
            ?>
          <a class="mmsa-card" href="<?php echo esc_url($href); ?>">
            <div class="mmsa-poster mmsa-tone-<?php echo (int) $tone; ?>">
              <?php if ($poster !== '') : ?>
                <img class="mmsa-poster-img" src="<?php echo esc_url($poster); ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                  onerror="this.style.display='none';var f=this.parentNode.querySelector('.mmsa-poster-fallback');if(f){f.hidden=false;f.removeAttribute('hidden');}">
                <div class="mmsa-poster-fallback" hidden aria-hidden="true">
                  <span class="mmsa-poster-letter"><?php echo esc_html($initial); ?></span>
                </div>
              <?php else : ?>
                <div class="mmsa-poster-fallback" aria-hidden="true">
                  <span class="mmsa-poster-letter"><?php echo esc_html($initial); ?></span>
                </div>
              <?php endif; ?>
              <span class="mmsa-badge mmsa-badge-hd">HD</span>
              <?php if ($year !== '') : ?>
                <span class="mmsa-badge mmsa-badge-year"><?php echo esc_html($year); ?></span>
              <?php endif; ?>
            </div>
            <div class="mmsa-card-body">
              <h2 class="mmsa-card-title"><?php echo esc_html($display); ?></h2>
              <?php if (!empty($meta_bits)) : ?>
                <p class="mmsa-card-meta"><?php echo esc_html(implode(' · ', $meta_bits)); ?></p>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
  .mmsa, .mmsa *, .mmsa *::before, .mmsa *::after { box-sizing: border-box; }
  .mmsa {
    --mmsa-font: "Sora", "Avenir Next", "Segoe UI", system-ui, sans-serif;
    width: 100%;
    max-width: 100%;
    overflow-x: clip;
    color: #12151a !important;
    font-family: var(--mmsa-font);
    padding: 1rem max(0.55rem, env(safe-area-inset-right)) 3rem max(0.55rem, env(safe-area-inset-left));
    background: #f3f5f8 !important;
  }
  .mmsa-shell {
    max-width: 1120px;
    width: 100%;
    margin: 0 auto;
    padding: 1.25rem 1.15rem 1.75rem;
    background: #ffffff !important;
    border: 1px solid rgba(18, 21, 26, 0.08);
    border-radius: 18px;
    box-shadow: 0 12px 36px rgba(18, 21, 26, 0.06);
    color: #12151a !important;
  }
  .mmsa-nav { margin: 0 0 1.15rem; }
  .mmsa-back {
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
  .mmsa-back svg { width: 18px; height: 18px; display: block; stroke: currentColor; }
  .mmsa-back:hover { color: #12151a !important; background: #eef1f5; }
  .mmsa-header { margin: 0 0 1.35rem; }
  .mmsa-kicker {
    margin: 0 0 0.4rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #1d4ed8 !important;
  }
  .mmsa-title {
    margin: 0;
    font-size: clamp(1.45rem, 4vw, 2.2rem);
    font-weight: 700;
    letter-spacing: -0.03em;
    line-height: 1.15;
    color: #12151a !important;
  }
  .mmsa-count {
    margin: 0.45rem 0 0;
    color: #4b5563 !important;
    font-size: 0.92rem;
    font-weight: 500;
  }
  .mmsa-empty-state {
    padding: 2rem 0.5rem;
    color: #6b7280 !important;
    font-size: 0.95rem;
  }
  .mmsa-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(148px, 1fr));
    gap: 1.15rem 0.95rem;
  }
  .mmsa-card {
    text-decoration: none !important;
    color: inherit;
    min-width: 0;
    -webkit-tap-highlight-color: transparent;
    transition: transform 0.2s ease;
  }
  @media (hover: hover) {
    .mmsa-card:hover { transform: translateY(-3px); }
  }
  .mmsa-poster {
    position: relative;
    aspect-ratio: 2 / 3;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 10px 24px rgba(18, 21, 26, 0.1);
    background-color: #1e293b;
  }
  .mmsa-poster-img {
    position: absolute;
    inset: 0;
    z-index: 1;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .mmsa-poster-fallback {
    position: absolute;
    inset: 0;
    z-index: 0;
    display: grid;
    place-items: center;
  }
  .mmsa-poster-fallback[hidden] { display: none !important; }
  .mmsa-poster-letter {
    font-size: clamp(2rem, 8vw, 3rem);
    font-weight: 700;
    color: #ffffff !important;
    opacity: 0.88;
    text-shadow: 0 6px 24px rgba(0, 0, 0, 0.35);
  }
  .mmsa-tone-1 { background: linear-gradient(145deg, #1b3a4b 0%, #0f7a6c 55%, #163a34 100%) !important; }
  .mmsa-tone-2 { background: linear-gradient(145deg, #2b2118 0%, #b45309 55%, #3f2a14 100%) !important; }
  .mmsa-tone-3 { background: linear-gradient(145deg, #1e293b 0%, #64748b 50%, #0f172a 100%) !important; }
  .mmsa-tone-4 { background: linear-gradient(145deg, #312e81 0%, #0e7490 55%, #164e63 100%) !important; }
  .mmsa-tone-5 { background: linear-gradient(145deg, #3f1d2e 0%, #e11d48 50%, #1f2937 100%) !important; }
  .mmsa-tone-6 { background: linear-gradient(145deg, #14532d 0%, #22c55e 45%, #052e16 100%) !important; }
  .mmsa-badge {
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
  .mmsa-badge-hd { top: 0.5rem; left: 0.5rem; }
  .mmsa-badge-year { bottom: 0.5rem; left: 0.5rem; font-weight: 600; }
  .mmsa-card-body { padding: 0.65rem 0.1rem 0; }
  .mmsa-card-title {
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
  .mmsa-card-meta {
    margin: 0.3rem 0 0;
    color: #4b5563 !important;
    font-size: 0.76rem;
  }
  .mmsa-empty, .mmsa-error {
    max-width: 1120px;
    margin: 0 auto;
    padding: 2rem 1rem;
    color: #4b5563 !important;
    font-family: var(--mmsa-font, system-ui, sans-serif);
    background: #fff;
    border-radius: 12px;
  }
  .mmsa-error { color: #b91c1c !important; }
  @media (max-width: 720px) {
    .mmsa-shell { padding: 1rem 0.85rem 1.25rem; border-radius: 14px; }
    .mmsa-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.9rem 0.65rem;
    }
    .mmsa-card-title { font-size: 0.8rem; }
    .mmsa-poster { border-radius: 12px; }
  }
  @media (max-width: 420px) {
    .mmsa-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  }
</style>
    <?php
    return ob_get_clean();
}

function mmsa_poster_tone($title) {
    $sum = 0;
    $s = (string) $title;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $sum += ord($s[$i]);
    }
    return ($sum % 6) + 1;
}
