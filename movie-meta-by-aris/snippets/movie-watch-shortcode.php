/**
 * Code Snippets plugin — paste this as a PHP snippet (Run everywhere).
 *
 * Shortcode: [movie_watch]
 * Optional:  [movie_watch home_url="/" related="12"]
 *
 * Create a WP page at /watch/ and put [movie_watch] in the content.
 * Genre rows link here as: /watch/?id=MOVIE_ID
 *
 * Requires: Movie Meta by Aris plugin (data source).
 * Pair with snippets/genre-rows-shortcode.php → [movie_genre_rows]
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('movie_watch', 'mmw_render_watch_shortcode');

function mmw_render_watch_shortcode($atts = []) {
    $atts = shortcode_atts(
        [
            'id'        => '',
            'related'   => '12',
            'home_url'  => '/',
            'watch_url' => '/watch/',
        ],
        $atts,
        'movie_watch'
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
        return '<div class="mmw mmw-empty">' . esc_html__('No movie selected. Open a title from the catalog.', 'movie-meta-by-aris') . '</div>';
    }

    if (!class_exists('MMBA_Storage')) {
        return '<div class="mmw mmw-error">' . esc_html__('Movie Meta by Aris plugin is required.', 'movie-meta-by-aris') . '</div>';
    }

    $movie = MMBA_Storage::get_movie($id);
    if (!$movie) {
        return '<div class="mmw mmw-empty">' . esc_html__('Movie not found.', 'movie-meta-by-aris') .
            ' <a class="mmw-link" href="' . esc_url($home_url) . '">' . esc_html__('Back to catalog', 'movie-meta-by-aris') . '</a></div>';
    }

    $catalog_id = isset($movie['id']) ? (string) $movie['id'] : $id;
    if (method_exists('MMBA_Storage', 'increment_view')) {
        MMBA_Storage::increment_view($catalog_id);
    }

    $title   = isset($movie['title']) ? (string) $movie['title'] : '';
    $details = isset($movie['details']) ? (string) $movie['details'] : '';
    $cast    = isset($movie['cast']) ? (string) $movie['cast'] : '';
    $year    = isset($movie['year']) ? (string) $movie['year'] : '';
    $genre   = isset($movie['genre']) ? (string) $movie['genre'] : '';
    $link    = isset($movie['movie_link']) ? (string) $movie['movie_link'] : '';
    $link_type = MMBA_Storage::get_movie_link_type($link);
    $play_url  = $link_type === 'embed' ? MMBA_Storage::get_embed_url($link) : $link;
    $play_src  = MMBA_Storage::escape_play_url($play_url);
    $poster    = MMBA_Storage::get_poster_url($link);

    $genres = mmw_split_list($genre);
    $cast_list = mmw_split_list($cast);
    $related = mmw_related_movies($movie, $related_limit);

    $uid = 'mmw-' . wp_unique_id();
    $needs_hls = ($link_type === 'hls' && $play_url !== '');
    $display_title = $title !== '' ? $title : __('Untitled', 'movie-meta-by-aris');

    ob_start();
    ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&display=swap">
<div id="<?php echo esc_attr($uid); ?>" class="mmw" data-link-type="<?php echo esc_attr($link_type); ?>">
  <div class="mmw-shell">
    <nav class="mmw-nav" aria-label="<?php echo esc_attr__('Watch navigation', 'movie-meta-by-aris'); ?>">
      <a class="mmw-back" href="<?php echo esc_url($home_url); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
        <span><?php echo esc_html__('Back to movies', 'movie-meta-by-aris'); ?></span>
      </a>
    </nav>

    <header class="mmw-hero">
      <div class="mmw-hero-copy">
        <p class="mmw-kicker"><?php echo esc_html__('Now playing', 'movie-meta-by-aris'); ?></p>
        <h1 class="mmw-title"><?php echo esc_html($display_title); ?></h1>
        <div class="mmw-chips" role="list">
          <span class="mmw-chip mmw-chip-hd" role="listitem">HD</span>
          <?php if ($year !== '') : ?>
            <span class="mmw-chip" role="listitem"><?php echo esc_html($year); ?></span>
          <?php endif; ?>
          <?php foreach ($genres as $g) : ?>
            <span class="mmw-chip mmw-chip-soft" role="listitem"><?php echo esc_html($g); ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </header>

    <div class="mmw-player-stage">
      <div class="mmw-player-wrap">
        <?php if ($play_url === '') : ?>
          <div class="mmw-player-empty"><?php echo esc_html__('No stream available for this title.', 'movie-meta-by-aris'); ?></div>
    <?php elseif ($link_type === 'embed') : ?>
      <iframe
        class="mmw-player"
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
        class="mmw-player"
        controls
        playsinline
        <?php if ($poster !== '') : ?>poster="<?php echo esc_url($poster); ?>"<?php endif; ?>
        data-src="<?php echo $play_src; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape_play_url() ?>"
      ></video>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($details !== '' || $cast !== '' || $year !== '' || !empty($genres)) : ?>
    <section class="mmw-info" aria-label="<?php echo esc_attr__('Movie information', 'movie-meta-by-aris'); ?>">
      <?php if ($details !== '') : ?>
        <div class="mmw-info-main">
          <h2 class="mmw-label"><?php echo esc_html__('Movie Details', 'movie-meta-by-aris'); ?></h2>
          <p class="mmw-synopsis"><?php echo esc_html($details); ?></p>
        </div>
      <?php endif; ?>

      <aside class="mmw-info-side">
        <?php if (!empty($cast_list)) : ?>
          <div class="mmw-side-block">
            <h2 class="mmw-label"><?php echo esc_html__('Cast', 'movie-meta-by-aris'); ?></h2>
            <ul class="mmw-cast">
              <?php foreach ($cast_list as $person) : ?>
                <li><?php echo esc_html($person); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php elseif ($cast !== '') : ?>
          <div class="mmw-side-block">
            <h2 class="mmw-label"><?php echo esc_html__('Cast', 'movie-meta-by-aris'); ?></h2>
            <p class="mmw-side-text"><?php echo esc_html($cast); ?></p>
          </div>
        <?php endif; ?>

        <?php if ($year !== '') : ?>
          <div class="mmw-side-block">
            <h2 class="mmw-label"><?php echo esc_html__('Year', 'movie-meta-by-aris'); ?></h2>
            <p class="mmw-side-text mmw-side-strong"><?php echo esc_html($year); ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($genres)) : ?>
          <div class="mmw-side-block">
            <h2 class="mmw-label"><?php echo esc_html__('Genre', 'movie-meta-by-aris'); ?></h2>
            <div class="mmw-chips mmw-chips-tight">
              <?php foreach ($genres as $g) : ?>
                <span class="mmw-chip mmw-chip-soft"><?php echo esc_html($g); ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </aside>
    </section>
    <?php endif; ?>

    <?php if (!empty($related)) : ?>
    <section class="mmw-related" aria-label="<?php echo esc_attr__('More like this', 'movie-meta-by-aris'); ?>">
      <div class="mmw-related-head">
        <h2 class="mmw-related-title"><?php echo esc_html__('More Like This', 'movie-meta-by-aris'); ?></h2>
        <p class="mmw-related-sub"><?php echo esc_html__('Titles that share a genre with this movie.', 'movie-meta-by-aris'); ?></p>
      </div>
      <div class="mmw-related-track" tabindex="0">
        <?php foreach ($related as $item) :
            $rid = isset($item['id']) ? (string) $item['id'] : '';
            $rtitle = isset($item['title']) ? (string) $item['title'] : '';
            $ryear = isset($item['year']) ? (string) $item['year'] : '';
            $rgenre = isset($item['genre']) ? (string) $item['genre'] : '';
            $rgenres = mmw_split_list($rgenre);
            $rprimary = !empty($rgenres) ? $rgenres[0] : '';
            $rlink = isset($item['movie_link']) ? (string) $item['movie_link'] : '';
            $rposter = MMBA_Storage::get_poster_url($rlink);
            $rhref = $watch_url . (strpos($watch_url, '?') === false ? '?' : '&') . 'id=' . rawurlencode($rid);
            $initial = $rtitle !== '' ? strtoupper(substr($rtitle, 0, 1)) : 'M';
            $tone = mmw_poster_tone($rtitle);
            $meta_bits = array_filter([$ryear, $rprimary]);
            $rdisplay = $rtitle !== '' ? $rtitle : __('Untitled', 'movie-meta-by-aris');
            $img_meta = method_exists('MMBA_Storage', 'poster_image_meta')
                ? MMBA_Storage::poster_image_meta($rdisplay)
                : ($rdisplay . ' DesiMoviesHub Free Watch');
            ?>
          <a class="mmw-card" href="<?php echo esc_url($rhref); ?>">
            <div class="mmw-poster mmw-tone-<?php echo (int) $tone; ?>">
              <?php if ($rposter !== '') : ?>
                <img class="mmw-poster-img" src="<?php echo esc_url($rposter); ?>" alt="<?php echo esc_attr($img_meta); ?>" title="<?php echo esc_attr($img_meta); ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                  onerror="this.style.display='none';var f=this.parentNode.querySelector('.mmw-poster-fallback');if(f){f.hidden=false;f.removeAttribute('hidden');}">
                <div class="mmw-poster-fallback" hidden aria-hidden="true">
                  <span class="mmw-poster-letter"><?php echo esc_html($initial); ?></span>
                </div>
              <?php else : ?>
                <div class="mmw-poster-fallback" aria-hidden="true">
                  <span class="mmw-poster-letter"><?php echo esc_html($initial); ?></span>
                </div>
              <?php endif; ?>
              <span class="mmw-badge mmw-badge-hd">HD</span>
              <?php if ($ryear !== '') : ?>
                <span class="mmw-badge mmw-badge-year"><?php echo esc_html($ryear); ?></span>
              <?php endif; ?>
            </div>
            <div class="mmw-card-body">
              <h3 class="mmw-card-title"><?php echo esc_html($rtitle !== '' ? $rtitle : __('Untitled', 'movie-meta-by-aris')); ?></h3>
              <?php if (!empty($meta_bits)) : ?>
                <p class="mmw-card-meta"><?php echo esc_html(implode(' · ', $meta_bits)); ?></p>
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
  .mmw,
  .mmw *,
  .mmw h1,
  .mmw h2,
  .mmw h3,
  .mmw p,
  .mmw span,
  .mmw li,
  .mmw a,
  .mmw div {
    color: inherit;
  }
  .mmw, .mmw *, .mmw *::before, .mmw *::after { box-sizing: border-box; }
  .mmw {
    --mmw-ink: #12151a;
    --mmw-muted: #4b5563;
    --mmw-soft: #6b7280;
    --mmw-line: rgba(18, 21, 26, 0.12);
    --mmw-surface: #ffffff;
    --mmw-panel: #eef1f5;
    --mmw-accent: #1d4ed8;
    --mmw-radius: 16px;
    --mmw-radius-sm: 10px;
    --mmw-card-w: 168px;
    --mmw-font: "Sora", "Avenir Next", "Segoe UI", system-ui, sans-serif;
    color: #12151a !important;
    font-family: var(--mmw-font);
    isolation: isolate;
    position: relative;
    width: 100%;
    max-width: 100%;
    padding: 1rem max(0px, env(safe-area-inset-right)) 3.5rem max(0px, env(safe-area-inset-left));
    overflow-x: clip;
  }
  .mmw-shell {
    max-width: 98%;
    width: 100%;
    margin: 0 auto;
    padding: 1.25rem 1.15rem 1.5rem;
    background: #ffffff !important;
    border: 1px solid rgba(18, 21, 26, 0.08);
    border-radius: 18px;
    box-shadow: 0 12px 36px rgba(18, 21, 26, 0.06);
    color: #12151a !important;
    animation: mmw-in 0.45s ease both;
  }
  @keyframes mmw-in {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: none; }
  }
  .mmw-nav { margin: 0 0 1.35rem; }
  .mmw-back {
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
  .mmw-back svg { width: 18px; height: 18px; display: block; stroke: currentColor; }
  .mmw-back:hover { color: #12151a !important; background: #eef1f5; }
  .mmw-hero { margin: 0 0 1.25rem; }
  .mmw-kicker {
    margin: 0 0 0.45rem;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #1d4ed8 !important;
  }
  .mmw-title {
    margin: 0;
    font-size: clamp(1.65rem, 3.6vw, 2.45rem);
    font-weight: 700;
    letter-spacing: -0.035em;
    line-height: 1.12;
    max-width: 18ch;
    color: #12151a !important;
  }
  .mmw-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin-top: 0.95rem;
  }
  .mmw-chips-tight { margin-top: 0.35rem; }
  .mmw-chip {
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
  .mmw-chip-hd {
    background: #12151a !important;
    border-color: #12151a !important;
    color: #ffffff !important;
  }
  .mmw-chip-soft {
    background: #eef1f5 !important;
    color: #1f2937 !important;
  }
  .mmw-player-stage { margin: 0 0 1.75rem; }
  .mmw-player-wrap {
    position: relative;
    border-radius: calc(var(--mmw-radius) + 2px);
    overflow: hidden;
    background: #0b0d10;
    box-shadow:
      0 1px 0 rgba(255, 255, 255, 0.6) inset,
      0 22px 50px rgba(18, 21, 26, 0.16);
    border: 1px solid rgba(18, 21, 26, 0.08);
  }
  .mmw-player-wrap::after {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.04);
    border-radius: inherit;
  }
  .mmw-player {
    display: block;
    width: 100%;
    max-width: 100%;
    height: auto;
    aspect-ratio: 16 / 9;
    border: 0;
    background: #000;
  }
  .mmw-player-empty {
    aspect-ratio: 16 / 9;
    display: grid;
    place-items: center;
    color: #9ca3af;
    font-size: 0.95rem;
    padding: 1.25rem;
    text-align: center;
  }
  .mmw-info {
    display: grid;
    grid-template-columns: minmax(0, 1.55fr) minmax(240px, 0.85fr);
    gap: 1.5rem 2rem;
    margin: 0 0 2.75rem;
    padding: 1.35rem 1.35rem 1.45rem;
    border: 1px solid rgba(18, 21, 26, 0.12);
    border-radius: var(--mmw-radius);
    background: #f8fafc !important;
    box-shadow: none;
    color: #12151a !important;
    min-width: 0;
  }
  .mmw-label {
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
  .mmw-label::before {
    content: "";
    width: 3px;
    height: 0.95em;
    border-radius: 999px;
    background: #1d4ed8;
    flex: 0 0 auto;
  }
  .mmw-synopsis {
    margin: 0;
    font-size: 1.02rem;
    line-height: 1.72;
    color: #1f2937 !important;
    max-width: 58ch;
    overflow-wrap: anywhere;
    word-break: break-word;
  }
  .mmw-info-main { min-width: 0; }
  .mmw-info-side {
    display: grid;
    gap: 1.25rem;
    align-content: start;
    padding-left: 1.5rem;
    border-left: 1px solid rgba(18, 21, 26, 0.12);
  }
  .mmw-side-block { min-width: 0; }
  .mmw-side-text {
    margin: 0;
    font-size: 0.95rem;
    line-height: 1.55;
    color: #1f2937 !important;
  }
  .mmw-side-strong {
    font-size: 1.15rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: #12151a !important;
  }
  .mmw-cast {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
  }
  .mmw-cast li {
    padding: 0.28rem 0.6rem;
    border-radius: 8px;
    background: #ffffff !important;
    border: 1px solid rgba(18, 21, 26, 0.12);
    font-size: 0.82rem;
    font-weight: 500;
    color: #111827 !important;
    line-height: 1.3;
  }
  .mmw-related { margin-top: 0.25rem; color: #12151a !important; }
  .mmw-related-head { margin: 0 0 1rem; }
  .mmw-related-title {
    margin: 0;
    font-size: clamp(1.15rem, 2vw, 1.35rem);
    font-weight: 700;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 0.55rem;
    color: #12151a !important;
  }
  .mmw-related-title::before {
    content: "";
    width: 4px;
    height: 1.05em;
    border-radius: 999px;
    background: #1d4ed8;
    flex: 0 0 auto;
  }
  .mmw-related-sub {
    margin: 0.35rem 0 0 0.9rem;
    color: #4b5563 !important;
    font-size: 0.88rem;
    line-height: 1.45;
  }
  .mmw-related-track {
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
  .mmw-related-track::-webkit-scrollbar { display: none; }
  .mmw-card {
    flex: 0 0 var(--mmw-card-w);
    width: var(--mmw-card-w);
    max-width: 72vw;
    scroll-snap-align: start;
    text-decoration: none;
    color: inherit;
    transition: transform 0.2s ease;
    -webkit-tap-highlight-color: transparent;
  }
  @media (hover: hover) {
    .mmw-card:hover { transform: translateY(-3px); }
  }
  .mmw-poster {
    position: relative;
    aspect-ratio: 2 / 3;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 10px 24px rgba(18, 21, 26, 0.1);
    transition: box-shadow 0.2s ease;
    background-color: #1e293b;
  }
  .mmw-card:hover .mmw-poster {
    box-shadow: 0 16px 32px rgba(18, 21, 26, 0.14);
  }
  .mmw-poster-img {
    position: absolute;
    inset: 0;
    z-index: 1;
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    background: #0f172a;
  }
  .mmw-poster-fallback {
    position: absolute;
    inset: 0;
    z-index: 0;
    display: grid;
    place-items: center;
    pointer-events: none;
  }
  .mmw-poster-fallback[hidden] { display: none !important; }
  .mmw-poster-letter {
    font-size: clamp(2.4rem, 8vw, 3.4rem);
    font-weight: 700;
    letter-spacing: -0.04em;
    line-height: 1;
    user-select: none;
    color: #ffffff !important;
    opacity: 0.88;
    text-shadow: 0 6px 24px rgba(0, 0, 0, 0.35);
  }
  .mmw-tone-1 { background: linear-gradient(145deg, #1b3a4b 0%, #0f7a6c 55%, #163a34 100%) !important; }
  .mmw-tone-2 { background: linear-gradient(145deg, #2b2118 0%, #b45309 55%, #3f2a14 100%) !important; }
  .mmw-tone-3 { background: linear-gradient(145deg, #1e293b 0%, #64748b 50%, #0f172a 100%) !important; }
  .mmw-tone-4 { background: linear-gradient(145deg, #312e81 0%, #0e7490 55%, #164e63 100%) !important; }
  .mmw-tone-5 { background: linear-gradient(145deg, #3f1d2e 0%, #e11d48 50%, #1f2937 100%) !important; }
  .mmw-tone-6 { background: linear-gradient(145deg, #14532d 0%, #22c55e 45%, #052e16 100%) !important; }
  .mmw-badge {
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
  .mmw-badge-hd { top: 0.5rem; left: 0.5rem; }
  .mmw-badge-year { bottom: 0.5rem; left: 0.5rem; font-weight: 600; }
  .mmw-card-body { padding: 0.65rem 0.1rem 0; }
  .mmw-card-title {
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
  .mmw-card-meta {
    margin: 0.3rem 0 0;
    color: #4b5563 !important;
    font-size: 0.76rem;
    line-height: 1.35;
  }
  .mmw-empty, .mmw-error {
    max-width: 1120px;
    margin: 0 auto;
    padding: 2.5rem 1.15rem;
    color: #4b5563 !important;
    font-family: var(--mmw-font, system-ui, sans-serif);
    background: #ffffff;
    border-radius: 12px;
  }
  .mmw-error { color: #b91c1c !important; }
  .mmw-link { color: #1d4ed8 !important; }
  @media (max-width: 900px) {
    .mmw { --mmw-card-w: 150px; }
    .mmw-shell { padding: 1.1rem 1rem 1.35rem; }
  }
  @media (max-width: 860px) {
    .mmw-info {
      grid-template-columns: 1fr;
      gap: 1.25rem;
      padding: 1.15rem;
    }
    .mmw-info-side {
      padding-left: 0;
      border-left: 0;
      padding-top: 1.1rem;
      border-top: 1px solid rgba(18, 21, 26, 0.12);
      grid-template-columns: 1fr 1fr;
      gap: 1rem 1.25rem;
    }
    .mmw-side-block:first-child { grid-column: 1 / -1; }
    .mmw-title { max-width: none; }
    .mmw-synopsis { font-size: 0.98rem; max-width: none; }
  }
  @media (max-width: 640px) {
    .mmw {
      --mmw-card-w: 132px;
      --mmw-radius: 14px;
      padding: 0.65rem max(0.55rem, env(safe-area-inset-right)) 2.5rem max(0.55rem, env(safe-area-inset-left));
    }
    .mmw-shell {
      padding: 0.95rem 0.8rem 1.15rem;
      border-radius: 14px;
    }
    .mmw-nav { margin-bottom: 0.95rem; }
    .mmw-hero { margin-bottom: 0.95rem; }
    .mmw-title {
      font-size: clamp(1.35rem, 7vw, 1.85rem);
      line-height: 1.15;
    }
    .mmw-chips { gap: 0.35rem; margin-top: 0.75rem; }
    .mmw-chip { font-size: 0.72rem; padding: 0.24rem 0.55rem; }
    .mmw-player-stage { margin-bottom: 1.25rem; }
    .mmw-player-wrap {
      border-radius: 12px;
      box-shadow: 0 12px 28px rgba(18, 21, 26, 0.12);
    }
    .mmw-info {
      margin-bottom: 1.75rem;
      padding: 1rem;
      border-radius: 12px;
    }
    .mmw-info-side { grid-template-columns: 1fr; }
    .mmw-related-sub { display: none; }
    .mmw-related-track { gap: 0.75rem; }
    .mmw-card { max-width: 42vw; }
    .mmw-card-title { font-size: 0.8rem; }
    .mmw-poster { border-radius: 12px; }
    .mmw-badge { font-size: 0.6rem; }
  }
  @media (max-width: 380px) {
    .mmw { --mmw-card-w: 118px; }
    .mmw-shell { padding: 0.85rem 0.7rem 1rem; }
    .mmw-back span { font-size: 0.84rem; }
  }
  @media (prefers-reduced-motion: reduce) {
    .mmw-shell { animation: none; }
    .mmw-card, .mmw-back { transition: none; }
    .mmw-related-track { scroll-behavior: auto; }
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
  var url = <?php echo wp_json_encode(rest_url('movie-meta/v1/movies/' . rawurlencode($catalog_id) . '/view')); ?>;
  var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
  if (!id || !url) return;
  fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    keepalive: true,
    headers: {
      Accept: 'application/json',
      'X-WP-Nonce': nonce
    }
  }).catch(function () {});
})();
</script>
    <?php
    return ob_get_clean();
}

/**
 * Split comma-separated metadata into a clean list.
 *
 * @param string $value
 * @return string[]
 */
function mmw_split_list($value) {
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
function mmw_related_movies(array $movie, $limit = 12) {
    $limit = (int) $limit;
    if ($limit <= 0 || !class_exists('MMBA_Storage')) {
        return [];
    }

    $id = isset($movie['id']) ? (string) $movie['id'] : '';
    $genre = isset($movie['genre']) ? (string) $movie['genre'] : '';
    $parts = mmw_split_list($genre);

    if (empty($parts)) {
        return [];
    }

    $scored = [];
    foreach (MMBA_Storage::get_movies() as $candidate) {
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

function mmw_poster_tone($title) {
    $sum = 0;
    $s = (string) $title;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $sum += ord($s[$i]);
    }
    return ($sum % 6) + 1;
}
