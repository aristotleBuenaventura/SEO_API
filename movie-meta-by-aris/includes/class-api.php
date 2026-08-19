<?php

if (!defined('ABSPATH')) {
    exit;
}

class MMBA_API {

    const REST_NS = 'movie-meta/v1';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route(self::REST_NS, '/movies', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'get_movies'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route(self::REST_NS, '/recent', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'get_recent'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route(self::REST_NS, '/top', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'get_top'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route(self::REST_NS, '/movies/(?P<id>[a-zA-Z0-9_\-\.]+)/view', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [__CLASS__, 'record_view'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ],
        ]);

        register_rest_route(self::REST_NS, '/movies/(?P<id>[a-zA-Z0-9_\-\.]+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'get_movie'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ],
        ]);
    }

    public static function get_movies(WP_REST_Request $request) {
        $movies = MMBA_Storage::get_movies();
        $genre = sanitize_text_field((string) $request->get_param('genre'));

        if ($genre !== '') {
            $movies = array_values(array_filter($movies, static function ($movie) use ($genre) {
                return isset($movie['genre']) && MMBA_Storage::genre_matches($movie['genre'], $genre);
            }));
        }

        $views = MMBA_Storage::get_views();
        $movies = array_map(static function ($movie) use ($views) {
            return self::enrich_movie($movie, $views);
        }, $movies);

        return rest_ensure_response([
            'generated_at' => gmdate('c'),
            'count'        => count($movies),
            'json_url'     => MMBA_Storage::json_file_url(),
            'movies'       => $movies,
        ]);
    }

    public static function get_movie(WP_REST_Request $request) {
        $movie = MMBA_Storage::get_movie($request['id']);
        if (!$movie) {
            return new WP_Error('mmba_not_found', __('Movie not found.', 'movie-meta-by-aris'), ['status' => 404]);
        }

        return rest_ensure_response(self::enrich_movie($movie, MMBA_Storage::get_views()));
    }

    public static function get_top(WP_REST_Request $request) {
        $limit = absint($request->get_param('limit'));
        if ($limit < 1) {
            $limit = 10;
        }

        $views = MMBA_Storage::get_views();
        $movies = array_map(static function ($movie) use ($views) {
            return self::enrich_movie($movie, $views);
        }, MMBA_Storage::get_top_movies($limit));

        $response = rest_ensure_response([
            'generated_at' => gmdate('c'),
            'count'        => count($movies),
            'movies'       => $movies,
        ]);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }

    public static function get_recent(WP_REST_Request $request) {
        $limit = absint($request->get_param('limit'));
        if ($limit < 1) {
            $limit = 10;
        }

        $views = MMBA_Storage::get_views();
        $movies = array_map(static function ($movie) use ($views) {
            return self::enrich_movie($movie, $views);
        }, MMBA_Storage::get_recent_movies($limit));

        $response = rest_ensure_response([
            'generated_at' => gmdate('c'),
            'count'        => count($movies),
            'movies'       => $movies,
        ]);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }

    public static function record_view(WP_REST_Request $request) {
        $id = sanitize_text_field((string) $request['id']);
        $counted = MMBA_Storage::increment_view($id);

        return rest_ensure_response([
            'id'      => $id,
            'counted' => (bool) $counted,
            'views'   => MMBA_Storage::get_view_count($id),
        ]);
    }

    /**
     * Extra fields for external layouts / code snippets (no HTML).
     */
    private static function enrich_movie(array $movie, array $views = []) {
        $link = isset($movie['movie_link']) ? (string) $movie['movie_link'] : '';
        $id = isset($movie['id']) ? (string) $movie['id'] : '';
        $movie['link_type']  = MMBA_Storage::get_movie_link_type($link);
        $movie['embed_url']  = $movie['link_type'] === 'embed' ? MMBA_Storage::get_embed_url($link) : $link;
        $movie['poster_url'] = MMBA_Storage::movie_poster_url($movie);
        $movie['views']      = isset($views[$id]) ? (int) $views[$id] : 0;
        if (!empty($movie['episodes']) && is_array($movie['episodes'])) {
            $movie['episodes'] = array_map(static function ($episode) {
                $elink = isset($episode['movie_link']) ? (string) $episode['movie_link'] : '';
                $etype = MMBA_Storage::get_movie_link_type($elink);
                $episode['link_type']  = $etype;
                $episode['embed_url']  = $etype === 'embed' ? MMBA_Storage::get_embed_url($elink) : $elink;
                $episode['poster_url'] = MMBA_Storage::movie_poster_url($episode);
                return $episode;
            }, $movie['episodes']);
        }
        return $movie;
    }
}
