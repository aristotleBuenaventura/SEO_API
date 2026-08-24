<?php
/**
 * Code Snippets plugin — paste this as a PHP snippet (Run everywhere).
 *
 * Shortcode: [movie_search_bar]
 * Optional:
 *   [movie_search_bar search_url="/search/" q_param="q" placeholder="Search movies, show"]
 *
 * Notes:
 * - This shortcode outputs a GET form that navigates to the results URL.
 * - Pair with snippets/movie-search-results-shortcode.php → [movie_search_results]
 *   placed on the page at search_url.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('movie_search_bar', 'mmsrch_render_search_bar_shortcode');

function mmsrch_render_search_bar_shortcode($atts = []) {
    $atts = shortcode_atts(
        [
            'search_url' => '/search/',
            'q_param' => 'q',
            'placeholder' => 'Search movies, show',
        ],
        $atts,
        'movie_search_bar'
    );

    $search_url = (string) $atts['search_url'];
    if ($search_url !== '' && strpos($search_url, 'http') !== 0) {
        $search_url = home_url($search_url);
    }
    $search_url = esc_url($search_url);

    $q_param = sanitize_key((string) $atts['q_param']);
    if ($q_param === '') {
        $q_param = 'q';
    }

    $placeholder = sanitize_text_field((string) $atts['placeholder']);

    $current_q = '';
    if (isset($_GET[$q_param])) {
        $current_q = sanitize_text_field(wp_unslash((string) $_GET[$q_param]));
    }

    $uid = 'mmsrchbar-' . wp_unique_id();

    ob_start();
    ?>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&display=swap">
    <style>
      .mmsrchbar, .mmsrchbar *::before, .mmsrchbar *::after { box-sizing: border-box; }
      .mmsrchbar {
        --mmsrchbar-font: "Sora", "Avenir Next", "Segoe UI", system-ui, sans-serif;
        --mmsrchbar-ink: #12151a;
        --mmsrchbar-muted: #6b7280;
        --mmsrchbar-line: rgba(18, 21, 26, 0.12);
        --mmsrchbar-bg: #ffffff;
        font-family: var(--mmsrchbar-font);
      }
      .mmsrchbar-shell {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        background: var(--mmsrchbar-bg);
        border: 1px solid var(--mmsrchbar-line);
        border-radius: 999px;
        padding: 0.75rem 1rem;
        box-shadow: 0 10px 30px rgba(18, 21, 26, 0.05);
        width: 100%;
        max-width: 720px;
      }
      .mmsrchbar-icon-btn {
        width: 34px;
        height: 34px;
        min-width: 34px;
        border-radius: 999px;
        border: 0;
        background: transparent;
        padding: 0;
        display: inline-grid;
        place-items: center;
        cursor: pointer;
        color: var(--mmsrchbar-muted);
        -webkit-tap-highlight-color: transparent;
      }
      .mmsrchbar-icon-btn:hover { color: var(--mmsrchbar-ink); }
      .mmsrchbar-icon {
        width: 20px;
        height: 20px;
        display: block;
      }
      .mmsrchbar-input {
        border: 0;
        outline: 0;
        background: transparent;
        color: var(--mmsrchbar-ink);
        font-size: 1.02rem;
        width: 100%;
        min-width: 0;
      }
      .mmsrchbar-input::placeholder {
        color: rgba(107, 114, 128, 0.9);
      }
      .mmsrchbar-form {
        margin: 0;
        width: 100%;
      }
      .mmsrchbar-sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        border: 0;
        white-space: nowrap;
      }
    </style>

    <form
      id="<?php echo esc_attr($uid); ?>"
      class="mmsrchbar mmsrchbar-form"
      method="get"
      action="<?php echo esc_url($search_url); ?>"
      role="search"
    >
      <div class="mmsrchbar-shell">
        <button type="submit" class="mmsrchbar-icon-btn" aria-label="<?php echo esc_attr__('Search', 'movie-meta-by-aris'); ?>">
          <span class="mmsrchbar-sr-only"><?php echo esc_html__('Search', 'movie-meta-by-aris'); ?></span>
          <svg class="mmsrchbar-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="11" cy="11" r="7"></circle>
            <path d="M21 21l-4.35-4.35"></path>
          </svg>
        </button>
        <input
          class="mmsrchbar-input"
          type="search"
          name="<?php echo esc_attr($q_param); ?>"
          value="<?php echo esc_attr($current_q); ?>"
          placeholder="<?php echo esc_attr($placeholder); ?>"
          autocomplete="off"
        />
      </div>
    </form>
    <?php
    return ob_get_clean();
}

