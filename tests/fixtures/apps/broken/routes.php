<?php

declare(strict_types=1);

// Раздел заявляет один маршрут метода GET: он нужен, чтобы реестр считал фикстуру обычным
// разделом. POST-обработчиков и форм здесь нет, поэтому страж CSRF к фикстуре не придирается,
// а страж обработчиков маршрутов находит brokenFeed в исходниках.
addRoute('GET', '/broken', 'brokenFeed', ['app' => 'broken']);

// Фикстура экрана внутреннего отказа (фикс финальной рецензии пакета V): маршрут с заведомо
// отсутствующим именем обработчика доводит dispatch() до ветки `!is_callable` без единого
// обращения к базе — имя не вызывается вовсе, поэтому боевая схема не тронута. Маршрут
// регистрируется только по отметке окружения: страж обработчиков маршрутов проверяет всю
// таблицу на `is_callable` и не должен видеть эту строку в обычном прогоне, а http-набор
// ErrorScreenTest передаёт отметку в `php -S` явно.
if (getenv('CMS_TEST_OMITTED_HANDLER') === '1') {
    addRoute('GET', '/test-omitted-handler', 'cmsTestOmittedHandler');
}

/** @param array<string, string> $params */
function brokenFeed(array $params = []): void
{
    respond(200, 'Лента раздела с битой миграцией', ['Content-Type' => 'text/plain; charset=utf-8']);
}
