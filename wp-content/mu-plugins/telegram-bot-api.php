<?php
/**
 * API REST minimale pour piloter le site depuis le bot Telegram.
 * Authentification par en-tete X-Bot-Secret compare a la constante SOUSS_BOT_SECRET
 * (definie via WORDPRESS_CONFIG_EXTRA sur Railway, jamais en dur ici).
 * Perimetre volontairement restreint: brouillons, publication, stats, commentaires.
 */

// ── Articles syndiques : auteur et style ──────────────────────────────────────
// Pour les articles provenant de la syndication automatique, on affiche le nom
// du journal source a la place du compte WordPress interne (lahcen / admin).
// La meta _syndication_source est positionnee par l'endpoint /syndicate ci-dessous.
add_filter('the_author', function ($display_name) {
    $post_id = get_the_ID();
    if ($post_id) {
        $source = get_post_meta($post_id, '_syndication_source', true);
        if ($source) {
            return $source;
        }
    }
    return $display_name;
});

// CSS : rend les liens de la zone attribution/source visibles quel que soit le theme.
add_action('wp_head', function () {
    echo '<style id="sa-syndication-css">
.syndication-attribution,
.syndication-readmore {
    font-size: 0.9em;
    color: #555;
    margin-top: 1em;
}
.syndication-attribution a,
.syndication-readmore a {
    color: #c0392b !important;
    text-decoration: underline !important;
    font-weight: 600;
}
.syndication-attribution a:hover,
.syndication-readmore a:hover {
    color: #922b21 !important;
}
</style>' . "\n";
});

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

    // ── Syndication automatique ──────────────────────────────────────────────
    // Publie un article attribue a un journal source, directement en "publish",
    // avec photo mise en avant telechargee depuis image_url.
    // Utilise le meme X-Bot-Secret que le reste de l'API.
    register_rest_route('souss-bot/v1', '/syndicate', [
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => function (WP_REST_Request $request) {
            $title       = sanitize_text_field((string) $request->get_param('title'));
            $content     = (string) $request->get_param('content');
            $image_url   = esc_url_raw((string) $request->get_param('image_url'));
            $category_ids = array_filter(array_map('intval', (array) ($request->get_param('category_ids') ?: [])));
            $source_name = sanitize_text_field((string) $request->get_param('source_name'));
            $source_url  = esc_url_raw((string) $request->get_param('source_url'));
            $event_key   = sanitize_text_field((string) $request->get_param('event_key'));

            if ($title === '' || $source_url === '') {
                return new WP_Error('bad_request', 'title et source_url sont requis.', ['status' => 400]);
            }

            // Telecharger et attacher la photo depuis la source
            $featured_media_id = 0;
            if ($image_url !== '') {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                $tmp = download_url($image_url, 20);
                if (!is_wp_error($tmp)) {
                    $ext = strtolower(pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                        $ext = 'jpg';
                    }
                    $filename   = 'syndic-' . ($event_key ?: uniqid()) . '-' . time() . '.' . $ext;
                    $file_array = ['name' => $filename, 'tmp_name' => $tmp];
                    $media_id   = media_handle_sideload($file_array, 0);
                    if (!is_wp_error($media_id)) {
                        $featured_media_id = (int) $media_id;
                    } else {
                        @unlink($tmp);
                    }
                }
            }

            $author  = get_user_by('login', 'lahcen');
            $cats    = !empty($category_ids) ? array_values($category_ids) : [3];
            $post_id = wp_insert_post([
                'post_title'    => $title,
                'post_content'  => wp_kses_post($content),
                'post_status'   => 'publish',
                'post_author'   => $author ? $author->ID : 1,
                'post_category' => $cats,
            ], true);

            if (is_wp_error($post_id)) {
                return $post_id;
            }

            wp_set_post_categories($post_id, $cats);

            if ($featured_media_id) {
                set_post_thumbnail($post_id, $featured_media_id);
            }

            // Meta tracabilite syndication
            update_post_meta($post_id, '_syndication_source',     $source_name);
            update_post_meta($post_id, '_syndication_source_url', $source_url);
            if ($event_key) {
                update_post_meta($post_id, '_syndication_event_key', $event_key);
            }

            return [
                'id'       => $post_id,
                'post_url' => get_permalink($post_id),
            ];
        },
    ]);
});
