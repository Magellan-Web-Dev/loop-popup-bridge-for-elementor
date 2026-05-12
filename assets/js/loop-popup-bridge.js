/**
 * Loop Popup Bridge for Elementor — frontend script
 *
 * Responsibilities:
 *  1. Listen (delegated) for clicks on any [data-lpb-trigger="1"] element.
 *  2. Store the clicked post ID + popup ID in the global LPB context.
 *  3. Fetch post data from the REST endpoint (cached per post ID after first load).
 *  4. Open the Elementor Pro popup via elementorProFrontend.modules.popup.showPopup().
 *  5. Locate [data-lpb-field] placeholder elements inside the popup and fill them.
 *
 * The global object (window.LoopPopupBridge) is initialised by PHP via
 * wp_add_inline_script() before this file runs, so restUrl and nonce are always set.
 */

(function () {
    'use strict';

    // ── Global context ────────────────────────────────────────────────────────────
    // PHP initialises this before the script runs; we just ensure it exists as a
    // safeguard in case the inline script is somehow stripped.
    window.LoopPopupBridge = window.LoopPopupBridge || {
        activePostId:  null,
        activePopupId: null,
        posts:         {},
        postMetaKeys:  {},
        restUrl:       '',
        nonce:         '',
    };

    var LPB = window.LoopPopupBridge;
    LPB.postMetaKeys = LPB.postMetaKeys || {};

    // ── REST fetch with client-side cache ─────────────────────────────────────────

    /** Deduplicates and normalizes a list of requested custom meta keys. */
    function normalizeMetaKeys(metaKeys) {
        var seen = {};

        (metaKeys || []).forEach(function (key) {
            key = String(key || '').trim();
            if (key) {
                seen[key] = true;
            }
        });

        return Object.keys(seen);
    }

    /** Returns true when the cached post already includes every requested meta key. */
    function hasCachedMetaKeys(postId, metaKeys) {
        if (!LPB.posts[postId]) {
            return false;
        }

        var cached = LPB.postMetaKeys[postId] || {};

        return metaKeys.every(function (key) {
            return !!cached[key];
        });
    }

    /** Marks the given meta keys as present in the cached payload for a post. */
    function rememberMetaKeys(postId, metaKeys) {
        LPB.postMetaKeys[postId] = LPB.postMetaKeys[postId] || {};

        metaKeys.forEach(function (key) {
            LPB.postMetaKeys[postId][key] = true;
        });
    }

    /** Builds the REST URL, including requested meta keys when needed. */
    function buildPostUrl(postId, metaKeys) {
        var url = LPB.restUrl + postId;

        if (metaKeys.length) {
            url += '?meta_keys=' + encodeURIComponent(metaKeys.join(','));
        }

        return url;
    }

    /**
     * Returns a Promise that resolves to the post data object.
     * Results are cached in LPB.posts keyed by post ID so subsequent clicks on the
     * same post skip the network round-trip entirely.
     *
     * @param  {number} postId
     * @param  {Array<string>} metaKeys
     * @return {Promise<Object|null>}
     */
    function fetchPostData(postId, metaKeys) {
        metaKeys = normalizeMetaKeys(metaKeys);

        if (hasCachedMetaKeys(postId, metaKeys)) {
            return Promise.resolve(LPB.posts[postId]);
        }

        var alreadyCached = Object.keys(LPB.postMetaKeys[postId] || {});
        var keysToRequest = normalizeMetaKeys(alreadyCached.concat(metaKeys));

        return fetch(buildPostUrl(postId, keysToRequest), {
            method:  'GET',
            headers: {
                'X-WP-Nonce':   LPB.nonce,
                'Accept':       'application/json',
            },
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('LPB: REST request failed (' + response.status + ') for post ' + postId);
                }
                return response.json();
            })
            .then(function (data) {
                if (LPB.posts[postId] && LPB.posts[postId].custom_meta && data.custom_meta) {
                    data.custom_meta = Object.assign({}, LPB.posts[postId].custom_meta, data.custom_meta);
                }

                LPB.posts[postId] = data;
                rememberMetaKeys(postId, keysToRequest);

                return data;
            })
            .catch(function (err) {
                console.error(err);
                return null;
            });
    }

    // ── Popup open ────────────────────────────────────────────────────────────────

    /**
     * Opens an Elementor Pro popup by its numeric ID.
     * Falls back to a custom DOM event so the call is safe even when the Pro module
     * is not available (e.g. during Elementor editor preview without Pro loaded).
     *
     * @param  {number} popupId
     * @return {Promise<void>} Resolves after a short delay to let the popup enter the DOM.
     */
    function openElementorPopup(popupId) {
        if (
            typeof window.elementorProFrontend !== 'undefined' &&
            window.elementorProFrontend.modules &&
            window.elementorProFrontend.modules.popup
        ) {
            window.elementorProFrontend.modules.popup.showPopup({ id: popupId });
        } else {
            // Fallback: Elementor Pro also listens to this custom event internally.
            document.dispatchEvent(
                new CustomEvent('elementor/popup/show', { detail: { id: popupId } })
            );
        }

        // Give the popup ~120 ms to become visible in the DOM before we try to fill it.
        return new Promise(function (resolve) { setTimeout(resolve, 120); });
    }

    // ── Field population ──────────────────────────────────────────────────────────

    /**
     * Finds the popup DOM node for the given popup ID.
     * Elementor Pro renders all popups in the page footer as hidden elements;
     * they are always in the DOM — just toggled visible when opened.
     *
     * @param  {number} popupId
     * @return {Element|null}
     */
    function getPopupContainer(popupId) {
        // Elementor Pro marks the popup wrapper with data-elementor-id.
        return (
            document.querySelector('.elementor-popup-modal[data-elementor-id="' + popupId + '"]') ||
            document.querySelector('.elementor-popup-modal') // fallback: first open popup
        );
    }

    var bindingSelector = [
        '[data-lpb-field]',
        'a[href*="lpb-field="]',
        'img[src*="lpb-field="]',
    ].join(',');

    /**
     * Fills every LPB binding within `container` with values from `postData`.
     *
     * @param {Element} container  The popup DOM node.
     * @param {Object}  postData   Payload from the REST endpoint.
     */
    function fillFields(container, postData) {
        var fields = container.querySelectorAll(bindingSelector);

        fields.forEach(function (el) {
            var binding = getBinding(el);
            if (!binding) { return; }

            if (binding.target === 'url') {
                fillUrlBinding(el, binding, postData);
                return;
            }

            if (binding.target === 'image') {
                fillImageBinding(el, binding, postData);
                return;
            }

            switch (binding.fieldName) {

                case 'featured_image':
                    fillImageField(el, postData);
                    break;

                case 'meta':
                    fillMetaField(el, postData);
                    break;

                case 'permalink':
                    fillPermalinkField(el, postData);
                    break;

                // 'content' allows safe server-escaped HTML (wp_kses_post on the server).
                case 'content':
                    el.innerHTML = postData.content || el.getAttribute('data-lpb-fallback') || '';
                    break;

                default:
                    // title, excerpt, date, modified, post_type, id — plain text only.
                    fillTextField(el, resolveBindingValue(binding, postData, 'text'));
                    break;
            }
        });

        fillFormBindings(container, postData);
    }

    /**
     * Finds hidden form inputs whose value attribute holds an lpb-bind: marker
     * (written by ClickedPostFormValueTag) and sets their live value to the
     * corresponding clicked-post field. Reads from the HTML attribute (not the
     * DOM property) so the marker survives repeated popup opens.
     *
     * @param {Element} container  The popup DOM node.
     * @param {Object}  postData   Payload from the REST endpoint.
     */
    function fillFormBindings(container, postData) {
        container.querySelectorAll('input[type="hidden"]').forEach(function (input) {
            var marker = parseFormValueMarker(input.getAttribute('value'));
            if (!marker) { return; }

            var resolved = normalizeResolvedValue(
                resolveBindingValue(marker, postData, 'text'),
                'text'
            );

            if (resolved !== '') {
                input.value = resolved;
            }
        });
    }

    /** Reads a binding from data attributes or from dynamic-tag URL/image markers. */
    function getBinding(el) {
        var fieldName = el.getAttribute('data-lpb-field');
        var metaKey   = el.getAttribute('data-lpb-meta-key') || '';
        var target    = el.getAttribute('data-lpb-bind-target') || '';

        if (fieldName) {
            return {
                fieldName: fieldName,
                metaKey:   metaKey,
                target:    target,
            };
        }

        var marker = null;

        if (el.tagName === 'A') {
            marker = parseBindingMarker(el.getAttribute('href'));
            target = 'url';
        } else if (el.tagName === 'IMG') {
            marker = parseBindingMarker(el.getAttribute('src'));
            target = 'image';
        }

        if (!marker) {
            return null;
        }

        el.setAttribute('data-lpb-field', marker.fieldName);
        el.setAttribute('data-lpb-bind-target', target);

        if (marker.metaKey) {
            el.setAttribute('data-lpb-meta-key', marker.metaKey);
        }

        return {
            fieldName: marker.fieldName,
            metaKey:   marker.metaKey,
            target:    target,
        };
    }

    /**
     * Parses the plain-text sentinel written by ClickedPostFormValueTag into the
     * value attribute of a hidden input (e.g. "lpb-bind:title" or
     * "lpb-bind:meta:event_date"). Returns null when the value is not an LPB marker.
     */
    function parseFormValueMarker(value) {
        value = String(value || '');
        if (value.indexOf('lpb-bind:') !== 0) { return null; }

        var rest = value.substring('lpb-bind:'.length);
        var fieldName, metaKey = '';

        if (rest.indexOf('meta:') === 0) {
            fieldName = 'meta';
            metaKey   = rest.substring('meta:'.length);
        } else {
            fieldName = rest;
        }

        return fieldName ? { fieldName: fieldName, metaKey: metaKey } : null;
    }

    /** Parses markers like #lpb-field=meta&lpb-meta-key=event_date. */
    function parseBindingMarker(value) {
        value = String(value || '');

        if (value.indexOf('lpb-field=') === -1) {
            return null;
        }

        var fieldMatch = value.match(/[?&#]lpb-field=([^&#]+)/);
        if (!fieldMatch) {
            return null;
        }

        var metaMatch = value.match(/[?&#]lpb-meta-key=([^&#]+)/);

        return {
            fieldName: decodeURIComponent(fieldMatch[1] || ''),
            metaKey:   metaMatch ? decodeURIComponent(metaMatch[1] || '') : '',
        };
    }

    /** Finds all custom meta keys required by bindings in a popup. */
    function collectRequiredMetaKeys(popupId) {
        var container = getPopupContainer(popupId);
        var root      = container || document;
        var keys      = [];

        root.querySelectorAll(bindingSelector).forEach(function (el) {
            var binding = getBinding(el);

            if (binding && binding.fieldName === 'meta' && binding.metaKey) {
                keys.push(binding.metaKey);
            }
        });

        // Also collect meta keys from hidden form inputs using lpb-bind:meta: markers.
        root.querySelectorAll('input[type="hidden"]').forEach(function (input) {
            var marker = parseFormValueMarker(input.getAttribute('value'));
            if (marker && marker.fieldName === 'meta' && marker.metaKey) {
                keys.push(marker.metaKey);
            }
        });

        return normalizeMetaKeys(keys);
    }

    /** Sets textContent; falls back to data-lpb-fallback if value is empty. */
    function fillTextField(el, value) {
        value = normalizeResolvedValue(value, 'text');
        el.textContent = value !== '' ? value : (el.getAttribute('data-lpb-fallback') || '');
    }

    /** Updates src and alt on an <img> [data-lpb-field="featured_image"] element. */
    function fillImageField(el, postData) {
        var src       = postData.featured_image || '';
        var altSource = el.getAttribute('data-lpb-alt-source') || 'image_alt';
        var alt       = altSource === 'post_title'
            ? (postData.title || '')
            : (postData.featured_image_alt || postData.title || '');

        if (src) {
            el.setAttribute('src', src);
        }
        el.setAttribute('alt', alt);
    }

    /** Reads data-lpb-meta-key and looks up that key in postData.custom_meta. */
    function fillMetaField(el, postData) {
        fillTextField(el, resolveBindingValue(getBinding(el), postData, 'text'));
    }

    /** Updates an href generated by the Clicked Post URL dynamic tag. */
    function fillUrlBinding(el, binding, postData) {
        var url = normalizeResolvedValue(resolveBindingValue(binding, postData, 'url'), 'url');

        if (url && isSafeUrl(url)) {
            el.setAttribute('href', url);
        } else {
            el.setAttribute('href', '#');
        }
    }

    /** Updates an image generated by the Clicked Post Image dynamic tag. */
    function fillImageBinding(el, binding, postData) {
        var value = resolveBindingValue(binding, postData, 'image');
        var src   = normalizeResolvedValue(value, 'image');
        var alt   = getObjectText(value, 'alt') || getObjectText(value, 'title') || postData.title || '';

        if (src && isSafeUrl(src)) {
            el.setAttribute('src', src);
        }

        el.setAttribute('alt', alt);
    }

    /** Resolves a binding from the post payload. */
    function resolveBindingValue(binding, postData, preferredType) {
        if (!binding) { return ''; }

        if (binding.fieldName === 'meta') {
            return (postData.custom_meta && binding.metaKey)
                ? postData.custom_meta[binding.metaKey]
                : '';
        }

        if (binding.fieldName === 'featured_image') {
            return postData.featured_image || '';
        }

        return typeof postData[binding.fieldName] !== 'undefined'
            ? postData[binding.fieldName]
            : '';
    }

    /** Converts scalar, ACF image arrays, and common object shapes into strings. */
    function normalizeResolvedValue(value, preferredType) {
        if (value === null || typeof value === 'undefined') {
            return '';
        }

        if (typeof value === 'number' || typeof value === 'boolean') {
            return String(value);
        }

        if (typeof value === 'string') {
            return value;
        }

        if (Array.isArray(value)) {
            return value.map(function (item) {
                return normalizeResolvedValue(item, preferredType);
            }).filter(Boolean).join(', ');
        }

        if (typeof value === 'object') {
            if (preferredType === 'image' || preferredType === 'url') {
                return getObjectText(value, 'url') ||
                    getObjectText(value, 'src') ||
                    getObjectText(value, 'permalink');
            }

            return getObjectText(value, 'title') ||
                getObjectText(value, 'name') ||
                getObjectText(value, 'label') ||
                getObjectText(value, 'url') ||
                getObjectText(value, 'permalink');
        }

        return '';
    }

    /** Safely reads a string-ish property from an object. */
    function getObjectText(value, key) {
        if (!value || typeof value !== 'object' || typeof value[key] === 'undefined') {
            return '';
        }

        return normalizeResolvedValue(value[key], 'text');
    }

    /** Rejects javascript: and other unsafe URL protocols before mutating href/src. */
    function isSafeUrl(url) {
        try {
            var parsed = new URL(String(url), window.location.href);
            return ['http:', 'https:', 'mailto:', 'tel:'].indexOf(parsed.protocol) !== -1;
        } catch (err) {
            return false;
        }
    }

    /**
     * For <a> elements: sets href and optionally its text.
     * For other elements: sets textContent to the URL.
     */
    function fillPermalinkField(el, postData) {
        var url = postData.permalink || '';
        if (el.tagName === 'A') {
            if (url) { el.setAttribute('href', url); }
            if (!el.textContent.trim()) {
                el.textContent = postData.title || url;
            }
        } else {
            el.textContent = url;
        }
    }

    /**
     * Top-level populate call. Finds the popup container, then fills fields.
     * If the popup is not yet in the DOM (edge case), retries once after 150 ms.
     *
     * @param {Object} postData
     * @param {number} popupId
     */
    function populatePopupFields(postData, popupId) {
        if (!postData) { return; }

        var container = getPopupContainer(popupId);

        if (container) {
            fillFields(container, postData);
            return;
        }

        // Popup not found yet — retry once after a short delay.
        setTimeout(function () {
            var retryContainer = getPopupContainer(popupId);
            if (retryContainer) {
                fillFields(retryContainer, postData);
            }
        }, 150);
    }

    // ── Click handler ─────────────────────────────────────────────────────────────

    /**
     * Delegated click listener attached to the document in capture phase.
     * Using capture (third arg = true) ensures we intercept clicks before Elementor's
     * own listeners, which lets us call stopPropagation() safely.
     */
    function handleTriggerClick(event) {
        var trigger = event.target.closest('[data-lpb-trigger="1"]');
        if (!trigger) { return; }

        event.preventDefault();
        event.stopPropagation();

        var postId  = parseInt(trigger.getAttribute('data-lpb-post-id'),  10);
        var popupId = parseInt(trigger.getAttribute('data-lpb-popup-id'), 10);

        if (!postId || !popupId) {
            console.warn('LPB: trigger element is missing data-lpb-post-id or data-lpb-popup-id.', trigger);
            return;
        }

        LPB.activePostId  = postId;
        LPB.activePopupId = popupId;

        var metaKeys = collectRequiredMetaKeys(popupId);

        // 1. Fetch post data (instant if cached).
        // 2. Pre-populate before showing so fields are ready when the popup animates in.
        // 3. Open the popup.
        // 4. Re-populate after open to handle any dynamic Elementor re-renders.
        fetchPostData(postId, metaKeys).then(function (postData) {
            // Pre-fill while popup is still hidden — no flash of empty content.
            if (postData) {
                populatePopupFields(postData, popupId);
            }

            return openElementorPopup(popupId).then(function () {
                return postData;
            });
        }).then(function (postData) {
            // Fill again after popup becomes visible (covers Elementor re-render on show).
            if (postData) {
                return fetchPostData(postId, collectRequiredMetaKeys(popupId)).then(function (freshPostData) {
                    populatePopupFields(freshPostData || postData, popupId);
                });
            }
        });
    }

    // ── Elementor popup open hook ─────────────────────────────────────────────────

    /**
     * Called by Elementor Pro's frontend hooks system when any popup opens.
     * Re-populates fields in case Elementor re-renders the popup DOM on show.
     *
     * @param {number|string} id  The popup ID reported by Elementor.
     */
    function onPopupShow(id) {
        var popupId = parseInt(id, 10);

        if (!LPB.activePostId || popupId !== LPB.activePopupId) {
            return; // Not our popup or no active post — leave it alone.
        }

        fetchPostData(LPB.activePostId, collectRequiredMetaKeys(popupId)).then(function (postData) {
            if (postData) {
                populatePopupFields(postData, popupId);
            }
        });
    }

    // ── Preload support ───────────────────────────────────────────────────────────

    /**
     * If any trigger element has data-lpb-preload="1", fetches its post data
     * immediately after the page loads so the first click is instant.
     */
    function preloadMarkedItems() {
        var preloads = document.querySelectorAll('[data-lpb-trigger="1"][data-lpb-preload="1"]');
        preloads.forEach(function (el) {
            var postId  = parseInt(el.getAttribute('data-lpb-post-id'), 10);
            var popupId = parseInt(el.getAttribute('data-lpb-popup-id'), 10);
            if (postId && !LPB.posts[postId]) {
                fetchPostData(postId, popupId ? collectRequiredMetaKeys(popupId) : []); // fire-and-forget; result stored in LPB.posts
            }
        });
    }

    // ── Initialisation ────────────────────────────────────────────────────────────

    function init() {
        // Delegated click listener — catches trigger clicks anywhere on the page,
        // including items loaded dynamically by Elementor's Loop Grid infinite scroll.
        document.addEventListener('click', handleTriggerClick, true);

        // Hook into Elementor's frontend hooks system (most reliable method).
        if (
            typeof window.elementorFrontend !== 'undefined' &&
            window.elementorFrontend.hooks &&
            typeof window.elementorFrontend.hooks.addAction === 'function'
        ) {
            window.elementorFrontend.hooks.addAction(
                'elementor/popup/show',
                function (id /*, instance */) { onPopupShow(id); }
            );
        }

        // Also wire up via jQuery event for older Elementor Pro versions that fire
        // a jQuery event rather than going through the hooks system.
        if (typeof window.jQuery !== 'undefined') {
            window.jQuery(document).on('elementor/popup/show', function (event, id) {
                onPopupShow(id);
            });
        }

        preloadMarkedItems();
    }

    // Run after DOM is ready; elementor-frontend.js (our dependency) is already parsed.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
