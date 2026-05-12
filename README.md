# Loop Popup Bridge for Elementor

Click any widget inside an Elementor Loop Grid item to open a shared Elementor Pro popup that is automatically populated with data from the post that was clicked.

**Version:** 1.0.1  
**Requires:** WordPress 6.0+, PHP 8.1+, Elementor (free), Elementor Pro

---

## Overview

Elementor's Loop Grid widget repeats a template for every post in a query. The Loop Popup Bridge lets you turn any widget inside that template into a clickable trigger that opens a single shared popup and fills it with data specific to the post that was clicked — title, excerpt, featured image, permalink, custom fields, and more — without duplicating popups or touching Elementor core.

---

## Requirements

| Dependency | Minimum Version |
|---|---|
| WordPress | 6.0 |
| PHP | 8.1 |
| Elementor (free) | 3.x |
| Elementor Pro | 3.x |

---

## How It Works

### 1. Mark a Widget as a Trigger

Open any widget inside your Loop Item template in the Elementor editor. Under the **Advanced** tab, a **Loop Popup Bridge** section appears. Enable the toggle, choose a popup from the dropdown, and save.

This works for every widget type — legacy widgets (Button, Image, Text, etc.) and Elementor's newer atomic widgets (e-image, e-heading, e-button, etc.).

### 2. Populate the Popup with Dynamic Tags

Inside your popup, use the four included dynamic tags to bind elements to the clicked post's data. Dynamic tags are available in any field that supports them (text, link, image source, etc.).

| Dynamic Tag | Use In | Output |
|---|---|---|
| **Clicked Post Field** | Text / HTML widgets | Renders an inline `<span>` that JS replaces with the field value |
| **Clicked Post URL** | Link URL fields | Inserts a hash marker that JS replaces with the post permalink or custom URL |
| **Clicked Post Image** | Image source fields | Inserts a query-arg marker that JS replaces with the image URL |
| **Clicked Post Form Value** | Elementor Form hidden inputs | Writes an `lpb-bind:` marker that JS replaces with the field value before submit |

**Available field names for Clicked Post Field / URL / Form Value:**

- `title` — post title (plain text)
- `excerpt` — post excerpt
- `content` — post content (HTML, sanitized via `wp_kses_post`)
- `permalink` — canonical URL
- `date` — publish date
- `modified` — last-modified date
- `post_type` — post type slug
- `id` — post ID
- `featured_image` — featured image URL
- `meta` — any custom field value (enter the meta key separately)

### 3. Click → Populate → Open

When a visitor clicks a trigger widget:

1. JavaScript reads the `data-lpb-post-id` and `data-lpb-popup-id` attributes on the wrapper.
2. Post data is fetched from the plugin's secured REST endpoint (`/wp-json/loop-popup-bridge/v1/post/{id}`). Results are cached in memory so repeated clicks on the same post never hit the network twice.
3. The Elementor Pro popup is opened via `elementorProFrontend.modules.popup.showPopup()`.
4. Every `[data-lpb-field]` placeholder inside the popup is replaced with the matching field value from the REST response.

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
│   │   └── WidgetControlsManager.php     Injects Loop Popup Bridge section into every widget's Advanced tab
│   ├── Frontend/
│   │   └── FrontendManager.php           Writes data-lpb-* attributes at render time; enqueues JS
│   ├── DynamicTags/
│   │   ├── DynamicTagsManager.php        Registers all four dynamic tags with Elementor Pro
│   │   ├── ClickedPostFieldTag.php       Inline HTML span placeholder for text/HTML fields
│   │   ├── ClickedPostUrlTag.php         Hash-marker placeholder for link URL fields
│   │   ├── ClickedPostImageTag.php       Query-arg marker for image source fields; ACF image fields only
│   │   └── ClickedPostFormValueTag.php   Plain-text lpb-bind: marker for Elementor Form hidden inputs
│   └── REST/
│       └── PostEndpoint.php              GET /wp-json/loop-popup-bridge/v1/post/{id}
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
| **Preload Post Data** | Toggle | Fetches post data on page load so the first click is instant |

---

## REST Endpoint

```
GET /wp-json/loop-popup-bridge/v1/post/{id}
```

- **Authentication:** WordPress REST nonce (`X-WP-Nonce` header), validated by `wp_verify_nonce`.
- **Custom meta:** Opt-in only. Meta keys must be registered via the `lpb_allowed_meta_keys` filter; the default allowlist is empty for security.

**Example response:**

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
  }
}
```

**Exposing custom meta fields:**

```php
add_filter('lpb_allowed_meta_keys', function (array $keys): array {
    $keys[] = 'event_date';
    $keys[] = 'speaker_name';
    return $keys;
});
```

---

## Preloading

When **Preload Post Data** is enabled on a trigger widget, the plugin fetches that post's data as soon as the page loads. This eliminates the network delay on the first click and is recommended for above-the-fold loop items.

---

## Elementor Atomic Widget Support

Elementor's newer atomic widgets (widget types prefixed with `e-`, such as `e-image`, `e-heading`, and `e-button`) use a different rendering and settings architecture than legacy widgets. The plugin handles both transparently:

- **Editor:** LPB controls are injected into atomic widgets via the `elementor/atomic-widgets/controls` filter. Props are registered in the widget schema via `elementor/atomic-widgets/props-schema` so settings are preserved when saved.
- **Frontend:** Because atomic widgets render via Twig templates (no `<div _wrapper>`), the plugin uses PHP output buffering to wrap the rendered output in a `<div data-lpb-trigger="1" …>`. The JavaScript click handler finds this wrapper with `closest()` regardless of which inner element was clicked.

---

## Changelog

### 1.0.1
- Added support for Elementor atomic widgets (e-image, e-heading, e-button, etc.) in both the editor panel and the frontend trigger system.

### 1.0.0
- Initial release.
- Loop Popup Bridge controls in every widget's Advanced tab.
- Four dynamic tags: Clicked Post Field, Clicked Post URL, Clicked Post Image, Clicked Post Form Value.
- Secured REST endpoint with opt-in custom meta.
- Client-side post-data cache to eliminate repeated network requests.
- Preload option for above-the-fold items.

---

## License

GPL-2.0-or-later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).
