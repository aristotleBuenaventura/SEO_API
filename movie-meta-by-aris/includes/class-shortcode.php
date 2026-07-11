<?php

if (!defined('ABSPATH')) {
    exit;
}

class MMBA_Shortcode {

    private static $assets_enqueued = false;

    public static function init() {
        add_shortcode('movie_meta', [__CLASS__, 'render']);
    }

    public static function render($atts) {
        $atts = shortcode_atts(
            [
                'id'     => '',
                'genre'  => '',
                'limit'  => 0,
                'player' => '1',
                'layout' => 'list', // list | grid | single
                'show'   => 'all',   // all | title,genre,details,player
            ],
            $atts,
            'movie_meta'
        );

        $movies = MMBA_Storage::get_movies();

        if ($atts['id'] !== '') {
            $movie = MMBA_Storage::get_movie($atts['id']);
            $movies = $movie ? [$movie] : [];
            $atts['layout'] = 'single';
        } elseif ($atts['genre'] !== '') {
            $genre = $atts['genre'];
            $movies = array_values(array_filter($movies, static function ($movie) use ($genre) {
                return isset($movie['genre']) && strcasecmp((string) $movie['genre'], $genre) === 0;
            }));
        }

        $limit = absint($atts['limit']);
        if ($limit > 0) {
            $movies = array_slice($movies, 0, $limit);
        }

        if (empty($movies)) {
            return '<div class="mmba-front mmba-empty">' . esc_html__('No movies found.', 'movie-meta-by-aris') . '</div>';
        }

        $show_player = self::truthy($atts['player']);
        self::enqueue_assets($show_player);

        $show = self::parse_show($atts['show']);
        $layout = in_array($atts['layout'], ['list', 'grid', 'single'], true) ? $atts['layout'] : 'list';

        ob_start();
        ?>
        <div class="mmba-front mmba-layout-<?php echo esc_attr($layout); ?>">
            <?php foreach ($movies as $movie) : ?>
                <?php echo self::render_movie_card($movie, $show, $show_player); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_movie_card(array $movie, array $show, $show_player) {
        $id = isset($movie['id']) ? (string) $movie['id'] : '';
        $title = isset($movie['title']) ? (string) $movie['title'] : '';
        $details = isset($movie['details']) ? (string) $movie['details'] : '';
        $genre = isset($movie['genre']) ? (string) $movie['genre'] : '';
        $link = isset($movie['movie_link']) ? (string) $movie['movie_link'] : '';
        $player_id = 'mmba-player-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id);

        ob_start();
        ?>
        <article class="mmba-movie" data-movie-id="<?php echo esc_attr($id); ?>">
            <?php if (!empty($show['title'])) : ?>
                <h3 class="mmba-movie-title"><?php echo esc_html($title); ?></h3>
            <?php endif; ?>

            <?php if (!empty($show['genre']) && $genre !== '') : ?>
                <p class="mmba-movie-genre"><span><?php echo esc_html__('Genre:', 'movie-meta-by-aris'); ?></span> <?php echo esc_html($genre); ?></p>
            <?php endif; ?>

            <?php if (!empty($show['details']) && $details !== '') : ?>
                <div class="mmba-movie-details"><?php echo nl2br(esc_html($details)); ?></div>
            <?php endif; ?>

            <?php if (!empty($show['player']) && $show_player && $link !== '') : ?>
                <?php $safe_link = esc_attr($link); ?>
                <div class="mmba-player-wrap">
                    <video
                        id="<?php echo esc_attr($player_id); ?>"
                        class="mmba-player"
                        controls
                        playsinline
                        preload="metadata"
                        data-src="<?php echo $safe_link; ?>"
                    ></video>
                    <noscript>
                        <p><a href="<?php echo $safe_link; ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open stream', 'movie-meta-by-aris'); ?></a></p>
                    </noscript>
                </div>
            <?php elseif (!empty($show['player']) && $link !== '') : ?>
                <p class="mmba-movie-link">
                    <a href="<?php echo esc_attr($link); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Watch movie', 'movie-meta-by-aris'); ?></a>
                </p>
            <?php endif; ?>
        </article>
        <?php
        return ob_get_clean();
    }

    private static function parse_show($show) {
        $defaults = [
            'title'   => true,
            'genre'   => true,
            'details' => true,
            'player'  => true,
        ];

        $show = strtolower(trim((string) $show));
        if ($show === '' || $show === 'all') {
            return $defaults;
        }

        $parts = array_filter(array_map('trim', explode(',', $show)));
        $map = [
            'title'   => false,
            'genre'   => false,
            'details' => false,
            'player'  => false,
        ];

        foreach ($parts as $part) {
            if (isset($map[$part])) {
                $map[$part] = true;
            }
        }

        return $map;
    }

    private static function truthy($value) {
        $value = strtolower(trim((string) $value));
        return !in_array($value, ['0', 'false', 'no', 'off'], true);
    }

    private static function enqueue_assets($with_player) {
        if (self::$assets_enqueued) {
            return;
        }
        self::$assets_enqueued = true;

        wp_enqueue_style(
            'mmba-frontend',
            MMBA_PLUGIN_URL . 'assets/frontend.css',
            [],
            MMBA_VERSION
        );

        if (!$with_player) {
            return;
        }

        wp_enqueue_script(
            'hls-js',
            'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js',
            [],
            '1.5.17',
            true
        );

        wp_enqueue_script(
            'mmba-frontend',
            MMBA_PLUGIN_URL . 'assets/frontend.js',
            ['hls-js'],
            MMBA_VERSION,
            true
        );
    }
}
