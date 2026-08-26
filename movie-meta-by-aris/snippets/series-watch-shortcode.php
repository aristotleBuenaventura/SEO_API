/**
 * Code Snippets plugin — paste this as a PHP snippet (Run everywhere).
 *
 * Shortcode: [series_watch]
 * Optional:  [series_watch home_url="/series/" related="12"]
 *
 * Create a WP page at /series-watch/ and put [series_watch] in the content.
 * Series rows link here as: /series-watch/?id=SERIES_ID
 * Opens Season 1 Episode 1 (or the oldest episode), with season/episode pickers below the player.
 *
 * Requires: Movie Meta plugin (data source).
 * Pair with snippets/series-rows-shortcode.php → [movie_series_rows]
 * Pair with snippets/series-all-shortcode.php → [movie_series]
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('series_watch', 'mmsw_render_series_watch_shortcode');

function mmsw_render_series_watch_shortcode($atts = []) {
    $atts = shortcode_atts(
        [
            'id'        => '',
            'related'   => '12',
            'home_url'  => '/series/',
            'watch_url' => '/series-watch/',
        ],
        $atts,
        'series_watch'
    );

    $id = $atts['id'] !== '' ? sanitize_text_field($atts['id']) : '';
    if ($id === '' && isset($_GET['id'])) {
        $id = sanitize_text_field(wp_unslash((string) $_GET['id']));
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

    $related_limit = max(0, absint($atts['related']));

    if ($id === '') {
        return '<div class="mmsw mmsw-empty">' . esc_html__('No series selected. Open a title from the series row.', 'movie-meta-by-aris') . '</div>';
    }

    if (!class_exists('MMBA_Storage')) {
        return '<div class="mmsw mmsw-error">' . esc_html__('Movie Meta plugin is required.', 'movie-meta-by-aris') . '</div>';
    }

    $movie = MMBA_Storage::get_movie($id);
    if (!$movie || (isset($movie['type']) && $movie['type'] === 'movie' && empty($movie['episodes']))) {
        return '<div class="mmsw mmsw-empty">' . esc_html__('Series not found.', 'movie-meta-by-aris') .
            ' <a class="mmsw-link" href="' . esc_url($home_url) . '">' . esc_html__('Back to series', 'movie-meta-by-aris') . '</a></div>';
    }

    $season_q = isset($_GET['season']) ? sanitize_text_field(wp_unslash((string) $_GET['season'])) : '';
    $episode_q = isset($_GET['episode']) ? sanitize_text_field(wp_unslash((string) $_GET['episode'])) : '';
    $picked = mmsw_pick_episode($movie, $season_q, $episode_q);
    $is_series = !empty($picked['episodes']);
    if (!$is_series) {
        return '<div class="mmsw mmsw-empty">' . esc_html__('This title has no episodes.', 'movie-meta-by-aris') .
            ' <a class="mmsw-link" href="' . esc_url($home_url) . '">' . esc_html__('Back to series', 'movie-meta-by-aris') . '</a></div>';
    }
    $catalog_id = isset($picked['id']) ? (string) $picked['id'] : $id;
    $current = $picked['current'];

    if (method_exists('MMBA_Storage', 'increment_view')) {
        MMBA_Storage::increment_view($catalog_id);
    }

    $title   = isset($movie['title']) ? (string) $movie['title'] : '';
    $details = isset($current['details']) && $current['details'] !== '' ? (string) $current['details'] : (isset($movie['details']) ? (string) $movie['details'] : '');
    $cast    = isset($current['cast']) && $current['cast'] !== '' ? (string) $current['cast'] : (isset($movie['cast']) ? (string) $movie['cast'] : '');
    $year    = isset($current['year']) && $current['year'] !== '' ? (string) $current['year'] : (isset($movie['year']) ? (string) $movie['year'] : '');
    $genre   = isset($current['genre']) && $current['genre'] !== '' ? (string) $current['genre'] : (isset($movie['genre']) ? (string) $movie['genre'] : '');
    $link    = isset($current['movie_link']) ? (string) $current['movie_link'] : (isset($movie['movie_link']) ? (string) $movie['movie_link'] : '');
    $link_type = MMBA_Storage::get_movie_link_type($link);
    $play_url  = $link_type === 'embed' ? MMBA_Storage::get_embed_url($link) : $link;
    $play_src  = MMBA_Storage::escape_play_url($play_url);
    $poster    = MMBA_Storage::movie_poster_url($current);

    $genres = mmsw_split_list($genre);
    $cast_list = mmsw_split_list($cast);
    $related = mmsw_related_series($movie, $related_limit);

    $uid = 'mmsw-' . wp_unique_id();
    $needs_hls = ($link_type === 'hls' && $play_url !== '');
    $display_title = $title !== '' ? $title : __('Untitled', 'movie-meta-by-aris');
    $current_season_n = isset($current['season_n']) ? (int) $current['season_n'] : 0;
    $current_episode_n = isset($current['episode_n']) ? (int) $current['episode_n'] : 0;
    $seasons = [];
    if ($is_series) {
        foreach ($picked['episodes'] as $ep) {
            $sn = isset($ep['season_n']) ? (int) $ep['season_n'] : 0;
            $slabel = isset($ep['season']) && $ep['season'] !== '' ? (string) $ep['season'] : ('Season ' . $sn);
            if (!isset($seasons[$sn])) {
                $seasons[$sn] = ['label' => $slabel, 'episodes' => []];
            }
            $seasons[$sn]['episodes'][] = $ep;
        }
        ksort($seasons, SORT_NUMERIC);
        if (!isset($seasons[$current_season_n]) && !empty($seasons)) {
            $current_season_n = (int) array_key_first($seasons);
            $first_eps = $seasons[$current_season_n]['episodes'][0];
            if ($season_q === '' && $episode_q === '') {
                $current = $first_eps;
                $current_episode_n = isset($first_eps['episode_n']) ? (int) $first_eps['episode_n'] : 0;
                $link    = isset($current['movie_link']) ? (string) $current['movie_link'] : $link;
                $link_type = MMBA_Storage::get_movie_link_type($link);
                $play_url  = $link_type === 'embed' ? MMBA_Storage::get_embed_url($link) : $link;
                $play_src  = MMBA_Storage::escape_play_url($play_url);
                $poster    = MMBA_Storage::movie_poster_url($current);
            }
        }
    }

    ob_start();
    ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&display=swap">
<div id="<?php echo esc_attr($uid); ?>" class="mmsw" data-link-type="<?php echo esc_attr($link_type); ?>">
  <div class="mmsw-shell">
    <nav class="mmsw-nav" aria-label="<?php echo esc_attr__('Watch navigation', 'movie-meta-by-aris'); ?>">
      <a class="mmsw-back" href="<?php echo esc_url($home_url); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
        <span><?php echo esc_html__('Back to series', 'movie-meta-by-aris'); ?></span>
      </a>
    </nav>

    <header class="mmsw-hero">
      <div class="mmsw-hero-copy">
        <p class="mmsw-kicker"><?php echo esc_html__('Now playing', 'movie-meta-by-aris'); ?></p>
        <h1 class="mmsw-title"><?php echo esc_html($display_title); ?></h1>
        <div class="mmsw-chips" role="list">
          <span class="mmsw-chip mmsw-chip-hd" role="listitem">HD</span>
          <?php if ($is_series) : ?>
            <span class="mmsw-chip" role="listitem"><?php echo esc_html__('Series', 'movie-meta-by-aris'); ?></span>
            <?php if ($current_season_n || $current_episode_n) : ?>
              <span class="mmsw-chip mmsw-chip-soft" role="listitem"><?php echo esc_html(sprintf('S%d E%d', $current_season_n, $current_episode_n)); ?></span>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($year !== '') : ?>
            <span class="mmsw-chip" role="listitem"><?php echo esc_html($year); ?></span>
          <?php endif; ?>
          <?php foreach ($genres as $g) : ?>
            <span class="mmsw-chip mmsw-chip-soft" role="listitem"><?php echo esc_html($g); ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </header>

    <div class="mmsw-player-stage">
      <div class="mmsw-player-wrap">
        <?php if ($play_url === '') : ?>
          <div class="mmsw-player-empty"><?php echo esc_html__('No stream available for this title.', 'movie-meta-by-aris'); ?></div>
    <?php elseif ($link_type === 'embed') : ?>
      <iframe
        class="mmsw-player"
        src="<?php echo $play_src; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape_play_url() ?>"
        title="<?php echo esc_attr($display_title); ?>"
        allow="fullscreen; encrypted-media; picture-in-picture"
        allowfullscreen
        referrerpolicy="no-referrer-when-downgrade"
        loading="lazy"
      ></iframe>
    <?php else : ?>
      <video
        id="<?php echo esc_attr($uid); ?>-video"
        class="mmsw-player"
        controls
        playsinline
        <?php if ($poster !== '') : ?>poster="<?php echo esc_url($poster); ?>"<?php endif; ?>
        data-src="<?php echo $play_src; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape_play_url() ?>"
      ></video>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($is_series && !empty($seasons)) : ?>
    <section class="mmsw-episodes" aria-label="<?php echo esc_attr__('Seasons and episodes', 'movie-meta-by-aris'); ?>">
      <div class="mmsw-season-tabs" role="tablist">
        <?php foreach ($seasons as $sn => $block) :
            $shref = $watch_url . (strpos($watch_url, '?') === false ? '?' : '&') . 'id=' . rawurlencode($catalog_id) . '&season=' . rawurlencode((string) $sn);
            $active = ((int) $sn === $current_season_n);
            ?>
          <a class="mmsw-season-tab<?php echo $active ? ' is-active' : ''; ?>" href="<?php echo esc_url($shref); ?>"><?php echo esc_html($block['label']); ?></a>
        <?php endforeach; ?>
      </div>
      <?php if (isset($seasons[$current_season_n])) : ?>
      <div class="mmsw-ep-grid">
        <?php foreach ($seasons[$current_season_n]['episodes'] as $ep) :
            $en = isset($ep['episode_n']) ? (int) $ep['episode_n'] : 0;
            $ehref = $watch_url . (strpos($watch_url, '?') === false ? '?' : '&') . 'id=' . rawurlencode($catalog_id) . '&season=' . rawurlencode((string) $current_season_n) . '&episode=' . rawurlencode((string) $en);
            $eactive = ($en === $current_episode_n);
            $elabel = isset($ep['episode']) && $ep['episode'] !== '' ? (string) $ep['episode'] : ('Episode ' . $en);
            ?>
          <a class="mmsw-ep<?php echo $eactive ? ' is-active' : ''; ?>" href="<?php echo esc_url($ehref); ?>"><?php echo esc_html($elabel); ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($details !== '' || $cast !== '' || $year !== '' || !empty($genres)) : ?>
    <section class="mmsw-info" aria-label="<?php echo esc_attr__('Series information', 'movie-meta-by-aris'); ?>">
      <?php if ($details !== '') : ?>
        <div class="mmsw-info-main">
          <h2 class="mmsw-label"><?php echo esc_html__('Series Details', 'movie-meta-by-aris'); ?></h2>
          <p class="mmsw-synopsis"><?php echo esc_html($details); ?></p>
        </div>
      <?php endif; ?>

      <aside class="mmsw-info-side">
        <?php if (!empty($cast_list)) : ?>
          <div class="mmsw-side-block">
            <h2 class="mmsw-label"><?php echo esc_html__('Cast', 'movie-meta-by-aris'); ?></h2>
            <ul class="mmsw-cast">
              <?php foreach ($cast_list as $person) : ?>
                <li><?php echo esc_html($person); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php elseif ($cast !== '') : ?>
          <div class="mmsw-side-block">
            <h2 class="mmsw-label"><?php echo esc_html__('Cast', 'movie-meta-by-aris'); ?></h2>
            <p class="mmsw-side-text"><?php echo esc_html($cast); ?></p>
          </div>
        <?php endif; ?>

        <?php if ($year !== '') : ?>
          <div class="mmsw-side-block">
            <h2 class="mmsw-label"><?php echo esc_html__('Year', 'movie-meta-by-aris'); ?></h2>
            <p class="mmsw-side-text mmsw-side-strong"><?php echo esc_html($year); ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($genres)) : ?>
          <div class="mmsw-side-block">
            <h2 class="mmsw-label"><?php echo esc_html__('Genre', 'movie-meta-by-aris'); ?></h2>
            <div class="mmsw-chips mmsw-chips-tight">
              <?php foreach ($genres as $g) : ?>
                <span class="mmsw-chip mmsw-chip-soft"><?php echo esc_html($g); ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </aside>
    </section>
    <?php endif; ?>

    <?php if (!empty($related)) : ?>
    <section class="mmsw-related" aria-label="<?php echo esc_attr__('More like this', 'movie-meta-by-aris'); ?>">
      <div class="mmsw-related-head">
        <h2 class="mmsw-related-title"><?php echo esc_html__('More Like This', 'movie-meta-by-aris'); ?></h2>
        <p class="mmsw-related-sub"><?php echo esc_html__('Other series that share a genre with this show.', 'movie-meta-by-aris'); ?></p>
      </div>
      <div class="mmsw-related-track" tabindex="0">
        <?php foreach ($related as $item) :
            $rid = isset($item['id']) ? (string) $item['id'] : '';
            $rtitle = isset($item['title']) ? (string) $item['title'] : '';
            $ryear = isset($item['year']) ? (string) $item['year'] : '';
            $rgenre = isset($item['genre']) ? (string) $item['genre'] : '';
            $rgenres = mmsw_split_list($rgenre);
            $rprimary = !empty($rgenres) ? $rgenres[0] : '';
            $rlink = isset($item['movie_link']) ? (string) $item['movie_link'] : '';
            $rposter = MMBA_Storage::movie_poster_url($item);
            $rhref = $watch_url . (strpos($watch_url, '?') === false ? '?' : '&') . 'id=' . rawurlencode($rid);
            $initial = $rtitle !== '' ? strtoupper(substr($rtitle, 0, 1)) : 'S';
            $tone = mmsw_poster_tone($rtitle);
            $meta_bits = array_filter([$ryear, $rprimary]);
            $rdisplay = $rtitle !== '' ? $rtitle : __('Untitled', 'movie-meta-by-aris');
            $img_meta = method_exists('MMBA_Storage', 'poster_image_meta')
                ? MMBA_Storage::poster_image_meta($rdisplay)
                : ($rdisplay . ' DesiMoviesHub Free Watch');
            ?>
          <a class="mmsw-card" href="<?php echo esc_url($rhref); ?>">
            <div class="mmsw-poster mmsw-tone-<?php echo (int) $tone; ?>">
              <?php if ($rposter !== '') : ?>
                <img class="mmsw-poster-img" src="<?php echo esc_url($rposter); ?>" alt="<?php echo esc_attr($img_meta); ?>" title="<?php echo esc_attr($img_meta); ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                  onerror="this.style.display='none';var f=this.parentNode.querySelector('.mmsw-poster-fallback');if(f){f.hidden=false;f.removeAttribute('hidden');}">
                <div class="mmsw-poster-fallback" hidden aria-hidden="true">
                  <span class="mmsw-poster-letter"><?php echo esc_html($initial); ?></span>
                </div>
              <?php else : ?>
                <div class="mmsw-poster-fallback" aria-hidden="true">
                  <span class="mmsw-poster-letter"><?php echo esc_html($initial); ?></span>
                </div>
              <?php endif; ?>
              <span class="mmsw-badge mmsw-badge-hd">HD</span>
              <?php if ($ryear !== '') : ?>
                <span class="mmsw-badge mmsw-badge-year"><?php echo esc_html($ryear); ?></span>
              <?php endif; ?>
            </div>
            <div class="mmsw-card-body">
              <h3 class="mmsw-card-title"><?php echo esc_html($rtitle !== '' ? $rtitle : __('Untitled', 'movie-meta-by-aris')); ?></h3>
              <?php if (!empty($meta_bits)) : ?>
                <p class="mmsw-card-meta"><?php echo esc_html(implode(' · ', $meta_bits)); ?></p>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<style>
  /* Force readable colors — Divi often inherits white text onto light sections */
  .mmsw,
  .mmsw *,
  .mmsw h1,
  .mmsw h2,
  .mmsw h3,
  .mmsw p,
  .mmsw span,
  .mmsw li,
  .mmsw a,
  .mmsw div {
    color: inherit;
  }
  .mmsw, .mmsw *, .mmsw *::before, .mmsw *::after { box-sizing: border-box; }
  .mmsw {
    --mmsw-ink: #12151a;
    --mmsw-muted: #4b5563;
    --mmsw-soft: #6b7280;
    --mmsw-line: rgba(18, 21, 26, 0.12);
    --mmsw-surface: #ffffff;
    --mmsw-panel: #eef1f5;
    --mmsw-accent: #1d4ed8;
    --mmsw-radius: 16px;
    --mmsw-radius-sm: 10px;
    --mmsw-card-w: 168px;
    --mmsw-font: "Sora", "Avenir Next", "Segoe UI", system-ui, sans-serif;
    color: #12151a !important;
    font-family: var(--mmsw-font);
    isolation: isolate;
    position: relative;
    width: 100%;
    max-width: 100%;
    padding: 1rem max(0px, env(safe-area-inset-right)) 3.5rem max(0px, env(safe-area-inset-left));
    overflow-x: clip;
  }
  .mmsw-shell {
    max-width: 98%;
    width: 100%;
    margin: 0 auto;
    padding: 1.25rem 1.15rem 1.5rem;
    background: #ffffff !important;
    border: 1px solid rgba(18, 21, 26, 0.08);
    border-radius: 18px;
    box-shadow: 0 12px 36px rgba(18, 21, 26, 0.06);
    color: #12151a !important;
    animation: mmsw-in 0.45s ease both;
  }
  @keyframes mmsw-in {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: none; }
  }
  .mmsw-nav { margin: 0 0 1.35rem; }
  .mmsw-back {
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
  .mmsw-back svg { width: 18px; height: 18px; display: block; stroke: currentColor; }
  .mmsw-back:hover { color: #12151a !important; background: #eef1f5; }
  .mmsw-hero { margin: 0 0 1.25rem; }
  .mmsw-kicker {
    margin: 0 0 0.45rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #1d4ed8 !important;
  }
  .mmsw-title {
    margin: 0;
    font-size: clamp(1.65rem, 3.6vw, 2.45rem);
    font-weight: 700;
    letter-spacing: -0.035em;
    line-height: 1.12;
    max-width: 18ch;
    color: #12151a !important;
  }
  .mmsw-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin-top: 0.95rem;
  }
  .mmsw-chips-tight { margin-top: 0.35rem; }
  .mmsw-chip {
    display: inline-flex;
    align-items: center;
    padding: 0.28rem 0.65rem;
    border-radius: 999px;
    border: 1px solid rgba(18, 21, 26, 0.14);
    background: #ffffff !important;
    color: #12151a !important;
    font-size: 0.78rem;
    font-weight: 600;
    line-height: 1.2;
  }
  .mmsw-chip-hd {
    background: #12151a !important;
    border-color: #12151a !important;
    color: #ffffff !important;
  }
  .mmsw-chip-soft {
    background: #eef1f5 !important;
    color: #1f2937 !important;
  }
  .mmsw-player-stage { margin: 0 0 1.75rem; }
  .mmsw-episodes { margin: 0 0 1.75rem; }
  .mmsw-season-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin: 0 0 0.75rem;
  }
  .mmsw-season-tab {
    display: inline-flex;
    align-items: center;
    padding: 0.38rem 0.75rem;
    border-radius: 999px;
    border: 1px solid rgba(18, 21, 26, 0.14);
    background: #ffffff !important;
    color: #12151a !important;
    text-decoration: none !important;
    font-size: 0.82rem;
    font-weight: 600;
  }
  .mmsw-season-tab.is-active {
    background: #12151a !important;
    border-color: #12151a !important;
    color: #ffffff !important;
  }
  .mmsw-ep-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
  }
  .mmsw-ep {
    display: inline-flex;
    align-items: center;
    padding: 0.4rem 0.7rem;
    border-radius: 10px;
    border: 1px solid rgba(18, 21, 26, 0.12);
    background: #eef1f5 !important;
    color: #12151a !important;
    text-decoration: none !important;
    font-size: 0.8rem;
    font-weight: 600;
  }
  .mmsw-ep.is-active {
    background: #1d4ed8 !important;
    border-color: #1d4ed8 !important;
    color: #ffffff !important;
  }
  .mmsw-player-wrap {
    position: relative;
    border-radius: calc(var(--mmsw-radius) + 2px);
    overflow: hidden;
    background: #0b0d10;
    box-shadow:
      0 1px 0 rgba(255, 255, 255, 0.6) inset,
      0 22px 50px rgba(18, 21, 26, 0.16);
    border: 1px solid rgba(18, 21, 26, 0.08);
  }
  .mmsw-player-wrap::after {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.04);
    border-radius: inherit;
  }
  .mmsw-player {
    display: block;
    width: 100%;
    max-width: 100%;
    height: auto;
    aspect-ratio: 16 / 9;
    border: 0;
    background: #000;
  }
  .mmsw-player-empty {
    aspect-ratio: 16 / 9;
    display: grid;
    place-items: center;
    color: #9ca3af;
    font-size: 0.95rem;
    padding: 1.25rem;
    text-align: center;
  }
  .mmsw-info {
    display: grid;
    grid-template-columns: minmax(0, 1.55fr) minmax(240px, 0.85fr);
    gap: 1.5rem 2rem;
    margin: 0 0 2.75rem;
    padding: 1.35rem 1.35rem 1.45rem;
    border: 1px solid rgba(18, 21, 26, 0.12);
    border-radius: var(--mmsw-radius);
    background: #f8fafc !important;
    box-shadow: none;
    color: #12151a !important;
    min-width: 0;
  }
  .mmsw-label {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    margin: 0 0 0.55rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #4b5563 !important;
  }
  .mmsw-label::before {
    content: "";
    width: 3px;
    height: 0.95em;
    border-radius: 999px;
    background: #1d4ed8;
    flex: 0 0 auto;
  }
  .mmsw-synopsis {
    margin: 0;
    font-size: 1.02rem;
    line-height: 1.72;
    color: #1f2937 !important;
    max-width: 58ch;
    overflow-wrap: anywhere;
    word-break: break-word;
  }
  .mmsw-info-main { min-width: 0; }
  .mmsw-info-side {
    display: grid;
    gap: 1.25rem;
    align-content: start;
    padding-left: 1.5rem;
    border-left: 1px solid rgba(18, 21, 26, 0.12);
  }
  .mmsw-side-block { min-width: 0; }
  .mmsw-side-text {
    margin: 0;
    font-size: 0.95rem;
    line-height: 1.55;
    color: #1f2937 !important;
  }
  .mmsw-side-strong {
    font-size: 1.15rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: #12151a !important;
  }
  .mmsw-cast {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
  }
  .mmsw-cast li {
    padding: 0.28rem 0.6rem;
    border-radius: 8px;
    background: #ffffff !important;
    border: 1px solid rgba(18, 21, 26, 0.12);
    font-size: 0.82rem;
    font-weight: 500;
    color: #111827 !important;
    line-height: 1.3;
  }
  .mmsw-related { margin-top: 0.25rem; color: #12151a !important; }
  .mmsw-related-head { margin: 0 0 1rem; }
  .mmsw-related-title {
    margin: 0;
    font-size: clamp(1.15rem, 2vw, 1.35rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 0.55rem;
    color: #12151a !important;
  }
  .mmsw-related-title::before {
    content: "";
    width: 4px;
    height: 1.05em;
    border-radius: 999px;
    background: #1d4ed8;
    flex: 0 0 auto;
  }
  .mmsw-related-sub {
    margin: 0.35rem 0 0 0.9rem;
    color: #4b5563 !important;
    font-size: 0.88rem;
    line-height: 1.45;
  }
  .mmsw-related-track {
    display: flex;
    gap: 1.05rem;
    overflow-x: auto;
    overflow-y: hidden;
    scroll-snap-type: x mandatory;
    scroll-behavior: smooth;
    padding: 0.2rem 0.1rem 0.85rem;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior-x: contain;
    touch-action: pan-x;
    scrollbar-width: none;
  }
  .mmsw-related-track::-webkit-scrollbar { display: none; }
  .mmsw-card {
    flex: 0 0 var(--mmsw-card-w);
    width: var(--mmsw-card-w);
    max-width: 72vw;
    scroll-snap-align: start;
    text-decoration: none;
    color: inherit;
    transition: transform 0.2s ease;
    -webkit-tap-highlight-color: transparent;
  }
  @media (hover: hover) {
    .mmsw-card:hover { transform: translateY(-3px); }
  }
  .mmsw-poster {
    position: relative;
    aspect-ratio: 2 / 3;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 10px 24px rgba(18, 21, 26, 0.1);
    transition: box-shadow 0.2s ease;
    background-color: #1e293b;
  }
  .mmsw-card:hover .mmsw-poster {
    box-shadow: 0 16px 32px rgba(18, 21, 26, 0.14);
  }
  .mmsw-poster-img {
    position: absolute;
    inset: 0;
    z-index: 1;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    background: #0f172a;
  }
  .mmsw-poster-fallback {
    position: absolute;
    inset: 0;
    z-index: 0;
    display: grid;
    place-items: center;
    pointer-events: none;
  }
  .mmsw-poster-fallback[hidden] { display: none !important; }
  .mmsw-poster-letter {
    font-size: clamp(2.4rem, 8vw, 3.4rem);
    font-weight: 700;
    letter-spacing: -0.04em;
    line-height: 1;
    user-select: none;
    color: #ffffff !important;
    opacity: 0.88;
    text-shadow: 0 6px 24px rgba(0, 0, 0, 0.35);
  }
  .mmsw-tone-1 { background: linear-gradient(145deg, #1b3a4b 0%, #0f7a6c 55%, #163a34 100%) !important; }
  .mmsw-tone-2 { background: linear-gradient(145deg, #2b2118 0%, #b45309 55%, #3f2a14 100%) !important; }
  .mmsw-tone-3 { background: linear-gradient(145deg, #1e293b 0%, #64748b 50%, #0f172a 100%) !important; }
  .mmsw-tone-4 { background: linear-gradient(145deg, #312e81 0%, #0e7490 55%, #164e63 100%) !important; }
  .mmsw-tone-5 { background: linear-gradient(145deg, #3f1d2e 0%, #e11d48 50%, #1f2937 100%) !important; }
  .mmsw-tone-6 { background: linear-gradient(145deg, #14532d 0%, #22c55e 45%, #052e16 100%) !important; }
  .mmsw-badge {
    position: absolute;
    z-index: 3;
    padding: 0.2rem 0.48rem;
    border-radius: 999px;
    background: rgba(15, 18, 22, 0.88) !important;
    color: #ffffff !important;
    font-size: 0.66rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    line-height: 1.2;
    border: 1px solid rgba(255, 255, 255, 0.18);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
  }
  .mmsw-badge-hd { top: 0.5rem; left: 0.5rem; }
  .mmsw-badge-year { bottom: 0.5rem; left: 0.5rem; font-weight: 600; }
  .mmsw-card-body { padding: 0.65rem 0.1rem 0; }
  .mmsw-card-title {
    margin: 0;
    font-size: 0.86rem;
    font-weight: 600;
    line-height: 1.3;
    color: #12151a !important;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .mmsw-card-meta {
    margin: 0.3rem 0 0;
    color: #4b5563 !important;
    font-size: 0.76rem;
    line-height: 1.35;
  }
  .mmsw-empty, .mmsw-error {
    max-width: 1120px;
    margin: 0 auto;
    padding: 2.5rem 1.15rem;
    color: #4b5563 !important;
    font-family: var(--mmsw-font, system-ui, sans-serif);
    background: #ffffff;
    border-radius: 12px;
  }
  .mmsw-error { color: #b91c1c !important; }
  .mmsw-link { color: #1d4ed8 !important; }
  @media (max-width: 900px) {
    .mmsw { --mmsw-card-w: 150px; }
    .mmsw-shell { padding: 1.1rem 1rem 1.35rem; }
  }
  @media (max-width: 860px) {
    .mmsw-info {
      grid-template-columns: 1fr;
      gap: 1.25rem;
      padding: 1.15rem;
    }
    .mmsw-info-side {
      padding-left: 0;
      border-left: 0;
      padding-top: 1.1rem;
      border-top: 1px solid rgba(18, 21, 26, 0.12);
      grid-template-columns: 1fr 1fr;
      gap: 1rem 1.25rem;
    }
    .mmsw-side-block:first-child { grid-column: 1 / -1; }
    .mmsw-title { max-width: none; }
    .mmsw-synopsis { font-size: 0.98rem; max-width: none; }
  }
  @media (max-width: 640px) {
    .mmsw {
      --mmsw-card-w: 132px;
      --mmsw-radius: 14px;
      padding: 0.65rem max(0.55rem, env(safe-area-inset-right)) 2.5rem max(0.55rem, env(safe-area-inset-left));
    }
    .mmsw-shell {
      padding: 0.95rem 0.8rem 1.15rem;
      border-radius: 14px;
    }
    .mmsw-nav { margin-bottom: 0.95rem; }
    .mmsw-hero { margin-bottom: 0.95rem; }
    .mmsw-title {
      font-size: clamp(1.35rem, 7vw, 1.85rem);
      line-height: 1.15;
    }
    .mmsw-chips { gap: 0.35rem; margin-top: 0.75rem; }
    .mmsw-chip { font-size: 0.72rem; padding: 0.24rem 0.55rem; }
    .mmsw-player-stage { margin-bottom: 1.25rem; }
    .mmsw-player-wrap {
      border-radius: 12px;
      box-shadow: 0 12px 28px rgba(18, 21, 26, 0.12);
    }
    .mmsw-info {
      margin-bottom: 1.75rem;
      padding: 1rem;
      border-radius: 12px;
    }
    .mmsw-info-side { grid-template-columns: 1fr; }
    .mmsw-related-sub { display: none; }
    .mmsw-related-track { gap: 0.75rem; }
    .mmsw-card { max-width: 42vw; }
    .mmsw-card-title { font-size: 0.8rem; }
    .mmsw-poster { border-radius: 12px; }
    .mmsw-badge { font-size: 0.6rem; }
  }
  @media (max-width: 380px) {
    .mmsw { --mmsw-card-w: 118px; }
    .mmsw-shell { padding: 0.85rem 0.7rem 1rem; }
    .mmsw-back span { font-size: 0.84rem; }
  }
  @media (prefers-reduced-motion: reduce) {
    .mmsw-shell { animation: none; }
    .mmsw-card, .mmsw-back { transition: none; }
    .mmsw-related-track { scroll-behavior: auto; }
  }
</style>

<?php if ($needs_hls) : ?>
<script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js"></script>
<script>
(function () {
  var video = document.getElementById(<?php echo wp_json_encode($uid . '-video'); ?>);
  if (!video) return;
  var src = video.getAttribute('data-src') || '';
  if (!src) return;
  if (window.Hls && Hls.isSupported()) {
    var hls = new Hls();
    hls.loadSource(src);
    hls.attachMedia(video);
  } else {
    video.src = src;
  }
})();
</script>
<?php endif; ?>
<script>
(function () {
  var id = <?php echo wp_json_encode($catalog_id); ?>;
  var url = <?php echo wp_json_encode(rest_url(MMBA_API::REST_NS . '/movies/' . rawurlencode($catalog_id) . '/view')); ?>;
  var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
  var key = 'mmba_viewed_' + id;
  try {
    if (window.localStorage && localStorage.getItem(key)) return;
  } catch (e) {}
  if (!url) return;
  fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'X-WP-Nonce': nonce
    }
  }).then(function (r) {
    if (!r.ok) return;
    try {
      if (window.localStorage) localStorage.setItem(key, '1');
    } catch (e) {}
  }).catch(function () {});
})();
</script>
    <?php
    return ob_get_clean();
}

function mmsw_pick_episode(array $movie, $season_q = '', $episode_q = '') {
    $episodes = isset($movie['episodes']) && is_array($movie['episodes']) ? $movie['episodes'] : [];
    usort($episodes, static function ($a, $b) {
        $sa = isset($a['season_n']) ? (int) $a['season_n'] : 0;
        $sb = isset($b['season_n']) ? (int) $b['season_n'] : 0;
        if ($sa !== $sb) {
            return $sa - $sb;
        }
        $ea = isset($a['episode_n']) ? (int) $a['episode_n'] : 0;
        $eb = isset($b['episode_n']) ? (int) $b['episode_n'] : 0;
        return $ea - $eb;
    });
    $current = [
        'id'         => isset($movie['id']) ? (string) $movie['id'] : '',
        'movie_link' => isset($movie['movie_link']) ? (string) $movie['movie_link'] : '',
        'poster'     => isset($movie['poster']) ? (string) $movie['poster'] : '',
        'details'    => isset($movie['details']) ? (string) $movie['details'] : '',
        'cast'       => isset($movie['cast']) ? (string) $movie['cast'] : '',
        'year'       => isset($movie['year']) ? (string) $movie['year'] : '',
        'genre'      => isset($movie['genre']) ? (string) $movie['genre'] : '',
        'season'     => isset($movie['season']) ? (string) $movie['season'] : '',
        'episode'    => isset($movie['episode']) ? (string) $movie['episode'] : '',
        'season_n'   => isset($movie['season_n']) ? (int) $movie['season_n'] : 0,
        'episode_n'  => isset($movie['episode_n']) ? (int) $movie['episode_n'] : 0,
    ];

    if (empty($episodes)) {
        return ['episodes' => [], 'current' => $current, 'id' => $current['id']];
    }

    $season_n = 0;
    $episode_n = 0;
    if (preg_match('/(\d+)/', (string) $season_q, $m)) {
        $season_n = (int) $m[1];
    }
    if (preg_match('/(\d+)/', (string) $episode_q, $m)) {
        $episode_n = (int) $m[1];
    }

    $wanted_id = isset($movie['current_episode_id']) ? (string) $movie['current_episode_id'] : '';
    $match = null;
    foreach ($episodes as $ep) {
        if ($wanted_id !== '' && isset($ep['id']) && (string) $ep['id'] === $wanted_id && $season_q === '' && $episode_q === '') {
            $match = $ep;
            break;
        }
        $esn = isset($ep['season_n']) ? (int) $ep['season_n'] : 0;
        $een = isset($ep['episode_n']) ? (int) $ep['episode_n'] : 0;
        if ($season_n && $esn !== $season_n) {
            continue;
        }
        if ($episode_n && $een === $episode_n && ($season_n === 0 || $esn === $season_n)) {
            $match = $ep;
            break;
        }
        if ($season_n && !$episode_n && $esn === $season_n && $match === null) {
            $match = $ep;
        }
    }

    if ($match === null) {
        $match = $episodes[0];
    }

    return [
        'episodes' => $episodes,
        'current'  => $match,
        'id'       => isset($movie['id']) ? (string) $movie['id'] : '',
    ];
}

/**
 * Split comma-separated metadata into a clean list.
 *
 * @param string $value
 * @return string[]
 */
function mmsw_split_list($value) {
    $parts = preg_split('/\s*,\s*/', (string) $value);
    if (!is_array($parts)) {
        return [];
    }
    $out = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return $out;
}

/**
 * Related titles that share at least one genre (excluding current).
 *
 * @param array $movie
 * @param int   $limit
 * @return array
 */
function mmsw_related_series(array $movie, $limit = 12) {
    $limit = (int) $limit;
    if ($limit <= 0 || !class_exists('MMBA_Storage')) {
        return [];
    }

    $id = isset($movie['id']) ? (string) $movie['id'] : '';
    $genre = isset($movie['genre']) ? (string) $movie['genre'] : '';
    $parts = mmsw_split_list($genre);

    if (empty($parts)) {
        return [];
    }

    $scored = [];
    $catalog = method_exists('MMBA_Storage', 'get_series')
        ? MMBA_Storage::get_series()
        : MMBA_Storage::get_movies();
    foreach ($catalog as $candidate) {
        if (isset($candidate['type']) && $candidate['type'] !== 'series') {
            continue;
        }
        $cid = isset($candidate['id']) ? (string) $candidate['id'] : '';
        if ($cid === '' || $cid === $id) {
            continue;
        }
        $cgenre = isset($candidate['genre']) ? (string) $candidate['genre'] : '';
        $score = 0;
        foreach ($parts as $g) {
            if (MMBA_Storage::genre_matches($cgenre, $g)) {
                $score++;
            }
        }
        if ($score > 0) {
            $scored[] = ['score' => $score, 'movie' => $candidate];
        }
    }

    usort($scored, static function ($a, $b) {
        if ($a['score'] === $b['score']) {
            return strcmp(
                isset($a['movie']['title']) ? (string) $a['movie']['title'] : '',
                isset($b['movie']['title']) ? (string) $b['movie']['title'] : ''
            );
        }
        return $b['score'] - $a['score'];
    });

    $out = [];
    foreach ($scored as $row) {
        $out[] = $row['movie'];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function mmsw_poster_tone($title) {
    $sum = 0;
    $s = (string) $title;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $sum += ord($s[$i]);
    }
    return ($sum % 6) + 1;
}
