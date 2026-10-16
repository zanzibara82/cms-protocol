<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use DateInterval;

// Обратная сторона договора: не только каждое действие обязано вернуть известный ключ, но и
// каждый ключ словаря обязано найти. Мёртвый текст — это обещание, которое экран не держит,
// и читается оно хуже отсутствующего.
//
// Подстановка NewsDbTestCase даёт каждой проверке три рубрики закладки и пустые справочники:
// достигаемость читается по порядку строк в таблице, а он зависит от числа рубрик.
final class NewsNoticeCoverageTest extends NewsDbTestCase
{
    private const EDITOR = ['id' => 'redaktor-4', 'email' => 'redaktor@shkola.ru'];

    /** @var array<string, string> ключ → результат, который его вернул */
    private array $catalogHits = [];

    /** @var array<string, string> */
    private array $itemHits = [];

    /** @var array<string, string> */
    private array $blockHits = [];

    public function testКаждыйТекстСправочниковДостигаетсяДействием(): void
    {
        $this->catalog(createCategory('Проба'), 'created');
        $id = categoryIdOf('proba');
        self::assertNotSame('', $id, 'Рубрика создаётся транслитом из названия');

        $this->catalog(renameCategory($id, 'Проба вторая'), 'renamed', $id);
        $this->catalog(updateCategoryDescription($id, 'Проба вторая', 'Описание'), 'described', $id);
        $this->catalog(toggleCategoryVisibility($id), 'hidden', $id);
        $this->catalog(toggleCategoryVisibility($id), 'shown', $id);

        // Крайние строки: `topped` и `bottomed` возвращаются только реальной первой и реальной
        // последней строкой списка, поэтому их берут из adminCategoryRows(), а не по имени.
        $first = categoryIdOf('zhizn-shkoly');
        $this->catalog(moveCategory($first, 'up'), 'topped', $first);
        $this->catalog(moveCategory($first, 'down'), 'moved', $first);

        $last = $this->lastCategoryId();
        $this->catalog(moveCategory($last, 'down'), 'bottomed', $last);
        $this->catalog(moveCategory($last, 'up'), 'moved', $last);
        $this->catalog(deleteCategory($id), 'deleted', $id);

        // Тег рождает только форма материала (§8.4), поэтому его заводят созданной строкой,
        // а перед удалением снимают с материала: занятого тега deleteTag() не трогает.
        $item = $this->item(createItem($this->input(['slug' => 'dlya-tega']), self::EDITOR, nowUtc()), 'created');
        $tag = $this->tagId();
        $this->catalog(renameTag($tag, 'Пробный тег'), 'tagRenamed', $tag);
        $this->item(updateItem($item, $this->input(['slug' => 'dlya-tega', 'tags' => []]), self::EDITOR, nowUtc()), 'updated', $item);
        $this->catalog(deleteTag($tag), 'tagDeleted', $tag);

        self::assertSame(
            [],
            array_diff(array_keys(CATALOG_NOTICE_TEXT), array_keys($this->catalogHits)),
            'Эти тексты справочников не достигаются ни одним действием'
        );
    }

