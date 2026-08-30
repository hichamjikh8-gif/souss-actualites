<?php
/**
 * API REST minimale pour piloter le site depuis le bot Telegram.
 * Authentification par en-tete X-Bot-Secret compare a la constante SOUSS_BOT_SECRET
 * (definie via WORDPRESS_CONFIG_EXTRA sur Railway, jamais en dur ici).
 * Perimetre volontairement restreint: brouillons, publication, stats, commentaires.
 */

add_action('rest_api_init', function () {
    $permission = function (WP_REST_Request $request) {
        if (!defined('SOUSS_BOT_SECRET') || SOUSS_BOT_SECRET === '') {
            return false;
        }
        $provided = $request->get_header('x-bot-secret');
        return is_string($provided) && hash_equals(SOUSS_BOT_SECRET, $provided);
    };

    register_rest_route('souss-bot/v1', '/drafts', [
        'methods' => 'GET',
        'permission_callback' => $permission,
        'callback' => function () {
            $posts = get_posts([
                'post_status' => 'draft',
                'numberposts' => 15,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            return array_map(function ($p) {
                return [
                    'id' => $p->ID,
                    'title' => get_the_title($p),
                    'date' => get_the_date('Y-m-d H:i', $p),
                ];
            }, $posts);
        },
    ]);

    register_rest_route('souss-bot/v1', '/stats', [
        'methods' => 'GET',
        'permission_callback' => $permission,
        'callback' => function () {
            $counts = wp_count_posts('post');
            $comments = wp_count_comments();
            $recent = get_posts([
                'post_status' => 'publish',
                'numberposts' => 5,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            return [
                'published' => (int) ($counts->publish ?? 0),
                'drafts' => (int) ($counts->draft ?? 0),
                'pending_review' => (int) ($counts->pending ?? 0),
                'comments_pending' => (int) ($comments->moderated ?? 0),
                'comments_total' => (int) ($comments->approved ?? 0),
                'recent' => array_map(function ($p) {
                    return [
                        'id' => $p->ID,
                        'title' => get_the_title($p),
                        'date' => get_the_date('Y-m-d H:i', $p),
                        'url' => get_permalink($p),
                    ];
                }, $recent),
            ];
        },
    ]);

    register_rest_route('souss-bot/v1', '/comments-pending', [
        'methods' => 'GET',
        'permission_callback' => $permission,
        'callback' => function () {
            $comments = get_comments([
                'status' => 'hold',
                'number' => 15,
                'orderby' => 'comment_date',
                'order' => 'DESC',
            ]);
            return array_map(function ($c) {
                return [
                    'id' => (int) $c->comment_ID,
                    'author' => $c->comment_author,
                    'excerpt' => wp_trim_words($c->comment_content, 20),
                    'post_title' => get_the_title($c->comment_post_ID),
                    'date' => $c->comment_date,
                ];
            }, $comments);
        },
    ]);

    register_rest_route('souss-bot/v1', '/publish', [
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => function (WP_REST_Request $request) {
            $id = (int) $request->get_param('id');
            $post = get_post($id);
            if (!$post || $post->post_type !== 'post') {
                return new WP_Error('not_found', 'Article introuvable.', ['status' => 404]);
            }
            $result = wp_update_post([
                'ID' => $id,
                'post_status' => 'publish',
            ], true);
            if (is_wp_error($result)) {
                return $result;
            }
            return [
                'id' => $id,
                'title' => get_the_title($id),
                'url' => get_permalink($id),
            ];
        },
    ]);

    register_rest_route('souss-bot/v1', '/new', [
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => function (WP_REST_Request $request) {
            $title = sanitize_text_field((string) $request->get_param('title'));
            $content = (string) $request->get_param('content');
            if ($title === '') {
                return new WP_Error('bad_request', 'Titre requis.', ['status' => 400]);
            }
            $author = get_user_by('login', 'lahcen');
            $id = wp_insert_post([
                'post_title' => $title,
                'post_content' => wp_kses_post($content),
                'post_status' => 'draft',
                'post_author' => $author ? $author->ID : 1,
            ], true);
            if (is_wp_error($id)) {
                return $id;
            }
            return [
                'id' => $id,
                'title' => get_the_title($id),
            ];
        },
    ]);
});
