<?php

declare(strict_types=1);

namespace Hl\AssetBundle\Tests;

use Hl\AssetBundle\AssetBundle;
use Hl\AssetBundle\AssetCollector;
use Hl\AssetBundle\AssetProcessor;
use Hl\AssetBundle\CssRewriter;
use Hl\AssetBundle\Minifier\MatthiasMullieMinifier;
use Hl\AssetBundle\Minifier\MinifierInterface;
use Hl\AssetBundle\Twig\AssetExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class AssetBundleTest extends TestCase
{
    private string $directory;
    private AssetProcessor $processor;
    private AssetCollector $collector;

    protected function setUp(): void
    {
        $this->directory = str_replace('\\', '/', sys_get_temp_dir()).'/hl-assets-test-'.bin2hex(random_bytes(6));
        mkdir($this->directory.'/resources/css', 0777, true);
        mkdir($this->directory.'/resources/media', 0777, true);
        mkdir($this->directory.'/public', 0777, true);
        $this->processor = $this->processor();
        $this->collector = new AssetCollector($this->processor);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testPriorityLibrariesStandaloneDeduplicationAndReset(): void
    {
        $this->collector->addJs('normal();', ['inline' => true]);
        $this->collector->addJs('normal();', ['inline' => true]);
        $this->collector->addJs('low();', ['inline' => true, 'library' => true]);
        $this->collector->addJs('high();', ['inline' => true, 'library' => true, 'priority' => 100]);
        $this->collector->addJs('separate();', ['inline' => true, 'priority' => 10, 'standalone' => true]);
        $this->collector->addJs('last();', ['inline' => true, 'priority' => -10]);
        $files = $this->outputs($this->collector->renderJs());
        self::assertCount(3, $files);
        self::assertSame("high();\n;\nlow();\n", file_get_contents($files[0]));
        self::assertSame("separate();\n", file_get_contents($files[1]));
        self::assertSame("normal();\n;\nlast();\n", file_get_contents($files[2]));
        self::assertSame('', $this->collector->renderJs());
        $this->collector->reset();
        $this->collector->addJs('normal();', ['inline' => true]);
        self::assertCount(1, $this->outputs($this->collector->renderJs()));
    }

    public function testStandaloneDoesNotMovePastItsDependencies(): void
    {
        foreach (['first', 'middle', 'last'] as $name) {
            $this->collector->addJs($name.'();', ['inline' => true, 'standalone' => $name === 'middle']);
        }
        $files = $this->outputs($this->collector->renderJs());
        self::assertCount(3, $files);
        self::assertSame("middle();\n", file_get_contents($files[1]));
    }

    public function testCssPublicationImportsAndMediaInvalidation(): void
    {
        $this->requireSymlinks();
        file_put_contents($this->directory.'/resources/media/image.jpg', 'image-v1');
        file_put_contents($this->directory.'/resources/css/nested.css', '.nested { color: red; }');
        file_put_contents($this->directory.'/resources/css/main.css', '@charset "UTF-8"; @import "nested.css" screen; .test { background: url(../media/image.jpg?x=1#fragment); }');
        $this->collector->addCss('resources/css/main.css');
        $file = $this->outputs($this->collector->renderCss())[0];
        $css = file_get_contents($file);
        self::assertStringContainsString('@media screen {', $css);
        self::assertStringContainsString('../../_assets/hl-', $css);
        self::assertStringContainsString('/media/image.jpg?x=1&hlv=', $css);
        self::assertStringContainsString('#fragment', $css);
        self::assertStringNotContainsString('@charset', $css);
        $links = glob($this->directory.'/public/_assets/hl-*');
        self::assertCount(1, $links);
        self::assertSame(realpath($this->directory.'/resources'), realpath($links[0]));
        file_put_contents($this->directory.'/resources/media/image.jpg', 'image-version-two');
        $this->collector->reset();
        $this->collector->addCss('resources/css/main.css');
        self::assertNotSame($file, $this->outputs($this->collector->renderCss())[0]);
    }

    public function testCacheHitDoesNotRewriteAndSourceChangeInvalidates(): void
    {
        file_put_contents($this->directory.'/resources/main.js', 'first();');
        $this->collector->addJs('resources/main.js');
        $file = $this->outputs($this->collector->renderJs())[0];
        touch($file, time() - 60);
        $this->collector->reset();
        $this->collector->addJs('resources/main.js');
        self::assertSame($file, $this->outputs($this->collector->renderJs())[0]);
        self::assertLessThan(time() - 30, filemtime($file));
        file_put_contents($this->directory.'/resources/main.js', 'changed-content();');
        $this->collector->reset();
        $this->collector->addJs('resources/main.js');
        self::assertNotSame($file, $this->outputs($this->collector->renderJs())[0]);
        self::assertCount(2, glob($this->directory.'/cache/*.json')); // Current and retired generation.
        self::assertSame([0, 0, 0], $this->processor->cleanup(7200));
        self::assertFileExists($file);
    }

    public function testCleanupKeepsSharedOutputsThenRemovesExpiredFiles(): void
    {
        $this->collector->addJs('same();', ['inline' => true]);
        $file = $this->outputs($this->collector->renderJs())[0];
        $this->collector->reset();
        $this->collector->addJs('same();', ['inline' => true, 'priority' => 10]);
        self::assertSame($file, $this->outputs($this->collector->renderJs())[0]);
        $entries = glob($this->directory.'/cache/*.json');
        self::assertCount(2, $entries);
        $this->expire($entries[0]);
        touch($file, time() - 9000);
        self::assertSame([1, 0, 0], $this->processor->cleanup(7200));
        self::assertFileExists($file);
        $this->expire($entries[1]);
        self::assertSame([1, 1, 0], $this->processor->cleanup(7200));
        self::assertFileDoesNotExist($file);
    }

    public function testCleanupUnlinksPublicationWithoutDeletingSource(): void
    {
        $this->requireSymlinks();
        file_put_contents($this->directory.'/resources/media/image.jpg', 'image');
        file_put_contents($this->directory.'/resources/css/main.css', 'a { background: url(../media/image.jpg); }');
        $this->collector->addCss('resources/css/main.css');
        $this->collector->renderCss();
        $link = glob($this->directory.'/public/_assets/hl-*')[0];
        self::assertSame([0, 0, 0], $this->processor->cleanup(7200));
        self::assertTrue(is_link($link));
        self::assertSame([1, 1, 1], $this->processor->cleanup(7200, time() + 15000));
        self::assertFalse(is_link($link));
        self::assertFileExists($this->directory.'/resources/media/image.jpg');
    }

    public function testTwigEscapingAndAttributeGroups(): void
    {
        $twig = new Environment(new ArrayLoader(['test' => "{% do add_css('a{}', {inline: true, attributes: {media: 'screen'}}) %}{% do add_css('b{}', {inline: true, attributes: {media: 'print'}}) %}{{ render_css() }}{{ render_css() }}"]), ['autoescape' => 'html']);
        $twig->addExtension(new AssetExtension($this->collector));
        $html = $twig->render('test');
        self::assertSame(2, substr_count($html, '<link '));
        self::assertStringContainsString('media="print"', $html);
        self::assertStringNotContainsString('&lt;link', $html);
    }

    public function testCssScannerLeavesStringsAndCommentsUntouched(): void
    {
        $css = '/* url(fake.png) */ a { content: "url(fake.png)"; background: URL("a\\20 b.png"); mask: url(data:image/svg+xml,abc); }';
        $urls = [];
        $result = (new CssRewriter())->rewrite($css, static function (string $url) use (&$urls): string { $urls[] = $url; return $url; }, static fn (): string => '');
        self::assertSame(['a b.png', 'data:image/svg+xml,abc'], $urls);
        self::assertStringContainsString('content: "url(fake.png)"', $result);
        self::assertStringContainsString('/* url(fake.png) */', $result);
    }

    public function testResponseNoncesReuseTheSameCacheEntry(): void
    {
        $this->collector->addJs('run();', ['inline' => true, 'attributes' => ['nonce' => 'first']]);
        self::assertStringContainsString('nonce="first"', $this->collector->renderJs());
        $this->collector->reset();
        $this->collector->addJs('run();', ['inline' => true, 'attributes' => ['nonce' => 'second']]);
        self::assertStringContainsString('nonce="second"', $this->collector->renderJs());
        self::assertCount(1, glob($this->directory.'/cache/*.json'));
    }

    public function testInlineCssBaseDirectoryAndCustomOutputPaths(): void
    {
        $this->requireSymlinks();
        file_put_contents($this->directory.'/resources/media/font.woff2', 'font');
        $processor = new AssetProcessor($this->directory, 'public', 'public/cache/processed', 'public/static/resources', 'cache', ['test' => $this->directory.'/resources'], '/my-app');
        $collector = new AssetCollector($processor);
        $collector->addCss('@font-face{src:url(../media/font.woff2)}', ['inline' => true, 'root' => 'test', 'base_dir' => 'resources/css']);
        $html = $collector->renderCss();
        self::assertStringContainsString('href="/my-app/cache/processed/css/', $html);
        $files = glob($this->directory.'/public/cache/processed/css/*.css');
        self::assertCount(1, $files);
        self::assertStringContainsString('../../../static/resources/hl-', file_get_contents($files[0]));
    }

    public function testOutsideRootIsRejected(): void
    {
        file_put_contents($this->directory.'/secret.js', 'secret');
        $this->collector->addJs('secret.js');
        $this->expectException(\InvalidArgumentException::class);
        $this->collector->renderJs();
    }

    public function testCssTraversalIsRejected(): void
    {
        file_put_contents($this->directory.'/secret.txt', 'secret');
        file_put_contents($this->directory.'/resources/css/main.css', 'a{background:url(../../secret.txt)}');
        $this->collector->addCss('resources/css/main.css');
        $this->expectException(\RuntimeException::class);
        $this->collector->renderCss();
    }

    public function testCircularImportFails(): void
    {
        file_put_contents($this->directory.'/resources/css/a.css', '@import "b.css";');
        file_put_contents($this->directory.'/resources/css/b.css', '@import "a.css";');
        $this->collector->addCss('resources/css/a.css');
        $this->expectException(\RuntimeException::class);
        $this->collector->renderCss();
    }

    public function testBundleConfigurationAndServiceWiring(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->directory);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', $this->directory.'/build');
        $bundle = new AssetBundle();
        $extension = $bundle->getContainerExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension('hl_assets', ['roots' => ['test' => $this->directory.'/resources']]);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->compile();
        self::assertTrue($container->hasDefinition(AssetCollector::class));
        self::assertTrue($container->getDefinition(AssetCollector::class)->hasTag('kernel.reset'));
        self::assertTrue($container->hasAlias(MinifierInterface::class));
    }

    public function testMinificationAndPerAssetOptOutPreserveOrder(): void
    {
        $collector = new AssetCollector($this->minifyingProcessor(new MatthiasMullieMinifier()));
        $collector->addJs("// remove this comment\nwindow.first = true;", ['inline' => true]);
        $collector->addJs("// keep this comment\nwindow.second = false;", ['inline' => true, 'minify' => false]);
        $collector->addJs("// remove this too\nwindow.third = true;", ['inline' => true, 'standalone' => true]);
        $files = $this->outputs($collector->renderJs());
        self::assertCount(3, $files);
        self::assertStringNotContainsString('remove this', file_get_contents($files[0]));
        self::assertStringContainsString('window.first', file_get_contents($files[0]));
        self::assertStringContainsString('// keep this comment', file_get_contents($files[1]));
        self::assertStringContainsString('window.third', file_get_contents($files[2]));
    }

    public function testCssMinificationPreservesPublishedResourceUrls(): void
    {
        $this->requireSymlinks();
        file_put_contents($this->directory.'/resources/media/image.jpg', 'image');
        $collector = new AssetCollector($this->minifyingProcessor(new MatthiasMullieMinifier()));
        $collector->addCss('/* remove */ .test { background: url(../media/image.jpg?x=1#fragment); color: #ffffff; }', [
            'inline' => true, 'base_dir' => 'resources/css',
        ]);
        $css = file_get_contents($this->outputs($collector->renderCss())[0]);
        self::assertStringNotContainsString('/* remove */', $css);
        self::assertStringNotContainsString('data:', $css);
        self::assertStringContainsString('../../_assets/hl-', $css);
        self::assertStringContainsString('/media/image.jpg?x=1&hlv=', $css);
        self::assertStringContainsString('#fragment', $css);
        self::assertStringContainsString('color:#fff', $css);
    }

    public function testMinifierOnlyRunsOnCacheMissAndItsVersionInvalidatesCache(): void
    {
        $minifier = new class implements MinifierInterface {
            public int $calls = 0;
            public string $version = 'first';
            public function minify(string $type, string $content): string
            {
                ++$this->calls;
                return $content.'/*'.$this->version.'*/';
            }
            public function getCacheKey(): string
            {
                return $this->version;
            }
        };
        $collector = new AssetCollector($this->minifyingProcessor($minifier));
        $collector->addJs('run();', ['inline' => true]);
        $first = $collector->renderJs();
        $collector->reset();
        $collector->addJs('run();', ['inline' => true]);
        self::assertSame($first, $collector->renderJs());
        self::assertSame(1, $minifier->calls);
        $minifier->version = 'second';
        $collector->reset();
        $collector->addJs('run();', ['inline' => true]);
        self::assertNotSame($first, $collector->renderJs());
        self::assertSame(2, $minifier->calls);
    }

    public function testExplicitOptInAndSeparateCssJsConfiguration(): void
    {
        $processor = $this->minifyingProcessor(new MatthiasMullieMinifier(), ['css' => true, 'js' => false]);
        $collector = new AssetCollector($processor);
        $collector->addJs("// keep\nfirst();", ['inline' => true]);
        $collector->addJs("// remove\nsecond();", ['inline' => true, 'minify' => true]);
        $files = $this->outputs($collector->renderJs());
        self::assertCount(2, $files);
        self::assertStringContainsString('// keep', file_get_contents($files[0]));
        self::assertStringNotContainsString('// remove', file_get_contents($files[1]));
        self::assertTrue($processor->shouldMinify('css'));
        self::assertFalse($processor->shouldMinify('js'));
    }

    public function testMinifierFailureDoesNotPublishPartialFilesOrCache(): void
    {
        $minifier = new class implements MinifierInterface {
            public function minify(string $type, string $content): string
            {
                throw new \RuntimeException('unsupported syntax');
            }
            public function getCacheKey(): string { return 'failing'; }
        };
        $collector = new AssetCollector($this->minifyingProcessor($minifier));
        $collector->addJs('run();', ['inline' => true]);
        try {
            $collector->renderJs();
            self::fail('Expected the minifier error to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Failed to minify js assets', $exception->getMessage());
        }
        self::assertSame([], glob($this->directory.'/cache/*.json'));
        self::assertSame([], glob($this->directory.'/public/processed/js/*.js'));
    }

    private function minifyingProcessor(MinifierInterface $minifier, array $configuration = ['css' => true, 'js' => true]): AssetProcessor
    {
        return new AssetProcessor($this->directory, 'public', 'public/processed', 'public/_assets', 'cache', ['test' => $this->directory.'/resources'], '', '1', $minifier, $configuration);
    }

    public function testExternalRoutePriorityDeduplicationAndAttributes(): void
    {
        $this->collector->addJs('app();', ['inline' => true]);
        $options = ['external' => true, 'library' => true, 'priority' => 100, 'attributes' => ['defer' => true]];
        $this->collector->addJs('/js/l10n?type=fe&locale=de', $options);
        $this->collector->addJs('/js/l10n?type=fe&locale=de', $options);
        $html = $this->collector->renderJs();
        self::assertStringStartsWith('<script src="/js/l10n?type=fe&amp;locale=de" defer></script>', $html);
        self::assertSame(2, substr_count($html, '<script '));
        self::assertCount(1, glob($this->directory.'/public/processed/js/*.js'));
        self::assertSame('', $this->collector->renderJs());
    }

    public function testExternalEntrySeparatesLocalBundlesWithoutReordering(): void
    {
        $this->collector->addJs('first();', ['inline' => true]);
        $this->collector->addJs('https://cdn.example.com/middle.js', ['external' => true]);
        $this->collector->addJs('last();', ['inline' => true]);
        $html = $this->collector->renderJs();
        $lines = explode("\n", $html);
        self::assertCount(3, $lines);
        self::assertStringContainsString('https://cdn.example.com/middle.js', $lines[1]);
        self::assertSame("first();\n", file_get_contents($this->outputs($lines[0])[0]));
        self::assertSame("last();\n", file_get_contents($this->outputs($lines[2])[0]));
    }

    public function testExternalCssAndModulesBypassProcessingEvenWhenMinificationIsEnabled(): void
    {
        $collector = new AssetCollector($this->minifyingProcessor(new MatthiasMullieMinifier()));
        $collector->addCss('//cdn.example.com/style.css', ['external' => true, 'attributes' => ['media' => 'print', 'integrity' => 'sha384-example', 'crossorigin' => 'anonymous']]);
        self::assertStringContainsString('href="//cdn.example.com/style.css"', $collector->renderCss());
        $collector->addJs('/js/module.js', ['external' => true, 'attributes' => ['type' => 'module']]);
        self::assertSame('<script src="/js/module.js" type="module"></script>', $collector->renderJs());
        self::assertDirectoryDoesNotExist($this->directory.'/cache');
        self::assertDirectoryDoesNotExist($this->directory.'/public/processed');
    }

    public function testExternalUrlsEscapeAttributeCharacters(): void
    {
        $this->collector->addJs('/js/test?q="quoted"&a=1', ['external' => true]);
        self::assertSame('<script src="/js/test?q=&quot;quoted&quot;&amp;a=1"></script>', $this->collector->renderJs());
    }

    public function testInvalidExternalOptionsAndUrlSchemesAreRejected(): void
    {
        foreach ([
            ['javascript:alert(1)', ['external' => true]],
            ['data:text/javascript,alert(1)', ['external' => true]],
            ["/js/\nfile.js", ['external' => true]],
            ['/js/file.js', ['external' => true, 'inline' => true]],
            ['/js/file.js', ['external' => true, 'minify' => true]],
        ] as [$source, $options]) {
            try {
                $this->collector->addJs($source, $options);
                self::fail('Expected invalid external asset to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    private function processor(): AssetProcessor
    {
        return new AssetProcessor($this->directory, 'public', 'public/processed', 'public/_assets', 'cache', ['test' => $this->directory.'/resources']);
    }

    private function outputs(string $html): array
    {
        preg_match_all('/(?:src|href)="([^"]+)"/', $html, $matches);

        return array_map(fn (string $url): string => $this->directory.'/public'.html_entity_decode($url), $matches[1]);
    }

    private function expire(string $path): void
    {
        $meta = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $meta['used'] = time() - 15000;
        file_put_contents($path, json_encode($meta, JSON_THROW_ON_ERROR));
    }

    private function requireSymlinks(): void
    {
        $probe = $this->directory.'/link-probe';
        if (!@symlink($this->directory.'/resources', $probe)) {
            self::markTestSkipped('This environment cannot create symlinks.');
        }
        unlink($probe);
    }
}
