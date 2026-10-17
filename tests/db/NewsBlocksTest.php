<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use DateInterval;

// Блоки вывода проверяются на живой базе: порядок зоны, предел выборки, правило видимости ленты,
// поведение «цели больше нет» и «раздел выключен» — свойства запросов, а не кода читателя.
final class NewsBlocksTest extends NewsDbTestCase
{
    public function testБлокиЗоныЧитятсяПоПорядкуИБезСкрытых(): void
    {
        self::makeBlock('home', 'list', 'Третий', ['order_index' => 30]);
        self::makeBlock('home', 'list', 'Первый', ['order_index' => 10]);
        self::makeBlock('home', 'list', 'Скрытый', ['order_index' => 20, 'visible' => 0]);
        self::makeBlock('category', 'list', 'Чужая зона', ['order_index' => 5]);

        self::assertSame(['Первый', 'Третий'], $this->titles(newsBlockRows('home')));
    }

    public function testВыборкаБлоковРежетсяПРЕДЕЛОМОМЗоны(): void
    {
        for ($n = 1; $n <= NEWS_BLOCK_PER_ZONE + 2; $n++) {
            self::makeBlock('home', 'list', sprintf('Блок %02d', $n), ['order_index' => $n]);
        }

        $rows = newsBlockRows('home');

        self::assertCount(NEWS_BLOCK_PER_ZONE, $rows);
        self::assertSame('Блок 06', (string) $rows[NEWS_BLOCK_PER_ZONE - 1]['title']);
    }

    public function testBlockFeedRowsЧититПравилаВидимостиЛенты(): void
    {
        $this->dated('na-sajte', 1);
        $this->dated('chernovik', 2, ['status' => NEWS_STATUS['DRAFT']]);
        $this->dated('zhdyot-sroka', 3, ['published_at' => utcStamp(nowUtc()->add(new DateInterval('PT2H')))]);

        self::assertSame(['na-sajte'], $this->slugs(blockFeedRows([], '', 5)));
    }

