<?php

declare(strict_types=1);

// Прайс-листы (M5): обмен ценами и остатками файлом CSV с точкой с запятой. Формат
// держится российскими привычками Excel: десятичная запятая, разделитель «;», BOM
// в экспорте. Импорт правит только известные SKU: удачные строки применяются, ошибки
// отдельных строк остаются в отчёте с причинами — список не падает «всё или ничего».
// Пустая ячейка не меняет ничего и не считается ошибкой.

/**
 * Строки экспорта: вариант со своим товаром, промо ниже обычной цены — по столбцам.
 *
 * @return list<array{sku: string, product_title: string, variant_title: string, price_retail: string, price_promo: string, stock: int}>
 */
function commercePricebookRows(?string $supplierId): array
{
    $parts = [
        'SELECT v.sku, p.title AS product_title, v.title AS variant_title,',
        'v.price_retail, v.price_promo, v.stock',
        'FROM ' . t('commerce_variant') . ' v JOIN ' . t('commerce_product') . ' p ON p.id = v.product_id',
    ];
    $args = [];

    if ($supplierId !== null && $supplierId !== '') {
        $parts[] = 'WHERE v.supplier_id = ?';
        $args[] = $supplierId;
    }

    $parts[] = 'ORDER BY p.title, v.sku';

    $rows = all(implode(' ', $parts), $args);
    $out = [];

    foreach ($rows as $row) {
        $out[] = [
            'sku' => (string) $row['sku'],
            'product_title' => (string) $row['product_title'],
            'variant_title' => (string) $row['variant_title'],
            'price_retail' => number_format((float) $row['price_retail'], 2, ',', ' '),
            'price_promo' => $row['price_promo'] !== null
                ? number_format((float) $row['price_promo'], 2, ',', ' ') : '',
            'stock' => (int) $row['stock'],
        ];
    }

    /** @var list<array{sku: string, product_title: string, variant_title: string, price_retail: string, price_promo: string, stock: int}> */
    return $out;
}

/**
 * Текст CSV прайс-листа: BOM в голове, защита колонок кавычками, концы строк CRLF —
 * файл открывается двойным щелчком без визарда.
 */
function commercePricebookCsv(?string $supplierId): string
{
    $field = static fn (string $value): string => '"' . str_replace('"', '""', $value) . '"';

    $lines = ['sku;товар;вариант;цена;промо;остаток'];

    foreach (commercePricebookRows($supplierId) as $row) {
        $lines[] = implode(';', [
            $field($row['sku']),
            $field($row['product_title']),
            $field($row['variant_title']),
            $field($row['price_retail']),
            $field($row['price_promo']),
            $field((string) $row['stock']),
        ]);
    }

    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}

/** Дата в имени скачанного файла: день по Москве, без разделителей. */
function commercePricebookFilename(DateTimeImmutable $moment): string
{
    return 'pricebook-' . str_replace('-', '', (string) substr(moscowIso($moment), 0, 10)) . '.csv';
}

/**
 * Разбор присланного файла: пришёл ли он формой и помещение ли в границах размера.
 * Причина отказа — словами хостинга, а не кодами PHP.
 *
 * @param array<string, mixed> $file запись $_FILES
 * @return array{path: string, error: string}
 */
function commercePricebookInspect(array $file): array
{
    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($code !== UPLOAD_ERR_OK) {
        return ['path' => '', 'error' => 'Файл не дошёл до сервера — выберите его ещё раз.'];
    }

    if ((int) ($file['size'] ?? 0) > COMMERCE_PRICEBOOK_FILE_MAX_BYTES) {
        return ['path' => '', 'error' => 'Файл больше 5 МБ — оставьте в нём только строки этого магазина.'];
    }

    $path = (string) ($file['tmp_name'] ?? '');

    if ($path === '' || !is_file($path) || !is_uploaded_file($path)) {
        return ['path' => '', 'error' => 'Файл нужно прислать формой — попробуйте ещё раз.'];
    }

    return ['path' => $path, 'error' => ''];
}

/**
 * Число из ячейки прайс-листа: неразрывные пробелы съедаются, запятая — десятичный
 * разделитель. Пустая ячейка означает «не менять»: null числа при разрешимости строки.
 *
 * @return array{num: ?float, ok: bool}
 */
