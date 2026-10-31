<?php

declare(strict_types=1);

namespace Cms\Tests\db;

use Cms\Tests\TestDb;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Операции записи слоя меню на живой базе: вставка пункта в конец слота, правка полей без смены
 * слота, перестановка с пересборкой порядка (в том числе при одинаковых sort), видимость,
 * удаление и читатели пустого слота. Таблица — ядро, приложение не ставят: TestDb::reset()
 * применяет все релизы sql/. Ссылки разделов из publicNav проверяет http-тест публичного рендера
 * (включённость приложений — механика живого HTTP, не этой базы).
 */
final class MenuTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestDb::reset();
    }

    protected function setUp(): void
    {
        run('DELETE FROM ' . t('menu_item'));
    }

    /**
     * Точка входа в операции через положительное создание: тест пошёл дальше — строка есть.
     *
     * @param array<string, mixed> $raw поля формы: label, href, section, blank
     */
    private function makeItem(string $slot, array $raw, ?DateTimeImmutable $now = null): string
    {
        $created = createMenuItem($slot, $raw, $now);

        self::assertTrue($created['ok'], $created['error']);

        return (string) $created['id'];
    }

    /**
     * Порядок слота списком подписей: перестановка гарантирована не по id, а по порядку чтения.
     *
     * @return list<string>
     */
    private function slotLabels(string $slot): array
    {
        $rows = all(
            'SELECT label FROM ' . t('menu_item') . ' WHERE slot = ? ORDER BY sort ASC, label ASC LIMIT 100',
            [$slot]
        );

        return array_map(static fn (array $row): string => (string) $row['label'], $rows);
    }

    /**
     * Порядок слота списком sort по идентификаторам: пересборка обязана дать подряд 0..n.
     *
     * @param list<string> $ids
     * @return list<int>
     */
    private function slotSorts(array $ids): array
    {
        $sorts = [];
        foreach ($ids as $id) {
            $sorts[] = (int) value('SELECT sort FROM ' . t('menu_item') . ' WHERE id = ?', [$id]);
        }

        return $sorts;
    }

    public function testСозданиеПунктаПишетСтрокуВКонецСлота(): void
    {
        $yesterday = nowUtc()->modify('-1 day');

        $first = createMenuItem('HEADER', ['label' => 'О школе', 'href' => '/o-shkole', 'blank' => '1', 'section' => 'Не для шапки'], $yesterday);

        self::assertTrue($first['ok'], $first['error']);
        self::assertSame('menu_created', $first['notice']);
        self::assertNotSame('', $first['id']);

        $row = one(
            'SELECT slot, section, label, href, blank, sort, visible, updated_at FROM ' . t('menu_item') . ' WHERE id = ?',
            [$first['id']]
        );

        self::assertNotNull($row);
        self::assertSame('HEADER', (string) $row['slot']);
        // Раздел — подпись колонки подвала: у строки шапки он уходит молча (решение 2 дизайна).
        self::assertSame('', (string) $row['section']);
        self::assertSame('О школе', (string) $row['label']);
        self::assertSame('/o-shkole', (string) $row['href']);
        self::assertSame('1', (string) $row['blank']);
        self::assertSame('1', (string) $row['visible']);
        // Первый пункт слота — порядок нулевой (перечитывание max+1), метка правки — переданный момент.
        self::assertSame(0, (int) $row['sort']);
        self::assertSame(utcStamp($yesterday), (string) $row['updated_at']);

        $second = createMenuItem('HEADER', ['label' => 'Контакты', 'href' => '/kontakty'], nowUtc());

        self::assertTrue($second['ok'], $second['error']);
        self::assertSame(1, (int) value('SELECT sort FROM ' . t('menu_item') . ' WHERE id = ?', [$second['id']]));

        // Подвал берёт своё: раздел остаётся в строке подписью колонки.
        $footerId = $this->makeItem('FOOTER', ['label' => 'Курсы', 'href' => '/kursy', 'section' => 'Обучение']);

        self::assertSame(
            'Обучение',
            (string) value('SELECT section FROM ' . t('menu_item') . ' WHERE id = ?', [$footerId])
        );

        // Отказ строки не оставляет мусора: ни кривого слота, ни пустой подписи, ни кривого адреса.
        $wrongSlot = createMenuItem('wormhole', ['label' => 'Кривой слот', 'href' => '/ok']);

        self::assertFalse($wrongSlot['ok']);
        self::assertSame('', $wrongSlot['id']);

        foreach ([
            ['label' => '', 'href' => '/ok'],
            ['label' => 'Кривой адрес', 'href' => 'javascript:alert(1)'],
        ] as $broken) {
            $refused = createMenuItem('FOOTER', $broken);

            self::assertFalse($refused['ok']);
            self::assertNotSame('', $refused['error'], 'Отказ обязан объясняться по-человечески');
            self::assertSame('', $refused['id']);
        }

        self::assertSame(1, (int) value('SELECT COUNT(*) FROM ' . t('menu_item') . ' WHERE slot = ?', ['FOOTER']));
    }

    public function testПравкаПунктаМеняетПоляНоНеСлотПорядокВидимость(): void
    {
        $id = $this->makeItem('FOOTER', ['label' => 'Старое', 'href' => '/staryj', 'section' => 'Обучение'], nowUtc()->modify('-1 day'));

        $saved = updateMenuItem($id, [
            // Чужой слот формы игнорируется: пункт не переезжает между картами сохранением.
            'slot' => 'HEADER',
            'label' => 'Переименованный',
            'href' => '/novyj',
            'section' => 'Программы',
            'blank' => '',
        ], nowUtc());

        self::assertTrue($saved['ok'], $saved['error']);
        self::assertSame('menu_saved', $saved['notice']);

        $row = one('SELECT slot, section, label, href, blank, sort, visible, updated_at FROM ' . t('menu_item') . ' WHERE id = ?', [$id]);

        self::assertNotNull($row);
        self::assertSame('FOOTER', (string) $row['slot']);
        self::assertSame('Программы', (string) $row['section']);
        self::assertSame('Переименованный', (string) $row['label']);
        self::assertSame('/novyj', (string) $row['href']);
        // Пустая флаговая строка читается «включено»: unchecked-чекбокс приходит ровно как '0'.
        self::assertSame('1', (string) $row['blank']);
        self::assertSame('0', (string) $row['sort']);
        self::assertSame('1', (string) $row['visible']);
        // Метка перезаписана сегодняшним моментом, а не вчерашним от создания.
        self::assertSame(utcStamp(nowUtc()), (string) $row['updated_at']);

        // Несуществующая строка и кривая правка отказывают по-человечески и ничего не трогают.
        $ghost = updateMenuItem('net-takogo', ['label' => 'Призрак', 'href' => '/ok']);

        self::assertFalse($ghost['ok']);
        self::assertSame('Строка меню исчезла — обновите список.', $ghost['error']);

        $refused = updateMenuItem($id, ['label' => 'Кривое', 'href' => 'ftp://files.example.com']);

        self::assertFalse($refused['ok']);
        self::assertNotSame('', $refused['error']);
        self::assertSame(
            'Переименованный',
            (string) value('SELECT label FROM ' . t('menu_item') . ' WHERE id = ?', [$id]),
            'Кривая правка не переписала строку'
        );
    }

    public function testПерестановкаПересобираетПорядокИОтказываетНаКраях(): void
    {
        $firstId = $this->makeItem('HEADER', ['label' => 'Первый', 'href' => '/pervyj']);
        $secondId = $this->makeItem('HEADER', ['label' => 'Второй', 'href' => '/vtoroj']);
        $this->makeItem('FOOTER', ['label' => 'Подвальный', 'href' => '/podval']);

        // Кривое направление — отказ без перемен в списке.
        $bad = moveMenuItem($firstId, 'sideways');

        self::assertFalse($bad['ok']);
        self::assertSame('Направление перестановки — «вверх» или «вниз».', $bad['error']);

        // Перестановка вверх: сосед уходит вниз, порядок пересобирается подряд 0..n.
        $moved = moveMenuItem($secondId, 'up');

        self::assertTrue($moved['ok'], $moved['error']);
        self::assertSame('menu_moved', $moved['notice']);
        self::assertSame(['Второй', 'Первый'], $this->slotLabels('HEADER'));
        self::assertSame([0, 1], $this->slotSorts([$secondId, $firstId]), 'Порядок пересобран подряд, без дыр');

        // Края: у верхнего и нижнего соседа больше нет — отказ, список не трогается.
        $topEdge = moveMenuItem($secondId, 'up');

        self::assertFalse($topEdge['ok']);
        self::assertSame('Пункт уже крайний.', $topEdge['error']);

        $bottomEdge = moveMenuItem($firstId, 'down');

        self::assertFalse($bottomEdge['ok']);
        self::assertSame('Пункт уже крайний.', $bottomEdge['error']);
        self::assertSame(['Второй', 'Первый'], $this->slotLabels('HEADER'), 'Отказ края не перемешал список');

        // Посторонний слот не задет, призрак отказывает человеческой причиной.
        self::assertSame(['Подвальный'], $this->slotLabels('FOOTER'));

        $ghost = moveMenuItem('net-takogo', 'up');

        self::assertFalse($ghost['ok']);
        self::assertSame('Строка меню исчезла — обновите список.', $ghost['error']);
    }

    public function testПерестановкаПриРавныхSortПересобираетПорядокСНуля(): void
    {
        $firstId = $this->makeItem('FOOTER', ['label' => 'Первый', 'href' => '/pervyj']);
        $secondId = $this->makeItem('FOOTER', ['label' => 'Второй', 'href' => '/vtoroj']);
        $thirdId = $this->makeItem('FOOTER', ['label' => 'Третий', 'href' => '/tretij']);

        // Сида с одинаковыми sort (форсируем прямым UPDATE): чтение слота всё равно
        // детерминировано — порядок идёт по sort, затем по подписи.
        run('UPDATE ' . t('menu_item') . ' SET sort = 7 WHERE slot = ?', ['FOOTER']);
        self::assertSame(7, (int) value('SELECT sort FROM ' . t('menu_item') . ' WHERE id = ?', [$firstId]));

        self::assertSame(['Второй', 'Первый', 'Третий'], $this->slotLabels('FOOTER'));

        // «Второй» стоит первым — двигаем вниз: перестановка не опирается на числа sort,
        // а перечитывает список слота, делает splice и пишет индексы подряд 0..n.
        $moved = moveMenuItem($secondId, 'down');

        self::assertTrue($moved['ok'], $moved['error']);
        self::assertSame(['Первый', 'Второй', 'Третий'], $this->slotLabels('FOOTER'));
        self::assertSame([0, 1, 2], $this->slotSorts([$firstId, $secondId, $thirdId]), 'Пересборка заменила ровные 7 на порядок 0..n');
    }

    public function testВидимостьУдалениеИЧитателиПустогоСлота(): void
    {
        // Пустые читатели: день-1 без пунктов сайт отдаёт пустые списки, а не строки-заглушки.
        self::assertSame([], menuHeaderLinks());
        self::assertSame([], menuFooterSections());
        self::assertCount(0, menuRowsForAdmin('HEADER'));
        self::assertCount(0, menuRowsForAdmin('wormhole'), 'Чужой слот пуст — пунктов не видно');

        $headerId = $this->makeItem('HEADER', ['label' => 'Уровни', 'href' => '#urovni', 'blank' => '1']);
        $contactId = $this->makeItem('FOOTER', ['label' => 'Контакты', 'href' => '/kontakty']);
        $this->makeItem('FOOTER', ['label' => 'Курсы', 'href' => '/kursy', 'section' => 'Обучение']);

        // Шапка: подпись, адрес и флаг «в новом окне» приходят булевым.
        self::assertSame([['label' => 'Уровни', 'href' => '#urovni', 'blank' => true]], menuHeaderLinks());

        // Подвал: колонки в порядке первого пункта, пустая подпись — запасная «Ссылки».
        self::assertSame([
            ['title' => 'Ссылки', 'links' => [['label' => 'Контакты', 'href' => '/kontakty', 'blank' => false]]],
            ['title' => 'Обучение', 'links' => [['label' => 'Курсы', 'href' => '/kursy', 'blank' => false]]],
        ], menuFooterSections());

        // Скрытие: пункт остаётся в базе и на карте конструктора, но публика его не видит.
        $hidden = toggleMenuItem($contactId);

        self::assertTrue($hidden['ok'], $hidden['error']);
        // Скрытие делит ключ с сохранением: и то и другое — сохранение пункта (решение 7).
        self::assertSame('menu_saved', $hidden['notice']);
        self::assertSame('0', (string) value('SELECT visible FROM ' . t('menu_item') . ' WHERE id = ?', [$contactId]));

        self::assertSame([['title' => 'Обучение', 'links' => [['label' => 'Курсы', 'href' => '/kursy', 'blank' => false]]]], menuFooterSections());

        $adminRows = menuRowsForAdmin('FOOTER');

        self::assertCount(2, $adminRows, 'Скрытый пункт остаётся виден конструктору');
        self::assertSame('0', (string) $adminRows[0]['visible']);

        $again = toggleMenuItem($contactId);

        self::assertTrue($again['ok'], $again['error']);
        self::assertSame('1', (string) value('SELECT visible FROM ' . t('menu_item') . ' WHERE id = ?', [$contactId]));

        $ghost = toggleMenuItem('net-takogo');

        self::assertFalse($ghost['ok']);
        self::assertSame('Строка меню исчезла — обновите список.', $ghost['error']);

        // Удаление: строка уходит целиком, повторное удаление отвечает отказом по-человечески.
        $deleted = deleteMenuItem($contactId);

        self::assertTrue($deleted['ok'], $deleted['error']);
        self::assertSame('menu_deleted', $deleted['notice']);
        self::assertNull(one('SELECT id FROM ' . t('menu_item') . ' WHERE id = ?', [$contactId]));
        self::assertSame(['Курсы'], $this->slotLabels('FOOTER'));

        $secondDelete = deleteMenuItem($contactId);

        self::assertFalse($secondDelete['ok']);
        self::assertSame('Строка меню исчезла — обновите список.', $secondDelete['error']);

        // Шапка не пустеет от чужих удалений: «Уровни» на месте.
        $headerRows = menuRowsForAdmin('HEADER');

        self::assertCount(1, $headerRows);
        self::assertSame($headerId, (string) $headerRows[0]['id']);
    }
}
