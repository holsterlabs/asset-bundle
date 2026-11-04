# HOLSTERLABS Asset Bundle

Collect local CSS/JavaScript files or source code from PHP and Twig, process only
the requested combination, and serve generated files with content hashes.
This is a standalone Symfony bundle, with no dependency on the skeleton's App namespace.

## Installation

The skeleton already declares the package through its `packages/*` Composer path
repository and registers `Hl\AssetBundle\AssetBundle` in `config/bundles.php`.
Run `composer install` after checkout. To use the package elsewhere, add this
directory as a Composer path repository (or publish it in your Composer repository),
require `holsterlabs/asset-bundle:^1.0`, and register the bundle.

The skeleton mirrors local packages for compatibility with Windows installations
without symlink privileges. After editing the bundle, refresh its installed copy
with `composer reinstall holsterlabs/asset-bundle --no-scripts`. On systems with
symlink support you can instead enable symlinking in the path repository options.

```php
// config/bundles.php
return [Hl\AssetBundle\AssetBundle::class => ['all' => true]];
```

## Configuration

```yaml
# config/packages/hl_assets.yaml
hl_assets:
    public_dir: '%kernel.project_dir%/public'
    output_dir: '%kernel.project_dir%/public/processed'
    publication_dir: '%kernel.project_dir%/public/_assets'
    cache_dir: '%kernel.project_dir%/var/asset-bundle'
    base_url: '' # Optional URL prefix, e.g. /my-app or https://cdn.example.com
    version: 'release-2026-10-31'
    retention: 604800 # Seconds; at least 7200, default seven days
    minify:
        css: false
        js: false
    roots:
        app: '%kernel.project_dir%/assets'
        my_extension: '%kernel.project_dir%/vendor/myExt/Resources/assets'
```

Output and publication directories must be separate directories below `public_dir`.
Metadata must be outside the public directory. Roots are explicit allowlists of
**public resources only**: their entire contents become accessible through a symlink.
Never register a package root containing PHP source or configuration. The processor
rejects source paths and CSS references escaping their allowed root, including through
symlinks. File paths are filesystem paths relative to the project, or absolute paths;
they are not web URLs or Symfony routes. Use UTF-8 CSS source files.

The web/PHP user needs write permissions for output, publication and metadata directories.
The web server must follow symlinks. Windows needs Developer Mode or symlink privileges;
failure raises an explicit exception instead of silently copying an entire resource tree.

## Twig

```twig
{% block register_assets %}
    {% do add_css('vendor/myExt/Resources/assets/css/any.css') %}
    {% do add_js('assets/js/jquery.js', {library: true, priority: 100}) %}
    {% do add_js('assets/js/plugin.js', {library: true, priority: 50}) %}
    {% do add_js('assets/js/editor.js', {standalone: true, priority: 10}) %}
    {% do add_js('assets/js/app.js') %}
    {% do add_js('App.start();', {inline: true}) %}
{% endblock %}
```

```twig
{# After registration, in the head: #}
{{ render_css() }}
{# After registration, at your chosen script position: #}
{{ render_js() }}
```

The skeleton's `fe.html.twig` provides `register_assets` before the head and renders
both types in the head. Register assets before rendering that type. Component templates
rendered in the body are too late for head CSS: collect their requirements in PHP or
the registration block. Repeated render calls emit only newly registered assets;
they cannot reorder tags already emitted. Twig output is safely marked as HTML;
attribute values and generated URLs are HTML-escaped.

Inline source is explicitly marked and is written into generated files too:

```twig
{% do add_css('.banner { background: url(../media/banner.jpg); }', {
    inline: true,
    root: 'my_extension',
    base_dir: 'vendor/myExt/Resources/assets/css'
}) %}
```

Relative URLs in inline CSS need a `base_dir` inside a configured root. The root
can be inferred from that directory; specify `root` to select a particular named root.
CSS without relative resources does not require these options.

## PHP

Inject `Hl\AssetBundle\AssetCollector` into controllers or services:

```php
$assets->addCss('assets/css/main.css');
$assets->addJs('assets/js/jquery.js', ['library' => true, 'priority' => 100]);
$assets->addJs('App.start();', ['inline' => true]);
$html = $assets->renderJs(); // Or leave rendering to Twig.
```