function commercePricebookCell(string $raw): array
{
    $clean = str_replace("\xC2\xA0", ' ', $raw);
    $clean = str_replace(' ', '', trim($clean));

    if ($clean === '') {
        return ['num' => null, 'ok' => true];
    }

    $clean = str_replace(',', '.', $clean);

    if (preg_match('/^\d+(\.\d{1,2})?$/', $clean) === 1) {
        return ['num' => (float) $clean, 'ok' => true];
    }

    return ['num' => null, 'ok' => false];
}

/** Остаток: пустая ячейка разрешена, число — целое в границах количества. */
function commercePricebookStockOk(?float $num): bool
{
    return $num === null
        || (floor($num) === $num && $num >= 0 && $num <= COMMERCE_QTY_MAX);
}

/** Цена: пустая ячейка разрешена, число — больше нуля и не улетело за пределы. */
function commercePricebookPriceOk(?float $num): bool
{
    return $num === null || ($num > 0 && $num <= COMMERCE_PRICE_MAX);
}

/** Промо: пусто (не менять), ноль (снять) или число больше нуля — граница ниже цены проверяется отдельно. */
function commercePricebookPromoOk(?float $num): bool
{
    return $num === null || ($num >= 0 && $num <= COMMERCE_PRICE_MAX);
}

/**
 * Строка импорта до применения: что менять по SKU. Промо различает три состояния:
 * «не менять» (ячейка пустая), «снять» (ноль), «проставить» (число — ценой ниже обычной,
 * сравнение с ценой строки или с фактом варианта — дело вызывающей стороны).
 *
 * @return array{ok: bool, sku: string, price: ?float, price_set: bool, stock: ?int, stock_set: bool, promo: ?float, promo_set: bool, error: string}
 */
function commercePricebookPlanRow(string $sku, string $priceRaw, string $promoRaw, string $stockRaw): array
{
    $fail = static function (string $error) use ($sku): array {
        return ['ok' => false, 'sku' => $sku, 'price' => null, 'price_set' => false, 'stock' => null, 'stock_set' => false, 'promo' => null, 'promo_set' => false, 'error' => $error];
    };

    if ($sku === '') {
        return $fail('Артикул не записан.');
    }

    $priceCell = commercePricebookCell($priceRaw);
    $stockCell = commercePricebookCell($stockRaw);
    $promoCell = commercePricebookCell($promoRaw);

    if (!commercePricebookPriceOk($priceCell['num'])) {
        return $fail('Цена — число, например 1290 или 1290,50.');
    }

    if (!commercePricebookStockOk($stockCell['num'])) {
        return $fail('Остаток — целое число от 0 до ' . COMMERCE_QTY_MAX . '.');
    }

    if (!commercePricebookPromoOk($promoCell['num'])) {
        return $fail('Промо-цена — число; 0 снимает промо, пустая ячейка его не трогает.');
    }

    $price = $priceCell['num'];
    $stock = $stockCell['num'];
    $promo = $promoCell['num'];
    $priceSet = $price !== null;
    $stockSet = $stock !== null;
    $promoSet = $promo !== null;

    if (!$priceSet && !$stockSet && !$promoSet) {
        return $fail('Ни одна ячейка не заполнена — менять нечего.');
    }

    // Промо при цене в той же строке обязано быть ниже этой цены.
    if ($promoSet && $priceSet && $promo > 0 && $promo >= $price) {
        return $fail('Промо-цена обязана быть ниже обычной цены.');
    }

    return [
        'ok' => true,
        'sku' => $sku,
        'price' => $priceSet ? $price : null,
        'price_set' => $priceSet,
        'stock' => $stockSet ? (int) $stock : null,
        'stock_set' => $stockSet,
        'promo' => $promoSet ? $promo : null,
        'promo_set' => $promoSet,
        'error' => '',
    ];
}

/**
 * Разбор CSV-строк вручную: штатный fgetcsv молча съедает последнюю пустую ячейку
 * («SKU;цена;промо;» теряет хвост), а строки прайс-листа часто кончаются пустым
 * «остатком». Кавычки понимаются как в Excel: "" внутри кавычек — одна кавычка,
 * перенос строки внутри кавычек остаётся внутри поля.
 *
 * @param string $bytes весь файл
 * @return list<list<string>>
 */
