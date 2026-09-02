# Loop Popup Bridge for Elementor

Click any widget inside an Elementor Loop Grid item to open a shared Elementor Pro popup that is automatically populated with data from the post that was clicked.

- **Requires:** WordPress 6.0+, PHP 8.1+, Elementor, Elementor Pro
- **Tested with:** Elementor 4.2.3, Elementor Pro 4.2.3

---

## Overview

Elementor's Loop Grid widget repeats a template for every post in a query. The Loop Popup Bridge lets you turn any widget inside that template into a clickable trigger that opens a single shared popup and fills it with data specific to the post that was clicked — title, excerpt, featured image, permalink, custom fields, and more — without duplicating popups or touching Elementor core.

---

## Requirements

| Dependency | Requirement |
|---|---|
| WordPress | 6.0 |
| PHP | 8.1 |
| Elementor (free) | Required |
| Elementor Pro | Required for popup functionality |

---

## How It Works

### 1. Mark a Widget as a Trigger

Open any widget inside your Loop Item template in the Elementor editor. Under the **Advanced** tab, a **Loop Popup Bridge** section appears. Enable the toggle, choose a popup from the dropdown, and save.

This works for every widget type — legacy widgets (Button, Image, Text, etc.) and Elementor's newer atomic widgets (e-image, e-heading, e-button, etc.).

### 2. Populate the Popup with Dynamic Tags

Inside your popup, use the included dynamic tags to bind elements to the clicked post's data. Dynamic tags are available in fields that support the relevant dynamic tag category, such as text, link URL, image source, and Elementor Form hidden values.

| Dynamic Tag | Use In | Output |
|---|---|---|
| **Clicked Post Field** | Text / HTML widgets | Renders an inline `<span>` that JS replaces with the field value |
| **Clicked Post URL** | Link URL fields | Inserts a hash marker that JS replaces with the post permalink or custom URL |
| **Clicked Post Image** | Image source fields | Inserts a query-arg marker that JS replaces with the image URL |
| **Clicked Post Form Value** | Elementor Form hidden inputs; Atomic Form Input / Text area / Checkbox / Radio | Writes an `lpb-bind:` marker that JS replaces with the field value before submit |

**Available built-in fields:**

- `title` — post title (plain text)
- `excerpt` — post excerpt
- `content` — post content (HTML, sanitized via `wp_kses_post`)
- `permalink` — canonical URL
- `date` — publish date
- `modified` — last-modified date
- `post_type` — post type slug
- `id` — post ID
- `featured_image` — featured image URL, available in image-capable bindings
- `meta` — custom field value, selected from discovered ACF fields or entered manually by key

ACF fields are discovered automatically and grouped in the dynamic tag controls by field group location. Manual non-ACF meta keys must be allowed with the `lpb_allowed_meta_keys` filter before the REST endpoint will return them.

### 3. Click → Populate → Open

Post data is **always preloaded**, for every trigger, with no setting to configure. On page render, PHP works out which meta keys each popup binds by reading that popup's saved Elementor data, then prints the complete payload for every trigger into the footer:

```js
window.LoopPopupBridge.posts          // { [postId]: postData }  — full, including custom_meta
window.LoopPopupBridge.postMetaKeys   // { [postId]: { [key]: true } }  — what has been resolved
window.LoopPopupBridge.popupMetaKeys  // { [popupId]: [key, …] }  — what each popup binds
```

So when a visitor clicks a trigger widget:

1. JavaScript reads the `data-lpb-post-id` and `data-lpb-popup-id` attributes on the wrapper.
2. `lpb:item-selected` is dispatched with the complete payload — **no network request**, and before the popup opens.
3. The Elementor Pro popup is opened via `elementorProFrontend.modules.popup.showPopup()`.
4. Every `[data-lpb-field]` placeholder inside the popup is replaced with the matching field value.

The REST endpoint is only reached for a trigger the page could not know about at render time — one inserted afterwards by Loop Grid infinite scroll or other AJAX. Those are preloaded too, by a `MutationObserver` that requests them as they appear.

