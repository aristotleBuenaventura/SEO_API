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
                return isset($movie['genre']) && strcasecmp((string) $movie['genre'], $genre) === 0;
            }));
        }

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

        return rest_ensure_response($movie);
    }
}
