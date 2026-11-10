# Template Caching Reference

> Cache the query, not the request. Anything that differs per visitor, per request, or per page stays outside the `{% cache %}` block.

## Documentation

- `{% cache %}` tag: https://craftcms.com/docs/5.x/reference/twig/tags.html#cache
- `{% js %}`, `{% script %}`, `{% css %}` tags (the `with` attributes): https://craftcms.com/docs/5.x/reference/twig/tags.html#js
- Image transforms: https://craftcms.com/docs/5.x/development/image-transforms.html
- `generateTransformsBeforePageLoad`: https://craftcms.com/docs/5.x/reference/config/general.html#generatetransformsbeforepageload

Source, verified against `craftcms/cms` 5.11.5.1:

- Parser (accepted parameters and their order): https://github.com/craftcms/cms/blob/5.11.5.1/src/web/twig/tokenparsers/CacheTokenParser.php
- Compiled output (Live Preview and token bypass): https://github.com/craftcms/cms/blob/5.11.5.1/src/web/twig/nodes/CacheNode.php
- Keys, resource capture, temporary-URL check: https://github.com/craftcms/cms/blob/5.11.5.1/src/services/TemplateCaches.php

For the PHP data cache, `enableTemplateCaching`, and the caching layer overview, see the `craftcms` skill's `caching.md`. For full-page caching, see `plugins/blitz.md`.

## Common Pitfalls

- A CSP nonce inside a cached block. The first visitor's nonce is stored and replayed to everyone, so a strict `Content-Security-Policy` blocks the script or style on every later request. See [CSP Nonces](#csp-nonces).
- `{{ csrfInput() }}` or a form inside the block. The docs: avoid caching "`{{ csrfInput() }}`, form fields, or parts of templates where the markup is highly dynamic or personalized."
- Active menu state, `aria-current`, language switcher or hreflang URLs inside `{% cache globally %}`. Every page gets the state of whichever page rendered first.
- Editing the markup inside a `using key` block and expecting the change to show. With a custom key, "existing template caches will not automatically be purged". Bump the key (`'nav-main-v2'`).
- A site handle in the key (`'nav-' ~ currentSite.handle`). `globally` already means "for the current site", and Craft appends the site ID to every key (`TemplateCaches::_cacheKey()`). It is redundant.
- Expecting the per-URL default to vary by query string. Output is keyed by URL "without regard for the query string": `?page=2`, `?q=shoes` and `?utm_source=x` all share one entry.
- Writing `{% cache for "1 hour" %}`, `{% cache for 3600 %}` or `{% cache tag('nav') %}`. None of these parse. `for` takes a number then a bare unit (`for 1 hour`), and the tag has no `tag()` parameter.
- Putting parameters in any order. The parser reads them in one fixed order: `globally`, `using key`, `for`/`until`, `if`/`unless`.
- Testing in Live Preview. Caching is skipped for preview and tokenized requests (`CacheNode`, `TemplateCaches::_isTemplateCachingEnabled()`), so a cache bug never reproduces there.

## How the Tag Stores Output

Verified behaviour, quoted from the docs and confirmed in source:

| Behaviour | Source |
|-----------|--------|
| Default key is the request path: "By default, cached output will be kept by URL without regard for the query string." The key also includes the page number. | docs; `TemplateCaches::_path()` |
| `globally`: "Caches the output globally (for the current site), rather than on a per-URL basis." | docs; `_cacheKey()` always appends the current site ID |
| `using key`: "When the key changes, the tag's contents are re-rendered." Without a key, "a random key will be generated each time Twig re-parses the template." | docs; `CacheNode::compile()` |
| Custom keys survive template edits: "existing template caches will not automatically be purged. Either assign the tag a new key, or clear your existing template caches manually." | docs |
| Invalidation: "Your template caches will automatically clear when any elements (entries, assets, etc.) within the tags are saved or deleted." Element queries inside the tag are tracked too, so a newly created entry that the query would return also clears it. | docs |
| Resources: the tag "captures code and styles registered with `{% js %}`, `{% script %}` and `{% css %}` tags. External references from `{% js %}` and `{% css %}` tags will be stored as well." | docs; `endTemplateCache()` clears and stores the JS, script, CSS, file, HTML, meta-tag, asset-bundle and JS-import buffers |
| Temporary transform URLs: the tag "detects un-generated image transform URLs within it and holds off caching the template until the next request so those temporary image URLs aren't cached." | docs; `endTemplateCache()` skips the save when the body contains `assets/generate-transform` |
| Skipped entirely for Live Preview, tokenized requests, console requests, and when `enableTemplateCaching` is `false`. | `CacheNode::compile()`, `_isTemplateCachingEnabled()` |

