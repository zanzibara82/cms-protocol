<?php

declare(strict_types=1);

// Иерархия шаблонов из спецификации §5.6: приложение → тема → ядро → 404. Тема добавляет вёрстку,
// не трогая код приложения; каталог темы можно перекрыть настройкой EXTRA_THEME_DIR (её
// используют тесты, чтобы не создавать файлы в настоящей теме).
/** @return list<string> */
function themeRoots(): array
{
    $root = dirname(__DIR__);
    $extra = (string) configValue('EXTRA_THEME_DIR', '');

    return $extra !== '' ? [$extra, $root . '/theme'] : [$root . '/theme'];
}

function resolveTemplate(string $template): string
{
    // «..» и обратный слэш вырезаются: имя шаблона приходит из кода, но лишний барьер не мешает.
    $name = str_replace(['..', '\\'], '', $template);
    $parts = explode('/', $name);
    $root = dirname(__DIR__);

    $candidates = [];
    if (count($parts) >= 2) {
        $candidates[] = $root . '/apps/' . $parts[0] . '/templates/' . implode('/', array_slice($parts, 1)) . '.php';
    }
    foreach (themeRoots() as $theme) {
        $candidates[] = rtrim($theme, '/') . '/' . $name . '.php';
    }
    $candidates[] = $root . '/core/templates/' . $name . '.php';

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return $root . '/theme/404.php';
}

// partial() возвращает строку: её можно собрать в переменную и передать в layout, не смешивая
// вывод с данными.
/** @param array<string, mixed> $data */
function partial(string $template, array $data = []): string
{
    $file = resolveTemplate($template);

    ob_start();
    (static function (string $__file, array $__scope): void {
        /** @var array<string, mixed> $__scope */
        extract($__scope, EXTR_SKIP);
        require $__file;
    })($file, $data);

    return (string) ob_get_clean();
}

// render() печатает страницу целиком: шаблон внутри layout, метаданные из $page.
/**
 * @param array<string, mixed> $data
 * @param array{title?: string, description?: string, noindex?: bool, head?: string} $page
 */
function render(string $template, array $data = [], array $page = []): void
{
    $content = partial($template, $data);

    $title = (string) ($page['title'] ?? (string) configValue('SITE_NAME', 'Сайт'));
    $description = (string) ($page['description'] ?? '');
    $noindex = (bool) ($page['noindex'] ?? false);

    ob_start();
    doAction('page_head');
    // Порядок: сначала вывод хука page_head, затем значение $page['head'] — читающий каскад видит их в этом порядке.
    // $page['head'] — сырой HTML по тому же правилу, что и вывод хука: его передаёт только первичный код
    // (ядро и приложения), никогда пользовательский ввод.
    $head = (string) ob_get_clean() . (string) ($page['head'] ?? '');

    ob_start();
    doAction('page_footer');
    $footer = (string) ob_get_clean();

    // Пакет V, находка 3 аудита просмотра: страница, помеченная `noindex`, запрещает кэширование своим
    // заголовком, а не значением `session.cache_limiter` по умолчанию. На хостинге со своей php.ini, где
    // этот лимитер выставлен в `public` или оставлен пустым, HTML админки, входа и установщика стал бы
    // кэшируемым, и метка `noindex` не спасла бы — она описывает индексирование, а не хранение ответа.
    // Вызов `header()` с таким же именем заменяет значение, поэтому наш заголовок перекрывает тот,
    // что прислал лимитер.
    if ($noindex && !headers_sent()) {
        header('Cache-Control: no-store');
    }

    require dirname(__DIR__) . '/theme/layout.php';
}

// Страницы отказа задают статус и заголовки в respond(), поэтому им нужна собранная строка, а не
// печать: renderToString() — тот же render() с тем же каркасом темы, только вывод возвращается,
// а не печатается, и уходит в respond() единственным каналом ответа.
/**
 * @param array<string, mixed> $data
 * @param array{title?: string, description?: string, noindex?: bool, head?: string} $page
 */
function renderToString(string $template, array $data = [], array $page = []): string
{
    ob_start();
    render($template, $data, $page);

    return (string) ob_get_clean();
}

// Настоящие размеры картинки из публичного каталога: [ширина, высота] либо [0, 0], если файл не
// читается. Шаблоны считают от них height, а не приравнивают его к width: браузер по честным
// размерам заранее занимает под картинку место. Кэш живёт до конца процесса, а не до конца запроса:
// php -S и FPM обслуживают несколько запросов одним процессом, а файл за это время меняется только
// при сборке ассетов.
/** @return array{0: int, 1: int} */
function imageNaturalSize(string $relative): array
{
    /** @var array<string, array{0: int, 1: int}> $sizes */
    static $sizes = [];

    if (isset($sizes[$relative])) {
        return $sizes[$relative];
    }

    $public = realpath(__DIR__ . '/../public');
    // Путь опрашивается внутри публичного каталога: `..` и абсолютный адрес наружу не выводят, а
    // отсутствующий каталог означает «не читается», а не чужой файл вместо картинки.
    $path = is_string($public) ? realpath($public . '/' . str_replace('\\', '/', $relative)) : false;
    $info = (is_string($public) && is_string($path) && str_starts_with($path, $public . DIRECTORY_SEPARATOR))
        ? @getimagesize($path)
        : false;
    $sizes[$relative] = is_array($info) ? [(int) $info[0], (int) $info[1]] : [0, 0];

    return $sizes[$relative];
}

// Разметка абзаца: сырого HTML в материале нет, выделение задаётся **звёздочками**.
// Возвращает строку, которую шаблон печатает с пометкой /* @safe html */.
function richText(string $text): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $text);
    $out = [];

    foreach (explode("\n", $normalized) as $line) {
        $out[] = (string) preg_replace_callback(
            '/\*\*(.+?)\*\*/u',
            static fn (array $m): string => '<strong>' . $m[1] . '</strong>',
            e($line)
        );
    }

    return implode('<br>', $out);
}

// Точка вывода, которую приложение наполняет своим содержимым: ядро знает только имя зоны.
// Печатает, а не возвращает строку, — вызов стоит внутри шаблона и обязан встать в общий поток
// страницы. Пустая зона не печатает ни байта, включая обёртку: сайт без раздела не обрастает
// пустыми рамками (решение 5 спецификации).
/**
 * @param array<string, mixed> $data контекст страницы для подписчика: адрес материала, рубрика
 */
function region(string $name, array $data = []): void
{
    $html = (string) applyFilters('region_' . $name, '', $name, $data);

    if (trim($html) === '') {
        return;
    }

    echo partial('region', ['zone' => $name, 'content' => $html]);
}
