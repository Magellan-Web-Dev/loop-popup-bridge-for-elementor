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
        popupMetaKeys: {},
        restUrl:       '',
        nonce:         '',
    };

    var LPB = window.LoopPopupBridge;
    LPB.postMetaKeys  = LPB.postMetaKeys  || {};
    LPB.popupMetaKeys = LPB.popupMetaKeys || {};

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

    /**
     * True when a binding reads a custom meta key that has never been requested
     * from the server for this post.
     *
     * This is the difference between "not loaded yet" and "loaded and empty":
     *
     *  - Requested and returned empty (or not on the server allowlist, so it can
     *    never arrive) counts as loaded — the field renders its configured
     *    fallback, which is the correct empty state.
     *  - Never requested is unknown, not empty. Writing to such a field would
     *    erase the value a more complete payload already put on screen, which is
     *    exactly the first-open race this guard exists to stop.
     *
     * @param  {Object|null} binding  Anything carrying {fieldName, metaKey}.
     * @param  {number}      postId
     * @return {boolean}
     */
    function isUnloadedMetaBinding(binding, postId) {
        if (!binding || binding.fieldName !== 'meta' || !binding.metaKey) {
            return false;
        }

        return !(LPB.postMetaKeys[postId] || {})[binding.metaKey];
    }

    /**
     * The same "unknown is not empty" rule, for base post fields.
     *
     * The page does not inline every base field: `content` is only rendered when a
     * popup actually binds it, and a site can trim more through lpb_preload_fields.
     * A field the payload never carried is unknown, so filling from it would blank a
     * binding rather than leave it for a payload that can answer.
     *
     * Presence is the test, not truthiness — a post with a genuinely empty excerpt
     * has `excerpt: ""` in the payload and must render its fallback, not refetch
     * forever. `id` is exempt: it is always present and is not a fill target.
     *
     * @param  {Object|null} binding
     * @param  {Object|null} postData
     * @return {boolean}
     */
    function isUnloadedFieldBinding(binding, postData) {
        if (!binding || !binding.fieldName || binding.fieldName === 'meta') {
            return false;
        }

        if (!postData || binding.fieldName === 'id') {
            return false;
        }

        return !Object.prototype.hasOwnProperty.call(postData, binding.fieldName);
    }

    /** Base fields the popup's bindings need but the cached payload does not carry. */
    function missingFieldsForPopup(popupId, postId) {
        var postData = LPB.posts[postId];
        var missing  = {};

        if (!postData) { return []; }

        var root = getPopupContainer(popupId);
        if (!root) { return []; }

        root.querySelectorAll(bindingSelector).forEach(function (el) {
            var binding = getBinding(el);

            if (isUnloadedFieldBinding(binding, postData)) {
                missing[binding.fieldName] = true;
            }
        });

        return Object.keys(missing);
    }

    /**
     * Builds the REST URL, including requested meta keys and the popup to resolve.
     *
     * Passing popup_id asks the server to add whatever meta keys that popup binds,
     * read from its saved Elementor data. That matters for a trigger that was not
     * on the page at render time (Loop Grid infinite scroll), where the client has
     * no preloaded key list and cannot read the popup's DOM either.
     */
    function buildPostUrl(postId, metaKeys, popupId) {
        var url    = LPB.restUrl + postId;
        var params = [];

        if (metaKeys.length) {
            params.push('meta_keys=' + encodeURIComponent(metaKeys.join(',')));
        }

        if (popupId) {
            params.push('popup_id=' + encodeURIComponent(popupId));
        }

        return params.length ? url + '?' + params.join('&') : url;
    }

    /**
     * Requests currently in flight, keyed by post ID + the exact key set requested.
     * The popup-show path and the post-open pass ask for the same keys at almost the
     * same moment; sharing one promise means one round-trip and one cache write
     * instead of two responses whose arrival order would decide the result.
     *
     * @type {Object<string, Promise<Object|null>>}
     */
    var inFlightRequests = {};

    /**
     * Folds a fresh response into whatever is already cached for the post.
     *
     * Values from the newer response win, but a key an earlier response resolved is
     * never dropped: a request that did not ask for a key says nothing about it, so
     * a narrow response landing after a wide one must not shrink the cache. Applied
     * unconditionally (the previous version skipped the merge whenever either side
     * had no custom_meta, which let such a response downgrade the cache).
     *
     * @param  {number} postId
     * @param  {Object} data   Parsed REST payload; mutated in place.
     * @return {Object}
     */
    function mergeIntoCachedPost(postId, data) {
        var cached = LPB.posts[postId] && LPB.posts[postId].custom_meta;

        data.custom_meta = Object.assign({}, cached || {}, data.custom_meta || {});

        return data;
    }

    /**
     * Returns a Promise that resolves to the post data object.
     *
     * On a normal page load nothing gets here: PHP renders the payload for every
     * trigger inline, so LPB.posts is already complete and every call is a cache
     * hit. What still reaches the network is a trigger the page could not know
     * about at render time — anything inserted later by AJAX.
     *
     * @param  {number} postId
     * @param  {Array<string>} metaKeys
     * @param  {number} [popupId]  Popup whose bindings the server should resolve.
     * @param  {boolean} [force]   Request even when the meta-key cache is satisfied.
     *                             The base fields are the reason: the cache index
     *                             tracks meta keys only, so a payload missing a base
     *                             field still looks complete to it. The REST response
     *                             always carries every base field, so one forced
     *                             request is what fills them.
     * @return {Promise<Object|null>}
     */
    function fetchPostData(postId, metaKeys, popupId, force) {
        metaKeys = normalizeMetaKeys(metaKeys);
        popupId  = parseInt(popupId, 10) || 0;

        // An unresolved popup is the one case where the cache cannot answer for
        // itself: metaKeys is empty only because nobody knows what this popup needs
        // yet, so a cache "hit" here would lock in that ignorance permanently. Let
        // the request through once and the server fills in the key list.
        var popupResolved = !popupId || !!LPB.popupMetaKeys[popupId];

        if (!force && popupResolved && hasCachedMetaKeys(postId, metaKeys)) {
            return Promise.resolve(LPB.posts[postId]);
        }

        var alreadyCached = Object.keys(LPB.postMetaKeys[postId] || {});
        var keysToRequest = normalizeMetaKeys(alreadyCached.concat(metaKeys));
        var requestKey    = postId + '|' + popupId + '|' + (force ? 'f|' : '')
            + keysToRequest.slice().sort().join(',');

        if (inFlightRequests[requestKey]) {
            return inFlightRequests[requestKey];
        }

        var request = fetch(buildPostUrl(postId, keysToRequest, popupId), {
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
                mergeIntoCachedPost(postId, data);

                LPB.posts[postId] = data;

                // meta_keys_loaded is what the server actually resolved, which is a
                // superset of what we asked for whenever popup_id widened it. Both
                // are recorded: the union is the set that no longer needs asking for.
                rememberMetaKeys(postId, keysToRequest.concat(data.meta_keys_loaded || []));

                // popup_meta_keys is this popup's own bindings; meta_keys_loaded is
                // the wider union this request happened to cover. Only the former can
                // be filed against the popup ID.
                if (popupId && data.popup_meta_keys) {
                    LPB.popupMetaKeys[popupId] = data.popup_meta_keys;
                }

                return data;
            })
            .catch(function (err) {
                console.error(err);
                return null;
            })
            .then(function (result) {
                delete inFlightRequests[requestKey];
                return result;
            });

        inFlightRequests[requestKey] = request;

        return request;
    }

    // ── Popup open ────────────────────────────────────────────────────────────────

    /**
     * Opens an Elementor Pro popup by its numeric ID.
     *
     * showPopup({ id }) is the only supported opening API. `elementor/popup/show`
     * is a notification Elementor emits *after* a popup opened, so dispatching it
     * ourselves opens nothing — when the Pro popup module is unavailable (Pro
     * inactive, or an editor preview without Pro) there is nothing to open and we
     * say so rather than firing a no-op event.
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
            console.warn('LPB: Elementor Pro popup module unavailable — cannot open popup ' + popupId + '.');
        }

        // Give the popup ~120 ms to become visible in the DOM before we try to fill it.
        return new Promise(function (resolve) { setTimeout(resolve, 120); });
    }

    // ── Field population ──────────────────────────────────────────────────────────

    /**
     * Finds the outer modal wrapper Elementor Pro created for the given popup ID.
     *
     * Elementor's actual popup lifecycle (elementor-pro/assets/js/elements-handlers.js
     * plus elementor/assets/lib/dialog/dialog.js):
     *
     *  - The popup document printed on the page is removed on init and kept only as
     *    an HTML string, so none of the popup is in the DOM before its first open.
     *  - The first open lazily creates the wrapper `#elementor-popup-modal-{id}`, and
     *    every open clones fresh inner content into it. The wrapper is appended
     *    before the `elementor/popup/show` notification fires, so it reliably exists
     *    by the time Elementor tells us the popup opened.
     *  - Hiding removes the cloned content but leaves the wrapper in the DOM, so once
     *    two popups have been opened two wrappers coexist and only the exact element
     *    ID identifies the one we were asked for.
     *
     * `data-elementor-id` is set on the inner Elementor document, never on this
     * wrapper, so the wrapper is addressed by ID alone. Returns null while the popup
     * has no wrapper yet; there is deliberately no fallback, because every other
     * `.elementor-popup-modal` in the page belongs to a different popup.
     *
     * @param  {number} popupId
     * @return {Element|null}
     */
    function getPopupContainer(popupId) {
        popupId = parseInt(popupId, 10);

        return popupId ? document.getElementById('elementor-popup-modal-' + popupId) : null;
    }

    /**
     * Same lookup as getPopupContainer(), but only returns the wrapper once the
     * popup's own content is actually inside it.
     *
     * The wrapper survives a close; the Elementor document Elementor clones into it
     * does not. A bare wrapper therefore says nothing about this popup's bindings,
     * and collectRequiredMetaKeys() would report "no custom meta keys" for a popup
     * that has plenty — the same false-empty answer as having no wrapper at all.
     * Readiness is what makes an empty key list authoritative, so every path that
     * decides "this popup needs nothing more" must go through here.
     *
     * @param  {number} popupId
     * @return {Element|null}
     */
    function getReadyPopupContainer(popupId) {
        var container = getPopupContainer(popupId);

        if (!container) {
            return null;
        }

        return container.querySelector('[data-elementor-type="popup"], .elementor') ? container : null;
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
     * @param {number}  postId     Post the payload belongs to; identifies which of
     *                             its custom meta keys have actually been loaded.
     */
    function fillFields(container, postData, postId) {
        var fields = container.querySelectorAll(bindingSelector);

        fields.forEach(function (el) {
            var binding = getBinding(el);
            if (!binding) { return; }

            // Unknown is not empty: a meta key this payload never requested, or a
            // base field it never carried, is left exactly as it is, so a narrower
            // pass cannot blank a field a wider one already filled.
            // See isUnloadedMetaBinding() / isUnloadedFieldBinding().
            if (isUnloadedMetaBinding(binding, postId)) { return; }
            if (isUnloadedFieldBinding(binding, postData)) { return; }

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
                    // title, excerpt, date, modified, post_type, id
                    fillTextField(el, resolveBindingValue(binding, postData, 'text'));
                    break;
            }
        });

        fillFormBindings(container, postData, postId);
        fillChoiceFieldsByMarkers(container, postData, postId);
    }

    /**
     * Fills scalar form fields (hidden, text, email, textarea, etc.) whose value
     * attribute / defaultValue holds an lpb-bind: marker written by
     * ClickedPostFormValueTag. Reads from the HTML attribute so the marker
     * survives repeated popup opens.
     *
     * @param {Element} container  The popup DOM node.
     * @param {Object}  postData   Payload from the REST endpoint.
     * @param {number}  postId     Post the payload belongs to.
     */
    function fillFormBindings(container, postData, postId) {
        container.querySelectorAll('input:not([type="radio"]):not([type="checkbox"]), textarea').forEach(function (el) {
            var attrValue = el.tagName === 'TEXTAREA' ? el.defaultValue : el.getAttribute('value');
            var marker = parseFormValueMarker(attrValue);
            if (!marker) { return; }
            if (isUnloadedMetaBinding(marker, postId)) { return; }

            var resolved = normalizeResolvedValue(
                resolveBindingValue(marker, postData, 'text'),
                'text'
            );
            el.value = resolved !== '' ? resolved : marker.fallback;
        });
    }

    /**
     * Parses a hidden-input marker for select or radio bindings.
     *
     * Format: "{prefix}{field}[|target={id}][|fallback={val}]"
     * e.g.    "lpb-bind-select:meta:colors|target=field_abc|fallback=Default"
     *
     * Returns null when the value does not start with the given prefix.
     *
     * @param  {string} value   The raw HTML value attribute.
     * @param  {string} prefix  "lpb-bind-select:" or "lpb-bind-radio:"
     * @return {Object|null}
     */
    function parseFormChoiceMarker(value, prefix) {
        value = String(value || '');
        if (value.indexOf(prefix) !== 0) { return null; }

        var rest   = value.substring(prefix.length);
        var params = {};

        var parts = rest.split('|');
        var bindingPart = parts[0];

        for (var i = 1; i < parts.length; i++) {
            var eqIdx = parts[i].indexOf('=');
            if (eqIdx !== -1) {
                var key = parts[i].substring(0, eqIdx);
                var val = decodeMarkerValue(parts[i].substring(eqIdx + 1));
                params[key] = val;
            }
        }

        var fieldName, metaKey = '';

        if (bindingPart.indexOf('meta:') === 0) {
            fieldName = 'meta';
            metaKey   = bindingPart.substring('meta:'.length);
        } else {
            fieldName = bindingPart;
        }

        return fieldName ? {
            fieldName: fieldName,
            metaKey:   metaKey,
            target:    params['target']   || '',
            fallback:  params['fallback'] || '',
        } : null;
    }

    /**
     * Normalizes any resolved post-data value to an array of {value, label} items
     * suitable for populating select options or radio buttons.
     *
     * - null / undefined / '' → []
     * - string or number      → [{value: str, label: str}]
     * - array of scalars      → [{value: item, label: item}, ...]
     * - array of objects      → [{value: obj.value|obj.id, label: obj.label|obj.name|obj.title}, ...]
     *
     * @param  {*}      value
     * @param  {string} fallback  Used when value resolves to empty.
     * @return {Array<{value: string, label: string}>}
     */
    function resolveToOptionItems(value, fallback) {
        if (value === null || typeof value === 'undefined' || value === '' || value === false ||
                (Array.isArray(value) && value.length === 0)) {
            value = fallback || '';
        }
        if (value === null || typeof value === 'undefined' || value === '' || value === false) {
            return [];
        }

        if (!Array.isArray(value)) {
            value = [value];
        }

        return value.map(function (item) {
            if (typeof item === 'string' || typeof item === 'number' || typeof item === 'boolean') {
                var str = String(item);
                return { value: str, label: str };
            }
            if (typeof item === 'object' && item !== null) {
                var v = String(item.value  || item.id    || item.key   || '');
                var l = String(item.label  || item.name  || item.title || v);
                return { value: v, label: l };
            }
            return null;
        }).filter(Boolean);
    }

    /**
     * Scans `root` for lpb-bind-select/radio markers and moves them onto their
     * parent element as data-lpb-marker, then removes the placeholder element.
     *
     * Handles two layouts produced by Elementor's form options editor:
     *
     *   Normal  — full marker is in the value attribute:
     *             <option value="lpb-bind-select:meta:key|fallback=X">…</option>
     *
     *   Split   — Elementor's "Label|Value" textarea format divided the marker:
     *             <option value="fallback=X">lpb-bind-select:meta:key</option>
     *             In this case we reconstruct the full marker from textContent + '|' + value.
     *
     * The same two layouts apply to radio inputs (label text vs. input value).
     *
     * @param {Element|Document} root  Scope for the DOM search.
     */
    function moveChoiceMarkersToParent(root) {

        // ── Select: normal case ───────────────────────────────────────────────
        root.querySelectorAll('option[value^="lpb-bind-select:"]').forEach(function (markerOpt) {
            var selectEl = markerOpt.parentElement;
            if (!selectEl || selectEl.tagName !== 'SELECT') { return; }
            selectEl.setAttribute('data-lpb-marker', markerOpt.getAttribute('value'));
            selectEl.removeChild(markerOpt);
        });

        // ── Select: split case (Elementor label|value textarea format) ────────
        root.querySelectorAll('option').forEach(function (markerOpt) {
            var text     = (markerOpt.textContent || '').trim();
            var selectEl = markerOpt.parentElement;
            if (!text || text.indexOf('lpb-bind-select:') !== 0) { return; }
            if (!selectEl || selectEl.tagName !== 'SELECT') { return; }
            if (selectEl.hasAttribute('data-lpb-marker')) { return; }
            var suffix = markerOpt.getAttribute('value') || '';
            if (suffix.indexOf('lpb-bind-select:') === 0) { return; }
            selectEl.setAttribute('data-lpb-marker', suffix ? text + '|' + suffix : text);
            selectEl.removeChild(markerOpt);
        });

        // ── Radio: normal case ────────────────────────────────────────────────
        root.querySelectorAll('input[type="radio"][value^="lpb-bind-radio:"]').forEach(function (markerInput) {
            var subgroup = markerInput.closest('.elementor-field-subgroup') || markerInput.parentElement;
            if (!subgroup) { return; }
            subgroup.setAttribute('data-lpb-marker', markerInput.getAttribute('value'));
            subgroup.setAttribute('data-lpb-name',   markerInput.name);
            var markerItem = markerInput.closest('.elementor-radio-item') || markerInput.parentElement;
            if (markerItem && markerItem !== subgroup) {
                subgroup.removeChild(markerItem);
            }
        });

        // ── Radio: split case ─────────────────────────────────────────────────
        root.querySelectorAll('input[type="radio"]').forEach(function (markerInput) {
            var labelEl = markerInput.nextElementSibling;
            if (!labelEl || labelEl.tagName !== 'LABEL') { return; }
            var text = (labelEl.textContent || '').trim();
            if (!text || text.indexOf('lpb-bind-radio:') !== 0) { return; }
            var subgroup = markerInput.closest('.elementor-field-subgroup') || markerInput.parentElement;
            if (!subgroup) { return; }
            if (subgroup.hasAttribute('data-lpb-marker')) { return; }
            var suffix = markerInput.getAttribute('value') || '';
            if (suffix.indexOf('lpb-bind-radio:') === 0) { return; }
            subgroup.setAttribute('data-lpb-marker', suffix ? text + '|' + suffix : text);
            subgroup.setAttribute('data-lpb-name',   markerInput.name);
            var markerItem = markerInput.closest('.elementor-radio-item') || markerInput.parentElement;
            if (markerItem && markerItem !== subgroup) {
                subgroup.removeChild(markerItem);
            }
        });
    }

    /** Runs once at page init — delegates to moveChoiceMarkersToParent. */
    function initChoiceFieldMarkers() {
        moveChoiceMarkersToParent(document);
    }

    /**
     * Fills select and radio fields whose binding was stored by initChoiceFieldMarkers.
     * Reads data-lpb-marker from the parent <select> or .elementor-field-subgroup,
     * removes previously LPB-appended items, cleans blank static options, then
     * appends new items from the clicked post's data (or a single fallback option).
     *
     * @param {Element} container  The popup DOM node.
     * @param {Object}  postData   Payload from the REST endpoint.
     * @param {number}  postId     Post the payload belongs to.
     */
    function fillChoiceFieldsByMarkers(container, postData, postId) {

        // Lazily pick up any markers not yet moved by initChoiceFieldMarkers,
        // including Elementor-split markers (see moveChoiceMarkersToParent).
        moveChoiceMarkersToParent(container);

        // ── Select fields ─────────────────────────────────────────────────────
        container.querySelectorAll('select[data-lpb-marker]').forEach(function (selectEl) {
            var marker = parseFormChoiceMarker(selectEl.getAttribute('data-lpb-marker'), 'lpb-bind-select:');
            if (!marker) { return; }
            if (isUnloadedMetaBinding(marker, postId)) { return; }

            var rawValue   = resolveBindingValue(marker, postData, 'text');
            var isFallback = marker.fallback !== '' &&
                (rawValue === null || rawValue === undefined || rawValue === '' || rawValue === false ||
                 (Array.isArray(rawValue) && rawValue.length === 0));
            var items = resolveToOptionItems(rawValue, marker.fallback);

            selectEl.querySelectorAll('option[data-lpb]').forEach(function (opt) {
                opt.parentNode.removeChild(opt);
            });

            selectEl.querySelectorAll('option').forEach(function (opt) {
                if (opt.textContent.trim() === '') {
                    opt.parentNode.removeChild(opt);
                }
            });

            if (isFallback) {
                selectEl.querySelectorAll('option').forEach(function (opt) {
                    opt.selected = false;
                    opt.removeAttribute('selected');
                });
            }

            items.forEach(function (item) {
                var opt = document.createElement('option');
                opt.value       = isFallback ? item.label : item.value;
                opt.textContent = item.label;
                opt.setAttribute('data-lpb', '');
                if (isFallback) {
                    opt.classList.add('lpb-fallback');
                    opt.setAttribute('data-novalue', '');
                    opt.setAttribute('selected', '');
                    opt.selected = true;
                }
                selectEl.appendChild(opt);
            });
        });

        // ── Radio groups ──────────────────────────────────────────────────────
        container.querySelectorAll('.elementor-field-subgroup[data-lpb-marker]').forEach(function (subgroup) {
            var marker   = parseFormChoiceMarker(subgroup.getAttribute('data-lpb-marker'), 'lpb-bind-radio:');
            var nameAttr = subgroup.getAttribute('data-lpb-name') || '';
            if (!marker || !nameAttr) { return; }
            if (isUnloadedMetaBinding(marker, postId)) { return; }

            var items = resolveToOptionItems(resolveBindingValue(marker, postData, 'text'), marker.fallback);

            subgroup.querySelectorAll('[data-lpb]').forEach(function (item) {
                item.parentNode.removeChild(item);
            });

            items.forEach(function (item, index) {
                var inputId = 'lpb-radio-' + nameAttr.replace(/[^a-z0-9_-]/gi, '-') + '-' + index;

                var wrapper = document.createElement('div');
                wrapper.className = 'elementor-radio-item';
                wrapper.setAttribute('data-lpb', '');

                var input = document.createElement('input');
                input.type      = 'radio';
                input.id        = inputId;
                input.name      = nameAttr;
                input.value     = item.value;
                input.className = 'elementor-field';

                var label = document.createElement('label');
                label.setAttribute('for', inputId);
                label.className   = 'elementor-field-label';
                label.textContent = item.label;

                wrapper.appendChild(input);
                wrapper.appendChild(label);
                subgroup.appendChild(wrapper);
            });
        });
    }

    /**
     * Reads a binding from data attributes or from dynamic-tag URL/image markers.
     *
     * The marker only survives in href/src until the first hydration — afterwards
     * the attribute holds a real URL — so every part of it, including the
     * optional value type, is persisted to data attributes on first read. This
     * keeps the binding intact when the popup is reopened for another Loop Grid
     * post.
     */
    function getBinding(el) {
        var fieldName = el.getAttribute('data-lpb-field');
        var metaKey   = el.getAttribute('data-lpb-meta-key') || '';
        var target    = el.getAttribute('data-lpb-bind-target') || '';

        if (fieldName) {
            return {
                fieldName: fieldName,
                metaKey:   metaKey,
                target:    target,
                valueType: normalizeValueType(el.getAttribute('data-lpb-value-type')),
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

        if (marker.valueType) {
            el.setAttribute('data-lpb-value-type', marker.valueType);
        }

        return {
            fieldName: marker.fieldName,
            metaKey:   marker.metaKey,
            target:    target,
            valueType: marker.valueType,
        };
    }

    /**
     * Parses the plain-text sentinel written by ClickedPostFormValueTag into the
     * value attribute of a hidden input (e.g. "lpb-bind:title" or
     * "lpb-bind:meta:event_date|fallback=TBD"). Returns null when the value is not an LPB marker.
     */
    function parseFormValueMarker(value) {
        value = String(value || '');
        if (value.indexOf('lpb-bind:') !== 0) { return null; }

        var rest = value.substring('lpb-bind:'.length);
        var fallback = '';
        var fallbackSeparator = '|fallback=';
        var fallbackIndex = rest.indexOf(fallbackSeparator);

        if (fallbackIndex !== -1) {
            fallback = decodeMarkerValue(rest.substring(fallbackIndex + fallbackSeparator.length));
            rest = rest.substring(0, fallbackIndex);
        }

        var fieldName, metaKey = '';

        if (rest.indexOf('meta:') === 0) {
            fieldName = 'meta';
            metaKey   = rest.substring('meta:'.length);
        } else {
            fieldName = rest;
        }

        return fieldName ? { fieldName: fieldName, metaKey: metaKey, fallback: fallback } : null;
    }

    /** Decodes optional marker values without letting malformed data break binding. */
    function decodeMarkerValue(value) {
        try {
            return decodeURIComponent(String(value || ''));
        } catch (err) {
            return '';
        }
    }

    /**
     * URI scheme applied to a URL binding for each recognised value type.
     * An unlisted (or empty) value type means "ordinary URL — leave it alone".
     */
    var URL_VALUE_SCHEMES = {
        email: 'mailto:',
        phone: 'tel:',
    };

    /** Keeps only the value types the URL scheme map knows about. */
    function normalizeValueType(value) {
        value = String(value || '');

        return Object.prototype.hasOwnProperty.call(URL_VALUE_SCHEMES, value) ? value : '';
    }

    /**
     * Restores the plain `&` separators inside a marker.
     *
     * Elementor's Atomic Image template escapes the resolved src twice: esc_url()
     * turns `&` into `&#038;`, then Twig's html autoescaping escapes that entity's
     * own `&` into `&amp;`. What reaches getAttribute('src') is therefore
     *
     *   ?lpb-field=meta&#038;lpb-meta-key=event_image
     *
     * where the legacy Image widget yields a plain `&`. The patterns below anchor
     * each key on [?&#], so without this every segment after the first is invisible
     * and an atomic image binding resolves with no meta key — which reads as
     * "loaded and empty" and leaves the placeholder on screen.
     *
     * Only the separator is normalised, and only for reading. The element's own
     * src/href is never rewritten from here.
     */
    function decodeMarkerSeparators(value) {
        var previous;
        var passes = 0;

        do {
            previous = value;
            value    = value.replace(/&(?:amp|#0*38|#[xX]0*26);/g, '&');
            passes  += 1;
        } while (value !== previous && passes < 3);

        return value;
    }

    /**
     * Parses markers like #lpb-field=meta&lpb-meta-key=event_date.
     *
     * `lpb-value-type` is optional and only present when PHP positively
     * identified the field as holding an email address or a phone number;
     * markers rendered before that existed simply resolve to an empty type.
     */
    function parseBindingMarker(value) {
        value = decodeMarkerSeparators(String(value || ''));

        if (value.indexOf('lpb-field=') === -1) {
            return null;
        }

        var fieldMatch = value.match(/[?&#]lpb-field=([^&#]+)/);
        if (!fieldMatch) {
            return null;
        }

        var metaMatch = value.match(/[?&#]lpb-meta-key=([^&#]+)/);
        var typeMatch = value.match(/[?&#]lpb-value-type=([^&#]+)/);

        return {
            fieldName: decodeURIComponent(fieldMatch[1] || ''),
            metaKey:   metaMatch ? decodeURIComponent(metaMatch[1] || '') : '',
            valueType: typeMatch ? normalizeValueType(decodeMarkerValue(typeMatch[1])) : '',
        };
    }

    /** Finds all custom meta keys required by bindings in a popup. */
    function collectRequiredMetaKeys(popupId) {
        var root = getPopupContainer(popupId);
        var keys = [];

        if (!root) {
            // This popup has no DOM yet, so none of its bindings can be read.
            // Scanning the whole document instead would collect the meta keys of a
            // *different* popup whose wrapper is still in the page; the post-show
            // pass collects the real keys once this popup's own content exists.
            return keys;
        }

        root.querySelectorAll(bindingSelector).forEach(function (el) {
            var binding = getBinding(el);

            if (binding && binding.fieldName === 'meta' && binding.metaKey) {
                keys.push(binding.metaKey);
            }
        });

        // Text markers on scalar inputs / textareas.
        root.querySelectorAll('input:not([type="radio"]):not([type="checkbox"]), textarea').forEach(function (el) {
            var attrValue = el.tagName === 'TEXTAREA' ? el.defaultValue : el.getAttribute('value');
            var marker = parseFormValueMarker(attrValue);
            if (marker && marker.fieldName === 'meta' && marker.metaKey) {
                keys.push(marker.metaKey);
            }
        });

        // Choice markers — already moved to data-lpb-marker by initChoiceFieldMarkers.
        root.querySelectorAll('select[data-lpb-marker]').forEach(function (el) {
            var marker = parseFormChoiceMarker(el.getAttribute('data-lpb-marker'), 'lpb-bind-select:');
            if (marker && marker.fieldName === 'meta' && marker.metaKey) {
                keys.push(marker.metaKey);
            }
        });

        root.querySelectorAll('.elementor-field-subgroup[data-lpb-marker]').forEach(function (el) {
            var marker = parseFormChoiceMarker(el.getAttribute('data-lpb-marker'), 'lpb-bind-radio:');
            if (marker && marker.fieldName === 'meta' && marker.metaKey) {
                keys.push(marker.metaKey);
            }
        });

        // Choice markers not yet moved (popup DOM added after page init).
        root.querySelectorAll('option[value^="lpb-bind-select:"]').forEach(function (el) {
            var marker = parseFormChoiceMarker(el.getAttribute('value'), 'lpb-bind-select:');
            if (marker && marker.fieldName === 'meta' && marker.metaKey) {
                keys.push(marker.metaKey);
            }
        });

        root.querySelectorAll('input[type="radio"][value^="lpb-bind-radio:"]').forEach(function (el) {
            var marker = parseFormChoiceMarker(el.getAttribute('value'), 'lpb-bind-radio:');
            if (marker && marker.fieldName === 'meta' && marker.metaKey) {
                keys.push(marker.metaKey);
            }
        });

        return normalizeMetaKeys(keys);
    }

    /**
     * The meta keys PHP resolved for this popup from its saved Elementor data.
     *
     * This is the answer collectRequiredMetaKeys() cannot give before a popup's
     * first open, and the reason the preload works at all now.
     */
    function serverMetaKeys(popupId) {
        return LPB.popupMetaKeys[parseInt(popupId, 10)] || [];
    }

    /**
     * Everything a popup needs: what the server resolved, plus whatever its DOM
     * reveals once it exists.
     *
     * Neither source alone is sufficient. The DOM scan is blind until the first
     * open, and the server scan cannot see bindings that live in another document
     * (global widgets, shortcode-rendered templates). The union means a gap in
     * either one is covered by the other, and the DOM scan's deliberate "return
     * nothing rather than read the wrong popup" behaviour costs nothing.
     */
    function requiredMetaKeys(popupId) {
        return normalizeMetaKeys(serverMetaKeys(popupId).concat(collectRequiredMetaKeys(popupId)));
    }

    /** Sets textContent; falls back to data-lpb-fallback if value is empty. */
    function fillTextField(el, value) {
        value = normalizeResolvedValue(value, 'text');
        el.innerHTML = value !== '' ? value : (el.getAttribute('data-lpb-fallback') || '');
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

    /**
     * Adds the URI scheme implied by a binding's value type.
     *
     * Values that already carry an explicit scheme (including mailto: and tel:)
     * are returned untouched, so nothing is ever double-prefixed and isSafeUrl()
     * stays the single authority on which protocols are allowed. The value
     * itself is never rewritten — phone punctuation such as "+", spaces,
     * parentheses, hyphens, and extensions survives exactly as entered.
     *
     * @param  {string} url
     * @param  {string} valueType  'email', 'phone', or '' for ordinary URLs.
     * @return {string}
     */
    function applyValueTypeScheme(url, valueType) {
        var scheme = URL_VALUE_SCHEMES[normalizeValueType(valueType)];

        if (!scheme || !url || hasUriScheme(url)) {
            return url;
        }

        return scheme + url;
    }

    /** Updates an href generated by the Clicked Post URL dynamic tag. */
    function fillUrlBinding(el, binding, postData) {
        var url = normalizeResolvedValue(resolveBindingValue(binding, postData, 'url'), 'url').trim();

        url = applyValueTypeScheme(url, binding.valueType);

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

    /**
     * Returns true when a value already starts with an explicit URI scheme
     * (https:, mailto:, tel:, …). Phone numbers such as "+1 555 123 4567" and
     * bare email addresses never match, so they can still be prefixed.
     */
    function hasUriScheme(url) {
        return /^[a-z][a-z0-9+.-]*:/i.test(String(url));
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
     * True while (postId, popupId) is still the selection the user last made.
     * Guards the async population paths so a slow REST response for an earlier
     * click cannot overwrite fields after a newer click took over the context.
     */
    function isActiveContext(postId, popupId) {
        return LPB.activePostId === postId && LPB.activePopupId === popupId;
    }

    // ── Selection lifecycle ───────────────────────────────────────────────────────

    /**
     * Every accepted trigger click takes the next selection token. The token is the
     * only thing that distinguishes two clicks on the *same* item, which (postId,
     * popupId) alone cannot, so it is what lets a callback tell "I am still the
     * current selection" from "a newer click already took over".
     */
    var selectionSequence        = 0;
    var activeSelectionToken     = 0;
    var dispatchedSelectionToken = 0;

    /**
     * The hydration in progress for `hydrationToken`, or null.
     *
     * The popup-show notification and the post-open backstop both try to hydrate the
     * same selection; memoising the promise makes the second caller join the first
     * instead of starting a parallel run, which is what makes one click produce one
     * `lpb:item-selected` no matter which path arrived first.
     */
    var hydrationToken   = 0;
    var hydrationPromise = null;

    /** Interval and ceiling for the backstop's wait on the popup becoming readable. */
    var POPUP_READY_RETRY_MS    = 60;
    var POPUP_READY_MAX_RETRIES = 20;

    /**
     * True while (postId, popupId, token) is still the selection the user last made.
     * Checked after every await so a slow response for an older click can neither
     * fill fields nor announce itself.
     */
    function isCurrentSelection(postId, popupId, token) {
        return isActiveContext(postId, popupId) && token === activeSelectionToken;
    }

    /**
     * Announces the selection exactly once per click.
     *
     * Public contract: `lpb:item-selected` on document, bubbling, with detail
     * {postId, popupId, post}. It is emitted only from here.
     *
     * This used to be dispatched at the end of a hydration that had read the real
     * popup DOM, because that was the earliest moment the payload was known to be
     * complete — before the popup existed, custom_meta was necessarily empty. Now
     * that the popup's meta keys come from its saved data, completeness can be
     * established at click time, so the event fires from the click path instead and
     * arrives before the popup opens rather than after.
     *
     * Three consequences worth knowing:
     *  - A listener that reaches into the popup DOM must use `elementor/popup/show`
     *    instead; there is nothing to read yet when this fires.
     *  - `post.custom_meta` carries the union of the keys of every popup this post
     *    triggers on the page, so it can be wider than any single popup uses.
     *  - Two rapid clicks on different items now produce two events. Announcing at
     *    click time cannot do otherwise: coalescing them would mean waiting to find
     *    out whether a later click is coming, which is the wait that was removed.
     *    Each event still carries only its own item's data, and only the last click
     *    owns the active context, so only its popup is filled.
     */
    function dispatchSelectionEvent(postId, popupId, token, postData) {
        if (dispatchedSelectionToken === token) { return; }

        dispatchedSelectionToken = token;

        document.dispatchEvent(new CustomEvent('lpb:item-selected', {
            bubbles: true,
            detail: { postId: postId, popupId: popupId, post: postData }
        }));
    }

    /**
     * Fills the popup once its own content is in the DOM.
     *
     * Requires a *ready* popup container, because filling genuinely needs elements
     * to write into — unlike the selection event, which no longer waits for this
     * (see dispatchSelectionEvent). Returns null when the popup is not ready yet and
     * leaves the retry to whichever caller comes back later.
     *
     * The keys requiredMetaKeys() asks for are normally already satisfied by the
     * preloaded payload, so the fetch resolves from cache without touching the
     * network. It stays a fetch for the sake of the one thing this pass can still
     * discover: a binding the server-side scan could not see.
     *
     * @param  {number} postId
     * @param  {number} popupId
     * @param  {number} token    Selection token of the click being served.
     * @return {Promise|null}    The shared hydration, or null if it cannot start yet.
     */
    function hydratePopupContent(postId, popupId, token) {
        if (!isCurrentSelection(postId, popupId, token)) { return null; }

        if (hydrationPromise && hydrationToken === token) {
            return hydrationPromise;
        }

        if (!getReadyPopupContainer(popupId)) { return null; }

        hydrationToken   = token;
        hydrationPromise = fetchPostData(postId, requiredMetaKeys(popupId), popupId).then(function (postData) {
            if (!isCurrentSelection(postId, popupId, token)) { return; }

            populatePopupFields(popupId, postId);

            if (!postData) {
                // The request failed (fetchPostData already logged it). Drop the memo
                // so a later notification can retry.
                if (hydrationToken === token) { hydrationPromise = null; }
                return;
            }

            // Safety net, not the normal path: the click already announced this
            // selection. This only lands if the click could not — a cache miss whose
            // request was still in flight, or one that failed and later succeeded.
            // dispatchSelectionEvent is idempotent per token, so when the click did
            // its job this is a no-op rather than a second event.
            dispatchSelectionEvent(postId, popupId, token, LPB.posts[postId] || postData);

            // Now that the popup's own DOM is readable, check whether any of its
            // bindings need a base field the page did not inline — `content` when the
            // server scan did not see it bound, or anything lpb_preload_fields trimmed.
            // One forced request brings back every base field and fills them, which is
            // what keeps trimming a performance choice rather than a broken binding.
            //
            // Deliberately after the dispatch: the event carries the fields the popup
            // asked for, and this only ever adds base fields the popup itself reads.
            // Holding the announcement for it would reintroduce the wait that moving
            // the event to click time removed.
            if (missingFieldsForPopup(popupId, postId).length) {
                return fetchPostData(postId, requiredMetaKeys(popupId), popupId, true)
                    .then(function () {
                        if (!isCurrentSelection(postId, popupId, token)) { return; }

                        populatePopupFields(popupId, postId);
                    });
            }
        });

        return hydrationPromise;
    }

    /**
     * Backstop for builds that never emit `elementor/popup/show`: waits for the
     * popup to become readable, then runs the same fill the show handler runs.
     * Polling instead of trusting one fixed delay means a slow open still fills, and
     * a popup that never opens fills nothing rather than writing into the wrong DOM.
     *
     * Note there is deliberately no "already dispatched, so stop" check here. The
     * click announces the selection immediately now, so such a check would abort the
     * poll before the popup was ever filled. Dispatch and fill are separate concerns:
     * this one owns the fill and keeps waiting until the popup can take it.
     */
    function fillWhenPopupReady(postId, popupId, token, attempt) {
        if (!isCurrentSelection(postId, popupId, token)) { return; }

        if (hydratePopupContent(postId, popupId, token)) { return; }

        if (attempt >= POPUP_READY_MAX_RETRIES) {
            console.warn(
                'LPB: popup ' + popupId + ' never became readable — fields not populated for post ' + postId + '.'
            );
            return;
        }

        setTimeout(function () {
            fillWhenPopupReady(postId, popupId, token, attempt + 1);
        }, POPUP_READY_RETRY_MS);
    }

    /**
     * Ticket of the newest population request per popup ID.
     *
     * A population request never carries a payload snapshot with it, and a retry it
     * scheduled aborts as soon as a newer request has taken a ticket for the same
     * popup. Together those two rules make it impossible for a delayed pass to write
     * older data over a newer hydration — the failure this replaces, where a 150 ms
     * retry closed over the pre-open payload and ran after the popup-show pass had
     * already filled the popup from a complete one.
     *
     * @type {Object<string, number>}
     */
    var populateTickets = {};
    var populateSequence = 0;

    /**
     * Top-level populate call. Fills the bindings of the requested popup only.
     * When that popup's wrapper does not exist yet (it is still opening), retries
     * once after 150 ms — no other popup is ever populated instead.
     *
     * The payload is read out of LPB.posts at fill time rather than passed in, so a
     * pass always writes the most complete data known at the moment it runs. The
     * cache only ever gains meta keys (see mergeIntoCachedPost), which is what makes
     * "latest wins" safe regardless of which REST response landed first.
     *
     * @param {number} popupId
     * @param {number} postId   Population is skipped once the user has selected a
     *                          different post or popup.
     */
    function populatePopupFields(popupId, postId) {
        if (!isActiveContext(postId, popupId)) { return; }

        var ticket = ++populateSequence;

        populateTickets[popupId] = ticket;

        var attempt = function () {
            var postData  = LPB.posts[postId];
            var container = getPopupContainer(popupId);

            if (!postData || !container) { return false; }

            fillFields(container, postData, postId);

            return true;
        };

        if (attempt()) { return; }

        // Wrapper or payload not there yet — retry once after a short delay, unless a
        // newer request superseded this one or the selection moved on meanwhile.
        setTimeout(function () {
            if (populateTickets[popupId] !== ticket) { return; }
            if (!isActiveContext(postId, popupId)) { return; }

            attempt();
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
        event.stopImmediatePropagation();

        var postId  = parseInt(trigger.getAttribute('data-lpb-post-id'), 10);
        var popupId = parseInt(trigger.getAttribute('data-lpb-popup-id'), 10);

        if (!postId || !popupId) {
            console.warn('LPB: trigger element is missing data-lpb-post-id or data-lpb-popup-id.', trigger);
            return;
        }

        // Deferred by two frames so the click animation paints before any of this
        // runs, including third-party lpb:item-selected listeners.
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                var token = ++selectionSequence;

                LPB.activePostId     = postId;
                LPB.activePopupId    = popupId;
                activeSelectionToken = token;

                // Abandon any hydration the previous selection memoised: its callbacks
                // now fail isCurrentSelection() and must not be joined by this click.
                hydrationToken   = 0;
                hydrationPromise = null;

                var metaKeys = requiredMetaKeys(popupId);

                var open = function () {
                    return openElementorPopup(popupId).then(function () {
                        fillWhenPopupReady(postId, popupId, token, 0);
                    });
                };

                // Announce the selection now rather than after the popup opens.
                //
                // This became possible once the popup's meta keys stopped depending on
                // the popup's DOM: the keys come from its saved data, so "is the payload
                // complete for this popup" is answerable here — which is exactly what
                // hasCachedMetaKeys() is being asked. Every trigger the page rendered is
                // preloaded, so this is the path essentially every click takes, and the
                // event goes out in this frame with nothing to wait for.
                if (hasCachedMetaKeys(postId, metaKeys)) {
                    dispatchSelectionEvent(postId, popupId, token, LPB.posts[postId]);
                    open();
                    return;
                }

                // Only reachable for a trigger the page did not render — inserted by
                // AJAX and not yet preloaded. Opening waits on the data here, the way
                // it always did: a popup that opens before its values arrive shows a
                // flash of fallbacks, which is worse than opening a moment later.
                fetchPostData(postId, metaKeys, popupId).then(function (postData) {
                    if (postData && isCurrentSelection(postId, popupId, token)) {
                        dispatchSelectionEvent(postId, popupId, token, LPB.posts[postId] || postData);
                    }
                }).then(open, open);
            });
        });
    }

    // ── Elementor popup show notification ─────────────────────────────────────────

    /**
     * Elementor Pro announces a single show twice — as a jQuery event on the document
     * and as a native CustomEvent on window (triggerPopupEvent() in
     * elementor-pro/assets/js/elements-handlers.js dispatches both back to back in the
     * same task). Collapsing them on a flag cleared on the next macrotask keeps one
     * show to one hydration, while a genuine later re-open — which cannot happen
     * inside that same task — still hydrates normally.
     */
    var handledShows = {};

    function isDuplicateShowEvent(popupId) {
        var key = String(popupId);

        if (handledShows[key]) { return true; }

        handledShows[key] = true;
        setTimeout(function () { delete handledShows[key]; }, 0);

        return false;
    }

    /**
     * Runs when Elementor reports that a popup opened. The wrapper and its freshly
     * cloned content are in the DOM by now, so this is the first moment the popup's
     * own bindings — and therefore its custom meta keys — can be read.
     *
     * @param {number|string} id  The popup ID reported by Elementor.
     */
    function onPopupShow(id) {
        var popupId = parseInt(id, 10);

        if (!popupId || isDuplicateShowEvent(popupId)) {
            return;
        }

        if (!LPB.activePostId || popupId !== LPB.activePopupId) {
            return; // Not our popup or no active post — leave it alone.
        }

        // Same fill path as the post-open backstop, joined rather than repeated:
        // whichever arrives first owns the hydration.
        hydratePopupContent(LPB.activePostId, popupId, activeSelectionToken);
    }

    // ── Preload support ───────────────────────────────────────────────────────────

    /**
     * Preloads triggers that were not on the page when PHP rendered it.
     *
     * Everything present at render time already has its payload inline, so there is
     * nothing to do on page load. What this covers is markup added afterwards —
     * Loop Grid infinite scroll and any other AJAX — where the server had no chance
     * to include the data. Passing the popup ID lets the endpoint resolve that
     * popup's meta keys itself, which is the only way to get them for a popup the
     * page never mentioned.
     */
    function preloadTriggers(root) {
        var scope    = root && root.querySelectorAll ? root : document;
        var triggers = scope.querySelectorAll('[data-lpb-trigger="1"]');

        triggers.forEach(function (el) {
            var postId  = parseInt(el.getAttribute('data-lpb-post-id'), 10);
            var popupId = parseInt(el.getAttribute('data-lpb-popup-id'), 10);

            if (postId && !LPB.posts[postId]) {
                // Fire-and-forget; the result lands in LPB.posts.
                fetchPostData(postId, requiredMetaKeys(popupId), popupId);
            }
        });
    }

    /**
     * Watches for trigger elements arriving after page load and preloads them.
     *
     * Elementor exposes no reliable "loop grid appended items" hook across versions,
     * so this observes the DOM instead. Batched on a microtask because an infinite-
     * scroll page inserts many items in one burst, and each insertion would
     * otherwise re-scan.
     */
    function watchForNewTriggers() {
        if (typeof window.MutationObserver === 'undefined') { return; }

        var scheduled = false;

        new MutationObserver(function (mutations) {
            if (scheduled) { return; }

            var sawElements = mutations.some(function (mutation) {
                return mutation.addedNodes.length > 0;
            });

            if (!sawElements) { return; }

            scheduled = true;

            Promise.resolve().then(function () {
                scheduled = false;
                preloadTriggers(document);
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    // ── Initialisation ────────────────────────────────────────────────────────────

    function init() {
        // Move lpb-bind-select/radio marker strings off DOM options into data attributes.
        initChoiceFieldMarkers();

        // Delegated click listener — catches trigger clicks anywhere on the page,
        // including items loaded dynamically by Elementor's Loop Grid infinite scroll.
        document.addEventListener('click', handleTriggerClick, true);

        // Primary notification: the jQuery event Elementor Pro triggers on the
        // document as (event, id, instance).
        if (typeof window.jQuery !== 'undefined') {
            window.jQuery(document).on('elementor/popup/show', function (event, id) {
                onPopupShow(id);
            });
        }

        // The same notification as a native CustomEvent on window, so hydration keeps
        // working if a build stops emitting the jQuery one. Both reach onPopupShow,
        // which collapses the pair into a single hydration.
        window.addEventListener('elementor/popup/show', function (event) {
            if (event && event.detail) {
                onPopupShow(event.detail.id);
            }
        });

        // Kept for backward compatibility only: current Elementor Pro does not route
        // this event through elementorFrontend.hooks.
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

        // Normally a no-op: PHP rendered every trigger's payload into the page. This
        // only picks up anything the inline payload had to skip.
        preloadTriggers(document);
        watchForNewTriggers();
    }

    // Run after DOM is ready; elementor-frontend.js (our dependency) is already parsed.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