function commercePricebookParseRows(string $bytes, string $sep): array
{
    $rows = [];
    $row = [];
    $field = '';
    $inQuotes = false;
    $length = strlen($bytes);
    $i = 0;

    while ($i < $length) {
        $ch = $bytes[$i];

        if ($inQuotes) {
            if ($ch === '"') {
                if ($i + 1 < $length && $bytes[$i + 1] === '"') {
                    $field .= '"';
                    $i += 2;

                    continue;
                }

                $inQuotes = false;
                $i++;

                continue;
            }

            $field .= $ch;
            $i++;

            continue;
        }

        if ($ch === $sep) {
            $row[] = $field;
            $field = '';
            $i++;

            continue;
        }

        if ($ch === '"' && $field === '') {
            $inQuotes = true;
            $i++;

            continue;
        }

        if ($ch === "\r" || $ch === "\n") {
            $i += $ch === "\r" && $i + 1 < $length && $bytes[$i + 1] === "\n" ? 2 : 1;
            $row[] = $field;
            $field = '';
            $rows[] = $row;
            $row = [];

            continue;
        }

        $field .= $ch;
        $i++;
    }

    if ($field !== '' || $row !== []) {
        $row[] = $field;
        $rows[] = $row;
    }

    /** @var list<list<string>> */
    return $rows;
}

/**
 * Импорт прайс-листа. Файл сперва разбирается целиком: переполняющий список даёт отказ
 * до любой правки базы, а ошибки отдельных строк собираются в отчёт. План применяется
 * одной транзакцией, скоуп поставщика правит только его SKU.
 *
 * @param string $path путь файла, проверенный commercePricebookInspect()
 * @return array{ok: bool, error: string, report: array{lines: int, applied: int, skipped: int, quiet: int, skips: list<array{sku: string, reason: string}>}}
 */
