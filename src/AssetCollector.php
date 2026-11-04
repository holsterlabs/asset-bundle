<?php

declare(strict_types=1);

namespace Hl\AssetBundle;

use Symfony\Contracts\Service\ResetInterface;

/** A request-local ordered collection, shared by PHP and Twig. */
final class AssetCollector implements ResetInterface
{
    private array $assets = ['css' => [], 'js' => []];
    private array $rendered = ['css' => [], 'js' => []];

    public function __construct(private readonly AssetProcessor $processor) {}

    public function addCss(string $source, array $options = []): void
    {
        $this->add('css', $source, $options);
    }

    public function addJs(string $source, array $options = []): void
    {
        $this->add('js', $source, $options);
    }

    public function renderCss(): string
    {
        return $this->render('css');
    }

    public function renderJs(): string
    {
        return $this->render('js');
    }

    public function reset(): void
    {
        $this->assets = $this->rendered = ['css' => [], 'js' => []];
    }

    private function add(string $type, string $source, array $options): void
    {
        $unknown = array_diff(array_keys($options), ['inline', 'external', 'library', 'standalone', 'priority', 'root', 'base_dir', 'attributes', 'id', 'minify']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown asset options: ' . implode(', ', $unknown));
        }
        $options += ['inline' => false, 'external' => false, 'library' => false, 'standalone' => false, 'priority' => 0, 'root' => null, 'base_dir' => null, 'attributes' => [], 'minify' => null];
        if ($options['minify'] !== null && !is_bool($options['minify'])) {
            throw new \InvalidArgumentException('minify must be a boolean or null (inherit configuration).');
        }
        foreach (['inline', 'external', 'library', 'standalone'] as $flag) {
            if (!is_bool($options[$flag])) {
                throw new \InvalidArgumentException($flag . ' must be a boolean.');
            }
        }
        if ($options['external']) {
            if ($options['inline'] || $options['minify'] === true) {
                throw new \InvalidArgumentException('External assets cannot be inline or explicitly minified.');
            }
            if (
                $source === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $source)
                || (preg_match('/^([a-z][a-z0-9+.-]*):/i', $source, $scheme) && !in_array(strtolower($scheme[1]), ['http', 'https'], true))
            ) {
                throw new \InvalidArgumentException('External assets require a relative URL or an HTTP(S) URL.');
            }
        }
        if (!is_int($options['priority']) || !is_array($options['attributes'])) {
            throw new \InvalidArgumentException('priority must be an integer; attributes must be an array.');
        }
        ksort($options['attributes']);
        foreach ($options['attributes'] as $name => $value) {
            $allowed = $type === 'css' ? ['media', 'title', 'disabled', 'crossorigin', 'referrerpolicy', 'nonce', 'integrity'] : ['type', 'defer', 'async', 'nomodule', 'crossorigin', 'referrerpolicy', 'nonce', 'integrity'];
            if (!in_array($name, $allowed, true) || (!is_string($value) && !is_bool($value))) {
                throw new \InvalidArgumentException('Invalid asset HTML attribute: ' . $name);
            }
        }
        if (($options['attributes']['type'] ?? '') === 'module' && !$options['standalone'] && !$options['external']) {
            throw new \InvalidArgumentException('JavaScript modules require standalone: true.');
        }
        $id = $options['id'] ?? hash('sha256', ($options['external'] ? 'external:' : ($options['inline'] ? 'inline:' : 'file:')) . $source);
        unset($options['id']);
        if (isset($this->assets[$type][$id]) && $this->assets[$type][$id] !== ['source' => $source, 'options' => $options]) {
            throw new \LogicException('Conflicting registration for asset ' . $id);
        }
        $this->assets[$type][$id] = ['source' => $source, 'options' => $options];
    }

    private function render(string $type): string
    {
        $pending = array_diff_key($this->assets[$type], $this->rendered[$type]);
        uasort(
            $pending,
            static fn(array $a, array $b): int => ($b['options']['library'] <=> $a['options']['library']) ?: ($b['options']['priority'] <=> $a['options']['priority'])
        );
        // Only adjacent compatible assets are merged: standalone items keep their position.
        $groups = [];
        $previous = null;
        foreach ($pending as $asset) {
            $options = $asset['options'];
            $key = serialize([$options['library'], $options['attributes'], $this->processor->shouldMinify($type, $options['minify'])]);
            if ($options['external'] || $options['standalone'] || $previous !== $key) {
                $groups[] = [];
            }
            $groups[array_key_last($groups)][] = $asset;
            $previous = ($options['external'] || $options['standalone']) ? null : $key;
        }
        $html = [];
        foreach ($groups as $group) {
            $url = $group[0]['options']['external'] ? $group[0]['source'] : $this->processor->process($type, $group);
            $attributes = '';
            foreach ($group[0]['options']['attributes'] as $name => $value) {
                if ($value !== false) {
                    $attributes .= ' ' . $name . ($value === true ? '' : '="' . self::escape($value) . '"');
                }
            }
            $html[] = $type === 'css'
                ? '<link rel="stylesheet" href="' . self::escape($url) . '"' . $attributes . '>'
                : '<script src="' . self::escape($url) . '"' . $attributes . '></script>';
        }
        $this->rendered[$type] += $pending;

        return implode("\n", $html);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
