<?php

declare(strict_types=1);

namespace Hl\AssetBundle\Twig;

use Hl\AssetBundle\AssetCollector;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AssetExtension extends AbstractExtension
{
    public function __construct(private readonly AssetCollector $collector)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('add_css', $this->collector->addCss(...)),
            new TwigFunction('add_js', $this->collector->addJs(...)),
            new TwigFunction('render_css', $this->collector->renderCss(...), ['is_safe' => ['html']]),
            new TwigFunction('render_js', $this->collector->renderJs(...), ['is_safe' => ['html']]),
        ];
    }
}
