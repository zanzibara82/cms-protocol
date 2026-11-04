<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/boot.php';

// Закладка идемпотентна: ядро запускает seed.php через `require` при каждой установке,
// поэтому файл не объявляет ни констант, ни функций — только повторяет запись по коду.
// Уровни и тест на уровень — единственный контент, который школа получает сразу:
// курсы, модули и уроки создаёт контент-админка.

$levels = [
    ['code' => 'Pre-A1', 'title' => 'Стартовый'],
    ['code' => 'A1', 'title' => 'Начальный'],
    ['code' => 'A2', 'title' => 'Базовый'],
    ['code' => 'B1', 'title' => 'Средний'],
    ['code' => 'B2', 'title' => 'Выше среднего'],
    ['code' => 'C1', 'title' => 'Продвинутый'],
    ['code' => 'C2', 'title' => 'Свободный'],
];

foreach ($levels as $sort => $level) {
    $exists = (int) value(
        'SELECT COUNT(*) FROM ' . t('learn_level') . ' WHERE code = ?',
        [$level['code']]
    );

    if ($exists > 0) {
        continue;
    }

    insert('learn_level', [
        'id' => newId(),
        'code' => $level['code'],
        'title' => $level['title'],
        'sort' => $sort,
    ]);
}

$placement = one(
    'SELECT id FROM ' . t('learn_test') . " WHERE kind = 'PLACEMENT' LIMIT 1"
);

if ($placement !== null) {
    return;
}

$testId = newId();
insert('learn_test', [
    'id' => $testId,
    'kind' => 'PLACEMENT',
    'lesson_id' => null,
    'module_id' => null,
    'title' => 'Тест на уровень',
    'pass_score' => 40,
    'status' => 'PUBLISHED',
]);