function commercePricebookApply(string $path, ?string $supplierId): array
{
    $emptyReport = ['lines' => 0, 'applied' => 0, 'skipped' => 0, 'quiet' => 0, 'skips' => []];

    $bytes = file_get_contents($path);

    if ($bytes === false) {
        return ['ok' => false, 'error' => 'Файл не открылся — попробуйте ещё раз.', 'report' => $emptyReport];
    }

    if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
        $bytes = (string) substr($bytes, 3);
    }

    if (trim($bytes) === '') {
        return ['ok' => false, 'error' => 'Файл пуст — сохраните таблицу как CSV.', 'report' => $emptyReport];
    }

    // Разделитель определяется строкой заголовка: так латинские запятые внутри названий
    // товаров не путают выбор.
    $headEnd = strpos($bytes, "\n");
    $head = $headEnd === false ? $bytes : (string) substr($bytes, 0, $headEnd);
    $sep = str_contains($head, ';') ? ';' : ',';

    $rows = commercePricebookParseRows($bytes, $sep);

    // Строка названий колонок пропускается ровно по слову «sku» в первом столбце: файл
    // данных без заголовка остаётся данными.
    if ($rows !== [] && mb_strtolower(trim((string) $rows[0][0])) === 'sku') {
        array_shift($rows);
    }

    $plan = [];
    $fails = [];
    $quiet = 0;

    foreach ($rows as $cells) {
        if (count($cells) === 1 && (string) $cells[0] === '') {
            // Пустая строка файла — артефакт таблиц, она не красит отчёт ошибкой.
            $quiet++;

            continue;
        }

        // Форматы: полный экспортный (sku;товар;вариант;цена;промо;остаток) и минимальный
        // (sku;цена;промо;остаток). Текстовые колонки «товар» и «вариант» служат взгляду
        // человека — применяются цена, промо и остаток.
        if (count($cells) === 4) {
            $skuRaw = (string) $cells[0];
            $priceRaw = (string) $cells[1];
            $promoRaw = (string) $cells[2];
            $stockRaw = (string) $cells[3];
        } elseif (count($cells) === 6) {
            $skuRaw = (string) $cells[0];
            $priceRaw = (string) $cells[3];
            $promoRaw = (string) $cells[4];
            $stockRaw = (string) $cells[5];
        } else {
            $fails[] = [
                'sku' => trim((string) ($cells[0] ?? '')),
                'reason' => 'Строка не понята: нужно 6 столбцов (sku;товар;вариант;цена;промо;остаток) или 4 (sku;цена;промо;остаток).',
            ];

            continue;
        }

        $row = commercePricebookPlanRow(trim($skuRaw), $priceRaw, $promoRaw, $stockRaw);

        if (!$row['ok']) {
            $fails[] = ['sku' => $row['sku'], 'reason' => $row['error']];

            continue;
        }

        // Скоуп поставщика: неизвестный поставщику SKU получает свою и видную причину.
        $parts = ['SELECT price_retail, price_promo FROM ' . t('commerce_variant') . ' WHERE sku = ?'];
        $args = [$row['sku']];

        if ($supplierId !== null && $supplierId !== '') {
            $parts[] = 'AND supplier_id = ?';
            $args[] = $supplierId;
        }

        $variant = one(implode(' ', $parts), $args);

        if ($variant === null) {
            $fails[] = ['sku' => $row['sku'], 'reason' => $supplierId !== null && $supplierId !== ''
                ? 'Артикул не числится за поставщиком.'
                : 'Такого артикула нет в магазине.'];

            continue;
        }

        // Промо без цены в той же строке сравнивается с фактической ценой варианта:
        // правка обязана ехать ниже факта, а не ниже «ничего».
        if ($row['promo_set'] && (float) $row['promo'] > 0 && !$row['price_set']
            && (float) $variant['price_retail'] <= (float) $row['promo']) {
            $fails[] = ['sku' => $row['sku'], 'reason' => 'Промо-цена обязана быть ниже обычной цены товара.'];

            continue;
        }

        // Обратная пара: строка меняет обычную цену и не трогает промо. После такой правки
        // промо-цена может оказаться выше новой обычной — и витрина молча продавала бы
        // «обычный» товар по промо. Строка отказывает с причиной: поставщик обязан указать
        // промо в этой же строке или снять его нулём.
        if ($row['price_set'] && !$row['promo_set']
            && $variant['price_promo'] !== null
            && (float) $variant['price_promo'] >= (float) $row['price']) {
            $fails[] = [
                'sku' => $row['sku'],
                'reason' => 'Новая обычная цена не ниже текущей промо-цены: укажите промо в строке или снимите его нулём.',
            ];

            continue;
        }

        $plan[] = $row;
    }

    $report = [
        'lines' => count($plan) + count($fails),
        'applied' => 0,
        'skipped' => count($fails),
        'quiet' => $quiet,
        'skips' => [],
    ];

    if ($report['lines'] > COMMERCE_PRICEBOOK_MAX_ROWS) {
        return ['ok' => false, 'error' => 'Строк больше 10000 — делите файл.', 'report' => $report];
    }

    if ($plan !== []) {
        $applied = false;
        $count = 0;
        $done = tx(static function () use ($plan, $supplierId, &$count): bool {
            foreach ($plan as $row) {
                $sets = ['updated_at = ?'];
                $args = [utcStamp(nowUtc())];

                if ($row['price_set']) {
                    $sets[] = 'price_retail = ?';
                    $args[] = $row['price'];
                }

                if ($row['stock_set']) {
                    $sets[] = 'stock = ?';
                    $args[] = $row['stock'];
                }

                if ($row['promo_set']) {
                    $sets[] = 'price_promo = ?';
                    // Ноль — «снять промо»: в базе хранится NULL, а не нулевая цена.
                    $args[] = (float) $row['promo'] > 0 ? $row['promo'] : null;
                }

                $sql = 'UPDATE ' . t('commerce_variant') . ' SET ' . implode(', ', $sets)
                    . ' WHERE sku = ?' . ($supplierId !== null && $supplierId !== '' ? ' AND supplier_id = ?' : '');
                $args[] = $row['sku'];

                if ($supplierId !== null && $supplierId !== '') {
                    $args[] = $supplierId;
                }

                $count += run($sql, $args);
            }

            return true;
        });

        if ($done === true) {
            $applied = true;
        }

        if (!$applied) {
            return ['ok' => false, 'error' => 'Прайс-лист не применился — попробуйте ещё раз.', 'report' => $emptyReport];
        }

        $report['applied'] = $count;
    }

    // Причин в отчёте печатаются первые 30: остальное грубого счёта.
    $report['skips'] = array_slice($fails, 0, 30);

    logInfo('commerce.pricebook_applied', [
        'applied' => $report['applied'],
        'skipped' => $report['skipped'],
        'supplierId' => (string) $supplierId,
    ]);

    return ['ok' => true, 'error' => '', 'report' => $report];
}
