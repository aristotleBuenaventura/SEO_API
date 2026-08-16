<?php

if (!defined('ABSPATH')) {
    exit;
}

class MMBA_Shortcode {

    private static $assets_enqueued = false;
    private static $modal_printed = false;

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
                'show'   => 'all',   // all | title,genre,details,cast,year,player
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
                return isset($movie['genre']) && MMBA_Storage::genre_matches($movie['genre'], $genre);
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
        $needs_hls = $show_player && self::movies_need_hls($movies);
        self::enqueue_assets($show_player, $needs_hls);

        $show = self::parse_show($atts['show']);
        $layout = in_array($atts['layout'], ['list', 'grid', 'single'], true) ? $atts['layout'] : 'list';
        $use_poster = $show_player && in_array($layout, ['list', 'grid'], true);

        ob_start();
        ?>
        <div class="mmba-front mmba-layout-<?php echo esc_attr($layout); ?>">
            <?php foreach ($movies as $movie) : ?>
                <?php echo self::render_movie_card($movie, $show, $show_player, $layout); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endforeach; ?>
            <?php if ($use_poster) : ?>
                <?php echo self::render_modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_modal() {
        if (self::$modal_printed) {
            return '';
        }
        self::$modal_printed = true;

        ob_start();
        ?>
        <div class="mmba-modal" id="mmba-modal" hidden aria-hidden="true">
            <div class="mmba-modal-backdrop" data-mmba-close></div>
            <div class="mmba-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="mmba-modal-title">
                <div class="mmba-modal-top">
                    <h3 class="mmba-modal-title" id="mmba-modal-title"></h3>
                    <button type="button" class="mmba-modal-close" data-mmba-close aria-label="<?php echo esc_attr__('Close', 'movie-meta-by-aris'); ?>">×</button>
                </div>
                <div class="mmba-modal-player" id="mmba-modal-player"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_movie_card(array $movie, array $show, $show_player, $layout) {
        $id = isset($movie['id']) ? (string) $movie['id'] : '';
        $title = isset($movie['title']) ? (string) $movie['title'] : '';
        $details = isset($movie['details']) ? (string) $movie['details'] : '';
        $cast = isset($movie['cast']) ? (string) $movie['cast'] : '';
        $year = isset($movie['year']) ? (string) $movie['year'] : '';
        $genre = isset($movie['genre']) ? (string) $movie['genre'] : '';
        $link = isset($movie['movie_link']) ? (string) $movie['movie_link'] : '';
        $player_id = 'mmba-player-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
        $use_poster = $show_player && in_array($layout, ['list', 'grid'], true);
        $initial = self::title_initial($title);
        $tone = self::poster_tone($title);

        ob_start();
        ?>
        <article class="mmba-movie" data-movie-id="<?php echo esc_attr($id); ?>">
            <?php if (!empty($show['player']) && $show_player && $link !== '') : ?>
                <?php
                $link_type = MMBA_Storage::get_movie_link_type($link);
                $play_url = $link_type === 'embed' ? MMBA_Storage::get_embed_url($link) : $link;
                $safe_play = esc_attr($play_url);
                $poster_url = MMBA_Storage::get_poster_url($link);
                ?>
                <div class="mmba-movie-media">
                    <?php if ($use_poster) : ?>
                        <button
                            type="button"
                            class="mmba-poster"
                            data-mmba-open
                            data-src="<?php echo $safe_play; ?>"
                            data-type="<?php echo esc_attr($link_type); ?>"
                            data-title="<?php echo esc_attr($title); ?>"
                            aria-label="<?php echo esc_attr(sprintf(__('Play %s', 'movie-meta-by-aris'), $title)); ?>"
                        >
                            <?php if ($poster_url !== '') : ?>
                                <span
                                    class="mmba-poster-art mmba-poster-thumb"
                                    style="background-image:url('<?php echo esc_url($poster_url); ?>');"
                                    aria-hidden="true"
                                ></span>
                            <?php else : ?>
                                <span class="mmba-poster-art mmba-tone-<?php echo esc_attr((string) $tone); ?>" aria-hidden="true">
                                    <span class="mmba-poster-initial"><?php echo esc_html($initial); ?></span>
                                </span>
                            <?php endif; ?>
                            <span class="mmba-poster-play" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                            </span>
                            <span class="mmba-poster-caption"><?php echo esc_html($title); ?></span>
                        </button>
                        <noscript>
                            <p><a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Open stream', 'movie-meta-by-aris'); ?></a></p>
                        </noscript>
                    <?php elseif ($link_type === 'embed') : ?>
                        <div class="mmba-player-wrap mmba-embed-wrap">
                            <iframe
                                id="<?php echo esc_attr($player_id); ?>"
                                class="mmba-embed"
                                src="<?php echo $safe_play; ?>"
                                title="<?php echo esc_attr($title); ?>"
                                loading="lazy"
                                allowfullscreen
                                allow="fullscreen; encrypted-media; picture-in-picture"
                                referrerpolicy="no-referrer-when-downgrade"
                            ></iframe>
                        </div>
                    <?php else : ?>
                        <div class="mmba-player-wrap">
                            <video
                                id="<?php echo esc_attr($player_id); ?>"
                                class="mmba-player"
                                controls
                                playsinline
                                preload="metadata"
                                data-src="<?php echo $safe_play; ?>"
                            ></video>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="mmba-movie-body">
                <?php if (!empty($show['title']) || (!empty($show['genre']) && $genre !== '') || (!empty($show['year']) && $year !== '')) : ?>
                    <header class="mmba-movie-header">
                        <?php if (!empty($show['title'])) : ?>
                            <?php if ($use_poster && $link !== '') : ?>
                                <?php
                                $title_play = MMBA_Storage::get_movie_link_type($link) === 'embed'
                                    ? MMBA_Storage::get_embed_url($link)
                                    : $link;
                                ?>
                                <h3 class="mmba-movie-title">
                                    <button
                                        type="button"
                                        class="mmba-title-btn"
                                        data-mmba-open
                                        data-src="<?php echo esc_attr($title_play); ?>"
                                        data-type="<?php echo esc_attr(MMBA_Storage::get_movie_link_type($link)); ?>"
                                        data-title="<?php echo esc_attr($title); ?>"
                                    ><?php echo esc_html($title); ?></button>
                                    <?php if (!empty($show['year']) && $year !== '') : ?>
                                        <span class="mmba-movie-year"><?php echo esc_html($year); ?></span>
                                    <?php endif; ?>
                                </h3>
                            <?php else : ?>
                                <h3 class="mmba-movie-title">
                                    <?php echo esc_html($title); ?>
                                    <?php if (!empty($show['year']) && $year !== '') : ?>
                                        <span class="mmba-movie-year"><?php echo esc_html($year); ?></span>
                                    <?php endif; ?>
                                </h3>
                            <?php endif; ?>
                        <?php elseif (!empty($show['year']) && $year !== '') : ?>
                            <p class="mmba-movie-year-only"><?php echo esc_html($year); ?></p>
                        <?php endif; ?>

                        <?php if (!empty($show['genre']) && $genre !== '') : ?>
                            <p class="mmba-movie-genre">
                                <?php foreach (array_filter(array_map('trim', explode(',', $genre))) as $genre_item) : ?>
                                    <span class="mmba-tag"><?php echo esc_html($genre_item); ?></span>
                                <?php endforeach; ?>
                            </p>
                        <?php endif; ?>
                    </header>
                <?php endif; ?>

                <?php if (!empty($show['cast']) && $cast !== '') : ?>
                    <p class="mmba-movie-cast">
                        <span class="mmba-meta-label"><?php echo esc_html__('Cast', 'movie-meta-by-aris'); ?></span>
                        <?php echo esc_html($cast); ?>
                    </p>
                <?php endif; ?>

                <?php if (!empty($show['details']) && $details !== '') : ?>
                    <div class="mmba-movie-details"><?php echo nl2br(esc_html($details)); ?></div>
                <?php endif; ?>

                <?php if (!empty($show['player']) && !$show_player && $link !== '') : ?>
                    <p class="mmba-movie-link">
                        <a class="mmba-watch-btn" href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Watch movie', 'movie-meta-by-aris'); ?></a>
                    </p>
                <?php endif; ?>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }

    private static function title_initial($title) {
        $title = trim((string) $title);
        if ($title === '') {
            return 'M';
        }
        if (function_exists('mb_substr')) {
            return mb_strtoupper(mb_substr($title, 0, 1));
        }
        return strtoupper(substr($title, 0, 1));
    }

    private static function poster_tone($title) {
        $sum = 0;
        $title = (string) $title;
        $len = strlen($title);
        for ($i = 0; $i < $len; $i++) {
            $sum += ord($title[$i]);
        }
        return ($sum % 6) + 1;
    }

    private static function parse_show($show) {
        $defaults = [
            'title'   => true,
            'genre'   => true,
            'details' => true,
            'cast'    => true,
            'year'    => true,
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
            'cast'    => false,
            'year'    => false,
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

    private static function movies_need_hls(array $movies) {
        foreach ($movies as $movie) {
            $link = isset($movie['movie_link']) ? (string) $movie['movie_link'] : '';
            if ($link !== '' && MMBA_Storage::get_movie_link_type($link) === 'hls') {
                return true;
            }
        }

        return false;
    }

    private static function enqueue_assets($with_player, $needs_hls = true) {
        if (self::$assets_enqueued) {
            return;
        }
        self::$assets_enqueued = true;

        wp_enqueue_style(
            'mmba-fonts',
            'https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&display=swap',
            [],
            null
        );

        wp_enqueue_style(
            'mmba-frontend',
            MMBA_PLUGIN_URL . 'assets/frontend.css',
            ['mmba-fonts'],
            MMBA_VERSION
        );

        if (!$with_player) {
            return;
        }

        $deps = [];
        if ($needs_hls) {
            wp_enqueue_script(
                'hls-js',
                'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js',
                [],
                '1.5.17',
                true
            );
            $deps[] = 'hls-js';
        }

        wp_enqueue_script(
            'mmba-frontend',
            MMBA_PLUGIN_URL . 'assets/frontend.js',
            $deps,
            MMBA_VERSION,
            true
        );
    }
}
