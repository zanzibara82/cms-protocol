<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/core/seo.php';

/**
 * Разметка Schema.org в ядре: JSON должен быть разбираемым и безопасным.
 */
final class JsonLdTest extends TestCase
{
    public function testРазметкаСобираетсяВСкрипт(): void
    {
        $script = jsonLdScript(['@context' => 'https://schema.org', 'name' => 'Велосипед']);

        self::assertStringStartsWith('<script type="application/ld+json">', $script);
        self::assertStringEndsWith('</script>', $script);

        $json = substr($script, strlen('<script type="application/ld+json">'), -strlen('</script>'));
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertSame('https://schema.org', $decoded['@context']);
        self::assertSame('Велосипед', $decoded['name']);
    }

    public function testТекстНеМожетЗакрытьТегСкрипта(): void
    {
        $script = jsonLdScript(['name' => '</script><script>alert(1)</script>']);

        self::assertStringNotContainsString('</script><script>', $script);
        self::assertStringContainsString('<', $script);
        self::assertStringNotContainsString('</script><script>', $script);

        $json = substr($script, strlen('<script type="application/ld+json">'), -strlen('</script>'));
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertSame('</script><script>alert(1)</script>', $decoded['name']);
    }

    public function testГрафИзНесколькихОбъектов(): void
    {
        $script = jsonLdGraphScript([
            ['@type' => 'Product', 'name' => 'Товар'],
            ['@type' => 'BreadcrumbList', 'itemListElement' => []],
        ]);

        self::assertStringContainsString('"@graph"', $script);
        self::assertStringContainsString('Product', $script);
        self::assertStringContainsString('BreadcrumbList', $script);
    }

    public function testПустойГрафНеПечатаетСкрипт(): void
    {
        self::assertSame('', jsonLdGraphScript([]));
    }
}