Parameter grammar, in the only order the parser accepts:

```twig
{% cache [globally] [using key <expr>] [for <number> <unit> | until <DateTime>] [if <expr> | unless <expr>] %}
```

Units come from `DateTimeHelper::RELATIVE_TIME_UNITS`: `sec`, `second`, `min`, `minute`, `hour`, `day`, `fortnight`, `week`, `month`, `year` (singular or plural). Without `for`/`until`, Craft uses `cacheDuration`.

## What May Go Inside `{% cache %}`

**Inside: output that is identical for every visitor of the cache key**

- [ ] Element queries and the markup built from them: menu trees, footer link lists, related-entry rails, category counts
- [ ] Expensive Twig work: sums, groupings, sorted collections
- [ ] External data fetched in Twig (feeds, JSON endpoints)
- [ ] Native transform URLs and ImageOptimize URLs. Temporary generate URLs are refused automatically; generated URLs are stable files. See [Images Inside Cached Blocks](#images-inside-cached-blocks).
- [ ] `{% js %}` / `{% css %}` blocks with no per-request values in them

**Outside: anything that differs per request, per visitor, or per page**

- [ ] CSP nonces: `<script nonce>`, `<style nonce>`, and any `{% js %}`, `{% script %}` or `{% css %}` given `with { nonce: … }`
- [ ] `{{ csrfInput() }}`, forms, Formie and Sprig components
- [ ] `currentUser` output: greetings, account links, cart counts
- [ ] Active nav state: `aria-current`, `is-active` classes, open sub-menus derived from the current entry or URL. This applies to `globally` and to any block whose key does not include the page.
- [ ] Language switcher and hreflang links (`entry.localized`, `craft.app.request` URLs)
- [ ] SEOmatic output (`plugins/seomatic.md`)
- [ ] `now`, random ordering, `shuffle`, A/B variants
- [ ] Variables needed after the block. The docs: "Variables set inside a `cache` tag won't be available to the surrounding template when the output is retrieved from the cache."

## CSP Nonces

The docs do not mention nonces. This rule follows from the capture behaviour above.

A nonce must be unique per response. `{% cache %}` stores the rendered body **and** the registered JS and CSS buffers, then replays both on a cache hit. Any nonce printed or registered inside the block is therefore frozen at the first render. The visitor who warmed the cache gets a working page. Everyone after gets a `nonce` attribute that does not match the `Content-Security-Policy` header of their own response, so a strict policy blocks the script. Nothing errors server-side; it only shows as a CSP violation in the browser console. SEOmatic's documented `{% cache %}` conflict (`plugins/seomatic.md`) is the same failure class, since it renders its own nonce'd JSON-LD and meta.

Craft core has no CSP nonce API. The nonce comes from a module, a plugin (SEOmatic's `cspNonce` setting, for example), or the server, often exposed to Twig as a global or through `craft.app.params`. Wherever it comes from, the rule is the same:

```twig
{# WRONG: the nonce is cached with the markup, and {% js %} is captured and replayed #}
{% cache globally using key 'footer-v3' %}
    <footer>…</footer>
    {% js with { nonce: cspNonce } %}
        initFooterAccordion();
    {% endjs %}
{% endcache %}

{# RIGHT: cache the markup, register the nonce'd script after the block #}
{% cache globally using key 'footer-v3' %}
    <footer>…</footer>
{% endcache %}
{% js with { nonce: cspNonce } %}
    initFooterAccordion();
{% endjs %}
```

`cspNonce` stands for however the project exposes its nonce. Prefer an external module script (`craft.vite.script()`, `{% js 'file.js' %}`) where the policy allows it, so the cached block needs no inline script at all.

## Example: Header Navigation and Footer

The menu query is the expensive part and the same on every page. Active state and the language switcher depend on the current page. Two working shapes:

**Shape A: per-URL cache (keeps `aria-current` in the markup).** Leave out `globally`, so the key includes the request path and each page caches its own copy of the nav with the right item marked. The query still runs once per URL instead of on every hit, and an entry save in the menu clears every copy. Prefer this when the nav must carry `aria-current="page"`, which is most of the time.

```twig
{# _organisms/site-header.twig #}
<header>
    <nav aria-label="{{ 'Main'|t }}">
        {%- cache using key 'nav-main-v2' -%}
            {%- set navItems = craft.entries.section('pages').level(1).all() -%}
            <ul>
                {%- for navItem in navItems -%}
                    <li>{{ tag('a', {
                        href: navItem.url,
                        text: navItem.title,
                        aria: { current: (entry.id ?? null) == navItem.id ? 'page' : false },
                    }) }}</li>
                {%- endfor -%}
            </ul>
        {%- endcache -%}
    </nav>

    {# Per-page and cheap: never inside a cache block #}
    {%- include '_molecules/navigation/language-switcher' with { entry: entry ?? null } only -%}
</header>
```

`entry` is only safe inside this block because the key is per path. It becomes wrong the moment someone adds `globally`, and on routes where the same path serves different entries by query string.

**Shape B: one global copy (active state moves outside).** Cache the menu once per site with no per-page state in it, and set the active state from a page-level attribute outside the block. Fewer cache entries, but CSS cannot set `aria-current`. Use this for a mega-menu or footer, where active state is decorative.

```twig
{# _layouts/base.twig: per-page state lives outside every cache block #}
<body data-section="{{ entry.section.handle ?? '' }}">

{# _organisms/site-footer.twig #}
<footer>
    {%- cache globally using key 'footer-links-v1' -%}
        {%- set footerItems = craft.entries.section('pages').level(1).all() -%}
        <ul>
            {%- for footerItem in footerItems -%}
                <li data-nav-section="{{ footerItem.slug }}">{{ tag('a', { href: footerItem.url, text: footerItem.title }) }}</li>
            {%- endfor -%}
        </ul>
    {%- endcache -%}

    {# Per-request: outside #}
    {%- include '_molecules/navigation/language-switcher' with { entry: entry ?? null } only -%}
</footer>
```

```css
/* Active state from the uncached body attribute */
[data-section="news"] [data-nav-section="news"] > a { font-weight: 700; }
```

Keep the language switcher out of both shapes. It reads the current element's localized URLs, it is cheap (one eager-loaded `entry.localized`), and a per-URL copy would still be wrong on query-string pages such as search. See `multi-site-patterns.md`.

**Versioning:** bump the key suffix (`-v2` to `-v3`) in the same commit that changes the markup inside the block. Old entries are not purged; they stop being read and expire after `cacheDuration`. If you cannot bump the key, run `ddev craft invalidate-tags/template` (or Utilities, Caches, "Template caches") after deploy.

## Images Inside Cached Blocks

- **Native transforms with `generateTransformsBeforePageLoad` off (the default).** A transform that does not exist yet renders as a temporary `actions/assets/generate-transform?transformId=…` URL. Craft refuses to store a block containing one, so the block is rebuilt on every request until all of its transforms exist. Expect a cached block with fresh images to stay uncached for a request or two after upload. See `image-presets.md`, "Transform Generation Timing".
- **ImageOptimize.** OptimizedImages field URLs are generated ahead of time, so they cache safely. One catch: every `ResaveOptimizedImages` queue job starts by clearing the cache tag for the whole Asset element type, which clears every `{% cache %}` block that loaded any asset, including a logo in a cached header. See the `craft-plugins` skill's `image-optimize.md`.

## Verification Recipe

Run with `devMode` on (so the debug toolbar is available) and `enableTemplateCaching` on. In a private window, never Live Preview, because preview requests bypass the cache.

1. **Nonces differ per response.** Load two different pages, then reload one. Run `curl -s https://site.ddev.site/page-a/ | grep -o 'nonce="[^"]*"'` twice for each page. Every nonce must change between responses and match that response's `Content-Security-Policy` header (`curl -sI`). A repeated value means a nonce is inside a cached block.
2. **No CSP violations.** Open both pages with DevTools, Console. There must be no "Refused to execute inline script" messages.
3. **Active state.** On page A, the nav item for A carries `aria-current="page"` (Shape A) or the A styling (Shape B), and B's does not. Then load B and check the reverse. Do it with A loaded first and then with B loaded first, after `ddev craft invalidate-tags/template`.
4. **Language switcher.** On each page, the switcher links point to that page's translations, not the first-cached page's.
5. **Element invalidation.** Change a menu entry's title in the CP and save. The next front-end request shows the new title with no cache clear.
6. **Hit confirmation.** On the second load of a page, the debug toolbar's DB panel shows the menu query gone. If it is still there, the block is not being stored: look for a temporary transform URL in its output, or an `if`/`unless` condition evaluating false.

## Related

- `craftcms` skill, `caching.md`: caching layers, the PHP data cache, `invalidate-tags`
- `plugins/blitz.md`: drop `{% cache %}` in templates Blitz caches
- `plugins/seomatic.md`, `plugins/sprig.md`: plugin output that must stay outside
- `image-presets.md`: transform generation timing
