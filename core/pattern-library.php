<?php

declare(strict_types=1);

// Библиотека паттернов: короткие наборы из одного-трёх блоков для вставки в уже собранную
// страницу. От шаблона отличается размером и намерением: шаблон собирает страницу целиком,
// паттерн добавляет один законченный кусок — три преимущества, сетку отзывов, блок вопросов.
//
// Каталог живёт в PHP, как и каталог шаблонов: никакой записи на диск и никаких внешних файлов.
// Блоки пишутся через createSection() — те же пределы, уникальный ключ и JSON-списки, что и у
// ручной сборки. Паттерн ничего не заменяет и не меняет макет страницы.

/** @var array<string, string> */
const PATTERN_LIBRARY_CATEGORIES = [
    'content' => 'Текст',
    'cards' => 'Карточки',
    'commerce' => 'Продажи',
    'contact' => 'Контакты',
    'trust' => 'Доверие',
];

/**
 * Каталог паттернов. Якоря внутри каждого паттерна уникальны и несут префикс `p-`: несколько
 * паттернов можно поставить на одну страницу, не перекликаясь якорями.
 *
 * @return array<string, array{name: string, category: string, description: string, sections: list<array<string, mixed>>}>
 */
function patternLibraryCatalog(): array
{
    return [
        'intro' => [
            'name' => 'Вступление',
            'category' => 'content',
            'description' => 'Первый экран с призывом и следующий за ним поясняющий текст.',
            'sections' => [
                [
                    'kind' => 'hero',
                    'anchor' => 'p-intro',
                    'badge' => 'Знакомство',
                    'title' => 'Коротко о главном',
                    'lead_text' => 'Одно ясное обещание и два абзаца: что вы предлагаете и кому это подходит.',
                    'buttons' => [
                        ['label' => 'Узнать больше', 'href' => '#p-intro-text'],
                    ],
                ],
                [
                    'kind' => 'prose',
                    'anchor' => 'p-intro-text',
                    'badge' => 'Подробнее',
                    'title' => 'Почему это важно',
                    'lead_text' => 'Пара абзацев, которые раскрывают обещание первого экрана.'
                        . "\n\n" . 'Второй абзац оставляет место для конкретной выгоды или факта.',
                ],
            ],
        ],
        'three-benefits' => [
            'name' => 'Три преимущества',
            'category' => 'cards',
            'description' => 'Три карточки с короткими описаниями и ссылками.',
            'sections' => [
                [
                    'kind' => 'cards',
                    'anchor' => 'p-benefits',
                    'badge' => 'Преимущества',
                    'title' => 'Что вы получаете',
                    'lead_text' => 'Три причины выбрать именно это решение.',
                    'items' => [
                        ['title' => 'Первое преимущество', 'text' => 'Короткое описание, чем оно помогает посетителю.', 'href' => '#p-benefits', 'note' => 'без лишних слов'],
                        ['title' => 'Второе преимущество', 'text' => 'Опишите результат, а не процесс: что изменится для человека.', 'href' => '#p-benefits', 'note' => 'по делу'],
                        ['title' => 'Третье преимущество', 'text' => 'Факт или цифра, которую можно проверить.', 'href' => '#p-benefits', 'note' => 'проверяемо'],
                    ],
                ],
            ],
        ],
        'three-steps' => [
            'name' => 'Три шага',
            'category' => 'content',
            'description' => 'Нумерованный список из трёх действий: что сделать по порядку.',
            'sections' => [
                [
                    'kind' => 'steps',
                    'anchor' => 'p-steps',
                    'badge' => 'Порядок',
                    'title' => 'Как это работает',
                    'lead_text' => 'Три шага от первого обращения до результата.',
                    'items' => [
                        ['title' => 'Оставьте заявку', 'text' => 'Имя и способ связи — этого достаточно для старта.'],
                        ['title' => 'Согласуем детали', 'text' => 'Уточним задачу, сроки и удобное время.'],
                        ['title' => 'Получите результат', 'text' => 'Работа сдана, а всё общение остаётся в одном месте.'],
                    ],
                ],
            ],
        ],
        'three-questions' => [
            'name' => 'Три вопроса',
            'category' => 'content',
            'description' => 'Пары «вопрос — ответ» с разметкой для поисковиков.',
            'sections' => [
                [
                    'kind' => 'faq',
                    'anchor' => 'p-questions',
                    'badge' => 'Вопросы',
                    'title' => 'Частые вопросы',
                    'items' => [
                        ['title' => 'Как быстро начнём?', 'text' => 'Обычно в течение рабочего дня после заявки.'],
                        ['title' => 'Что нужно подготовить?', 'text' => 'Только задачу и доступы, если они нужны для работы.'],
                        ['title' => 'Можно ли изменить условия?', 'text' => 'Да, обсуждаем детали до старта и фиксируем их письменно.'],
                    ],
                ],
            ],
        ],
        'three-plans' => [
            'name' => 'Три тарифа',
            'category' => 'commerce',
            'description' => 'Сравнение трёх планов с ценой, составом и кнопкой выбора.',
            'sections' => [
                [
                    'kind' => 'pricing',
                    'anchor' => 'p-plans',
                    'badge' => 'Тарифы',
                    'title' => 'Выберите подходящий',
                    'lead_text' => 'Три варианта с разным набором возможностей.',
                    'note' => 'Условия уточняются перед оплатой.',
                    'items' => [
                        ['title' => 'Базовый', 'text' => 'Основные возможности', 'note' => '1 900 ₽', 'href' => '#p-plans'],
                        ['title' => 'Стандарт', 'text' => 'Больше возможностей и поддержка', 'note' => '3 900 ₽', 'href' => '#p-plans'],
                        ['title' => 'Расширенный', 'text' => 'Все возможности и персональный подход', 'note' => '7 900 ₽', 'href' => '#p-plans'],
                    ],
                ],
            ],
        ],
        'reviews' => [
            'name' => 'Отзывы',
            'category' => 'trust',
            'description' => 'Сетка из трёх отзывов с именами и краткими подписями.',
            'sections' => [
                [
                    'kind' => 'review',
                    'anchor' => 'p-reviews',
                    'badge' => 'Отзывы',
                    'title' => 'Что говорят клиенты',
                    'items' => [
                        ['title' => 'Анна', 'text' => 'Всё объяснили заранее, результат получили в срок.', 'note' => 'Москва'],
                        ['title' => 'Игорь', 'text' => 'Удобно было следить за ходом работы и задавать вопросы.', 'note' => 'Казань'],
                        ['title' => 'Мария', 'text' => 'Понравилось, что не навязывали лишнего.', 'note' => 'Санкт-Петербург'],
                    ],
                ],
            ],
        ],
        'contacts' => [
            'name' => 'Короткие контакты',
            'category' => 'contact',
            'description' => 'Телефон, почта и адрес с активными ссылками и сноской.',
            'sections' => [
                [
                    'kind' => 'contacts',
                    'anchor' => 'p-contacts',
                    'badge' => 'Контакты',
                    'title' => 'Как связаться',
                    'note' => 'Отвечаем в рабочие часы, письмо — в течение дня.',
                    'items' => [
                        ['title' => 'Телефон', 'text' => '+7 900 000-00-00', 'href' => 'tel:+79000000000'],
                        ['title' => 'Почта', 'text' => 'hello@example.com', 'href' => 'mailto:hello@example.com'],
                        ['title' => 'Адрес', 'text' => 'Москва, улица Примерная, 1'],
                    ],
                ],
            ],
        ],
        'call-to-action' => [
            'name' => 'Призыв к действию',
            'category' => 'commerce',
            'description' => 'Тёмная полоса с заголовком и двумя кнопками в конце страницы.',
            'sections' => [
                [
                    'kind' => 'cta',
                    'anchor' => 'p-cta',
                    'badge' => 'Начать',
                    'title' => 'Готовы обсудить задачу?',
                    'lead_text' => 'Напишите или позвоните — ответим в течение рабочего дня.',
                    'buttons' => [
                        ['label' => 'Оставить заявку', 'href' => '#p-cta'],
                        ['label' => 'Позвонить', 'href' => 'tel:+79000000000'],
                    ],
                ],
            ],
        ],
        'gallery' => [
            'name' => 'Галерея',
            'category' => 'content',
            'description' => 'Три кадра с подписями и местом съёмки.',
            'sections' => [
                [
                    'kind' => 'gallery',
                    'anchor' => 'p-gallery',
                    'badge' => 'Галерея',
                    'title' => 'Как это выглядит',
                    'note' => 'Снимки сделаны на объекте.',
                    'items' => [
                        ['image' => '/assets/longread/hero-student.png', 'title' => 'Первый кадр', 'note' => 'Рабочий процесс'],
                        ['image' => '/assets/longread/icon-community.png', 'title' => 'Второй кадр', 'note' => 'Команда'],
                        ['image' => '/assets/longread/icon-goals.png', 'title' => 'Третий кадр', 'note' => 'Результат'],
                    ],
                ],
            ],
        ],
        'lead-form' => [
            'name' => 'Форма заявки',
            'category' => 'contact',
            'description' => 'Обещания слева и форма с обязательным согласием справа.',
            'sections' => [
                [
                    'kind' => 'lead',
                    'anchor' => 'p-lead',
                    'badge' => 'Заявка',
                    'title' => 'Оставьте заявку',
                    'lead_text' => 'Имя и способ связи — администратор свяжется с вами в течение дня.',
                    'items' => [
                        ['text' => 'Отвечаем в течение рабочего дня.'],
                        ['text' => 'Не звоним без вашего согласия.'],
                        ['text' => 'Данные используем только для ответа.'],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Список паттернов для карточек: без самих блоков, но с их числом и подписью категории.
 *
 * @return list<array{key: string, name: string, category: string, categoryName: string, description: string, sections: int}>
 */
function patternLibraryAll(): array
{
    $rows = [];

    foreach (patternLibraryCatalog() as $key => $pattern) {
        $rows[] = [
            'key' => $key,
            'name' => (string) $pattern['name'],
            'category' => (string) $pattern['category'],
            'categoryName' => PATTERN_LIBRARY_CATEGORIES[$pattern['category']] ?? (string) $pattern['category'],
            'description' => (string) $pattern['description'],
            'sections' => count((array) $pattern['sections']),
        ];
    }

    return $rows;
}

/** @return array<string, mixed>|null */
function patternLibraryFind(string $key): ?array
{
    return patternLibraryCatalog()[$key] ?? null;
}

/** @return list<array{key: string, name: string, category: string, categoryName: string, description: string, sections: int}> */
function patternLibraryFiltered(string $category): array
{
    $rows = patternLibraryAll();

    if ($category === '') {
        return $rows;
    }

    return array_values(array_filter(
        $rows,
        static fn (array $row): bool => $row['category'] === $category
    ));
}

/**
 * Вставка паттерна в страницу. Предел проверяется до вставки, всё остальное делает createSection():
 * конфликт якоря или негодное поле откатывает весь паттерн, а не оставляет половину.
 *
 * @return array{ok: bool, error: string, created: int, pageId: string, notice: string}
 */
function patternLibraryApply(string $pageId, string $key, ?DateTimeImmutable $now = null): array
{
    $pattern = patternLibraryFind($key);

    if ($pattern === null) {
        return ['ok' => false, 'error' => 'Такого паттерна нет — обновите список.', 'created' => 0, 'pageId' => $pageId, 'notice' => ''];
    }

    if ($pageId !== PAGES_HOME_ID && pageById($pageId) === null) {
        return ['ok' => false, 'error' => 'Страница исчезла — обновите список.', 'created' => 0, 'pageId' => $pageId, 'notice' => ''];
    }

    $existing = pageSections($pageId);

    if (count($existing) + count((array) $pattern['sections']) > PAGES_SECTIONS_LIMIT) {
        return [
            'ok' => false,
            'error' => 'На странице станет больше ' . PAGES_SECTIONS_LIMIT . ' блоков — уберите лишние перед вставкой.',
            'created' => 0,
            'pageId' => $pageId,
            'notice' => '',
        ];
    }

    $created = 0;

    try {
        tx(static function () use ($pageId, $pattern, $key, $now, &$created): void {
            foreach ((array) $pattern['sections'] as $section) {
                $result = createSection(
                    $pageId,
                    (array) $section,
                    $now,
                    ['operation' => 'system', 'comment' => 'Паттерн «' . (string) $pattern['name'] . '» (' . $key . ')']
                );

                if (($result['ok'] ?? false) !== true) {
                    throw new RuntimeException((string) ($result['error'] ?? 'Блок паттерна не добавился.'));
                }

                $created++;
            }
        });
    } catch (Throwable $error) {
        logError('patterns.apply_failed', ['key' => $key, 'pageId' => $pageId, 'message' => $error->getMessage()]);

        return ['ok' => false, 'error' => $error->getMessage(), 'created' => 0, 'pageId' => $pageId, 'notice' => ''];
    }

    logInfo('patterns.applied', ['key' => $key, 'pageId' => $pageId, 'created' => $created]);

    return ['ok' => true, 'error' => '', 'created' => $created, 'pageId' => $pageId, 'notice' => 'pattern_applied'];
}
