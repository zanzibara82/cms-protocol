<?php

declare(strict_types=1);

namespace Cms\Tests\guard;

use PHPUnit\Framework\TestCase;

// Резервная копия — второй после выкладки сценарий, где ошибка стоит не сломанной страницы,
// а потерянных данных, и при этом обнаруживается она ровно в тот день, когда копия нужна.
// Чек-лист выкладки требовал «mysqldump должен создаваться регулярно и храниться вне сервера»,
// но требовал прозой: руками этот пункт не выполняется, а неверный скрипт хуже отсутствующего —
// он выглядит как бэкап. Поэтому страж читает `scripts/backup.sh` и отвечает на пять вопросов,
// у каждого из которых есть цена на боевом сервере.
final class BackupSafetyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const SCRIPT = 'scripts/backup.sh';

    public function testКаталогКопийВнеДокументКорня(): void
    {
        $sh = $this->withoutCommentLines($this->read(self::SCRIPT));

        // Копия базы в public/ — это весь сайт на скачивание по прямой ссылке: почты, хеши
        // паролей, тексты обращений. Образец `*/public/*` эту дыру пропускал на относительном
        // пути (`public/backups` без слэша перед словом), поэтому проверяется не образец в коде,
        // а исполнение: скрипт обязан отвергать относительный и абсолютный путь внутри public/.
        self::assertMatchesRegularExpression(
            '/case "\$OUT_DIR\/" in/',
            $sh,
            'сравнение каталога копий обязано идти по пути с завершающим слэшем: иначе /app/public2 не отличить от /app/public/'
        );

        self::assertMatchesRegularExpression(
            '/"\$REPO\/public\/"\*/',
            $sh,
            'запрет каталога копий обязан опираться на абсолютный путь проекта, а не на кусок строки из аргумента'
        );

        // `cd … && pwd` схлопывает `..` и относительные формы до сравнения. Без этого
        // `./x/../public/bk` проходил проверку строкой и оказывался в документ-корне.
        self::assertMatchesRegularExpression(
            '/OUT_DIR="\$\(cd "\$OUT_DIR" && pwd\)"/',
            $sh,
            'путь каталога копий обязан нормализоваться до сравнения с документ-корнем'
        );
    }

    public function testПарольБазыНеПопадаетВАргументыИСписокПроцессов(): void
    {
        $sh = $this->withoutCommentLines($this->read(self::SCRIPT));

        // Пароль в командной строке виден всем, кто смотрит список процессов сервера, а cron
        // пишет команду в журнал. `--defaults-extra-file` — единственный способ передать его
        // mysqldump, не выставляя наружу.
        self::assertStringContainsString(
            '--defaults-extra-file="$defaults"',
            $sh,
            'пароль обязан передаваться mysqldump файлом настроек, а не ключом --password'
        );
        self::assertStringNotContainsString(
            '--password=',
            $sh,
            'ключ --password в командной строке виден в списке процессов'
        );

        // Временный файл с паролем обязан исчезнуть по любому выходу, включая отказ: иначе он
        // остаётся в /tmp и следующий прогон дописывает поверх чужое.
        self::assertMatchesRegularExpression(
            '/trap cleanup EXIT/',
            $sh,
            'временный файл настроек обязан удаляться по выходу — и при успехе, и при отказе'
        );
        self::assertMatchesRegularExpression(
            '/umask\(0077\)/',
            $sh,
            'файл с паролем создаётся при закрытом umask: он не должен быть читаем другим даже мгновение'
        );
    }

    public function testОборваннаяКопияНеСчитаетсяГотовой(): void
    {
        $sh = $this->withoutCommentLines($this->read(self::SCRIPT));

        // mysqldump пишет итог последней строкой. Копия без него — это оборванный файл: кончилось
        // место, сняли процесс, отвалилась сеть. Такой файл опаснее отсутствующего ровно тем, что
        // выглядит как резервная копия, а обнаруживается при восстановлении.
        self::assertMatchesRegularExpression(
            "/grep -q '\^-- Dump completed'/",
            $sh,
            'скрипт обязан проверять строку-итог дампа: без неё оборванная копия выглядит готовой'
        );

        // Копия без таблиц — дамп пустой базы или чужой: она тоже не должна уехать в backup/.
        self::assertMatchesRegularExpression(
            "/grep -c '\^CREATE TABLE'/",
            $sh,
            'скрипт обязан считать таблицы в копии: ноль таблиц означает не ту базу'
        );

        // Итог пишется в файл `.part` и переименовывается только после проверок: недописанный
        // файл не должен носить имя готовой копии ни мгновения.
        self::assertMatchesRegularExpression(
            '/partial="\$OUT_DIR\/\.cms-\$\{stamp\}\.sql\.part"/',
            $sh,
            'копия обязана писаться во временное имя и переименовываться после проверок'
        );
        self::assertMatchesRegularExpression(
            '/mv -f -- "\$partial" "\$target"/',
            $sh,
            'имя готовой копии присваивается только после проверок целостности'
        );
    }

    public function testСтарыеКопииУдаляютсяСЧисломАНеПодстановкой(): void
    {
        $sh = $this->withoutCommentLines($this->read(self::SCRIPT));

        // Ротация — единственное место, которое удаляет файлы. Значение берётся числом и
        // проверяется на число: опечатка в переменной окружения не должна превращаться в
        // «удалить всё» (пустое значение) или в подстановку пути из CMS_BACKUP_KEEP.
        self::assertMatchesRegularExpression(
            '/KEEP="\$\{CMS_BACKUP_KEEP:-7\}"/',
            $sh,
            'число хранимых копий обязано иметь значение по умолчанию'
        );
        self::assertMatchesRegularExpression(
            '/\[\[ "\$KEEP" =~ \^\[0-9\]\+\$ \]\]/',
            $sh,
            'число хранимых копий обязано проверяться на целое — иначе путь из окружения уедет в rm'
        );

        // Удаляются только файлы, названные скриптом: `ls cms-*.sql`, а не всё содержимое каталога.
        // Иначе соседняя копия другого сайта, положенная в тот же backup/, исчезла бы молча.
        self::assertMatchesRegularExpression(
            '/ls -1t "\$OUT_DIR"\/cms-\*\.sql/',
            $sh,
            'ротация обязана удалять только свои копии по образцу имени'
        );

        // Порог не даёт ротации исчезнуть молча: без вызова rm старые копии копятся, пока не
        // кончится место, и однажды mysqldump падает на записи.
        self::assertMatchesRegularExpression(
            '/rm -f -- "\$old"/',
            $sh,
            'старые копии обязаны удаляться, иначе место на сервере кончится'
        );
    }

    public function testЧекЛистНазываетСкриптВместоРучногоПункта(): void
    {
        // Проверка не косметическая: пункт «mysqldump должен создаваться регулярно» без имени
        // скрипта невыполним — человек не знает, чем. Сверяется раздел мониторинга чек-листа.
        $checklist = $this->read('docs/deploy-checklist.md');

        self::assertStringContainsString(
            'scripts/backup.sh',
            $checklist,
            'чек-лист обязан называть скрипт копий: иначе пункт остаётся ручным и невыполнимым'
        );

        // И обязан говорить, что копия на том же сервере резервом не является: иначе бэкап
        // «есть» ровно до отказа самого сервера.
        self::assertMatchesRegularExpression(
            '/вне сервера/',
            $checklist,
            'чек-лист обязан требовать хранения копии вне сервера'
        );
    }

    private function read(string $relative): string
    {
        $path = self::ROOT . '/' . $relative;
        self::assertFileExists($path, $relative . ': файл обязан существовать');

        $code = file_get_contents($path);
        self::assertIsString($code);

        return str_replace("\r\n", "\n", $code);
    }

    // Строки-пояснения не должны засчитывать барьер: строка `--password=`, переписанная в
    // комментарий, ничего не выставляет наружу. Тот же приём, что и в DeploySafetyTest.
    private function withoutCommentLines(string $code): string
    {
        $kept = [];
        foreach (explode("\n", $code) as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                continue;
            }

            $kept[] = $line;
        }

        return implode("\n", $kept);
    }
}