    public function testКаждыйТекстМатериалаДостигаетсяДействием(): void
    {
        $first = $this->item(createItem($this->input(['slug' => 'pervyj']), self::EDITOR, nowUtc()), 'created');
        $this->item(updateItem($first, $this->input(['slug' => 'pervyj', 'lead' => 'Новый лид.']), self::EDITOR, nowUtc()), 'updated', $first);

        // Новый адрес при том же статусе: прежняя строка уходит в перенаправления, и человек
        // обязан узнать об этом плашкой, а не молча потерять ссылку из закладок (§8.3).
        $this->item(updateItem($first, $this->input(['slug' => 'pervoj-vtoroj']), self::EDITOR, nowUtc()), 'slugChanged', $first);

        $this->item(setItemStatus($first, NEWS_STATUS['PUBLISHED']), 'published', $first);
        $this->item(setItemStatus($first, NEWS_STATUS['DRAFT']), 'unpublished', $first);
        $this->item(setItemStatus($first, NEWS_STATUS['DRAFT']), 'unchanged', $first);

        // Закрепление просят у материала, который уже на сайте (isOpen()), поэтому строку
        // сначала снова выпускают — на черновике кнопка обязана отказать, а не вернуть ключ.
        $this->item(setItemStatus($first, NEWS_STATUS['PUBLISHED']), 'published', $first);
        $this->item(setItemPinned($first, true), 'pinned', $first);
        $this->item(setItemPinned($first, false), 'unpinned', $first);

        // «Ждёт срока» даёт createItem() с будущим publishedAt: третий аргумент setItemStatus() —
        // это «сейчас», а не дата выпуска, и подставить будущий срок им нельзя.
        $this->item(
            createItem(
                $this->input([
                    'slug' => 'chetvertyj',
                    'status' => NEWS_STATUS['PUBLISHED'],
                    'publishedAt' => nowUtc()->add(new DateInterval('PT1H')),
                ]),
                self::EDITOR,
                nowUtc()
            ),
            'scheduled'
        );

        self::assertSame(
            [],
            array_diff(array_keys(NEWS_NOTICE_TEXT), array_keys($this->itemHits)),
            'Эти тексты материала не достигаются ни одним действием'
        );
    }

    public function testОтказНикогдаНеНесётКлючаУведомления(): void
    {
        // Прямое направление договора: успех называет ключ из словаря (его проверяют
        // $this->catalog() и $this->item()), отказ — не называет никакого, иначе экран печатал
        // бы плашку «Рубрика добавлена» там, где ничего не добавилось.
        $draft = $this->item(createItem($this->input(['slug' => 'otkaz']), self::EDITOR, nowUtc()), 'created');

        foreach ([
            renameCategory('net-takogo-identifikatora', 'Новое название'),
            updateCategoryDescription('net-takogo-identifikatora', 'Новое название', ''),
            toggleCategoryVisibility('net-takogo-identifikatora'),
            moveCategory('net-takogo-identifikatora', 'up'),
            deleteCategory('net-takogo-identifikatora'),
            renameTag('net-takogo-identifikatora', 'Новый тег'),
            deleteTag('net-takogo-identifikatora'),
            setItemStatus('net-takogo-identifikatora', NEWS_STATUS['PUBLISHED']),
            setItemPinned('net-takogo-identifikatora', true),
            // Отказ по состоянию, а не по идентификатору: черновик не закрепляют (isOpen()).
            setItemPinned($draft, true),
            // Блоки: тот же договор, что у справочников и материалов — отказ не называет ключ.
            createBlock('sidebar', 'list', 'Чужая зона', 5),
            createBlock('home', 'carousel', 'Чужой вид', 5),
            renameBlock('net-takogo-identifikatora', 'Новое название'),
            retargetBlock('net-takogo-identifikatora', 'category', categoryIdOf('metodika')),
            limitBlock('net-takogo-identifikatora', 3),
            setBlockVisibility('net-takogo-identifikatora', true),
            deleteBlock('net-takogo-identifikatora'),
        ] as $index => $result) {
            self::assertFalse($result['ok'], 'Отказ №' . $index . ' обязан не удаваться');
            self::assertSame('', (string) $result['notice'], 'Отказ №' . $index . ' не несёт ключа');
            self::assertNotSame('', (string) $result['error'], 'Отказ №' . $index . ' называет причину');
        }
    }

