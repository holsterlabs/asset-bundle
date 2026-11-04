<?php

declare(strict_types=1);

namespace Hl\AssetBundle;

use Hl\AssetBundle\Minifier\MinifierInterface;
use Symfony\Component\Filesystem\Path;

/** Generates only requested combinations; metadata lives outside the document root. */
final class AssetProcessor
{
    private string $projectDir;
    private string $publicDir;
    private string $outputDir;
    private string $publicationDir;
    private string $cacheDir;
    private array $roots = [];
    private array $dependencies = [];
    private array $links = [];

    public function __construct(
        string $projectDir,
        string $publicDir,
        string $outputDir,
        string $publicationDir,
        string $cacheDir,
        array $roots = [],
        private readonly string $baseUrl = '',
        private readonly string $version = '1',
        private readonly ?MinifierInterface $minifier = null,
        private readonly array $minify = ['css' => false, 'js' => false],
    ) {
        $this->projectDir = Path::canonicalize($projectDir);
        $this->publicDir = $this->absolute($publicDir);
        $this->outputDir = $this->absolute($outputDir);
        $this->publicationDir = $this->absolute($publicationDir);
        $this->cacheDir = $this->absolute($cacheDir);
        foreach ([$this->outputDir, $this->publicationDir] as $directory) {
            if (!$this->inside($directory, $this->publicDir)) {
                throw new \InvalidArgumentException('Asset output and publication directories must be below public_dir.');
            }
        }
        if ($this->overlaps($this->outputDir, $this->publicationDir) || $this->overlaps($this->cacheDir, $this->publicDir)) {
            throw new \InvalidArgumentException('Output/publication directories must be separate; cache_dir must be outside public_dir.');
        }
        foreach ($roots as $name => $root) {
            $root = $this->absolute($root);
            if ($root === $this->projectDir || $this->overlaps($root, $this->outputDir) || $this->overlaps($root, $this->publicationDir) || $this->overlaps($root, $this->cacheDir)) {
                throw new \InvalidArgumentException('An asset root must be a dedicated public resource directory: '.$root);
            }
            $this->roots[$name] = $root;
        }
    }

    public function shouldMinify(string $type, ?bool $override = null): bool
    {
        return $override ?? ($this->minify[$type] ?? false);
    }