**Why the server has to resolve the meta keys.** Elementor Pro removes a popup's document from the page on init and keeps it as an HTML string until the popup's first open. Nothing client-side can read a popup's bindings before then — which is why preloading used to fetch every post with an empty `custom_meta` and only fill it in after a click. Reading the popup's saved data server-side is the only place that answer exists ahead of the first open.

**Two blind spots**, both of which degrade to the old behaviour rather than breaking: bindings that live in another document the scan cannot follow (global widgets, shortcode-rendered templates — plain **Template** widgets *are* followed), and keys injected at runtime by third-party code. The popup-show pass still reads the real DOM and fills anything missed a moment later. Use `lpb_popup_meta_keys` to have such keys preloaded anyway:

```php
add_filter('lpb_popup_meta_keys', function (array $keys, int $popup_id): array {
    if (1234 === $popup_id) {
        $keys[] = 'key_inside_a_global_widget';
    }
    return $keys;
}, 10, 2);
```

**Page weight.** The payload is in the HTML, so its size is the page's size. `content` dominates it — it runs the whole `the_content` filter chain, which on an Elementor-built post means rendering a document, and it is the only field big enough to matter. Every other base field together comes to roughly 950 bytes per entry.

So `content` is inlined **only when a popup actually binds the Content field**, determined by the same scan that resolves the meta keys. A popup that never uses it costs nothing. Where the loop posts are themselves built with Elementor, even a correctly scoped `content` is heavy (~20 ms and ~174 KB each) — trim it:

```php
add_filter('lpb_preload_fields', fn(array $fields, int $post_id): array
    => array_diff($fields, ['content']), 10, 2);
```

Trimming is safe. A field missing from the payload is treated as *unknown* rather than empty, so the binding is left alone and the frontend fetches the field from the REST endpoint when a popup that needs it opens — the same "unknown is not empty" rule that governs meta keys. The cost of trimming is one request on first open, not a broken binding. `id`, `custom_meta` and `meta_keys_loaded` are structural and are always present.

`lpb_popup_fields` is the matching hook on the other side: it adjusts which base fields a *popup* is considered to bind, for bindings the scan cannot see.

**Full-page caches** now serve the preloaded data along with the page, so it goes stale like any other cached markup. Purging on post save — which most caching plugins do by default — covers the normal case.

---

## The `lpb:item-selected` Event

Dispatched on `document`, bubbling, once per click:

```js
document.addEventListener('lpb:item-selected', function (event) {
    var postId  = event.detail.postId;
    var popupId = event.detail.popupId;
    var post    = event.detail.post;   // the same object as LoopPopupBridge.posts[postId]

    console.log(post.title, post.custom_meta);
});
```

It fires roughly two animation frames after the click, **before the popup opens**, with `custom_meta` already complete. Two frames because the click animation is allowed to paint first — a slow listener slows the event, never the animation.

> **Changed behaviour.** This event used to fire *after* the popup had opened and been filled, because before the popup existed its meta keys were unknowable and the payload would have been incomplete. It now fires from the click instead.
>
> - A listener that only reads `event.detail` needs no change, and gets its data sooner.
> - **A listener that reaches into the popup DOM must move to `elementor/popup/show`** — there is nothing to read when this fires.

`post.custom_meta` carries the union of the keys of every popup that post triggers on the page, so it can be wider than the popup being opened actually uses.

Exactly one event is dispatched **per click**. Note the shift: when the event fired after popup-open, two rapid clicks on different items produced a single event for the item that won. Announcing at click time cannot coalesce that way — doing so would mean waiting to find out whether a later click is coming, which is the wait that was removed. So two rapid clicks now yield two events. If your listener counts selections, de-duplicate on `postId`.

What is still guaranteed: each event carries only its own item's data, and only the last click owns the active context, so only that popup is filled.

---

## Plugin Architecture