Symfony resets the collector between requests in long-running workers. When using
it outside Symfony's request lifecycle, call `reset()` between documents/jobs.
The older skeleton `include_js`/`renderJavaScriptFiles` API remains independent;
use the new API for processing and bundling.

## Ordering and options

* `library` (boolean, default false): libraries always precede ordinary entries.
* `priority` (integer, default 0): higher values first within each group. Ties retain
  registration order.
* `standalone` (boolean): this entry gets a separate output file at its sorted position.
  Compatible entries before and after it form separate bundles to preserve execution order.
* `inline` (boolean): source is code rather than a local filename.
* `external` (boolean): source is a URL, including a Symfony route URL. Output it
  directly without reading, copying, minifying or caching it. Library/priority
  sorting, deduplication and HTML attributes still apply. Each external entry
  separates adjacent local bundles to preserve execution order.
* `minify` (boolean or null): override CSS/JS minification for this entry, or inherit
  configuration when null/omitted. Different effective settings produce separate bundles.
* `root` / `base_dir`: optional named resource root and inline CSS source directory.
* `attributes`: HTML properties such as `media` for CSS, `defer`/`async`/`type` for JS,
  or `nonce`. Different attributes produce separate bundles. Use compatible execution
  flags for dependent scripts; `async` does not guarantee library execution order.
* `id`: optional registration identifier. Otherwise the source identifies the entry.
  Identical registrations are deduplicated; conflicting registrations throw instead
  of silently selecting different priorities or flags.

Use `id` for two intentionally distinct entries using identical source. Within a
group, ordinary CSS/JS is concatenated, with JavaScript separated by semicolons and
newlines. Optional minification runs after CSS URL rewriting and concatenation;
no transpilation takes place. These are classic browser
scripts that must support concatenation; scripts relying on per-file lexical scopes,
`document.currentScript.src`, or original relative resource paths need adaptation.

This is not an ES module dependency bundler. Module entries must be standalone;
relative JS imports are rejected. Use Vite/esbuild/etc. for module graphs and register
their browser-ready output. Source maps are not relocated or combined. Remote
resources/routes are not fetched by PHP; use `external: true` to emit their URLs.

```twig
{% do add_js(path('js_l10n', {type: 'fe'}), {
    external: true,
    library: true,
    priority: 100,
    attributes: {defer: true}
}) %}
{{ render_js() }}
```

In PHP: `$assets->addJs($url, ['external' => true]);`. This also works with
`add_css`/`addCss` for stylesheets. URLs may be relative, root-relative,
protocol-relative or HTTP(S). Attribute values and URLs are HTML-escaped; attributes
include `integrity` and `crossorigin` for CDN resources. External module scripts
do not need `standalone: true`. `inline: true` and explicit `minify: true` are
incompatible with `external: true`; global minification is automatically bypassed.
HTTP caching of external resources is controlled by their endpoint or CDN.

## CSS resource publication

For `vendor/myExt/Resources/assets/css/any.css` containing
`url(../media/img.jpg)`, output becomes:

```text
public/processed/css/<content-hash>.css
public/_assets/hl-<root-hash> -> vendor/myExt/Resources/assets/
```

The rewritten value is
`url("../../_assets/hl-<root-hash>/media/img.jpg?hlv=<image-content-hash>")`.
The `hl-` prefix lets cleanup distinguish owned symlinks from other tools' entries.
Existing query parameters and fragments survive rewriting. Images and fonts receive
content versions too, so changing an image invalidates CSS and its browser URL.
URLs are relative to the generated CSS directory, so custom output directories work.
Absolute URLs, root-relative URLs, data URIs and fragment references are preserved.
Comments and quoted strings containing text such as `url(...)` are not rewritten.

Local CSS `@import` is expanded recursively; media conditions are preserved with
`@media`, circular imports fail explicitly, and `@charset` is removed from UTF-8
fragments. External imports and `@import layer()/supports()` fail explicitly because
naively concatenating them would change cascade semantics. Precompile those styles
with a dedicated CSS tool. Missing files are errors rather than empty output.

## Minification