    public function testКаждыйТекстБлокаДостигаетсяДействием(): void
    {
        $categoryId = categoryIdOf('zhizn-shkoly');
        $tagId = resolveTagIds(['Пробный тег'])[0];

        $this->block(createBlock('home', 'list', 'Свежие материалы', 5), 'created');
        $id = $this->blockIdOf('home', 'Свежие материалы');

        $this->block(renameBlock($id, 'Свежие за неделю'), 'renamed', $id);
        $this->block(retargetBlock($id, 'category', $categoryId), 'retargeted', $id);
        $this->block(retargetBlock($id, 'tag', $tagId), 'retargeted', $id);
        $this->block(retargetBlock($id, '', ''), 'retargeted', $id);
        $this->block(limitBlock($id, 3), 'limited', $id);
        $this->block(setBlockVisibility($id, false), 'hidden', $id);
        $this->block(setBlockVisibility($id, true), 'shown', $id);
        $this->block(deleteBlock($id), 'deleted', $id);

        // Три ключа порядка (`moved`, `topped`, `bottomed`) приносят стрелки в задаче 7. Список
        // назван, а не оставлен пустым: если задача 6 добавит в словарь текст, который не
        // достигает ни одна её операция, проверка красна уже сейчас — мёртвый плашечный ключ не
        // должен переждать одну задачу.
        self::assertSame(
            ['moved', 'topped', 'bottomed'],
            array_values(array_diff(array_keys(NEWS_BLOCK_NOTICE_TEXT), array_keys($this->blockHits))),
            'Эти тексты блока достигаются только стрелками — они приходят в задаче 7'
        );
    }

    /**
     * @param array{ok: bool, notice: string, error: string} $result
     *
     * @return array{ok: bool, notice: string, error: string}
     */
    private function catalog(array $result, string $expect, string $id = ''): array
    {
        self::assertTrue($result['ok'], $expect . ' / ' . $id . ': ' . (string) $result['error']);
        self::assertSame($expect, (string) $result['notice'], $expect . ' / ' . $id);
        self::assertArrayHasKey($expect, CATALOG_NOTICE_TEXT, 'Ключ есть в словаре?');

        $this->catalogHits[$expect] = $id;

        return $result;
    }

    /** @param array{ok: bool, notice: string, error: string, id: string, slug: string} $result */
    private function item(array $result, string $expect, string $id = ''): string
    {
        self::assertTrue($result['ok'], $expect . ' / ' . $id . ': ' . (string) $result['error']);
        self::assertSame($expect, (string) $result['notice'], $expect . ' / ' . $id);
        self::assertArrayHasKey($expect, NEWS_NOTICE_TEXT, $expect . ': ключ обязан быть в словаре');

        $this->itemHits[$expect] = $id;

        return (string) $result['id'];
    }

    private function blockIdOf(string $zone, string $title): string
    {
        return $this->column('SELECT id FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', [$zone, $title]);
    }

    /** @param array{ok: bool, notice: string, error: string} $result */
    private function block(array $result, string $expect, string $id = ''): void
    {
        self::assertTrue($result['ok'], $expect . ' / ' . $id . ': ' . (string) $result['error']);
        self::assertSame($expect, (string) $result['notice'], $expect . ' / ' . $id);
        self::assertArrayHasKey($expect, NEWS_BLOCK_NOTICE_TEXT, $expect . ': ключ обязан быть в словаре раздела');

        $this->blockHits[$expect] = $id;
    }

    private function lastCategoryId(): string
    {
        $rows = adminCategoryRows();

        return (string) $rows[count($rows) - 1]['id'];
    }

    private function tagId(): string
    {
        $rows = adminTagRows();
        self::assertNotSame([], $rows, 'Тег обязан появиться из формы материала');

        return (string) $rows[0]['id'];
    }

    /**
     * Набор полей для `createItem()`/`updateItem()` — тот же, что отдаёт parseNewsForm() (задача
     * 12) в ключе `data`.
     *
     * Статус по умолчанию — черновик: Published-строка на создании вернула бы `published` или
     * `scheduled` вместо `created`, и тест терял бы ровно тот переход, который проверяет.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function input(array $overrides): array
    {
        return array_merge([
            'title' => 'Пробный материал',
            'slug' => 'probnyj',
            'lead' => 'Коротко.',
            'body' => [['title' => '', 'text' => 'Абзац.']],
            'categoryId' => categoryIdOf('zhizn-shkoly'),
            'tags' => ['проба'],
            'status' => NEWS_STATUS['DRAFT'],
            'publishedAt' => null,
            'pinned' => false,
            'seoTitle' => '',
            'seoDescription' => '',
            'ogImage' => null,
            'noindex' => false,
        ], $overrides);
    }
}