    public function testBlockFeedRowsИсключаетМатериалИРежетЧисло(): void
    {
        $this->dated('m-1', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('m-2', 2, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('m-3', 3, ['category_id' => categoryIdOf('metodika')]);

        // Порядок ленты — `published_at DESC`, значит из трёх материалов свежий m-1, затем m-2.
        self::assertSame(['m-2', 'm-3'], $this->slugs(blockFeedRows(['category' => 'metodika'], 'm-1', 2)));
    }

    public function testBlockFeedRowsОбщуюЛентуРежетПРЕДЕЛОМОМСтраницы(): void
    {
        for ($n = 1; $n <= NEWS_PER_PAGE + 2; $n++) {
            $this->dated(sprintf('m-%02d', $n), $n);
        }

        // Предел стоит в `takeLimit()`, а не в проверке формы: строка с завышенным числом,
        // попавшая в базу мимо редактора, не должна вытаскивать больше материалов, чем страница.
        self::assertCount(NEWS_PER_PAGE, blockFeedRows([], '', NEWS_PER_PAGE + 2));
    }

    public function testBlockFeedRowsМеткиСоединяетсяСТаблицейСвязей(): void
    {
        $tagId = resolveTagIds(['Урок чтения'])[0];

        $linked = $this->dated('s-urokom', 1);
        self::linkTag($linked, $tagId);
        $this->dated('bez-uroka', 2);

        self::assertSame(['s-urokom'], $this->slugs(blockFeedRows(['tag' => blockTargetSlug('tag', $tagId)], '', 5)));
    }

    public function testBlockFeedRowsФильтрРубрикиНеТрогаетСоседей(): void
    {
        $this->dated('v-metodike', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('v-zhizni', 2);

        self::assertSame(['v-metodike'], $this->slugs(blockFeedRows(['category' => 'metodika'], '', 5)));
    }

    public function testBlockFeedRowsСкрытойРубрикиДаётПустойСписок(): void
    {
        $this->dated('iz-postupleniya', 1, ['category_id' => categoryIdOf('postuplenie')]);
        $this->dated('v-zhizni', 2);
        run('UPDATE ' . t('news_category') . ' SET visible = 0 WHERE slug = ?', ['postuplenie']);

        self::assertSame([], $this->slugs(blockFeedRows(['category' => 'postuplenie'], '', 5)));
        self::assertSame(['v-zhizni'], $this->slugs(blockFeedRows([], '', 5)));
    }

    public function testNewsRegionHtmlПечатаетЗаголовокИКарточки(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('home', 'list', 'Свежие материалы');

        $html = newsRegionHtml('', 'home');

        self::assertStringContainsString('Свежие материалы', $html);
        self::assertStringContainsString('/news/novyy', $html);
    }

    public function testNewsRegionHtmlСохраняетВыводДругихПодписчиков(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('home', 'list', 'Свежие материалы');

        $html = newsRegionHtml('чужой вывод', 'home');

        self::assertStringStartsWith('чужой вывод', $html);
        self::assertStringContainsString('Свежие материалы', $html);
    }

    public function testNewsRegionHtmlБезБлоковВозвращаетВход(): void
    {
        $this->dated('novyy', 1);

        self::assertSame('до', newsRegionHtml('до', 'home'));
    }

    public function testNewsRegionHtmlНеПечатаетПустойБлок(): void
    {
        self::makeBlock('home', 'list', 'Свежие материалы');

        self::assertSame('', newsRegionHtml('', 'home'));
    }

    public function testДваБлокаЗоныПечатаютсяВПорядкеВыборки(): void
    {
        $this->dated('odinnadtsatyy', 1);
        self::makeBlock('home', 'list', 'Второй блок', ['order_index' => 20]);
        self::makeBlock('home', 'list', 'Первый блок', ['order_index' => 10]);

        $html = newsRegionHtml('', 'home');

        self::assertLessThan(
            (int) strpos($html, 'Второй блок'),
            (int) strpos($html, 'Первый блок'),
            'заголовки блоков идут в порядке выборки'
        );
    }

    public function testRelatedБерётРубрикуСтраницыИИсключаетЕёМатериал(): void
    {
        $this->dated('sam-material', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('sosed', 2, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('iz-drugoy', 3);
        self::makeBlock('article', 'related', 'Похожие материалы');

        $html = newsRegionHtml('', 'article', ['slug' => 'sam-material', 'category' => 'metodika']);

        self::assertStringContainsString('/news/sosed', $html);
        self::assertStringNotContainsString('/news/sam-material', $html);
        self::assertStringNotContainsString('/news/iz-drugoy', $html);
    }

    public function testRelatedБезРубрикиСтраницыМолчит(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('article', 'related', 'Похожие материалы');

        self::assertSame('', newsRegionHtml('', 'article', []));
    }

    public function testБлокСЦельюКоторойНетНеПечатаетОбщуюЛенту(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('home', 'list', 'Методика', ['target_kind' => 'category', 'target_id' => newId()]);

        self::assertSame('', newsRegionHtml('', 'home'));

        // Тот же исход у пропавшей метки: у цели два вида, и молчать обязан любой из них.
        self::makeBlock('home', 'list', 'Урок чтения', ['target_kind' => 'tag', 'target_id' => newId(), 'order_index' => 20]);

        self::assertSame('', newsRegionHtml('', 'home'));
    }

    public function testБлокМеткиЧитаетТолькоМетку(): void
    {
        $tagId = resolveTagIds(['Урок чтения'])[0];

        $linked = $this->dated('s-urokom', 1);
        self::linkTag($linked, $tagId);
        $this->dated('bez-uroka', 2);

        self::makeBlock('category', 'list', 'Читаем вслух', ['target_kind' => 'tag', 'target_id' => $tagId]);

        $html = newsRegionHtml('', 'category');

        self::assertStringContainsString('/news/s-urokom', $html);
        self::assertStringNotContainsString('/news/bez-uroka', $html);
    }

    public function testБлокПохожихИгнорируетЦельЕслиОнаЗатесалась(): void
    {
        // Редактор такую строку не создаст (правило Р9), но строка могла попасть в базу мимо него:
        // вид `related` определён как рубрика материала, и цель не имеет права менять его смысл.
        $this->dated('sam-material', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('sosed', 2, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('iz-drugoy', 3);
        self::makeBlock('article', 'related', 'Похожие материалы', [
            'target_kind' => 'category',
            'target_id' => categoryIdOf('postuplenie'),
        ]);

        $html = newsRegionHtml('', 'article', ['slug' => 'sam-material', 'category' => 'metodika']);

        self::assertStringContainsString('/news/sosed', $html);
        self::assertStringNotContainsString('/news/iz-drugoy', $html);
    }

    public function testБлокРубрикиЧитаетТолькоСвоюРубрику(): void
    {
        $this->dated('v-metodike', 1, ['category_id' => categoryIdOf('metodika')]);
        $this->dated('v-zhizni', 2);
        self::makeBlock('category', 'list', 'Только методика', [
            'target_kind' => 'category',
            'target_id' => categoryIdOf('metodika'),
        ]);

        $html = newsRegionHtml('', 'category');

        self::assertStringContainsString('/news/v-metodike', $html);
        self::assertStringNotContainsString('/news/v-zhizni', $html);
    }

    public function testNewsRegionHtmlМолчитКогдаРазделВыключен(): void
    {
        $this->dated('novyy', 1);
        self::makeBlock('home', 'list', 'Свежие материалы');
        run('UPDATE ' . t('app_installation') . " SET enabled = 0 WHERE id = 'news'");

        try {
            self::assertSame('', newsRegionHtml('', 'home'));
        } finally {
            run('UPDATE ' . t('app_installation') . " SET enabled = 1 WHERE id = 'news'");
        }
    }

    // ── Операции записи ──────────────────────────────────

    public function testНовыйБлокПолучаетСледующийПорядокВСвоейЗоне(): void
    {
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        self::assertTrue(createBlock('home', 'list', 'Методика', 4)['ok']);
        self::assertTrue(createBlock('article', 'related', 'Похожие материалы', 3)['ok']);

        // Порядок плотный внутри зоны: блок «article» не сдвигает нумерацию «home» и наоборот.
        self::assertSame('1', $this->orderOf('home', 'Свежие материалы'));
        self::assertSame('2', $this->orderOf('home', 'Методика'));
        self::assertSame('1', $this->orderOf('article', 'Похожие материалы'));
        self::assertSame(
            ['Свежие материалы', 'Методика'],
            array_column(newsBlockRows('home'), 'title'),
            'новый блок в конце зоны, и читатель отдаёт его последним'
        );
    }

    public function testСедьмойБлокВЗонеОтказывается(): void
    {
        for ($n = 1; $n <= NEWS_BLOCK_PER_ZONE; $n++) {
            self::assertTrue(createBlock('home', 'list', sprintf('Блок %02d', $n), 3)['ok'], 'Блок №' . $n . ' заводится');
        }

        $refusal = createBlock('home', 'list', 'Лишний', 3);

        self::assertFalse($refusal['ok']);
        self::assertSame('', (string) $refusal['notice'], 'Отказ не несёт ключа');
        self::assertStringContainsString((string) NEWS_BLOCK_PER_ZONE, (string) $refusal['error']);
        self::assertSame('6', $this->column('SELECT COUNT(*) FROM ' . t('news_block') . ' WHERE zone = ?', ['home']));
    }

    public function testСкрытыйБлокЗанимаетМестоВЗоне(): void
    {
        for ($n = 1; $n <= NEWS_BLOCK_PER_ZONE; $n++) {
            self::makeBlock('home', 'list', sprintf('Скрытый %02d', $n), ['visible' => 0, 'order_index' => $n]);
        }

        // Снятый с показа блок — всё ещё настройка зоны (§5): предел считается по строкам, а не
        // по числу блоков, которые страница печатает.
        self::assertFalse(createBlock('home', 'list', 'Ещё один', 3)['ok']);
    }

    public function testПределСчитаетсяВЗонеАНеНаВсёмСайте(): void
    {
        for ($n = 1; $n <= NEWS_BLOCK_PER_ZONE; $n++) {
            self::assertTrue(createBlock('home', 'list', sprintf('Блок %02d', $n), 3)['ok']);
        }

        // Зона `article` остаётся доступной: шесть блоков — это место в настройке одной точки
        // вывода, а не лимит строк таблицы.
        $result = createBlock('article', 'related', 'Похожие материалы', 3);
        self::assertTrue($result['ok'], 'Чужая зона не исчерпывается: ' . (string) $result['error']);
    }

    public function testБлокЗаписываетсяСЦельюРубрикиИМетки(): void
    {
        $categoryId = categoryIdOf('zhizn-shkoly');
        $tagId = resolveTagIds(['Урок чтения'])[0];

        self::assertTrue(createBlock('category', 'list', 'Только жизнь школы', 4, 'category', $categoryId)['ok']);
        self::assertTrue(createBlock('category', 'list', 'Читаем вслух', 3, 'tag', $tagId)['ok']);

        self::assertSame('category', $this->column('SELECT target_kind FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', ['category', 'Только жизнь школы']));
        self::assertSame($categoryId, $this->column('SELECT target_id FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', ['category', 'Только жизнь школы']));
        self::assertSame('tag', $this->column('SELECT target_kind FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', ['category', 'Читаем вслух']));
        self::assertSame($tagId, $this->column('SELECT target_id FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', ['category', 'Читаем вслух']));
    }

    public function testБлокБезЦелиХранитNullАНеПустуюСтроку(): void
    {
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        $id = $this->blockIdOf('home', 'Свежие материалы');

        // Схема (§2) объявляет обе колонки цели `NULL`: пустая строка — третье состояние, которого
        // экран задачи 8 не ожидает, и «вся лента» читается именно по NULL.
        self::assertSame('NULL', $this->column('SELECT COALESCE(target_kind, \'NULL\') FROM ' . t('news_block') . ' WHERE id = ?', [$id]));
        self::assertSame('NULL', $this->column('SELECT COALESCE(target_id, \'NULL\') FROM ' . t('news_block') . ' WHERE id = ?', [$id]));
    }

    public function testНесуществующаяЦельОтказывается(): void
    {
        foreach (['category', 'tag'] as $kind) {
            $result = createBlock('home', 'list', 'Методика', 4, $kind, newId());

            self::assertFalse($result['ok'], 'Цель ' . $kind . ', которой нет, не записывается');
            self::assertSame('Выбранной цели уже нет — обновите страницу.', (string) $result['error']);
        }

        self::assertSame('0', $this->column('SELECT COUNT(*) FROM ' . t('news_block')));
    }

    public function testЦельУПохожихБлокаОтказывается(): void
    {
        $result = createBlock('article', 'related', 'Похожие материалы', 5, 'category', categoryIdOf('metodika'));

        // «Похожие» берут рубрику того материала, на котором лежат (§5), и цель тут же — два
        // смысла одного вида блока.
        self::assertFalse($result['ok']);
        self::assertSame('У блока «похожие материалы» цели нет: он берёт рубрику того материала, на котором лежит.', (string) $result['error']);
    }

    public function testЧужаяЗонаИлиВидОтказываются(): void
    {
        // Строка из POST, которой нет в перечнях раздела, не записывается даже мимо редактора (§5).
        self::assertSame('Зоны с таким именем нет.', (string) createBlock('sidebar', 'list', 'Боковая колонка', 4)['error']);
        self::assertSame('Такого вида блока нет.', (string) createBlock('home', 'carousel', 'Карусель', 4)['error']);
        self::assertSame('0', $this->column('SELECT COUNT(*) FROM ' . t('news_block')));
    }

    public function testЧислоМатериаловПроверяетсяПриЗаписи(): void
    {
        // Просьба вне предела — отказ, а не тихое зажатие: человек должен увидеть, что форма
        // приняла другое число, чем он написал (§5).
        self::assertFalse(createBlock('home', 'list', 'Свежие материалы', 999)['ok']);
        self::assertFalse(createBlock('home', 'list', 'Свежие материалы', 0)['ok']);
        self::assertSame('0', $this->column('SELECT COUNT(*) FROM ' . t('news_block')));
    }

    public function testОдинаковыеЗаголовкиВЗонеЛегальны(): void
    {
        // Две «Свежие материалы» в одной зоне — легальная настройка (§5): блоки могут различаться
        // целью и пределом, а заголовок — только надпись для человека.
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 3)['ok']);

        self::assertSame('2', $this->column('SELECT COUNT(*) FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', ['home', 'Свежие материалы']));
    }

    public function testПравкаЗаголовкаОтказываетсяТемЖеТекстомЧтоИСоздание(): void
    {
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        $id = $this->blockIdOf('home', 'Свежие материалы');

        $byForm = checkBlockFields('home', 'list', 'а', 5);
        $byOp = renameBlock($id, 'а');

        self::assertFalse($byOp['ok']);
        // Один текст на одно правило: правка заголовка не несёт своей проверки длины.
        self::assertSame((string) $byForm['error'], (string) $byOp['error']);
        self::assertSame('Свежие материалы', $this->column('SELECT title FROM ' . t('news_block') . ' WHERE id = ?', [$id]), 'отказ ничего не переписывает');
    }

    public function testСменаЦелиБерётВидИзСтроки(): void
    {
        $categoryId = categoryIdOf('metodika');
        self::makeBlock('article', 'related', 'Похожие материалы');
        $id = $this->blockIdOf('article', 'Похожие материалы');

        $result = retargetBlock($id, 'category', $categoryId);

        self::assertFalse($result['ok'], 'Рубрику «похожему» блоку не выдают');
        self::assertSame('', (string) $result['notice']);
        self::assertSame('', $this->column('SELECT target_kind FROM ' . t('news_block') . ' WHERE id = ?', [$id]), 'цель осталась пустой');
    }

    public function testСнятаяЦельОбеКолонкиСтановятсяNull(): void
    {
        self::assertTrue(createBlock('category', 'list', 'Только методика', 4, 'category', categoryIdOf('metodika'))['ok']);
        $id = $this->blockIdOf('category', 'Только методика');

        self::assertSame('retargeted', (string) retargetBlock($id, '', '')['notice']);

        // Обе колонки разом: половину цели оставить нельзя, иначе читатель задачи 4 увидел бы
        // вид без идентификатора и печатал бы общую ленту под заголовком про рубрику.
        self::assertSame('NULL', $this->column('SELECT COALESCE(target_kind, \'NULL\') FROM ' . t('news_block') . ' WHERE id = ?', [$id]));
        self::assertSame('NULL', $this->column('SELECT COALESCE(target_id, \'NULL\') FROM ' . t('news_block') . ' WHERE id = ?', [$id]));
    }

    public function testЧислоМатериаловПравится(): void
    {
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        $id = $this->blockIdOf('home', 'Свежие материалы');

        self::assertSame('limited', (string) limitBlock($id, 3)['notice']);
        self::assertSame('3', $this->column('SELECT take_limit FROM ' . t('news_block') . ' WHERE id = ?', [$id]));

        self::assertFalse(limitBlock($id, 13)['ok'], 'за предел страницы — отказ, а не зажатие');
        self::assertSame('3', $this->column('SELECT take_limit FROM ' . t('news_block') . ' WHERE id = ?', [$id]), 'отказ не трогает принятое значение');
    }

    public function testПоказИСкрытиеБлока(): void
    {
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        $id = $this->blockIdOf('home', 'Свежие материалы');

        self::assertSame('hidden', (string) setBlockVisibility($id, false)['notice']);
        self::assertSame('[]', json_encode(array_column(newsBlockRows('home'), 'title'), JSON_THROW_ON_ERROR), 'скрытый блок не участвует в выдаче');

        // Направление берётся из намерения, а не из текущего состояния: двойное «скрыть» обязано
        // оставить блок скрытым, а не показать его (в отличие от рубрики, где кнопка одна — переключатель).
        self::assertSame('hidden', (string) setBlockVisibility($id, false)['notice']);
        self::assertSame('0', $this->column('SELECT visible FROM ' . t('news_block') . ' WHERE id = ?', [$id]));

        self::assertSame('shown', (string) setBlockVisibility($id, true)['notice']);
        self::assertSame(['Свежие материалы'], array_column(newsBlockRows('home'), 'title'));
    }

    public function testУдалениеБлока(): void
    {
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok']);
        $id = $this->blockIdOf('home', 'Свежие материалы');

        self::assertSame('deleted', (string) deleteBlock($id)['notice']);
        self::assertSame('', $this->column('SELECT id FROM ' . t('news_block') . ' WHERE id = ?', [$id]));

        // Блок не содержит контента, только настройку, и внешний ключ (§2, решение 8) ни за что
        // его не держит: удаление не требует подтверждения и не считает материалы.
        self::assertTrue(createBlock('home', 'list', 'Свежие материалы', 5)['ok'], 'освободившееся место занимает новый блок');
    }

    // ── Порядок в зоне ───────────────────────────────────

    public function testСтрелкаМеняетМестамиСоседейВСвоейЗоне(): void
    {
        // Заголовки идут обратно порядку: «О школе» по алфавиту раньше «Свежие материалы», поэтому
        // обход по заголовку дал бы другую перестановку и эту проверку покраснил.
        $readToday = self::makeBlock('home', 'list', 'Читают сегодня', ['order_index' => 2]);
        self::makeBlock('home', 'list', 'Свежие материалы', ['order_index' => 1]);
        self::makeBlock('home', 'list', 'О школе', ['order_index' => 3]);

        self::assertSame('moved', (string) moveBlock($readToday, 'up')['notice']);

        self::assertSame(
            ['Читают сегодня', 'Свежие материалы', 'О школе'],
            array_column(newsBlockRows('home'), 'title'),
            'средняя строка встала первой, сосед опустился на её место'
        );
        self::assertSame('1', $this->orderOf('home', 'Читают сегодня'));
        self::assertSame('2', $this->orderOf('home', 'Свежие материалы'));
        self::assertSame('3', $this->orderOf('home', 'О школе'));
        self::assertSame(
            '3',
            $this->column('SELECT COUNT(*) FROM ' . t('news_block') . ' WHERE zone = ?', ['home']),
            'движение не создаёт и не удаляет строк'
        );
    }

    public function testДвижениеНеТрогаетПорядокДругойЗоны(): void
    {
        self::makeBlock('home', 'list', 'Первый дома', ['order_index' => 1]);
        $secondHome = self::makeBlock('home', 'list', 'Второй дома', ['order_index' => 2]);
        self::makeBlock('article', 'related', 'Первый в карточке', ['order_index' => 1]);
        self::makeBlock('article', 'related', 'Второй в карточке', ['order_index' => 2]);

        self::assertSame('moved', (string) moveBlock($secondHome, 'up')['notice']);

        // Без фильтра по зоне пересборка проходила бы по всем строкам сайта: соседняя зона получила
        // бы порядки 3 и 4 и исчезла бы из своей выборки (§5 спецификации).
        self::assertSame(
            ['Первый в карточке', 'Второй в карточке'],
            array_column(newsBlockRows('article'), 'title'),
            'зона «article» не участвует в движении зоны «home»'
        );
        self::assertSame('1', $this->orderOf('article', 'Первый в карточке'));
        self::assertSame('2', $this->orderOf('article', 'Второй в карточке'));
    }

    public function testСкрытыйБлокУчаствуетВПорядкеЗоны(): void
    {
        self::makeBlock('home', 'list', 'Видимый первый', ['order_index' => 1]);
        $hidden = self::makeBlock('home', 'list', 'Скрытый', ['order_index' => 2, 'visible' => 0]);
        $third = self::makeBlock('home', 'list', 'Третий', ['order_index' => 3]);

        self::assertSame('moved', (string) moveBlock($third, 'up')['notice']);

        // Строка зоны считает и скрытых соседей: «вверх» передвигает на одну позицию, а не
        // перепрыгивает через скрытый блок.
        self::assertSame('2', $this->orderOf('home', 'Третий'));
        self::assertSame('3', $this->orderOf('home', 'Скрытый'));
        self::assertSame($hidden, $this->blockIdOf('home', 'Скрытый'), 'скрытая строка осталась собой');

        // Печать при этом знает только про видимые: порядок считается по зоне целиком, а читатель
        // отсекает скрытое (задача 4).
        self::assertSame(['Видимый первый', 'Третий'], array_column(newsBlockRows('home'), 'title'));
    }

    public function testОдиночнаяСтрокаЗоныНеДвигается(): void
    {
        $only = self::makeBlock('home', 'list', 'Единственный', ['order_index' => 1]);

        $up = moveBlock($only, 'up');
        self::assertTrue($up['ok'], 'край зоны — не отказ');
        self::assertSame('topped', (string) $up['notice']);
        self::assertSame('', (string) $up['error']);

        $down = moveBlock($only, 'down');
        self::assertTrue($down['ok'], 'край зоны — не отказ');
        self::assertSame('bottomed', (string) $down['notice']);

        self::assertSame('1', $this->orderOf('home', 'Единственный'), 'пометка не пишет базу');
    }

    public function testКрайниеСтрокиМногойЗоныВозвращаютПометку(): void
    {
        $top = self::makeBlock('home', 'list', 'Первый', ['order_index' => 1]);
        $bottom = self::makeBlock('home', 'list', 'Последний', ['order_index' => 2]);

        self::assertSame('topped', (string) moveBlock($top, 'up')['notice']);
        self::assertSame('bottomed', (string) moveBlock($bottom, 'down')['notice']);

        // Два разных ключа за одно и то же «ничего не произошло»: вверх и вниз человек видит
        // разные плашки и понимает, к какому краю он упёрся.
        self::assertSame(['Первый', 'Последний'], array_column(newsBlockRows('home'), 'title'));
        self::assertSame('1', $this->orderOf('home', 'Первый'));
        self::assertSame('2', $this->orderOf('home', 'Последний'));
    }

    public function testДвижениеПересобираетРазреженныйПорядокПлотно(): void
    {
        // Дубликаты и разрывы оставляла ручная правка базы. Тот же довод, что у `moveCategory()`:
        // пересборка чинит их целиком, а не меняет местами две строки.
        self::makeBlock('home', 'list', 'Первый', ['order_index' => 7]);
        self::makeBlock('home', 'list', 'Второй', ['order_index' => 7]);
        $third = self::makeBlock('home', 'list', 'Третий', ['order_index' => 12]);

        self::assertSame('moved', (string) moveBlock($third, 'up')['notice']);

        $orders = array_map(
            static fn (array $row): string => (string) $row['order_index'],
            all('SELECT order_index FROM ' . t('news_block') . ' WHERE zone = ? ORDER BY order_index', ['home'])
        );

        self::assertSame(['1', '2', '3'], $orders, 'в зоне плотность 1..N без разрывов и дубликатов');
    }

    public function testЧужойИдентификаторОтказываетсяОднимТекстом(): void
    {
        // Шесть операций правки, удаления и движения отвечают одной строкой: человек смотрит на
        // экран, где строку уже не видно, и ему важно понять «обнови страницу», а не различать
        // шесть формулировок.
        $results = [
            renameBlock('net-takogo-identifikatora', 'Новое название'),
            retargetBlock('net-takogo-identifikatora', 'category', categoryIdOf('metodika')),
            limitBlock('net-takogo-identifikatora', 3),
            setBlockVisibility('net-takogo-identifikatora', true),
            deleteBlock('net-takogo-identifikatora'),
            moveBlock('net-takogo-identifikatora', 'up'),
        ];

        foreach ($results as $index => $result) {
            self::assertFalse($result['ok'], 'Операция №' . $index . ' не удаётся');
            self::assertSame('', (string) $result['notice'], 'Операция №' . $index . ' не несёт ключа');
            self::assertSame('Блока уже нет — обновите страницу.', (string) $result['error']);
        }
    }

    private function blockIdOf(string $zone, string $title): string
    {
        return $this->column('SELECT id FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', [$zone, $title]);
    }

    private function orderOf(string $zone, string $title): string
    {
        return $this->column('SELECT order_index FROM ' . t('news_block') . ' WHERE zone = ? AND title = ?', [$zone, $title]);
    }

    /**
     * Опубликованный материал с заданным возрастом: порядок ленты задаётся моментом публикации,
     * поэтому он подставляется в каждой вставке, а не остаётся NULL.
     *
     * @param array<string, mixed> $overrides
     */
    private function dated(string $slug, int $hoursAgo, array $overrides = []): string
    {
        return self::makeItem(categoryIdOf('zhizn-shkoly'), $slug, array_merge([
            'status' => NEWS_STATUS['PUBLISHED'],
            'published_at' => utcStamp(nowUtc()->sub(new DateInterval('PT' . $hoursAgo . 'H'))),
        ], $overrides));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function titles(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['title'], $rows);
    }

    // ── Строки экрана ────────────────────────────────────

    public function testСтрокиЭкранаИдутВПорядкеЗоныАНеПоАлфавиту(): void
    {
        self::makeBlock('home', 'list', 'Январь', ['order_index' => 30]);
        self::makeBlock('home', 'list', 'Август', ['order_index' => 10]);
        self::makeBlock('category', 'list', 'Рубричный', ['order_index' => 20]);

        // Порядок экрана — тот же, что печатает страница: человек правит стрелками именно ту
        // последовательность, которую читатель отдаёт читателю.
        self::assertSame(['Рубричный', 'Август', 'Январь'], $this->titles(adminBlockRows()));
    }

    public function testЭкранПоказываетИСкрытыеСтроки(): void
    {
        self::makeBlock('home', 'list', 'Видимый', ['order_index' => 10]);
        $hiddenId = self::makeBlock('home', 'list', 'Скрытый', ['order_index' => 20, 'visible' => 0]);

        $rows = adminBlockRows();

        self::assertCount(2, $rows, 'Снятый с сайта блок обязан остаться в админке: иначе его не вернуть');
        self::assertSame($hiddenId, $rows[1]['id']);
        self::assertFalse($rows[1]['visible']);
    }

    public function testСтрокаНесётВсеПоляКоторыеЧитаетШаблон(): void
    {
        $id = self::makeBlock('article', 'related', 'Похожие чтения', ['order_index' => 4, 'take_limit' => 3]);

        self::assertSame(
            [[
                'id' => $id,
                'zone' => 'article',
                'kind' => 'related',
                'title' => 'Похожие чтения',
                'take' => 3,
                'visible' => true,
                'order' => 4,
                'target' => null,
            ]],
            adminBlockRows()
        );
    }

    public function testПодписьРубрикиСледуетЗаПереименованием(): void
    {
        $categoryId = categoryIdOf('zhizn-shkoly');
        self::makeBlock('category', 'list', 'Из рубрики', ['target_kind' => 'category', 'target_id' => $categoryId]);

        self::assertSame('Жизнь школы', adminBlockRows()[0]['target']['label']);

        self::assertTrue(renameCategory($categoryId, 'Жизнь класса')['ok']);

        self::assertSame(
            'Жизнь класса',
            adminBlockRows()[0]['target']['label'],
            'Название цели экран берёт из справочника, а не из копии в своей строке'
        );
    }

    public function testМеткаПодписываетсяСвоейНадписью(): void
    {
        [$tagId] = resolveTagIds(['Выпускник']);
        self::makeBlock('home', 'list', 'По метке', ['target_kind' => 'tag', 'target_id' => $tagId]);

        $target = adminBlockRows()[0]['target'];

        self::assertSame('tag', $target['kind']);
        self::assertSame('Выпускник', $target['label']);
        self::assertTrue($target['alive']);
    }

    public function testБлокБезЦелиНесётПустуюЦель(): void
    {
        self::makeBlock('home', 'list', 'Вся лента');

        self::assertNull(adminBlockRows()[0]['target']);
    }

    public function testУдалённаяЦельПомечаетсяНедоступной(): void
    {
        self::assertTrue(createCategory('Праздники')['ok']);
        $categoryId = $this->column('SELECT id FROM ' . t('news_category') . ' WHERE title = ?', ['Праздники']);
        self::makeBlock('home', 'list', 'Сирота', ['target_kind' => 'category', 'target_id' => $categoryId]);

        self::assertTrue(deleteCategory($categoryId)['ok'], 'Рубрика без материалов удаляется');

        $target = adminBlockRows()[0]['target'];

        // Цели больше нет, но строка остаётся и говорит об этом (§5): читатель задачи 4 отдаёт по
        // такой строке общую ленту, и редактор обязан видеть, что подборка съехала.
        self::assertSame($categoryId, $target['id']);
        self::assertSame('', $target['label']);
        self::assertFalse($target['alive']);
    }

    public function testПолуразрушеннаяЦельНеИсчезаетСоСтроки(): void
    {
        // Ручная правка базы способна оставить вид цели без идентификатора. Такая строка
        // показывается мёртвой: молча считать её «блоком без цели» — значит спрятать поломку.
        self::makeBlock('home', 'list', 'Наполовину', ['target_kind' => 'category', 'target_id' => null]);

        $target = adminBlockRows()[0]['target'];

        self::assertIsArray($target);
        self::assertFalse($target['alive']);
    }

    public function testОпцииЦелиСоставныеИГруппированыПоСловарю(): void
    {
        $categoryId = categoryIdOf('zhizn-shkoly');
        [$tagId] = resolveTagIds(['Выпускник']);

        $groups = blockTargetOptions();

        // Две группы в порядке NEWS_BLOCK_TARGETS: шаблон печатает из них `<optgroup>`, и третья
        // цель появилась бы в формах сама, без правки разметки.
        self::assertSame(['category', 'tag'], array_column($groups, 'kind'));
        self::assertSame(['Рубрики', 'Теги'], array_column($groups, 'label'));
        self::assertContains('category:' . $categoryId, array_column($groups[0]['items'], 'value'));
        self::assertContains('tag:' . $tagId, array_column($groups[1]['items'], 'value'));
    }

    public function testСкрытаяРубрикаОстаётсяВОпцияхЦели(): void
    {
        self::assertTrue(createCategory('Праздники')['ok']);
        $categoryId = $this->column('SELECT id FROM ' . t('news_category') . ' WHERE title = ?', ['Праздники']);
        self::assertTrue(toggleCategoryVisibility($categoryId)['ok']);

        $found = null;

        foreach (blockTargetOptions()[0]['items'] as $item) {
            if ($item['value'] === 'category:' . $categoryId) {
                $found = $item;
            }
        }

        self::assertNotNull($found, 'Снятие рубрики с показа не убирает её из выбора: блок на неё уже настроен');
        self::assertFalse($found['visible'], 'Скрытая рубрика помечена — шаблон печатает слово «скрыта»');
    }

    public function testПодделаннаяЦельНеПроходитЧерезПисателя(): void
    {
        $target = parseBlockTarget('shop:9');

        $result = createBlock('home', 'list', 'Подделка', 3, $target['kind'], $target['id']);

        self::assertFalse($result['ok']);
        self::assertSame('', $result['notice'], 'Отказ не несёт ключа уведомления');
        self::assertSame(0, (int) value('SELECT COUNT(*) FROM ' . t('news_block')));
    }

    /**
     * @param list<array<string, mixed>> $cards
     * @return list<string>
     */
    private function slugs(array $cards): array
    {
        return array_map(static fn (array $card): string => (string) $card['slug'], $cards);
    }
}