```
loop-popup-bridge-for-elementor/
├── loop-popup-bridge-for-elementor.php   Main plugin file, constants, autoloader bootstrap
├── src/
│   ├── Autoloader.php                    PSR-4 autoloader (LoopPopupBridge\ → src/)
│   ├── Plugin.php                        Singleton composition root; boots all components
│   ├── DependencyChecker.php             Checks for Elementor / Elementor Pro; surfaces admin notices
│   ├── Controls/
│   │   └── WidgetControlsManager.php     Injects Loop Popup Bridge controls into legacy and atomic widgets
│   ├── Frontend/
│   │   └── FrontendManager.php           Writes data-lpb-* attributes at render time; enqueues JS;
│   │                                     renders the inline preload payload in the footer
│   ├── DynamicTags/
│   │   ├── DynamicTagsManager.php        Registers all four dynamic tags with Elementor Pro
│   │   ├── ClickedPostFieldTag.php       Inline HTML span placeholder for text/HTML fields
│   │   ├── ClickedPostUrlTag.php         Hash-marker placeholder for link URL fields
│   │   ├── ClickedPostImageTag.php       Query-arg marker for image source fields; ACF image fields only
│   │   └── ClickedPostFormValueTag.php   Plain-text lpb-bind: marker for Elementor Form hidden inputs
│   ├── REST/
│   │   └── PostEndpoint.php              GET /wp-json/loop-popup-bridge/v1/post/{id}
│   ├── Support/
│   │   ├── FieldRegistry.php             Shared field options, ACF discovery, binding helpers, meta allowlist
│   │   ├── PopupBindingScanner.php       Resolves a popup's bound meta keys from its saved Elementor data
│   │   └── PostPayload.php               Access checks + the sanitised payload, shared by inline and REST
│   └── Updates/
│       └── GitHubUpdater.php             GitHub release checks and update package handling
├── assets/
│   └── js/
│       └── loop-popup-bridge.js          Click handler, REST fetch with cache, popup open, field fill
└── stubs/                                PHP stubs for Elementor classes (development only)
```

---

## Controls Reference

### Advanced Tab → Loop Popup Bridge

| Control | Type | Description |
|---|---|---|
| **Enable Loop Popup Trigger** | Toggle | Marks this widget as a click trigger |
| **Popup** | Select (searchable) | The Elementor Pro popup to open on click |

There is no preload control. Post data is preloaded for every trigger, always. The old **Preload Post Data** toggle was not a real choice: switched off, the first click paid for a round-trip; switched on, the preload could not discover the popup's meta keys, so it fetched an empty `custom_meta` either way. Resolving the keys server-side removed the reason for the setting.

---

## REST Endpoint

```
GET /wp-json/loop-popup-bridge/v1/post/{id}
```

Most page loads never touch this route — the payload is rendered inline. It serves triggers added after render (infinite scroll and other AJAX).

- **Authentication:** None required. This is a public read-only endpoint for publicly available content.
- **Access checks:** The post must exist, have `publish` status, not be password-protected, and belong to a public post type.
- **Custom meta:** Callers request specific keys with `?meta_keys=key1,key2`. The endpoint returns only requested keys that are allowlisted server-side.
- **Popup resolution:** `?popup_id=123` adds whatever meta keys that popup binds, resolved from its saved Elementor data. This lets a caller that has never opened the popup still ask for the right keys. Only an `elementor_library` post whose template type is `popup` is accepted; anything else resolves to no keys. This widens what is *requested* — the allowlist gate on what may be *returned* is unaffected.
- **ACF fields:** Registered ACF fields are automatically included in the allowlist so popup bindings work without extra configuration.
- **Manual meta keys:** Non-ACF keys must be added through the `lpb_allowed_meta_keys` filter.

**Example response** for `?popup_id=123`:

```json
{
  "id": 42,
  "title": "Example Post",
  "excerpt": "A short excerpt…",
  "content": "<p>Full post content…</p>",
  "permalink": "https://example.com/example-post/",
  "date": "2025-01-15",
  "modified": "2025-03-22",
  "post_type": "post",
  "featured_image": "https://example.com/wp-content/uploads/hero.jpg",
  "featured_image_alt": "Hero image alt text",
  "custom_meta": {
    "event_date": "2025-06-01"
  },
  "meta_keys_loaded": ["event_date"],
  "popup_meta_keys": ["event_date"]
}
```