The bundle integrates `matthiasmullie/minify` for CSS and JavaScript, without Node.js.
The bundle defaults to disabled minification. The skeleton's configuration enables
both types under `when@prod`; development/test output remains readable. Set the
booleans explicitly to enable either type in another environment:

```yaml
hl_assets:
    minify:
        css: true
        js: true
```

Per-file overrides work for both inline source and files, in PHP and Twig:

```twig
{% do add_js('assets/js/vendor.min.js', {library: true, minify: false}) %}
{% do add_js('assets/js/app.js', {minify: true, attributes: {defer: true}}) %}
```

```php
$assets->addCss('assets/css/vendor.min.css', ['minify' => false]);
```

Standalone entries can be minified too. Entries with different effective settings
stay in separate files at their sorted positions. Already-minified filenames are
not auto-detected: use `minify: false` when needed. Compression only runs on cache
misses; its configuration, implementation and dependency version enter the cache key.
The filename hashes the final minified bytes. CSS asset embedding is disabled so
published image/font URLs, query versions and fragments remain intact.

Minifier failures propagate as explicit errors and do not publish partial output
or mark the entry as cached. Check modern JavaScript against the selected library;
this does not add module bundling or transpilation. HTTP gzip/Brotli is separate and
can be configured in the web server independently.

To replace the provider, implement `Hl\AssetBundle\Minifier\MinifierInterface`
and override its service alias in the application's `services.yaml`:

```yaml
services:
    App\Assets\CustomMinifier: ~
    Hl\AssetBundle\Minifier\MinifierInterface:
        alias: App\Assets\CustomMinifier
```

Implement `minify(string $type, string $content): string` and
`getCacheKey(): string`. The latter must change with provider versions or options
that affect output. Providers receive code strings after resource rewriting;
they must preserve those rewritten CSS URLs and must not write their own output files.

## Cache and cleanup

No build-time inventory or global asset manifest is needed. On first render of a
particular ordered combination, the processor creates its output and a private cache
entry with source/resource dependencies and referenced publication links. Later renders
only check dependency metadata (`mtime`, `ctime`, size and symlink target) and file/link
existence. Files are not re-read or regenerated on cache hits. Used timestamps update
at most hourly. A filesystem lock serializes generation and cleanup; writes use atomic
rename so readers never receive partial files. Deploy using atomic releases rather
than editing sources while generation is running.

Change `version` each deployment when timestamps may be preserved or contents may
change without different size/timestamps. Files and root links get release-specific
identities. Keep previous release resource directories available for at least the
retention period: symlinks do not snapshot files, and overwriting a stable vendor
directory cannot preserve its previous image/font contents. Set long-lived HTTP
caching on the generated CSS/JS directory, e.g.
`Cache-Control: public, max-age=31536000, immutable`. Hashed image query parameters
also allow versioned caching. Files referenced directly through a stable symlink URL
without `hlv` should use cache revalidation or release-specific root links.

Schedule this command (e.g. daily):

```console
php bin/console hl:assets:cleanup
```

Entries unused for `retention` plus the hourly timestamp margin expire. The command
then removes old generated files with no retained entry, followed by old owned symlinks
with no remaining reference. It only unlinks links and never recursively deletes their
targets. Previous generations retain link references during the grace period too.
Corrupt metadata makes cleanup stop rather than guess which links can be removed.
Keep retention longer than the maximum HTML cache/CDN lifetime. If a full-page cache
bypasses PHP, its hits do not refresh asset usage; account for that lifetime explicitly.

Keep `cache_dir` persistent alongside generated files. Normal Symfony `cache:clear`
does not remove it. Do not delete metadata separately while retaining cached HTML:
it contains the references that protect old publication links. For multiple nodes,
use shared output/publication/cache storage with reliable `flock`, or generate/cache
on each node with consistent deployment paths. Publish files to a CDN before emitting
its URL; `base_url` only changes HTML URLs and does not upload files.

## Tests

From the skeleton:

```console
ddev exec php vendor/bin/phpunit -c packages/asset-bundle/phpunit.xml.dist
```

Independently: `composer install` then `vendor/bin/phpunit`. Tests cover ordering,
standalone boundaries, cache reuse/invalidation, CSS resource URLs/imports, traversal
rejection, Twig output, cleanup references and Symfony container registration.
