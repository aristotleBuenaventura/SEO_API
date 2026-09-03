<?php
/**
 * Code Snippets plugin — paste this as a PHP snippet (Run everywhere).
 *
 * Shortcode: [movie_ads]
 * Optional:  [movie_ads slug="desimovieshubpromoads"]
 *            [movie_ads api="https://bo.bannershive.dev/api/v1/hive/tag-images/desimovieshubpromoads"]
 *
 * Fetches promo banners from BannersHive and shows one at random on each page load.
 * Clicking the banner opens the matching promotional link in a new tab.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('movie_ads', 'mmad_render_ads_shortcode');

function mmad_render_ads_shortcode($atts = []) {
    $atts = shortcode_atts(
        [
            'slug' => 'desimovieshubpromoads',
            'api'  => '',
        ],
        $atts,
        'movie_ads'
    );

    $slug = sanitize_key((string) $atts['slug']);
    if ($slug === '') {
        $slug = 'desimovieshubpromoads';
    }

    $api = trim((string) $atts['api']);
    if ($api === '') {
        $api = 'https://bo.bannershive.dev/api/v1/hive/tag-images/' . rawurlencode($slug);
    }
    $api = esc_url_raw($api);

    $uid = 'mmad-' . wp_unique_id();

    ob_start();
    ?>
<div
  id="<?php echo esc_attr($uid); ?>"
  class="mmad"
  data-api="<?php echo esc_attr($api); ?>"
  data-slug="<?php echo esc_attr($slug); ?>"
  aria-live="polite"
>
  <div class="mmad-loading"><?php echo esc_html__('Loading…', 'movie-meta-by-aris'); ?></div>
</div>

<style>
  .mmad, .mmad *, .mmad *::before, .mmad *::after { box-sizing: border-box; }
  .mmad {
    --mmad-font: "Sora", "Avenir Next", "Segoe UI", system-ui, sans-serif;
    width: 100%;
    max-width: 100%;
    margin: 0.75rem auto 1.25rem;
    padding: 0 1rem;
    font-family: var(--mmad-font);
    isolation: isolate;
  }
  .mmad-loading,
  .mmad-empty,
  .mmad-error {
    color: #6b7280;
    font-size: 0.85rem;
    text-align: center;
    padding: 0.5rem 0;
  }
  .mmad-error { color: #b91c1c; }
  .mmad-link {
    display: block;
    width: 100%;
    max-width: 100%;
    margin: 0 auto;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid rgba(18, 21, 26, 0.12);
    background: #0f1419;
    line-height: 0;
    text-decoration: none;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }
  .mmad-link:focus-visible {
    outline: 2px solid #2563eb;
    outline-offset: 3px;
  }
  .mmad-img {
    display: block;
    width: 100%;
    height: auto;
    max-height: 120px;
    object-fit: cover;
    object-position: center;
  }
  @media (hover: hover) {
    .mmad-link:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 24px rgba(15, 20, 25, 0.18);
    }
  }
  @media (max-width: 720px) {
    .mmad {
      padding: 0 max(0.75rem, env(safe-area-inset-right)) 0 max(0.75rem, env(safe-area-inset-left));
      margin: 0.6rem auto 1rem;
    }
    .mmad-img { max-height: 90px; }
  }
  @media (prefers-reduced-motion: reduce) {
    .mmad-link { transition: none; }
  }
</style>

<script>
(function () {
  'use strict';

  var root = document.getElementById(<?php echo wp_json_encode($uid); ?>);
  if (!root) return;

  var API = root.getAttribute('data-api') || '';
  var SLUG = root.getAttribute('data-slug') || 'desimovieshubpromoads';

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function pickRandom(list) {
    if (!list || !list.length) return null;
    return list[Math.floor(Math.random() * list.length)];
  }

  function getPromoLink(item) {
    var links = item && item.promotional_links ? item.promotional_links : [];
    var i;
    for (i = 0; i < links.length; i++) {
      if (links[i] && links[i].website_name === 'default' && links[i].promobtn_link) {
        return String(links[i].promobtn_link);
      }
    }
    for (i = 0; i < links.length; i++) {
      if (links[i] && links[i].promobtn_link) {
        return String(links[i].promobtn_link);
      }
    }
    return '';
  }

  function getImageMeta(item) {
    var altText = item && item.alt_text != null ? String(item.alt_text).trim() : '';
    var titleText = item && item.title != null ? String(item.title).trim() : '';

    if (!altText && titleText) {
      altText = titleText;
    }
    if (!titleText && altText) {
      titleText = altText;
    }
    if (!altText) {
      altText = 'Promo banner';
    }

    return {
      alt: altText,
      title: titleText
    };
  }

  function renderBanner(item) {
    if (!item || !item.image_url) {
      root.innerHTML = '<div class="mmad-empty"><?php echo esc_js(__('No promo banners available.', 'movie-meta-by-aris')); ?></div>';
      return;
    }

    var href = getPromoLink(item);
    var meta = getImageMeta(item);
    var img = esc(item.image_url);
    var imgAttrs =
      ' class="mmad-img"' +
      ' src="' + img + '"' +
      ' alt="' + esc(meta.alt) + '"' +
      (meta.title ? ' title="' + esc(meta.title) + '"' : '') +
      ' loading="lazy" decoding="async"';

    if (!href) {
      root.innerHTML =
        '<div class="mmad-link mmad-link-static">' +
          '<img' + imgAttrs + '>' +
        '</div>';
      return;
    }

    root.innerHTML =
      '<a class="mmad-link" href="' + esc(href) + '" target="_blank" rel="noopener noreferrer sponsored"' +
        ' aria-label="' + esc(meta.alt) + '"' +
        (meta.title ? ' title="' + esc(meta.title) + '"' : '') +
      '>' +
        '<img' + imgAttrs + '>' +
      '</a>';
  }

  function fetchImages() {
    if (!API) {
      root.innerHTML = '<div class="mmad-error"><?php echo esc_js(__('Missing API URL.', 'movie-meta-by-aris')); ?></div>';
      return;
    }

    var url = API + (API.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();

    fetch(url, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      cache: 'no-store'
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('API request failed: ' + response.status + ' - ' + response.statusText);
        }
        return response.json();
      })
      .then(function (result) {
        if (!result || !result.status) {
          throw new Error((result && result.message) ? result.message : 'API returned error status');
        }

        var images = Array.isArray(result.data) ? result.data : [];
        if (!images.length) {
          root.innerHTML = '<div class="mmad-empty"><?php echo esc_js(__('No promo banners available.', 'movie-meta-by-aris')); ?></div>';
          return;
        }

        renderBanner(pickRandom(images));
      })
      .catch(function (error) {
        root.innerHTML =
          '<div class="mmad-error"><?php echo esc_js(__('Could not load promo banner.', 'movie-meta-by-aris')); ?> (' +
          esc(error && error.message ? error.message : 'Unknown error') +
          ')</div>';
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fetchImages);
  } else {
    fetchImages();
  }
})();
</script>
    <?php
    return ob_get_clean();
}
