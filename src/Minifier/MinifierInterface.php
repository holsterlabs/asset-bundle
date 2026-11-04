<?php

declare(strict_types=1);

namespace Hl\AssetBundle\Minifier;

interface MinifierInterface
{
    public function minify(string $type, string $content): string;

    /** Must change when the implementation, its configuration or dependencies change. */
    public function getCacheKey(): string;
}