    public function process(string $type, array $assets): string
    {
        if (!in_array($type, ['css', 'js'], true) || $assets === []) {
            throw new \InvalidArgumentException('Expected a non-empty CSS or JavaScript group.');
        }

        return $this->locked(function () use ($type, $assets): string {
            $minify = $this->shouldMinify($type, $assets[0]['options']['minify'] ?? null);
            foreach ($assets as $asset) {
                if ($this->shouldMinify($type, $asset['options']['minify'] ?? null) !== $minify) {
                    throw new \InvalidArgumentException('Assets with different minify settings require separate output groups.');
                }
            }
            if ($minify && $this->minifier === null) {
                throw new \LogicException('Minification requires a configured MinifierInterface service.');
            }
            $minifierKey = $minify ? $this->minifier->getCacheKey() : null;
            $resolvedRoots = array_map(static fn (string $root): string|false => realpath($root), $this->roots);
            $cacheAssets = $assets;
            foreach ($cacheAssets as &$cacheAsset) {
                // CSP nonces change per response but do not change generated file contents.
                unset($cacheAsset['options']['attributes']['nonce']);
            }
            unset($cacheAsset);
            $resolvedSources = [];
            foreach ($assets as $asset) {
                if (!$asset['options']['inline']) {
                    $resolvedSources[] = realpath($this->absolute($asset['source']));
                }
            }
            $key = hash('sha256', serialize([4, $type, $cacheAssets, $this->roots, $resolvedRoots, $resolvedSources, $this->version, $minify, $minifierKey, $this->outputDir, $this->publicationDir]));
            $metadataPath = $this->cacheDir.'/'.$key.'.json';
            $metadata = is_file($metadataPath) ? json_decode($this->read($metadataPath), true) : null;
            if (is_array($metadata) && $this->fresh($metadata)) {
                // Avoid a filesystem write on every request. Cleanup has a >= 2 hour grace period.
                if (time() - $metadata['used'] >= 3600) {
                    $metadata['used'] = time();
                    $this->atomicWrite($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));
                }

                return $this->url($metadata['output']);
            }
            $this->dependencies = $this->links = [];
            $parts = [];
            foreach ($assets as $asset) {
                $options = $asset['options'];
                if ($options['inline']) {
                    $content = $asset['source'];
                    $directory = $options['base_dir'] === null ? null : $this->absolute($options['base_dir']);
                    $root = $directory === null ? null : $this->rootFor($directory, $options['root'], true);
                } else {
                    $file = $this->source($asset['source']);
                    $root = $this->rootFor($file, $options['root']);
                    $content = $this->depend($file);
                    $directory = dirname($file);
                }
                if ($type === 'css') {
                    $content = (new CssRewriter())->rewrite(
                        $content,
                        fn (string $url): string => $this->rewriteUrl($url, $directory, $root),
                        fn (string $url, string $media): string => $this->importCss($url, $media, $directory, $root, []),
                    );
                } elseif (($options['attributes']['type'] ?? '') === 'module' && preg_match('~\b(?:import|export)\s[^;]*[\'"]\.{1,2}/|\bimport\s*\(\s*[\'"]\.{1,2}/~', $content)) {
                    throw new \InvalidArgumentException('Relative JavaScript module imports require a frontend bundler.');
                }
                $parts[] = $content;
            }
            $content = implode($type === 'js' ? "\n;\n" : "\n", $parts)."\n";
            if ($minify) {
                try {
                    $content = $this->minifier->minify($type, $content);
                } catch (\Throwable $exception) {
                    throw new \RuntimeException('Failed to minify '.$type.' assets: '.$exception->getMessage(), 0, $exception);
                }
            }
            $hash = hash('sha256', $this->version."\0".$content);
            $output = $this->outputDir.'/'.$type.'/'.$hash.'.'.$type;
            if (!is_file($output)) {
                $this->atomicWrite($output, $content);
            }
            // Preserve the previous generation's link references for cached HTML during the grace period.
            if (is_array($metadata) && isset($metadata['output']) && $metadata['output'] !== $output) {
                $metadata['used'] = time();
                $archive = $this->cacheDir.'/retired-'.hash('sha256', serialize([$metadata['output'], $metadata['links']])).'.json';
                $this->atomicWrite($archive, json_encode($metadata, JSON_THROW_ON_ERROR));
            }
            $metadata = ['output' => $output, 'dependencies' => $this->dependencies, 'links' => $this->links, 'used' => time()];
            $this->atomicWrite($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));

            return $this->url($output);
        });
    }

    /** Returns removed metadata/file/symlink counts. Never follows a publication link for deletion. */
    public function cleanup(int $retention, ?int $now = null): array
    {
        if ($retention < 7200) {
            throw new \InvalidArgumentException('Retention must be at least two hours.');
        }

        return $this->locked(function () use ($retention, $now): array {
            $cutoff = ($now ?? time()) - $retention;
            $liveFiles = $liveLinks = [];
            $removed = [0, 0, 0];
            foreach (glob($this->cacheDir.'/*.json') ?: [] as $path) {
                $meta = json_decode($this->read($path), true);
                if (!is_array($meta) || !isset($meta['used'], $meta['output'], $meta['links'])) {
                    // A corrupt entry may be the only reference to a link: fail closed.
                    throw new \RuntimeException('Invalid asset cache metadata: '.$path);
                }
                // Cache hits refresh timestamps hourly. Add that hour rather than shortening retention.
                if ($meta['used'] + 3600 < $cutoff) {
                    $this->removeFile($path);
                    ++$removed[0];
                } else {
                    $liveFiles[$meta['output']] = true;
                    $liveLinks += $meta['links'];
                }
            }
            foreach (['css', 'js'] as $type) {
                foreach (glob($this->outputDir.'/'.$type.'/*.'.$type) ?: [] as $path) {
                    if (preg_match('/^[a-f0-9]{64}\.'. $type.'$/D', basename($path)) && !isset($liveFiles[$path]) && filemtime($path) < $cutoff && !is_link($path)) {
                        $this->removeFile($path);
                        ++$removed[1];
                    }
                }
            }
            foreach (glob($this->publicationDir.'/hl-*') ?: [] as $path) {
                if (preg_match('/^hl-[a-f0-9]{64}$/D', basename($path)) && is_link($path) && !isset($liveLinks[$path]) && lstat($path)['mtime'] < $cutoff) {
                    $this->removeFile($path);
                    ++$removed[2];
                }
            }

            return $removed;
        });
    }

    private function importCss(string $url, string $media, ?string $directory, ?string $root, array $stack): string
    {
        if ($directory === null || $root === null || $this->external($url) || strpbrk($url, '?#') !== false) {
            throw new \InvalidArgumentException('CSS @import must reference a local file within the configured root.');
        }
        if (preg_match('/\b(?:layer|supports)\s*(?:\(|$)/i', $media)) {
            throw new \InvalidArgumentException('CSS @import layer()/supports() requires a frontend CSS bundler.');
        }
        $file = $this->checkedFile($directory.'/'.rawurldecode($url), $root);
        if (in_array($file, $stack, true)) {
            throw new \RuntimeException('Circular CSS @import: '.$file);
        }
        $stack[] = $file;
        $content = (new CssRewriter())->rewrite(
            $this->depend($file),
            fn (string $value): string => $this->rewriteUrl($value, dirname($file), $root),
            fn (string $value, string $condition): string => $this->importCss($value, $condition, dirname($file), $root, $stack),
        );

        return $media === '' ? $content : '@media '.$media." {\n".$content."\n}";
    }

    private function rewriteUrl(string $url, ?string $directory, ?string $root): string
    {
        if ($url === '' || $this->external($url)) {
            return $url;
        }
        if ($directory === null || $root === null) {
            throw new \InvalidArgumentException('Relative inline CSS URLs require root and base_dir options.');
        }
        preg_match('/^([^?#]*)(\?[^#]*)?(#.*)?$/s', $url, $parts);
        $file = $this->checkedFile($directory.'/'.rawurldecode($parts[1]), $root);
        $bytes = $this->depend($file);
        $link = $this->publish($root);
        $relative = Path::makeRelative($file, $root);
        $relative = implode('/', array_map('rawurlencode', explode('/', $relative)));
        $target = Path::makeRelative($link, $this->outputDir.'/css').'/'.$relative;
        $query = $parts[2] ?? '';

        return $target.$query.($query === '' ? '?' : '&').'hlv='.substr(hash('sha256', $bytes), 0, 16).($parts[3] ?? '');
    }

    private function publish(string $root): string
    {
        $link = $this->publicationDir.'/hl-'.hash('sha256', Path::makeRelative($root, $this->projectDir)."\0".$this->version);
        $this->mkdir($this->publicationDir);
        if (is_link($link)) {
            if (realpath($link) !== realpath($root)) {
                throw new \RuntimeException('Publication symlink points to an unexpected target: '.$link);
            }
        } elseif (file_exists($link) || !@symlink($root, $link)) {
            throw new \RuntimeException('Cannot create asset symlink '.$link.'. On Windows enable Developer Mode or grant symlink privileges.');
        }
        $this->links[$link] = $root;

        return $link;
    }

    private function rootFor(string $path, ?string $name, bool $directory = false): string
    {
        $real = realpath($path);
        if ($real === false) {
            throw new \RuntimeException('Asset path does not exist: '.$path);
        }
        $candidates = $name === null ? $this->roots : [$name => $this->roots[$name] ?? throw new \InvalidArgumentException('Unknown asset root: '.$name)];
        $matches = [];
        foreach ($candidates as $candidate) {
            $root = realpath($candidate);
            if ($root !== false && ($this->overlaps(Path::canonicalize($root), $this->outputDir) || $this->overlaps(Path::canonicalize($root), $this->publicationDir) || $this->overlaps(Path::canonicalize($root), $this->cacheDir))) {
                throw new \InvalidArgumentException('Resolved asset root is not a dedicated public resource directory: '.$root);
            }
            if ($root !== false && ($this->inside(Path::canonicalize($real), Path::canonicalize($root)) || ($directory && $real === $root))) {
                $matches[] = Path::canonicalize($root);
            }
        }
        usort($matches, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $matches[0] ?? throw new \InvalidArgumentException('Asset is outside the configured roots: '.$path);
    }

    private function source(string $path): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $path)) {
            throw new \InvalidArgumentException('Only local asset files are accepted.');
        }
        $file = realpath($this->absolute($path));
        if ($file === false || !is_file($file)) {
            throw new \RuntimeException('Asset file not found: '.$path);
        }

        return Path::canonicalize($file);
    }

    private function checkedFile(string $path, string $root): string
    {
        $file = realpath($path);
        if ($file === false || !is_file($file) || !$this->inside(Path::canonicalize($file), $root)) {
            throw new \RuntimeException('Missing asset or reference outside its configured root: '.$path);
        }
        // Also track the original path: a nested symlink can be retargeted without changing its old target.
        $this->dependencies[$path] = $this->stamp($path);

        return Path::canonicalize($file);
    }

    private function depend(string $file): string
    {
        $before = $this->stamp($file);
        $content = $this->read($file);
        $after = $this->stamp($file);
        if ($before === false || $before !== $after) {
            throw new \RuntimeException('Asset changed during generation; retry the request: '.$file);
        }
        $this->dependencies[$file] = $after;

        return $content;
    }

    private function stamp(string $file): array|false
    {
        clearstatcache(true, $file);
        $stat = @stat($file);

        return $stat === false ? false : [$stat['mtime'], $stat['ctime'], $stat['size'], realpath($file)];
    }

    private function fresh(array $metadata): bool
    {
        if (!isset($metadata['output'], $metadata['dependencies'], $metadata['links'], $metadata['used']) || !is_file($metadata['output'])) {
            return false;
        }
        foreach ($metadata['dependencies'] as $file => $stamp) {
            if ($this->stamp($file) !== $stamp) {
                return false;
            }
        }
        foreach ($metadata['links'] as $link => $root) {
            if (!is_link($link) || realpath($link) !== realpath($root)) {
                return false;
            }
        }

        return true;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.Path::makeRelative($path, $this->publicDir);
    }

    private function absolute(string $path): string
    {
        return Path::makeAbsolute($path, $this->projectDir);
    }

    private function inside(string $path, string $root): bool
    {
        return str_starts_with($path, rtrim($root, '/').'/');
    }

    private function overlaps(string $a, string $b): bool
    {
        return $a === $b || $this->inside($a, $b) || $this->inside($b, $a);
    }

    private function external(string $url): bool
    {
        return str_starts_with($url, '/') || str_starts_with($url, '#') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1;
    }

    private function mkdir(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create directory: '.$directory);
        }
    }

    private function read(string $path): string
    {
        $content = @file_get_contents($path);

        return $content === false ? throw new \RuntimeException('Cannot read file: '.$path) : $content;
    }

    private function atomicWrite(string $path, string $content): void
    {
        $this->mkdir(dirname($path));
        $temporary = tempnam(dirname($path), '.hl-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create temporary asset file.');
        }
        try {
            if (file_put_contents($temporary, $content) !== strlen($content) || !chmod($temporary, 0644) || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot write generated asset: '.$path);
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function removeFile(string $path): void
    {
        if (!unlink($path)) {
            throw new \RuntimeException('Cannot remove asset cache entry: '.$path);
        }
    }

    private function locked(callable $callback): mixed
    {
        $this->mkdir($this->cacheDir);
        $lock = fopen($this->cacheDir.'/assets.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open asset cache lock.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock asset cache.');
            }

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