- `meta_keys_loaded` — every key this response resolved, **before** the allowlist. A key listed here with no entry in `custom_meta` was asked for and came back empty, which is different from never having been asked: the frontend renders the binding's configured fallback rather than waiting for data that will never arrive.
- `popup_meta_keys` — present only when `popup_id` was passed. Just that popup's own bindings, which is narrower than `meta_keys_loaded` whenever the request also carried keys for something else.

**Exposing manual custom meta fields:**

```php
add_filter('lpb_allowed_meta_keys', function (array $keys): array {
    $keys[] = 'event_date';
    $keys[] = 'speaker_name';
    return $keys;
});
```

Because registered ACF fields are automatically allowlisted, any visitor who can reach the endpoint and knows a field key can request that field for published posts. Do not store sensitive public-post data in ACF fields that this plugin should expose.

---

## Email and Phone URLs

When a **Clicked Post URL** binding points at a field that holds an email address or a telephone number, the frontend prepends the matching URI scheme so the link works as a link:

| Field type | Value | Rendered `href` |
| --- | --- | --- |
| ACF `email` | `test@example.com` | `mailto:test@example.com` |
| Phone add-on (`phone_number`, `phone`, `tel`, `telephone`) | `+1 555 123 4567` | `tel:+1 555 123 4567` |

Notes:

- The classification comes from the **registered field type only** — never from the field label, the meta key, or the shape of the stored value.
- Values that already carry a scheme (`mailto:`, `tel:`, `https:`, …) are left alone, so nothing is double-prefixed.
- The number itself is never rewritten: `+`, spaces, parentheses, hyphens, and extensions are preserved exactly as entered.
- Only the URL binding is affected. The same field used through **Clicked Post Field** or as an Elementor form value still yields the original, unprefixed value, as does the REST response.

Core ACF ships no phone field. If you store phone numbers in a plain Text or Number field — or use an add-on with a different type identifier — opt the field in explicitly:

```php
add_filter('lpb_url_binding_value_type', function (string $type, string $meta_key, ?array $field_data, array $binding): string {
    if ('office_phone' === $meta_key) {
        return 'phone'; // or 'email'
    }
    return $type;
}, 10, 4);
```

Only `'email'`, `'phone'`, and `''` (ordinary URL) are accepted; anything else is discarded, and the existing URL protocol allowlist still decides whether the final link is rendered.

---

## Preloading

When **Preload Post Data** is enabled on a trigger widget, the plugin fetches that post's data as soon as the page loads. This eliminates the network delay on the first click and is recommended for above-the-fold loop items.

---

## Elementor Atomic Widget Support

Elementor's newer atomic widgets (widget types prefixed with `e-`, such as `e-image`, `e-heading`, and `e-button`) use a different rendering and settings architecture than legacy widgets. The plugin handles both transparently:

- **Editor:** LPB controls are injected into atomic widgets via the `elementor/atomic-widgets/controls` filter. Props are registered in the widget schema via `elementor/atomic-widgets/props-schema` so settings are preserved when saved.
- **Frontend:** Because atomic widgets render via Twig templates (no `<div _wrapper>`), the plugin uses PHP output buffering to wrap the rendered output in a `<div data-lpb-trigger="1" …>`. The JavaScript click handler finds this wrapper with `closest()` regardless of which inner element was clicked.

### Dynamic tags in atomic widgets

Legacy widgets store a dynamic tag as a shortcode-like string under `settings.__dynamic__`. Atomic widgets store it as a typed prop value that *replaces* the prop it is bound to, at whatever depth that prop lives. An Atomic Image bound to **Clicked Post Image** saves as:

```json
{
  "settings": {
    "image": {
      "$$type": "image",
      "value": {
        "src": {
          "$$type": "dynamic",
          "value": {
            "name": "lpb-clicked-post-image",
            "group": "loop-popup-bridge",
            "settings": { "field": { "$$type": "string", "value": "meta:event_image" } }
          }
        },
        "size": { "$$type": "string", "value": "full" }
      }
    }
  }
}
```

