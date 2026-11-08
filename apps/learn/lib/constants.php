<?php

declare(strict_types=1);

// Канонические перечни и пороги школы. Строковые значения совпадают с порталом:
// по ним читаются старые черновики и пишутся новые, поэтому переименование сломало бы
// и контент, и проверку ответов. Пределы полей читаются через fieldLimit().
const LEARN_EXERCISE_TYPES = [
    'MULTIPLE_CHOICE',
    'FILL_IN_THE_BLANK',
    'TRANSLATION',
    'SENTENCE_BUILDING',
    'MATCHING',
    'LISTENING_CHOICE',
    'BRAINSTORM',
    'TIMED_QUIZ',
];

// Семь навыков в ответах, четыре из них показываются в процентах кабинета:
// по остальным порталу не хватало данных для честного вывода.
const LEARN_SKILLS = [
    'VOCABULARY',
    'GRAMMAR',
    'READING',
    'LISTENING',
    'WRITING',
    'SPEAKING',
    'PRONUNCIATION',
];

const LEARN_SKILL_LABELS = [
    'VOCABULARY' => 'Лексика',
    'GRAMMAR' => 'Грамматика',
    'READING' => 'Чтение',
    'LISTENING' => 'Аудирование',
    'WRITING' => 'Письмо',
    'SPEAKING' => 'Говорение',
    'PRONUNCIATION' => 'Произношение',
];

const LEARN_SKILL_TITLES = ['VOCABULARY', 'GRAMMAR', 'READING', 'LISTENING'];

const LEARN_CONTENT_STATUS = ['DRAFT', 'REVIEW', 'PUBLISHED'];

const LEARN_TEST_KINDS = ['PLACEMENT', 'LESSON', 'MODULE'];

// Начисления за урок: сам урок, мини-тест, идеальный тест и закрытый модуль.
const LEARN_XP_RULES = [
    'LESSON_COMPLETE' => 20,
    'TEST_COMPLETE' => 30,
    'PERFECT_TEST_BONUS' => 10,
    'MODULE_COMPLETE' => 100,
];

// Сколько упражнений показываем за один заход и с какого числа ответов верим проценту навыка.
const LEARN_EXERCISES_PER_VISIT = 7;
const LEARN_SKILL_SAMPLE_MIN = 8;

// Уровни школы в порядке изучения. C2 шкалой не ставится: отделить его от C1
// по тридцати вопросам невозможно, точный уровень правит преподаватель.
const LEARN_PLACEMENT_LEVELS = [
    ['code' => 'Pre-A1', 'min' => 0],
    ['code' => 'A1', 'min' => 40],
    ['code' => 'A2', 'min' => 70],
    ['code' => 'B1', 'min' => 80],
    ['code' => 'B2', 'min' => 90],
    ['code' => 'C1', 'min' => 97],
];

// Вклад раздела в конструктор страниц: вид блока «карта уровней» и предел ступеней в нём.
// Предел равен числу уровней программы, поэтому настройка «все уровни» — это и есть шкала целиком.
const LEARN_PAGES_LEVELS_KIND = 'learn_levels';
const LEARN_PAGES_LEVELS_LIMIT = 7;

// Композиция теста на уровень: сколько вопросов каждого навыка кладёт сид.
const LEARN_PLACEMENT_COUNTS = [
    'VOCABULARY' => 10,
    'GRAMMAR' => 10,
    'READING' => 5,
    'LISTENING' => 5,
];

// Редкие типы упражнений: в заходе они встречаются не больше одного раза.
const LEARN_ONCE_PER_LESSON = [
    'LISTENING_CHOICE',
    'MATCHING',
    'BRAINSTORM',
    'TIMED_QUIZ',
];

// Онбординг: три вопроса портала, нагрузка — из четырёх вариантов.
const LEARN_ONBOARDING_SELF = [
    ['value' => 'NONE', 'label' => 'Никогда не изучал'],
    ['value' => 'SCHOOL', 'label' => 'Учил в школе'],
    ['value' => 'UNIVERSITY', 'label' => 'Учил в университете'],
    ['value' => 'BASICS', 'label' => 'Немного знаю'],
    ['value' => 'CAN_TALK', 'label' => 'Уже говорю'],
];

const LEARN_ONBOARDING_GOALS = [
    ['value' => 'TRAVEL', 'label' => 'Путешествия'],
    ['value' => 'WORK', 'label' => 'Работа'],
    ['value' => 'STUDY', 'label' => 'Учёба'],
    ['value' => 'COMMUNICATION', 'label' => 'Общение'],
    ['value' => 'RELOCATION', 'label' => 'Переезд'],
];

const LEARN_ONBOARDING_MINUTES = [10, 20, 30, 60];

// Пределы полей: подписи, описание, порядок, вопрос упражнения, слово и порог теста.
const LEARN_FIELDS = [
    'title' => ['min' => 2, 'max' => 120],
    'description' => ['max' => 500],
    'sort' => 999,
    'question' => ['max' => 300],
    'word' => ['max' => 60],
    'passScore' => 100,
];
