<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

// Метаданные раздела — чистые функции: материал на входе, строка разметки на выходе. База и HTTP
// им не нужны, а ошибка здесь видна только в чужом мессенджере, поэтому правила проверяются отдельно.
final class NewsSeoTest extends TestCase
{
    /** Моменты в UTC: moscowIso() прибавляет три часа, и «09:00» становится «12:00+03:00». */
    private const PUBLISHED = '2026-10-07 09:00:00';
    private const UPDATED = '2026-10-08 15:30:00';

    /**
     * Материал в том виде, в котором его отдаёт toFull(): метаданные читают готовые ключи,
     * а не строку базы.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'id' => 'id-1',
            'slug' => 'vecher-vstrechi',
            'title' => 'Вечер встречи',
            'lead' => 'Короткий лид про вечер.',
            'cover' => '',
            'status' => NEWS_STATUS['PUBLISHED'],
            'open' => true,
            'href' => '/news/vecher-vstrechi',
            'publishedAt' => new DateTimeImmutable(self::PUBLISHED, new DateTimeZone('UTC')),
            'updatedAt' => new DateTimeImmutable(self::UPDATED, new DateTimeZone('UTC')),
            'date' => '7 октября 2026',
            'updated' => '8 октября 2026, 18:30',
            'categorySlug' => 'zhizn-shkoly',
            'categoryTitle' => 'Жизнь школы',
            'paragraphs' => [['title' => '', 'text' => 'Текст абзаца.']],
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => '',
            'noindex' => false,
            'tags' => [['slug' => 'vypusknik', 'label' => 'Выпускник', 'href' => '/news/t/vypusknik']],
        ], $overrides);
    }

    public function testЗаголовокВложеннойСтраницыНесётНазваниеРаздела(): void
    {
        self::assertSame('Жизнь школы · Новости школы', newsTitle('Жизнь школы'));
    }

    public function testПустоеПолеПоисковикаЗаменяетсяТекстомМатериала(): void
    {
        $seo = seoOf($this->item(), NEWS_SITE['description']);

        self::assertSame('Вечер встречи', $seo['title'], 'заголовок берётся из материала');
        self::assertSame('Короткий лид про вечер.', $seo['description'], 'описание — из лида');
    }

    public function testПолеПоисковикаВажнееТекстаМатериала(): void
    {
        $seo = seoOf($this->item([
            'seoTitle' => 'Вечер встречи выпускников',
            'seoDescription' => 'Своё описание для сниппета.',
        ]), NEWS_SITE['description']);

        self::assertSame('Вечер встречи выпускников', $seo['title']);
        self::assertSame('Своё описание для сниппета.', $seo['description']);
    }

    public function testБезЛидаОписаниеБерётсяИзНазначенияРаздела(): void
    {
        $seo = seoOf($this->item(['lead' => '   ']), NEWS_SITE['description']);

        self::assertSame(NEWS_SITE['description'], $seo['description']);
    }

    public function testДлинныйЛидОбрезаетсяДоДвухсотЗнаков(): void
    {
        $seo = seoOf($this->item(['lead' => str_repeat('а', 260)]), NEWS_SITE['description']);

        self::assertSame(201, mb_strlen($seo['description']), '200 знаков и многоточие');
    }

    public function testВписанноеОписаниеОтдаётсяЦеликом(): void
    {
        $seo = seoOf($this->item(['seoDescription' => str_repeat('о', 260)]), NEWS_SITE['description']);

        self::assertSame(260, mb_strlen($seo['description']), 'редактор вписал двести шестьдесят — столько и отдаём');
    }

    public function testКартинкаПревьюБерётсяИзОбложкиЕслиСвоейНет(): void
    {
        /** @var array<string, array{0: array<string, mixed>, 1: string}> $cases */
        $cases = [
            'своя картинка важнее обложки' => [$this->item(['ogImage' => '/uploads/og.png', 'cover' => '/uploads/cover.png']), '/uploads/og.png'],
            'обложка вместо своей' => [$this->item(['cover' => '/uploads/cover.png']), '/uploads/cover.png'],
            'без картинок — фирменная' => [$this->item([]), NEWS_OG_IMAGE],
            'пробелы не считаются картинкой' => [$this->item(['ogImage' => '  ', 'cover' => ' ']), NEWS_OG_IMAGE],
        ];