`PopupBindingScanner` reads both shapes. The atomic walk matches on the shape of the node — `$$type: "dynamic"` naming one of the plugin's own tags — rather than on a fixed path, so a tag bound to any prop of any atomic widget is discovered, and both shapes resolve through the same `FieldRegistry::resolve_selection()` the tag itself calls at render time.

Two consequences of Elementor's atomic pipeline the plugin has to accommodate:

- **The fallback image arrives under a different key.** Elementor resolves a dynamic tag's `MEDIA` control through `Image_Prop_Type` before the tag is constructed, so the tag receives `src`, not `url`. Elementor also merges the control's default over every media value, so `url` is always present and always holds the placeholder — which is why `src` is read first. Legacy media values carry no `src` key and are unaffected.
- **The rendered `src` is escaped twice.** `atomic-image.html.twig` pipes the URL through `e('full_url')` (`esc_url`, which turns `&` into `&#038;`) and Twig's HTML autoescaping then escapes that entity's own `&`. The browser hands `getAttribute('src')` back as `?lpb-field=meta&#038;lpb-meta-key=…`, so the frontend normalises entity-encoded `&` separators before reading a marker. The legacy Image widget, and `href` markers in both architectures, are unaffected.

### Atomic Form fields

**Clicked Post Field (Form Text)** works in an Atomic Form, but the marker cannot arrive the way it does in a legacy form, because the atomic field widgets expose different props:

| Widget | Marker transport | What is populated |
|---|---|---|
| **Input** | `placeholder` — the widget has no value or default-value prop | the live `.value` |
| **Text area** | `placeholder` — likewise, and the element body stays empty | the live `.value` |
| **Checkbox** | the **Choice value** prop's real `value` attribute | the submitted `.value`; the checked state is never touched |
| **Radio button** | the **Choice value** prop's real `value` attribute | the submitted `.value`; the checked state is never touched |

The frontend copies whichever marker it finds into `data-lpb-form-value-marker` on first read, and every later pass reads it from there. That is what makes the binding survive: a placeholder has to be cleared once captured, both because a visitor would otherwise read the raw marker as help text and because Elementor's submit handler falls back to the placeholder for a field's label; and setting `.value` on a radio or checkbox writes through to its `value` attribute, destroying the marker in place. `data-lpb-marker` is deliberately not reused — that attribute already carries the select/radio choice marker.

A marker is only ever read from a placeholder when the field sits inside an Atomic Form — an ancestor `form[data-element_type="e-form"]` (or `data-e-type`) *and* the `data-interaction-id` Elementor's own submit handler keys on — and only when the text begins exactly with `lpb-bind:`. An ordinary placeholder is left alone.

Elementor's atomic Checkbox and Radio templates build `value` with `e('html_attr')` and then print it without `| raw`, so Twig's HTML autoescaping escapes the escaper's own output and the marker's `:` and `|` reach the browser still entity-encoded. One entity layer is decoded before the marker is read. The Input and Text area placeholder is printed *with* `| raw`, arrives one layer shallower, and needs nothing.

Hydrated values reach the server unchanged: Elementor's atomic submit handler reads each field's `.value` off the live DOM when the form is sent, so no synthetic `input`/`change` event is dispatched.

Two limitations follow from the prop schemas rather than from the plugin:

- An Atomic Input or Text area bound to Form Text cannot also show placeholder text, because the placeholder *is* the transport.
- **Clicked Post Field (Form Select)** and **(Form Radio)** still only generate choices for legacy Elementor form widgets. The atomic Select renders its options from a static `options` prop rather than from per-option dynamic tags, and an atomic Radio button is a standalone widget with no `.elementor-field-subgroup` wrapper to append generated items to, so neither has a place for the `lpb-bind-select:` / `lpb-bind-radio:` markers those tags emit. Bind an atomic Checkbox or Radio's **Choice value** with Form Text instead when a single value is enough.

---

## Updates

