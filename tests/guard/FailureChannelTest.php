<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Причина сбоя — текст драйвера, базы или filesystem: в адресной строке он светился бы в истории
// браузера и в access-логе хостинга, а экран печатал бы чужую строку из `?error=`. Разрешённый канал
// один: сессионный flash `admin_error` + ключ словаря `migration-failed` в адресе.
//
// Страж стережёт канал, а не имена ключей: `redirectWithNotice($to, $notice)` — законный вызов,
// переменная с ключом живёт в обработчике, и за её содержание отвечает NoticeKeyTest. Попытка
// проверить здесь и тип аргумента даёт ложные красные строки на легальном коде ядра.
//
// Страж читает те же корни, что и остальные проверки исходников (core, admin, theme, apps, public):
// проверка по всему дереву краснела бы на собственных тестовых фикстурах.
final class FailureChannelTest extends TestCase
{
    /** @var list<string> */
    private const ROOTS = ['core', 'admin', 'theme', 'apps', 'public'];

    /** @return array<string, string> паттерн => что он делает не так */
    private function forbidden(): array
    {
        return [
            '/queryString\(\s*([\'"])error\1/i' => 'читает причину отказа из адресной строки',
            '/queryAppend\(\s*[^,()]+,\s*([\'"])error\1/i' => 'пишет причину отказа в адресную строку',
            '/[\'"][^\'"]*\?error=/i' => 'склеивает отказ с адресом вручную',
        ];
    }

    public function testНиОдинИсходникНеПереводитПричинуОтказаЧерезАдрес(): void
    {
        $files = $this->phpFiles();
        // Пустой список — сломанный поиск, а не «нарушений нет»: без этой строки шаг зелёный и при
        // переезде дерева, и при сбое прав доступа.
        self::assertNotSame([], $files, 'Ни одного PHP-файла не найдено — страж проверяет пустоту');

        $violations = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach ($this->forbidden() as $pattern => $what) {
                if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $violations[] = $this->relative($file) . ': ' . $what . ' — ' . trim($match[0]);
                    }
                }
            }
        }

        self::assertSame([], $violations, 'Причина отказа уехала в адресную строку');
    }

    // Обратная половина инварианта: flash-канал обязан оставаться включённым. Если его выбросить,
    // первая проверка пройдёт молча — в адресе отказа нет, но экрану показывать будет нечего.
    public function testЯдроПишетПричинуОтказаВСессионныйFlash(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/core/routes.php');

        self::assertMatchesRegularExpression(
            '/\$_SESSION\[\s*[\'"]admin_error[\'"]\s*\]\s*=/',
            $routes,
            'finishAppAction() больше не пишет причину в flash — экран админки останется без объяснения'
        );
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $out = [];

        foreach (self::ROOTS as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }

            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($items as $item) {
                if ($item instanceof \SplFileInfo && $item->getExtension() === 'php') {
                    $out[] = $item->getPathname();
                }
            }
        }

        sort($out);

        return $out;
    }

    private function relative(string $file): string
    {
        return str_replace(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR, '', $file);
    }
}