        foreach ($cases as $label => [$item, $expected]) {
            self::assertSame($expected, ogImageOf($item), $label);
        }
    }

    public function testРазметкаСпискаЭтоПревьюСайта(): void
    {
        $html = listHeadTags('Новости школы', 'Описание раздела', '/news');

        foreach ([
            'тип — сайт' => '<meta property="og:type" content="website">',
            'адрес' => '<meta property="og:url" content="http://localhost:8080/news">',
            'каноник' => '<link rel="canonical" href="http://localhost:8080/news">',
            'картинка' => '<meta property="og:image" content="http://localhost:8080' . NEWS_OG_IMAGE . '">',
            'размер' => '<meta property="og:image:width" content="1200">',
            'карточка твиттера' => '<meta name="twitter:card" content="summary_large_image">',
        ] as $label => $needle) {
            self::assertStringContainsString($needle, $html, $label);
        }

        self::assertStringNotContainsString('article', $html, 'у списка нет разметки статьи');
    }

    public function testРазметкаСтатьиНесётМосковскоеВремяРубрикуИТеги(): void
    {
        $html = articleHeadTags($this->item(), '/news/vecher-vstrechi', true);

        foreach ([
            'тип — статья' => '<meta property="og:type" content="article">',
            'срок публикации' => '<meta property="article:published_time" content="2026-10-07T12:00:00+03:00">',
            'время правки' => '<meta property="article:modified_time" content="2026-10-08T18:30:00+03:00">',
            'рубрика' => '<meta property="article:section" content="Жизнь школы">',
            'тег' => '<meta property="article:tag" content="Выпускник">',
            'каноник' => '<link rel="canonical" href="http://localhost:8080/news/vecher-vstrechi">',
        ] as $label => $needle) {
            self::assertStringContainsString($needle, $html, $label);
        }
    }

    public function testУЧерновикаПревьюПоказываетПравкуВместоПубликации(): void
    {
        $html = articleHeadTags($this->item([
            'status' => NEWS_STATUS['DRAFT'],
            'open' => false,
            'publishedAt' => null,
            'date' => '—',
        ]), '/news/vecher-vstrechi', false);

        self::assertStringContainsString(
            '<meta property="article:published_time" content="2026-10-08T18:30:00+03:00">',
            $html,
            'черновик показываем по последней правке'
        );
        self::assertStringNotContainsString('1970', $html, 'пустого срока в разметке нет');
    }

    public function testЗначенияПревьюЭкранируются(): void
    {
        $html = articleHeadTags($this->item(['title' => 'Вечер "<кот>" & друзья']), '/news/vecher-vstrechi', true);

        self::assertStringContainsString('content="Вечер &quot;&lt;кот&gt;&quot; &amp; друзья"', $html);
        self::assertStringNotContainsString('<кот>', $html);
    }

    public function testОписаниеСтатьиДляПоисковикаСобираетсяИзМатериала(): void
    {
        $ld = newsArticleLd(
            $this->item(),
            '/news/vecher-vstrechi',
            'Вечер встречи',
            'Короткий лид про вечер.',
            'http://localhost:8080' . NEWS_OG_IMAGE
        );

        self::assertSame('NewsArticle', $ld['@type']);
        self::assertSame('https://schema.org', $ld['@context']);
        self::assertSame('http://localhost:8080/news/vecher-vstrechi', $ld['@id']);
        self::assertSame('2026-10-07T12:00:00+03:00', $ld['datePublished']);
        self::assertSame('2026-10-08T18:30:00+03:00', $ld['dateModified']);
        self::assertSame('Жизнь школы', $ld['articleSection']);
        self::assertSame('Выпускник', $ld['keywords']);
        self::assertSame('ru-RU', $ld['inLanguage']);
        self::assertTrue($ld['isAccessibleForFree']);
        self::assertSame('Organization', $ld['author']['@type'], 'автор — школа, а не почта редактора');
        // Ключи JSON-LD («@type», «@id») всегда несут «@», поэтому почту ищут только значения:
        // адрес редактора не должен просочиться ни в одно из них (§7.4).
        foreach ((array) $ld['author'] as $value) {
            self::assertStringNotContainsString('@', (string) $value, 'почты в разметке нет');
        }
    }

    public function testБлокРазметкиНеЗакрываетсяТекстомМатериала(): void
    {
        $html = jsonLdTag(['@type' => 'NewsArticle', 'headline' => 'Разбор </script> и <b>тегов</b>']);

        self::assertStringStartsWith('<script type="application/ld+json">', $html);
        self::assertStringEndsWith('</script>', $html);
        self::assertSame(1, substr_count($html, '</script>'), 'один закрывающий тег на всю страницу');
        self::assertStringNotContainsString('</script> и', $html);
    }

    public function testБезТеговКлючевыхСловНет(): void
    {
        $ld = newsArticleLd($this->item(['tags' => []]), '/news/vecher-vstrechi', 'Вечер', 'Лид', 'x.png');

        self::assertArrayNotHasKey('keywords', $ld);
    }
}
