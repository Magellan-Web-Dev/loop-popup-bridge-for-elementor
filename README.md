# Loop Popup Bridge for Elementor

Click any widget inside an Elementor Loop Grid item to open a shared Elementor Pro popup that is automatically populated with data from the post that was clicked.

- **Requires:** WordPress 6.0+, PHP 8.1+, Elementor, Elementor Pro
- **Tested with:** Elementor 4.0.1, Elementor Pro 4.0.1

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
| **Clicked Post Form Value** | Elementor Form hidden inputs | Writes an `lpb-bind:` marker that JS replaces with the field value before submit |

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

When a visitor clicks a trigger widget:

1. JavaScript reads the `data-lpb-post-id` and `data-lpb-popup-id` attributes on the wrapper.
2. Post data is fetched from the plugin's public read-only REST endpoint (`/wp-json/loop-popup-bridge/v1/post/{id}`). Results are cached in memory so repeated clicks on the same post avoid repeated network requests.
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
│   │   └── WidgetControlsManager.php     Injects Loop Popup Bridge controls into legacy and atomic widgets
│   ├── Frontend/
│   │   └── FrontendManager.php           Writes data-lpb-* attributes at render time; enqueues JS
│   ├── DynamicTags/
│   │   ├── DynamicTagsManager.php        Registers all four dynamic tags with Elementor Pro
│   │   ├── ClickedPostFieldTag.php       Inline HTML span placeholder for text/HTML fields
│   │   ├── ClickedPostUrlTag.php         Hash-marker placeholder for link URL fields
│   │   ├── ClickedPostImageTag.php       Query-arg marker for image source fields; ACF image fields only
│   │   └── ClickedPostFormValueTag.php   Plain-text lpb-bind: marker for Elementor Form hidden inputs
│   ├── REST/
│   │   └── PostEndpoint.php              GET /wp-json/loop-popup-bridge/v1/post/{id}
│   ├── Support/
│   │   └── FieldRegistry.php             Shared field options, ACF discovery, binding helpers, meta allowlist
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
| **Preload Post Data** | Toggle | Fetches post data on page load so the first click is instant |

---

## REST Endpoint

```
GET /wp-json/loop-popup-bridge/v1/post/{id}
```

- **Authentication:** None required. This is a public read-only endpoint for publicly available content.
- **Access checks:** The post must exist, have `publish` status, not be password-protected, and belong to a public post type.
- **Custom meta:** Callers request specific keys with `?meta_keys=key1,key2`. The endpoint returns only requested keys that are allowlisted server-side.
- **ACF fields:** Registered ACF fields are automatically included in the allowlist so popup bindings work without extra configuration.
- **Manual meta keys:** Non-ACF keys must be added through the `lpb_allowed_meta_keys` filter.

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

## Preloading

When **Preload Post Data** is enabled on a trigger widget, the plugin fetches that post's data as soon as the page loads. This eliminates the network delay on the first click and is recommended for above-the-fold loop items.

---

## Elementor Atomic Widget Support

Elementor's newer atomic widgets (widget types prefixed with `e-`, such as `e-image`, `e-heading`, and `e-button`) use a different rendering and settings architecture than legacy widgets. The plugin handles both transparently:

- **Editor:** LPB controls are injected into atomic widgets via the `elementor/atomic-widgets/controls` filter. Props are registered in the widget schema via `elementor/atomic-widgets/props-schema` so settings are preserved when saved.
- **Frontend:** Because atomic widgets render via Twig templates (no `<div _wrapper>`), the plugin uses PHP output buffering to wrap the rendered output in a `<div data-lpb-trigger="1" …>`. The JavaScript click handler finds this wrapper with `closest()` regardless of which inner element was clicked.

---

## Updates

The plugin includes a GitHub-based updater. WordPress checks the latest published GitHub release, caches the response for 12 hours, and shows the normal plugin update UI when a newer release is available.

The Plugins screen also adds a **Check for updates** row action. That action is nonce-protected and requires the `update_plugins` capability.

---

## Changelog

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
