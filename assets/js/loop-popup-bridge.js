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
                    // title, excerpt, date, modified, post_type, id
                    fillTextField(el, resolveBindingValue(binding, postData, 'text'));
                    break;
            }
        });

        fillFormBindings(container, postData);
        fillChoiceFieldsByMarkers(container, postData);
    }

    /**
     * Fills scalar form fields (hidden, text, email, textarea, etc.) whose value
     * attribute / defaultValue holds an lpb-bind: marker written by
     * ClickedPostFormValueTag. Reads from the HTML attribute so the marker
     * survives repeated popup opens.
     *
     * @param {Element} container  The popup DOM node.
     * @param {Object}  postData   Payload from the REST endpoint.
     */
    function fillFormBindings(container, postData) {
        container.querySelectorAll('input:not([type="radio"]):not([type="checkbox"]), textarea').forEach(function (el) {
            var attrValue = el.tagName === 'TEXTAREA' ? el.defaultValue : el.getAttribute('value');
            var marker = parseFormValueMarker(attrValue);
            if (!marker) { return; }

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
     */
    function fillChoiceFieldsByMarkers(container, postData) {

        // Lazily pick up any markers not yet moved by initChoiceFieldMarkers,
        // including Elementor-split markers (see moveChoiceMarkersToParent).
        moveChoiceMarkersToParent(container);

        // ── Select fields ─────────────────────────────────────────────────────
        container.querySelectorAll('select[data-lpb-marker]').forEach(function (selectEl) {
            var marker = parseFormChoiceMarker(selectEl.getAttribute('data-lpb-marker'), 'lpb-bind-select:');
            if (!marker) { return; }

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
     * Parses markers like #lpb-field=meta&lpb-meta-key=event_date.
     *
     * `lpb-value-type` is optional and only present when PHP positively
     * identified the field as holding an email address or a phone number;
     * markers rendered before that existed simply resolve to an empty type.
     */
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

    /**
     * Top-level populate call. Fills the bindings of the requested popup only.
     * When that popup's wrapper does not exist yet (it is still opening), retries
     * once after 150 ms — no other popup is ever populated instead.
     *
     * @param {Object} postData
     * @param {number} popupId
     * @param {number} [postId]  When given, population is skipped once the user has
     *                           selected a different post or popup.
     */
    function populatePopupFields(postData, popupId, postId) {
        if (!postData) { return; }

        var isStale = function () {
            return typeof postId !== 'undefined' && !isActiveContext(postId, popupId);
        };

        if (isStale()) { return; }

        var container = getPopupContainer(popupId);

        if (container) {
            fillFields(container, postData);
            return;
        }

        // Wrapper not created yet — retry once after a short delay.
        setTimeout(function () {
            var retryContainer = getPopupContainer(popupId);
            if (retryContainer && !isStale()) {
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
        event.stopImmediatePropagation();

        var postId  = parseInt(trigger.getAttribute('data-lpb-post-id'), 10);
        var popupId = parseInt(trigger.getAttribute('data-lpb-popup-id'), 10);

        if (!postId || !popupId) {
            console.warn('LPB: trigger element is missing data-lpb-post-id or data-lpb-popup-id.', trigger);
            return;
        }

        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                LPB.activePostId  = postId;
                LPB.activePopupId = popupId;

                var metaKeys = collectRequiredMetaKeys(popupId);

                fetchPostData(postId, metaKeys).then(function (postData) {
                    if (postData) {
                        document.dispatchEvent(new CustomEvent('lpb:item-selected', {
                            bubbles: true,
                            detail: { postId: postId, popupId: popupId, post: postData }
                        }));

                        populatePopupFields(postData, popupId, postId);
                    }

                    return openElementorPopup(popupId).then(function () {
                        return postData;
                    });
                }).then(function (postData) {
                    if (postData) {
                        return fetchPostData(postId, collectRequiredMetaKeys(popupId)).then(function (freshPostData) {
                            populatePopupFields(freshPostData || postData, popupId, postId);
                        });
                    }
                });
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

        var postId = LPB.activePostId;

        fetchPostData(postId, collectRequiredMetaKeys(popupId)).then(function (postData) {
            if (postData) {
                populatePopupFields(postData, popupId, postId);
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

        preloadMarkedItems();
    }

    // Run after DOM is ready; elementor-frontend.js (our dependency) is already parsed.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