// Тридцать вопросов портала: по десять на лексику и грамматику, по пять на чтение
// и аудирование. Порядок — от простого к сложному, он же sort.
$questions = [
    // Лексика (10)
    [
        'skill' => 'VOCABULARY',
        'text' => 'Что означает слово «apple»?',
        'options' => ['Яблоко', 'Апельсин', 'Груша', 'Банан'],
        'correct' => 0,
        'explanation' => 'apple переводится как яблоко',
    ],
    [
        'skill' => 'VOCABULARY',
        'text' => 'Что означает «house»?',
        'options' => ['Машина', 'Дом', 'Окно', 'Крыша'],
        'correct' => 1,
        'explanation' => 'house — дом, окно будет window, крыша — roof',
    ],
    [
        'skill' => 'VOCABULARY',
        'text' => 'Выберите перевод слова «water».',
        'options' => ['Молоко', 'Сок', 'Вода', 'Чай'],
        'correct' => 2,
        'explanation' => 'water — вода, молоко это milk',
    ],
    [
        'skill' => 'VOCABULARY',
        'text' => '«Brother» — это…',
        'options' => ['Сестра', 'Брат', 'Дядя', 'Кузен'],
        'correct' => 1,
        'explanation' => 'brother — брат, сестра будет sister',
    ],
    [
        'skill' => 'VOCABULARY',
        'text' => 'Что значит «to work»?',
        'options' => ['Отдыхать', 'Работать', 'Учиться', 'Спать'],
        'correct' => 1,
        'explanation' => 'to work — работать, отдыхать будет to rest',
    ],
    [
        'skill' => 'VOCABULARY',
        'text' => 'Выберите значение «expensive».',
        'options' => ['Дешёвый', 'Красивый', 'Дорогой', 'Быстрый'],
        'correct' => 2,
        'explanation' => 'expensive — дорогой, дешёвый будет cheap',
    ],
    [
        'skill' => 'VOCABULARY',
        'text' => '«Weather» — это…',
        'options' => ['Погода', 'Вода', 'Одежда', 'Неделя'],
        'correct' => 0,
        'explanation' => 'weather — погода, неделя это week',
    ],
    [
        'skill' => 'VOCABULARY',
        'text' => 'Что значит «to borrow»?',
        'options' => ['Должить/занимать', 'Покупать', 'Продавать', 'Дарить'],
        'correct' => 0,
        'explanation' => 'to borrow — взять взаймы, отдать долг — to lend',
    ],
    [
        'skill' => 'VOCABULARY',
        'text' => '«Delicious» ближе всего по смыслу к…',
        'options' => ['Грязный', 'Вкусный', 'Громкий', 'Тёмный'],
        'correct' => 1,
        'explanation' => 'delicious говорят об очень вкусной еде',
    ],
    [
        'skill' => 'VOCABULARY',
        'text' => 'Что означает «to achieve a goal»?',
        'options' => ['Поставить цель', 'Добиться цели', 'Отменить цель', 'Обсудить цель'],
        'correct' => 1,
        'explanation' => 'to achieve — добиться, довести дело до результата',
    ],
    // Грамматика (10)
    [
        'skill' => 'GRAMMAR',
        'text' => 'I ___ a student.',
        'options' => ['am', 'is', 'are', 'be'],
        'correct' => 0,
        'explanation' => 'с I глагол to be стоит в форме am',
    ],
    [
        'skill' => 'GRAMMAR',
        'text' => 'She ___ to school every day.',
        'options' => ['go', 'goes', 'going', 'gone'],
        'correct' => 1,
        'explanation' => 'с she, he и it к глаголу добавляется -es: goes',
    ],
    [
        'skill' => 'GRAMMAR',
        'text' => '___ you like coffee?',
        'options' => ['Does', 'Do', 'Are', 'Is'],
        'correct' => 1,
        'explanation' => 'вопрос с you строится с Do, Does — только для he и she',
    ],
    [
        'skill' => 'GRAMMAR',
        'text' => 'There ___ two books on the table.',
        'options' => ['is', 'are', 'has', 'have'],
        'correct' => 1,
        'explanation' => 'There are + множественное число',
    ],
    [
        'skill' => 'GRAMMAR',
        'text' => 'This is ___ phone. (принадлежит мне)',
        'options' => ['my', 'me', 'mine', 'I'],
        'correct' => 0,
        'explanation' => 'перед существительным стоит притяжательное my',
    ],
    [
        'skill' => 'GRAMMAR',
        'text' => 'We ___ TV now.',
        'options' => ['watch', 'watches', 'are watching', 'watched'],
        'correct' => 2,
        'explanation' => 'Present Continuous для «сейчас»',
    ],
    [
        'skill' => 'GRAMMAR',
        'text' => 'He ___ here yesterday.',
        'options' => ['is', 'was', 'were', 'did'],
        'correct' => 1,
        'explanation' => 'yesterday — прошедшее время, форма was',
    ],
    [
        'skill' => 'GRAMMAR',
        'text' => 'I have ___ brothers.',
        'options' => ['some', 'many', 'much', 'a lot'],
        'correct' => 1,
        'explanation' => 'brothers — исчисляемое во множественном числе, подходит many',
    ],
    [
        'skill' => 'GRAMMAR',
        'text' => 'Choose the correct question:',
        'options' => ['What your name is?', 'What is your name?', 'What is name your?', 'What your name?'],
        'correct' => 1,
        'explanation' => 'в вопросе сказуемое идёт перед подлежащим: is your name',
    ],
    [
        'skill' => 'GRAMMAR',
        'text' => 'She can ___ the piano.',
        'options' => ['plays', 'playing', 'play', 'to play'],
        'correct' => 2,
        'explanation' => 'После can — базовая форма глагола',
    ],
    // Чтение (5)
    [
        'skill' => 'READING',
        'text' => 'Прочитайте: «Hi, my name is Tom. I am 12 years old. I live in London. I have a dog. Its name is Rex.» Где живёт Tom?',
        'options' => ['В Париже', 'В Лондоне', 'В Нью-Йорке', 'В Москве'],
        'correct' => 1,
        'explanation' => 'в тексте прямо сказано: I live in London',
    ],
    [
        'skill' => 'READING',
        'text' => 'Прочитайте: «Hi, my name is Tom. I am 12 years old. I live in London. I have a dog. Its name is Rex.» Как зовут его собаку?',
        'options' => ['Tom', 'London', 'Rex', 'Ben'],
        'correct' => 2,
        'explanation' => 'Its name is Rex — так зовут собаку Тома',
    ],
    [
        'skill' => 'READING',
        'text' => 'Прочитайте: «Anna works in a hospital. She gets up at 6 o’clock. In the evening she reads books.» Где работает Anna?',
        'options' => ['В школе', 'В больнице', 'В магазине', 'Дома'],
        'correct' => 1,
        'explanation' => 'works in a hospital — Анна работает в больнице',
    ],
    [
        'skill' => 'READING',
        'text' => 'Прочитайте: «The shop opens at 9 and closes at 8. On Sunday it is closed.» Когда магазин закрыт?',
        'options' => ['Утром', 'В субботу', 'В воскресенье', 'Вечером'],
        'correct' => 2,
        'explanation' => 'On Sunday it is closed — в воскресенье магазин закрыт',
    ],
    [
        'skill' => 'READING',
        'text' => 'Прочитайте: «Paul is on a plane to Rome. He is on holiday. He wants to eat pizza.» Куда летит Paul?',
        'options' => ['В Рим', 'В Лондон', 'В Мадрид', 'В Париж'],
        'correct' => 0,
        'explanation' => 'a plane to Rome — самолёт летит в Рим',
    ],
    // Аудирование (5)
    [
        'skill' => 'LISTENING',
        'text' => 'Послушайте фразу и выберите её запись:',
        'options' => ['I am from Canada.', 'I am from Cuba.', 'I am from China.', 'I am from Colombia.'],
        'correct' => 0,
        'explanation' => 'звучит фраза о Канаде: I am from Canada',
    ],
    [
        'skill' => 'LISTENING',
        'text' => 'Послушайте и выберите правильный вариант:',
        'options' => ['She has got three sisters.', 'He has got three sisters.', 'She has got two sisters.', 'She likes sisters.'],
        'correct' => 0,
        'explanation' => 'говорят о ней и о трёх сёстрах',
    ],
    [
        'skill' => 'LISTENING',
        'text' => 'Послушайте число:',
        'options' => ['739', '731', '793', '379'],
        'correct' => 0,
        'explanation' => 'seven three nine — это 739, порядок цифр важен',
    ],
    [
        'skill' => 'LISTENING',
        'text' => 'Послушайте вопрос и выберите ответ:',
        'options' => ['I am 20.', 'In Moscow.', 'By bus.', 'Yes, I do.'],
        'correct' => 1,
        'explanation' => 'вопрос о месте жительства, отвечает название города',
    ],
    [
        'skill' => 'LISTENING',
        'text' => 'Послушайте предложение:',
        'options' => ['We have lunch at eight.', 'We have breakfast at eight.', 'We wake up at eight.', 'We have dinner at eight.'],
        'correct' => 1,
        'explanation' => 'звучит have breakfast — завтрак в восемь',
    ],
];

foreach ($questions as $index => $question) {
    insert('learn_test_question', [
        'id' => newId(),
        'test_id' => $testId,
        'skill' => $question['skill'],
        'text' => $question['text'],
        'options' => (string) json_encode($question['options'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'correct_option_index' => $question['correct'],
        'explanation' => $question['explanation'],
        'audio_url' => null,
        'passage_ref' => null,
        'sort' => $index + 1,
    ]);
}
