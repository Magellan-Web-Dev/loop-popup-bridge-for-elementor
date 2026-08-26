<?php

declare(strict_types=1);

namespace LoopPopupBridge\REST;

if (!defined('ABSPATH')) exit;

use LoopPopupBridge\Support\PopupBindingScanner;
use LoopPopupBridge\Support\PostPayload;

/**
 * REST endpoint: GET /wp-json/loop-popup-bridge/v1/post/{id}
 *
 * Returns a curated JSON payload for a single published post so the frontend
 * JavaScript can populate Elementor popup field widgets.
 *
 * Most page loads never touch this route: FrontendManager renders the payload for
 * every trigger on the page inline, so the data is already present before any JS
 * runs. What is left for the route is the case the page could not know about at
 * render time — a trigger inserted afterwards, by Loop Grid infinite scroll or any
 * other AJAX.
 *
 * Security model:
 *   permission_callback is __return_true (the endpoint is publicly readable,
 *   matching WordPress core's behaviour for published post data via the REST
 *   API). PostPayload::build() performs all access-control checks:
 *     – Post must exist and be published.
 *     – Post must not be password-protected.
 *     – Post type must be marked as public.
 *   Custom meta is opt-in: callers pass ?meta_keys=key1,key2 and only keys
 *   explicitly allowed via the lpb_allowed_meta_keys filter are returned.
 */
final class PostEndpoint
{
    /**
     * REST API namespace for all routes registered by this plugin.
     *
     * @var string
     */
    private const NAMESPACE = 'loop-popup-bridge/v1';

    /**
     * Route pattern for the single-post endpoint.
     * The named capture group id matches one or more digits.
     *
     * @var string
     */
    private const ROUTE = '/post/(?P<id>[\d]+)';

    /**
     * Registers the REST route with WordPress.
     *
     * Should be called inside a rest_api_init action callback.
     *
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_request'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id'        => [
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => static fn(mixed $v): bool => is_numeric($v) && (int) $v > 0,
                    'description'       => 'Numeric post ID.',
                ],
                'meta_keys' => [
                    'required'          => false,
                    'default'           => [],
                    'sanitize_callback' => static function (mixed $v): array {
                        if (!is_array($v)) {
                            $v = explode(',', (string) $v);
                        }
                        return array_values(array_filter(array_map('sanitize_key', $v)));
                    },
                    'description'       => 'Comma-separated list of meta keys to include (subject to server-side allowlist).',
                ],
                'popup_id'  => [
                    'required'          => false,
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                    'description'       => 'Popup template ID; its bound meta keys are resolved server-side and added to meta_keys.',
                ],
            ],
        ]);
    }

    /**
     * Handles an incoming REST request and returns post data or a WP_Error.
     *
     * @param  \WP_REST_Request $request  The incoming REST request object.
     * @return \WP_REST_Response|\WP_Error  200 response with post data, or an error.
     */
    public function handle_request(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $post_id        = absint($request->get_param('id'));
        $requested_keys = (array) $request->get_param('meta_keys');
        $popup_id       = absint($request->get_param('popup_id'));

        // Resolving the popup's own bindings server-side is what lets a caller that
        // has never opened the popup — and therefore cannot read its DOM — still ask
        // for the right keys. This widens only what is REQUESTED; the allowlist gate
        // on what may actually be returned is untouched.
        $popup_keys = $popup_id > 0 ? PopupBindingScanner::get_meta_keys($popup_id) : [];

        if (!empty($popup_keys)) {
            $requested_keys = array_merge($requested_keys, $popup_keys);
        }

        $data = PostPayload::build($post_id, $requested_keys);

        if ($data instanceof \WP_Error) {
            return $data;
        }

        // Reported separately from meta_keys_loaded, which is the union of everything
        // this request asked for — including keys already cached for the post on
        // behalf of a DIFFERENT popup. The client caches this against the popup ID,
        // so it has to be that popup's own bindings and nothing else.
        if ($popup_id > 0) {
            $data['popup_meta_keys'] = $popup_keys;
        }

        return new \WP_REST_Response($data, 200);
    }
}