The plugin includes a GitHub-based updater. WordPress checks the latest published GitHub release, caches the response for 12 hours, and shows the normal plugin update UI when a newer release is available.

The Plugins screen also adds a **Check for updates** row action. That action is nonce-protected and requires the `update_plugins` capability.

---

## Changelog

### 1.7.3
- **Clicked Post Field (Form Text)** now populates Elementor **Atomic Form** fields. Elementor's Atomic Input and Text area have no value or default-value prop, so their only dynamic-tag-capable text setting is the placeholder — the marker rendered as `placeholder="lpb-bind:…"`, which the frontend never read, and the field was skipped. Atomic Input and Text area now populate their live `.value` from the placeholder-borne marker.
- Atomic **Checkbox** and **Radio button** now populate their submitted value when Form Text is attached to the **Choice value** prop. Only `.value` is written — the checked state is left exactly as the visitor or the widget set it. Elementor's atomic templates print that attribute double-escaped, so the marker's own `:` and `|` arrive entity-encoded; one entity layer is decoded before the marker is read.
- An atomic marker is preserved in `data-lpb-form-value-marker` the first time it is read, and the raw marker is cleared off the placeholder in the same step, so it is never shown to a visitor and never submitted as the field's label. The same popup can be opened for one post after another and each atomic field takes the new value, because the binding no longer lives in an attribute the fill overwrites.
- Scalar form bindings now answer to the same "unknown is not empty" guard as every other binding, for base fields as well as custom meta, and are visible to the missing-field refetch — so a narrower payload can no longer blank a field a complete one already filled.
- Legacy Elementor v3 form fields are unchanged: the HTML `value` attribute and a textarea's default content are still read first, radio and checkbox groups are still reached only through the existing `lpb-bind-radio:` choice path, and **Form Select** / **Form Radio** are untouched.

### 1.7.2
- Fixed **Clicked Post Image** not populating in an Atomic Image. Elementor's atomic image template escapes the rendered `src` twice, so the binding marker reached the browser as `?lpb-field=meta&#038;lpb-meta-key=…` and everything after the first separator was invisible to the marker parser — the image resolved with no meta key and kept its placeholder. The frontend now normalises entity-encoded `&` separators when reading a marker.
- `PopupBindingScanner` now discovers dynamic tags saved as atomic typed prop values (`$$type: "dynamic"`), at any depth in a widget's settings, so atomic bindings are preloaded rather than fetched on the popup's first open. Legacy `__dynamic__` scanning is unchanged.
- The scanner's cache stamp carries a scan-schema salt, so results cached before this release are re-scanned without clearing unrelated transients.
- **Clicked Post Image** now honours a fallback image chosen on an atomic image, which previously fell through to Elementor's placeholder.
- Updated Elementor compatibility metadata through Elementor 4.2.3 and Elementor Pro 4.2.3.

### 1.0.4
- Added GitHub release update checks and a manual "Check for updates" plugin row action.
- Added folder normalization after GitHub archive installs so updates keep the canonical plugin directory name.
- Updated Elementor compatibility metadata through Elementor 4.0.1 and Elementor Pro 4.0.1.

### 1.0.3
- Improved dynamic tag field handling with shared field registry helpers.
- Added automatic ACF field discovery for text, URL, and image-capable bindings.
- Added server-side custom meta allowlisting for manually entered keys.

### 1.0.2
- Added popup-side dynamic tags for URL, image, and Elementor Form hidden-value bindings.
- Improved frontend field hydration, custom meta fetching, and client-side caching.

### 1.0.1
- Added support for Elementor atomic widgets (e-image, e-heading, e-button, etc.) in both the editor panel and the frontend trigger system.

### 1.0.0
- Initial release.
- Loop Popup Bridge controls in every widget's Advanced tab.
- Four dynamic tags: Clicked Post Field, Clicked Post URL, Clicked Post Image, Clicked Post Form Value.
- Public read-only REST endpoint for published posts with opt-in manual custom meta.
- Client-side post-data cache to eliminate repeated network requests.
- Preload option for above-the-fold items.

---

## License

GPL-2.0-or-later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
