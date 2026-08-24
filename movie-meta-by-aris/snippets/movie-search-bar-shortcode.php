<?php
/**
 * Code Snippets plugin — paste this as a PHP snippet (Run everywhere).
 *
 * Shortcode: [movie_search_bar]
 * Optional:
 *   [movie_search_bar search_url="/search/" q_param="q" placeholder="Search movies and series"]
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
            'placeholder' => 'Search movies and series',
            // Used only for suggestion click targets (not for the form submit).
            'watch_url' => '/watch/',
            'series_watch_url' => '/series-watch/',
            'suggest_limit' => '0', // 0 = preload all titles; otherwise cap the suggestion source list.
            'suggest_min_chars' => '2', // Minimum typed chars before showing suggestions.
            'suggest_count' => '8', // Max suggestions shown at once.
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

    $current_q = '';
    if (isset($_GET[$q_param])) {
        $current_q = sanitize_text_field(wp_unslash((string) $_GET[$q_param]));
    }

    $suggest_limit = max(0, min(1000, absint($atts['suggest_limit'])));
    $suggest_min_chars = max(1, min(20, absint($atts['suggest_min_chars'])));
    $suggest_count = max(1, min(12, absint($atts['suggest_count'])));

    $suggestions = [];
    if (class_exists('MMBA_Storage')) {
        $catalog = MMBA_Storage::get_movies();
        if (!is_array($catalog)) {
            $catalog = [];
        }

        if (method_exists('MMBA_Storage', 'get_series')) {
            $series_catalog = MMBA_Storage::get_series();
            if (is_array($series_catalog) && !empty($series_catalog)) {
                $catalog = array_merge($catalog, $series_catalog);
            }
        }

        // Preload a limited set of titles to keep the front-end lightweight.
        $candidates = [];
        $seen_ids = [];
        foreach ($catalog as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = isset($item['id']) ? (string) $item['id'] : '';
            $title = isset($item['title']) ? (string) $item['title'] : '';
            if ($id === '' || trim($title) === '') {
                continue;
            }
            if (isset($seen_ids[$id])) {
                continue;
            }
            $seen_ids[$id] = true;
            $type = isset($item['type']) ? (string) $item['type'] : '';
            if ($type === '') {
                $has_episodes = !empty($item['episodes']) || !empty($item['season_count']) || !empty($item['episode_count']);
                $type = $has_episodes ? 'series' : 'movie';
            }

            $href_base = (strcasecmp($type, 'series') === 0) ? $series_watch_url : $watch_url;
            if ($href_base === '') {
                continue;
            }
            $join = (strpos($href_base, '?') === false) ? '?' : '&';
            $href = $href_base . $join . 'id=' . rawurlencode($id);

            $candidates[] = [
                'title' => $title,
                'type' => $type,
                'href' => $href,
            ];
        }

        usort($candidates, static function ($a, $b) {
            return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
        });

        $suggestions = ($suggest_limit > 0)
            ? array_slice($candidates, 0, $suggest_limit)
            : $candidates;
    }

    $uid = 'mmsrchbar-' . wp_unique_id();
    $suggestions_json = wp_json_encode($suggestions);

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
      }
      .mmsrchbar-wrap {
        position: relative;
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
      .mmsrchbar-suggest {
        position: absolute;
        left: 0;
        right: 0;
        top: calc(100% + 10px);
        background: #ffffff;
        border: 1px solid var(--mmsrchbar-line);
        border-radius: 16px;
        box-shadow: 0 22px 60px rgba(18, 21, 26, 0.12);
        overflow: hidden;
        z-index: 9999;
      }
      .mmsrchbar-suggest a {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.65rem 1rem;
        text-decoration: none;
        color: var(--mmsrchbar-ink);
        border-bottom: 1px solid rgba(18, 21, 26, 0.06);
      }
      .mmsrchbar-suggest a:last-child { border-bottom: 0; }
      .mmsrchbar-suggest a:hover {
        background: rgba(37, 99, 235, 0.08);
      }
      .mmsrchbar-suggest-title {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-weight: 650;
      }
      .mmsrchbar-suggest-type {
        flex: 0 0 auto;
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--mmsrchbar-muted);
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
      data-suggestions="<?php echo esc_attr($suggestions_json); ?>"
      data-suggest-min-chars="<?php echo esc_attr((string) $suggest_min_chars); ?>"
      data-suggest-count="<?php echo esc_attr((string) $suggest_count); ?>"
    >
      <div class="mmsrchbar-wrap">
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

        <div class="mmsrchbar-suggest" hidden aria-hidden="true"></div>
      </div>
    </form>

    <script>
      (function () {
        var root = document.getElementById(<?php echo wp_json_encode($uid); ?>);
        if (!root) return;

        var input = root.querySelector('.mmsrchbar-input');
        var suggest = root.querySelector('.mmsrchbar-suggest');
        if (!input || !suggest) return;

        var raw = root.getAttribute('data-suggestions') || '[]';
        var suggestions = [];
        try { suggestions = JSON.parse(raw); } catch (e) {}

        var minChars = parseInt(root.getAttribute('data-suggest-min-chars') || '2', 10) || 2;
        var maxItems = parseInt(root.getAttribute('data-suggest-count') || '8', 10) || 8;

        function esc(s) {
          return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        }

        function hide() {
          suggest.hidden = true;
          suggest.setAttribute('aria-hidden', 'true');
          suggest.innerHTML = '';
        }

        function show(items) {
          if (!items.length) {
            hide();
            return;
          }

          var html = items.map(function (it) {
            var title = it.title || '';
            var type = (String(it.type || '').toLowerCase() === 'series') ? 'Series' : 'Movie';
            return (
              '<a href="' + esc(it.href) + '">' +
                '<span class="mmsrchbar-suggest-title">' + esc(title) + '</span>' +
                '<span class="mmsrchbar-suggest-type">' + esc(type) + '</span>' +
              '</a>'
            );
          }).join('');

          suggest.innerHTML = html;
          suggest.hidden = false;
          suggest.setAttribute('aria-hidden', 'false');
        }

        function normalize(s) {
          return String(s || '').toLowerCase().trim();
        }

        function scoreSuggestion(item, q) {
          var title = normalize(item && item.title);
          if (!title || !q) return 0;
          if (title === q) return 300;
          if (title.indexOf(q) === 0) return 220;
          if (title.indexOf(' ' + q) !== -1) return 180;
          if (title.indexOf(q) !== -1) return 120;
          return 0;
        }

        input.addEventListener('input', function () {
          var q = normalize(input.value);
          if (q.length < minChars) {
            hide();
            return;
          }

          var matches = [];
          for (var i = 0; i < suggestions.length; i++) {
            var item = suggestions[i];
            var score = scoreSuggestion(item, q);
            if (score > 0) {
              matches.push({
                item: item,
                score: score
              });
            }
          }
          matches.sort(function (a, b) {
            if (b.score !== a.score) return b.score - a.score;
            return normalize(a.item.title).localeCompare(normalize(b.item.title));
          });
          show(matches.slice(0, maxItems).map(function (entry) { return entry.item; }));
        });

        document.addEventListener('click', function (e) {
          if (!root.contains(e.target)) {
            hide();
          }
        }, { passive: true });
      })();
    </script>
    <?php
    return ob_get_clean();
}

