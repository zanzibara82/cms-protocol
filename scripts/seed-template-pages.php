<?php

declare(strict_types=1);

// Наполнение тестовыми страницами по одному шаблону из библиотеки `core/template-library.php`.
// Демо-контент не трогается: все страницы создаются с новыми адресами `test-<ключ шаблона>`.
// По умолчанию скрипт только печатает план; запись включается флагом --write. Повторный прогон
// пропускает страницы, которые уже созданы, поэтому его безопасно запускать ещё раз.
//
// Использование:
//   php scripts/seed-template-pages.php
//   php scripts/seed-template-pages.php --write
//
// Страницы создаются опубликованными, но с noindex: тестовые наборы блоков не должны попадать
// в поисковые отчёты и карту сайта. Макет страницы берётся из описания шаблона, а блоки затем
// наполняются через templateLibraryApply() — тем же путём, что и кнопка «Применить»
// в /admin/templates.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../core/bootstrap.php';

// В консоли отказ печатается текстом: обработчик ядра иначе отдал бы HTML-страницу 500.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'Отказ: ' . $error->getMessage() . "\n");
    exit(1);
});

$write = array_key_exists('write', getopt('', ['write']));
$catalog = templateLibraryCatalog();

if ($catalog === []) {
    fwrite(STDERR, "Отказ: библиотека шаблонов пуста — наполнять нечего.\n");
    exit(1);
}

$created = 0;
$skipped = 0;
$failed = 0;

foreach ($catalog as $key => $template) {
    $slug = 'test-' . $key;
    $name = (string) $template['name'];
    $blocks = count((array) ($template['sections'] ?? []));

    if (!$write) {
        echo 'План: /' . $slug . ' — «' . $name . '», блоков: ' . $blocks . "\n";
        continue;
    }

    if (findPage($slug) !== null) {
        echo 'Пропущена: /' . $slug . ' — страница уже есть.' . "\n";
        ++$skipped;
        continue;
    }

    $page = createPage([
        'title' => 'Тест: ' . $name,
        'slug' => $slug,
        'description' => 'Тестовая страница шаблона «' . $name . '» (' . $key . ').',
        'status' => PAGES_STATUS_PUBLISHED,
        'noindex' => '1',
        'layout' => (string) ($template['layout'] ?? PAGE_LAYOUT_DEFAULT),
    ]);

    if (!$page['ok']) {
        echo 'Отказ: /' . $slug . ' — ' . $page['error'] . "\n";
        ++$failed;
        continue;
    }

    $applied = templateLibraryApply($page['id'], $key);

    if (!$applied['ok']) {
        echo 'Отказ: /' . $slug . ' — шаблон не применён: ' . $applied['error'] . "\n";
        ++$failed;
        continue;
    }

    echo 'Создана: /' . $slug . ' — «' . $name . '», блоков: ' . (int) $applied['created'] . "\n";
    ++$created;
}

if (!$write) {
    echo "\nПробег без записи: добавьте --write, чтобы создать страницы.\n";
    exit(0);
}

echo "\nИтого: создано " . $created . ', пропущено ' . $skipped . ', отказов ' . $failed . ".\n";
exit($failed === 0 ? 0 : 1);
