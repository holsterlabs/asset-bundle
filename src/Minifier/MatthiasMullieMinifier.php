<?php

declare(strict_types=1);

namespace Hl\AssetBundle\Minifier;

use Composer\InstalledVersions;
use MatthiasMullie\Minify\CSS;
use MatthiasMullie\Minify\JS;

final class MatthiasMullieMinifier implements MinifierInterface
{
    public function minify(string $type, string $content): string
    {
        if ($type === 'css') {
            $minifier = new CSS();
            // Resource publication and URL rewriting belong to AssetProcessor.
            $minifier->setMaxImportSize(0);
            $minifier->setImportExtensions([]);
        } elseif ($type === 'js') {
            $minifier = new JS();
        } else {
            throw new \InvalidArgumentException('Unsupported minification type: '.$type);
        }
        $minifier->add($content);

        return $minifier->minify();
    }

    public function getCacheKey(): string
    {
        return self::class.':1:'.InstalledVersions::getPrettyVersion('matthiasmullie/minify').':'.InstalledVersions::getReference('matthiasmullie/minify');
    }
}
