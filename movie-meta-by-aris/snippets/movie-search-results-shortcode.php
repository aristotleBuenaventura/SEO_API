<?php
/**
 * Code Snippets plugin — paste this as a PHP snippet (Run everywhere).
 *
 * Shortcode: [movie_search_results]
 * Optional:
 *   [movie_search_results home_url="/" watch_url="/watch/" series_watch_url="/series-watch/" q_param="q" limit="24" min_chars="2"]
 *
 * How it works:
 * - Reads the search term from GET param `q_param` (default: `q`).
 * - Filters the Movie Meta catalog and shows both `movie` and `series` matches.
 * - Pair with snippets/movie-search-bar-shortcode.php → [movie_search_bar]
 *   so the form navigates here.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('movie_search_results', 'mmsrch_render_search_results_shortcode');

function mmsrch_render_search_results_shortcode($atts = []) {
    $atts = shortcode_atts(
        [
            'home_url' => '/',
            'watch_url' => '/watch/',
            'series_watch_url' => '/series-watch/',
            'q_param' => 'q',
            'limit' => '24',
            'min_chars' => '2',
        ],
        $atts,
        'movie_search_results'
    );

    $home_url = (string) $atts['home_url'];
    if ($home_url !== '' && strpos($home_url, 'http') !== 0) {
        $home_url = home_url($home_url);
    }
    $home_url = esc_url($home_url);

    $watch_url = (string) $atts['watch_url'];
    if ($watch_url !== '' && strpos($watch_url, 'http') !== 0) {
        $watch_url = home_url($watch_url);
    }
    $watch_url = esc_url($watch_url);

    $series_watch_url = (string) $atts['series_watch_url'];
    if ($series_watch_url !== '' && strpos($series_watch_url, 'http') !== 0) {
        $series_watch_url = home_url($series_watch_url);
    }
    $series_watch_url = esc_url($series_watch_url);

    $q_param = sanitize_key((string) $atts['q_param']);
    if ($q_param === '') {
        $q_param = 'q';
    }

    $limit = max(1, min(60, absint($atts['limit'])));
    $min_chars = max(1, min(20, absint($atts['min_chars'])));

    $q = '';
    if (isset($_GET[$q_param])) {
        $q = sanitize_text_field(wp_unslash((string) $_GET[$q_param]));
    }
    $q = trim((string) $q);

    if (!class_exists('MMBA_Storage')) {
        return '<div class="mmsa mmsa-error">' . esc_html__('Movie Meta plugin is required.', 'movie-meta-by-aris') . '</div>';
    }

    $q_len = function_exists('mb_strlen') ? (int) mb_strlen($q) : (int) strlen($q);
    if ($q === '' || $q_len < $min_chars) {
        ob_start();
        ?>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&display=swap">
        <div class="mmsa">
          <div class="mmsa-shell">
            <nav class="mmsa-nav" aria-label="<?php echo esc_attr__('Search navigation', 'movie-meta-by-aris'); ?>">
              <a class="mmsa-back" href="<?php echo esc_url($home_url); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                <span><?php echo esc_html__('Back to movies', 'movie-meta-by-aris'); ?></span>
              </a>
            </nav>
            <header class="mmsa-header">
              <p class="mmsa-kicker"><?php echo esc_html__('Search', 'movie-meta-by-aris'); ?></p>
              <h1 class="mmsa-title"><?php echo esc_html__('Search results', 'movie-meta-by-aris'); ?></h1>
              <p class="mmsa-count"><?php echo esc_html(sprintf(__('Type at least %d characters to search.', 'movie-meta-by-aris'), $min_chars)); ?></p>
            </header>
            <div class="mmsa-empty-state"><?php echo esc_html__('No search term provided.', 'movie-meta-by-aris'); ?></div>
          </div>
        </div>
        <style>
          .mmsa,
          .mmsa *,
          .mmsa h1,
          .mmsa h2,
          .mmsa p,
          .mmsa span,
          .mmsa a,
          .mmsa div {
            color: inherit;
          }
          .mmsa, .mmsa *::before, .mmsa *::after { box-sizing: border-box; }
          .mmsa {
            --mmsa-font: "Sora", "Avenir Next", "Segoe UI", system-ui, sans-serif;
            color: #12151a !important;
            font-family: var(--mmsa-font);
            isolation: isolate;
            position: relative;
            width: 100%;
            max-width: 100%;
            padding: 1rem max(0px, env(safe-area-inset-right)) 3.5rem max(0px, env(safe-area-inset-left));
            overflow-x: clip;
          }
          .mmsa-shell {
            max-width: 98%;
            width: 100%;
            margin: 0 auto;
            padding: 1.25rem 1.15rem 1.5rem;
            background: #ffffff !important;
            border: 1px solid rgba(18, 21, 26, 0.08);
            border-radius: 18px;
            box-shadow: 0 12px 36px rgba(18, 21, 26, 0.06);
            color: #12151a !important;
            animation: mmsa-in 0.45s ease both;
          }
          @keyframes mmsa-in {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: none; }
          }
          .mmsa-nav { margin: 0 0 1.35rem; }
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
            transition: color 0.18s ease, background 0.18s ease;
          }
          .mmsa-back svg { width: 18px; height: 18px; display: block; stroke: currentColor; }
          .mmsa-back:hover { color: #12151a !important; background: #eef1f5; }
          .mmsa-header { margin: 0 0 1.25rem; }
          .mmsa-kicker {
            margin: 0 0 0.45rem;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #1d4ed8 !important;
          }
          .mmsa-title {
            margin: 0;
            font-size: clamp(1.65rem, 3.6vw, 2.45rem);
            font-weight: 700;
            letter-spacing: -0.035em;
            line-height: 1.12;
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
          @media (max-width: 900px) {
            .mmsa-shell { padding: 1.1rem 1rem 1.35rem; }
          }
          @media (max-width: 640px) {
            .mmsa {
              padding: 0.65rem max(0.55rem, env(safe-area-inset-right)) 2.5rem max(0.55rem, env(safe-area-inset-left));
            }
            .mmsa-shell {
              padding: 0.95rem 0.8rem 1.15rem;
              border-radius: 14px;
            }
            .mmsa-nav { margin-bottom: 0.95rem; }
            .mmsa-header { margin-bottom: 0.95rem; }
            .mmsa-title {
              font-size: clamp(1.35rem, 7vw, 1.85rem);
              line-height: 1.15;
            }
          }
          @media (max-width: 380px) {
            .mmsa-shell { padding: 0.85rem 0.7rem 1rem; }
            .mmsa-back span { font-size: 0.84rem; }
          }
          @media (prefers-reduced-motion: reduce) {
            .mmsa-shell { animation: none; }
            .mmsa-back { transition: none; }
          }
        </style>
        <?php
        return ob_get_clean();
    }

    $q_norm = mmsrch_normalize_query($q);

    if (method_exists('MMBA_Storage', 'increment_search')) {
        MMBA_Storage::increment_search($q);
    }

    $catalog = MMBA_Storage::get_movies();
    if (!is_array($catalog)) {
        $catalog = [];
    }

    $series_matches = [];
    $movie_matches = [];

    foreach ($catalog as $item) {
        if (!is_array($item)) {
            continue;
        }

        $type = isset($item['type']) ? (string) $item['type'] : 'movie';
        $score = mmsrch_search_score_item($item, $q_norm);
        if ($score <= 0) {
            continue;
        }

        if (strcasecmp($type, 'series') === 0) {
            $series_matches[] = ['score' => $score, 'movie' => $item];
        } else {
            $movie_matches[] = ['score' => $score, 'movie' => $item];
        }
    }

    usort($series_matches, static function ($a, $b) {
        $ds = (int) $b['score'] - (int) $a['score'];
        if ($ds !== 0) {
            return $ds;
        }
        return strcmp((string) ($a['movie']['title'] ?? ''), (string) ($b['movie']['title'] ?? ''));
    });

    usort($movie_matches, static function ($a, $b) {
        $ds = (int) $b['score'] - (int) $a['score'];
        if ($ds !== 0) {
            return $ds;
        }
        return strcmp((string) ($a['movie']['title'] ?? ''), (string) ($b['movie']['title'] ?? ''));
    });

    $series_total = count($series_matches);
    $movies_total = count($movie_matches);

    $series = array_slice(array_map(static function ($x) { return $x['movie']; }, $series_matches), 0, $limit);
    $movies = array_slice(array_map(static function ($x) { return $x['movie']; }, $movie_matches), 0, $limit);

    $total = $series_total + $movies_total;

    ob_start();
    ?>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&display=swap">
    <div class="mmsa">
      <div class="mmsa-shell">
        <nav class="mmsa-nav" aria-label="<?php echo esc_attr__('Search navigation', 'movie-meta-by-aris'); ?>">
          <a class="mmsa-back" href="<?php echo esc_url($home_url); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            <span><?php echo esc_html__('Back to movies', 'movie-meta-by-aris'); ?></span>
          </a>
        </nav>

        <header class="mmsa-header">
          <p class="mmsa-kicker"><?php echo esc_html__('Search', 'movie-meta-by-aris'); ?></p>
          <h1 class="mmsa-title"><?php echo esc_html__('Search results', 'movie-meta-by-aris'); ?></h1>
          <p class="mmsa-count">
            <?php
            echo esc_html(sprintf(
                _n('%d result', '%d results', $total, 'movie-meta-by-aris'),
                $total
            ));
            ?>
          </p>
        </header>

        <?php if ($total === 0) : ?>
          <div class="mmsa-empty-state">
            <?php echo esc_html(sprintf(__('No results found for "%s".', 'movie-meta-by-aris'), $q)); ?>
          </div>
        <?php else : ?>
          <div class="mmsrch-sections">
            <?php if (!empty($series)) : ?>
              <section class="mmsrch-section">
                <h2 class="mmsrch-section-title"><?php echo esc_html__('Series', 'movie-meta-by-aris'); ?></h2>
                <div class="mmsa-grid">
                  <?php foreach ($series as $movie) :
                    $id = isset($movie['id']) ? (string) $movie['id'] : '';
                    $title = isset($movie['title']) ? (string) $movie['title'] : '';
                    $year = isset($movie['year']) ? (string) $movie['year'] : '';
                    $poster = MMBA_Storage::movie_poster_url($movie);
                    $href = $series_watch_url . (strpos($series_watch_url, '?') === false ? '?' : '&') . 'id=' . rawurlencode($id);
                    $initial = $title !== '' ? strtoupper(substr($title, 0, 1)) : 'S';
                    $tone = mmsrch_poster_tone($title);
                    $display = $title !== '' ? $title : __('Untitled', 'movie-meta-by-aris');

                    $seasons = isset($movie['season_count']) ? (int) $movie['season_count'] : 0;
                    $eps = isset($movie['episode_count']) ? (int) $movie['episode_count'] : 0;
                    $episode_bit = '';
                    if ($seasons > 0) {
                        $episode_bit = sprintf(_n('%d season', '%d seasons', $seasons, 'movie-meta-by-aris'), $seasons);
                    } elseif ($eps > 0) {
                        $episode_bit = sprintf(_n('%d episode', '%d episodes', $eps, 'movie-meta-by-aris'), $eps);
                    }

                    $meta_bits = array_filter([$year, $episode_bit]);
                    $img_meta = method_exists('MMBA_Storage', 'poster_image_meta')
                        ? MMBA_Storage::poster_image_meta($display)
                        : ($display . ' DesiMoviesHub Free Watch');
                    ?>
                    <a class="mmsa-card" href="<?php echo esc_url($href); ?>">
                      <div class="mmsa-poster mmsa-tone-<?php echo (int) $tone; ?>">
                        <?php if ($poster !== '') : ?>
                          <img
                            class="mmsa-poster-img"
                            src="<?php echo esc_url($poster); ?>"
                            alt="<?php echo esc_attr($img_meta); ?>"
                            title="<?php echo esc_attr($img_meta); ?>"
                            loading="lazy"
                            decoding="async"
                            referrerpolicy="no-referrer"
                            onerror="this.style.display='none';var f=this.parentNode.querySelector('.mmsa-poster-fallback');if(f){f.hidden=false;f.removeAttribute('hidden');}"
                          >
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
              </section>
            <?php endif; ?>

            <?php if (!empty($movies)) : ?>
              <section class="mmsrch-section">
                <h2 class="mmsrch-section-title"><?php echo esc_html__('Movies', 'movie-meta-by-aris'); ?></h2>
                <div class="mmsa-grid">
                  <?php foreach ($movies as $movie) :
                    $id = isset($movie['id']) ? (string) $movie['id'] : '';
                    $title = isset($movie['title']) ? (string) $movie['title'] : '';
                    $year = isset($movie['year']) ? (string) $movie['year'] : '';
                    $genre = mmsrch_primary_genre($movie);
                    $poster = MMBA_Storage::movie_poster_url($movie);
                    $href = $watch_url . (strpos($watch_url, '?') === false ? '?' : '&') . 'id=' . rawurlencode($id);
                    $initial = $title !== '' ? strtoupper(substr($title, 0, 1)) : 'M';
                    $tone = mmsrch_poster_tone($title);
                    $display = $title !== '' ? $title : __('Untitled', 'movie-meta-by-aris');
                    $meta_bits = array_filter([$year, $genre]);
                    $img_meta = method_exists('MMBA_Storage', 'poster_image_meta')
                        ? MMBA_Storage::poster_image_meta($display)
                        : ($display . ' DesiMoviesHub Free Watch');
                    ?>
                    <a class="mmsa-card" href="<?php echo esc_url($href); ?>">
                      <div class="mmsa-poster mmsa-tone-<?php echo (int) $tone; ?>">
                        <?php if ($poster !== '') : ?>
                          <img
                            class="mmsa-poster-img"
                            src="<?php echo esc_url($poster); ?>"
                            alt="<?php echo esc_attr($img_meta); ?>"
                            title="<?php echo esc_attr($img_meta); ?>"
                            loading="lazy"
                            decoding="async"
                            referrerpolicy="no-referrer"
                            onerror="this.style.display='none';var f=this.parentNode.querySelector('.mmsa-poster-fallback');if(f){f.hidden=false;f.removeAttribute('hidden');}"
                          >
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
              </section>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <style>
      .mmsa, .mmsa *, .mmsa *::before, .mmsa *::after { box-sizing: border-box; }
      .mmsa {
        --mmsa-font: "Sora", "Avenir Next", "Segoe UI", system-ui, sans-serif;
        color: #12151a !important;
        font-family: var(--mmsa-font);
        isolation: isolate;
        position: relative;
        width: 100%;
        max-width: 100%;
        padding: 1rem max(0px, env(safe-area-inset-right)) 3.5rem max(0px, env(safe-area-inset-left));
        overflow-x: clip;
      }
      .mmsa-shell {
        max-width: 98%;
        width: 100%;
        margin: 0 auto;
        padding: 1.25rem 1.15rem 1.5rem;
        background: #ffffff !important;
        border: 1px solid rgba(18, 21, 26, 0.08);
        border-radius: 18px;
        box-shadow: 0 12px 36px rgba(18, 21, 26, 0.06);
        color: #12151a !important;
        animation: mmsa-in 0.45s ease both;
      }
      @keyframes mmsa-in {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: none; }
      }
      .mmsa-nav { margin: 0 0 1.35rem; }
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
        transition: color 0.18s ease, background 0.18s ease;
      }
      .mmsa-back svg { width: 18px; height: 18px; display: block; stroke: currentColor; }
      .mmsa-back:hover { color: #12151a !important; background: #eef1f5; }
      .mmsa-header { margin: 0 0 1.25rem; }
      .mmsa-kicker {
        margin: 0 0 0.45rem;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #1d4ed8 !important;
      }
      .mmsa-title {
        margin: 0;
        font-size: clamp(1.65rem, 3.6vw, 2.45rem);
        font-weight: 700;
        letter-spacing: -0.035em;
        line-height: 1.12;
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
      .mmsrch-sections { margin: 0.25rem 0 0; }
      .mmsrch-section { margin: 0 0 1.8rem; }
      .mmsrch-section-title {
        margin: 0 0 1rem;
        font-size: 1.12rem;
        font-weight: 800;
        color: #12151a !important;
      }
      .mmsa-empty, .mmsa-error {
        max-width: 98%;
        margin: 0 auto;
        padding: 2.5rem 1.15rem;
        color: #4b5563 !important;
        font-family: var(--mmsa-font, system-ui, sans-serif);
        background: #fff;
        border-radius: 12px;
      }
      .mmsa-error { color: #b91c1c !important; }
      @media (max-width: 900px) {
        .mmsa-shell { padding: 1.1rem 1rem 1.35rem; }
      }
      @media (max-width: 720px) {
        .mmsa-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.9rem 0.65rem; }
        .mmsa-card-title { font-size: 0.8rem; }
        .mmsa-poster { border-radius: 12px; }
      }
      @media (max-width: 640px) {
        .mmsa {
          padding: 0.65rem max(0.55rem, env(safe-area-inset-right)) 2.5rem max(0.55rem, env(safe-area-inset-left));
        }
        .mmsa-shell {
          padding: 0.95rem 0.8rem 1.15rem;
          border-radius: 14px;
        }
        .mmsa-nav { margin-bottom: 0.95rem; }
        .mmsa-header { margin-bottom: 0.95rem; }
        .mmsa-title {
          font-size: clamp(1.35rem, 7vw, 1.85rem);
          line-height: 1.15;
        }
      }
      @media (max-width: 420px) {
        .mmsa-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      }
      @media (max-width: 380px) {
        .mmsa-shell { padding: 0.85rem 0.7rem 1rem; }
        .mmsa-back span { font-size: 0.84rem; }
      }
      @media (prefers-reduced-motion: reduce) {
        .mmsa-shell { animation: none; }
        .mmsa-card, .mmsa-back { transition: none; }
      }
    </style>
    <?php
    return ob_get_clean();
}

function mmsrch_normalize_query(string $q): string {
    $q = trim($q);
    if ($q === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($q, 'UTF-8');
    }
    return strtolower($q);
}

function mmsrch_primary_genre(array $movie): string {
    $genre = isset($movie['genre']) ? (string) $movie['genre'] : '';
    $parts = array_filter(array_map('trim', explode(',', $genre)));
    return $parts ? (string) $parts[0] : '';
}

function mmsrch_poster_tone(string $title): int {
    $sum = 0;
    $s = (string) $title;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $sum += ord($s[$i]);
    }
    return ($sum % 6) + 1;
}

function mmsrch_query_tokens(string $q_norm): array {
    $q_norm = trim($q_norm);
    if ($q_norm === '') {
        return [];
    }
    // Keep only alphanumeric tokens; split by punctuation/whitespace.
    $tokens = preg_split('/[^a-z0-9]+/iu', $q_norm, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($tokens)) {
        return [];
    }
    return array_values(array_filter($tokens));
}

function mmsrch_search_score_item(array $item, string $q_norm): int {
    $q_norm = trim($q_norm);
    if ($q_norm === '') {
        return 0;
    }

    $title = isset($item['title']) ? (string) $item['title'] : '';
    $title_norm = mmsrch_normalize_query($title);
    if ($title_norm === '') {
        return 0;
    }

    $genre = isset($item['genre']) ? (string) $item['genre'] : '';
    $genre_norm = mmsrch_normalize_query($genre);

    $year = isset($item['year']) ? (string) $item['year'] : '';
    $year_norm = mmsrch_normalize_query($year);

    // Quick reject: only continue if title/genre/year actually contains something relevant.
    $has_substring = (strpos($title_norm, $q_norm) !== false)
        || ($genre_norm !== '' && strpos($genre_norm, $q_norm) !== false)
        || ($year_norm !== '' && $year_norm === $q_norm);

    $tokens = mmsrch_query_tokens($q_norm);
    $token_hits = 0;
    foreach ($tokens as $t) {
        if ($t !== '' && strpos($title_norm, $t) !== false) {
            $token_hits++;
        }
    }

    if (!$has_substring && $token_hits === 0) {
        return 0;
    }

    $score = 0;

    // Title matching.
    if ($title_norm === $q_norm) {
        $score += 200;
    } elseif (strpos($title_norm, $q_norm) === 0) {
        $score += 160;
    } elseif (strpos($title_norm, $q_norm) !== false) {
        $score += 120;
    }

    // Word/token matching.
    $token_total = count($tokens);
    if ($token_total > 0 && $token_hits > 0) {
        if ($token_hits === $token_total) {
            $score += 90 + ($token_hits * 8);
        } else {
            $score += 50 + ($token_hits * 10);
        }
    }

    // Genre matching.
    if ($genre_norm !== '' && strpos($genre_norm, $q_norm) !== false) {
        $score += 60;
    }

    // Exact year match.
    if ($year_norm !== '' && $year_norm === $q_norm) {
        $score += 70;
    }

    return $score;
}

