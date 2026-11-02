<?php

declare(strict_types=1);

namespace Cms\Tests\unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Чистые правила школы: константы и пределы, форматы черновиков упражнений, проверка ответов,
 * публичная форма упражнения, подбор заданий на заход, оценка теста на уровень, награда за
 * урок и подсказки кабинета. Базы нет: всё, что здесь проверяется, приходит параметрами.
 */
final class LearnRulesTest extends TestCase
{
    public function testКонстантыШколыЗафиксированы(): void
    {
        // Восемь типов упражнений портала: порядок — как в справочнике форм админки.
        self::assertSame([
            'MULTIPLE_CHOICE',
            'FILL_IN_THE_BLANK',
            'TRANSLATION',
            'SENTENCE_BUILDING',
            'MATCHING',
            'LISTENING_CHOICE',
            'BRAINSTORM',
            'TIMED_QUIZ',
        ], LEARN_EXERCISE_TYPES);

        // Семь навыков в ответах, четыре из них показываются в процентах кабинета.
        self::assertSame([
            'VOCABULARY',
            'GRAMMAR',
            'READING',
            'LISTENING',
            'WRITING',
            'SPEAKING',
            'PRONUNCIATION',
        ], LEARN_SKILLS);
        self::assertSame(['VOCABULARY', 'GRAMMAR', 'READING', 'LISTENING'], LEARN_SKILL_TITLES);
        self::assertSame('Лексика', LEARN_SKILL_LABELS['VOCABULARY']);
        self::assertSame('Аудирование', LEARN_SKILL_LABELS['LISTENING']);

        // Статусы контента и виды тестов — строки портала.
        self::assertSame(['DRAFT', 'REVIEW', 'PUBLISHED'], LEARN_CONTENT_STATUS);
        self::assertSame(['PLACEMENT', 'LESSON', 'MODULE'], LEARN_TEST_KINDS);

        // Начисления за урок: урок, мини-тест, идеальный тест, закрытый модуль.
        self::assertSame([
            'LESSON_COMPLETE' => 20,
            'TEST_COMPLETE' => 30,
            'PERFECT_TEST_BONUS' => 10,
            'MODULE_COMPLETE' => 100,
        ], LEARN_XP_RULES);

        // Сколько заданий показываем за заход и с какого числа ответов верим проценту навыка.
        self::assertSame(7, LEARN_EXERCISES_PER_VISIT);
        self::assertSame(8, LEARN_SKILL_SAMPLE_MIN);

        // Уровни и пороги теста на уровень: C2 шкалой не ставится.
        self::assertSame(['Pre-A1', 'A1', 'A2', 'B1', 'B2', 'C1'], array_column(LEARN_PLACEMENT_LEVELS, 'code'));
        self::assertSame([0, 40, 70, 80, 90, 97], array_column(LEARN_PLACEMENT_LEVELS, 'min'));
        self::assertSame(
            ['VOCABULARY' => 10, 'GRAMMAR' => 10, 'READING' => 5, 'LISTENING' => 5],
            LEARN_PLACEMENT_COUNTS
        );

        // Онбординг: три вопроса портала, нагрузка — из четырёх вариантов.
        self::assertCount(5, LEARN_ONBOARDING_SELF);
        self::assertCount(5, LEARN_ONBOARDING_GOALS);
        self::assertSame([10, 20, 30, 60], LEARN_ONBOARDING_MINUTES);

        // Пределы полей читаются справочником через fieldLimit(): подписи, вопрос, слово.
        self::assertSame(120, fieldLimit(LEARN_FIELDS, 'title', 'max'));
        self::assertSame(2, fieldLimit(LEARN_FIELDS, 'title', 'min'));
        self::assertSame(500, fieldLimit(LEARN_FIELDS, 'description', 'max'));
        self::assertSame(999, fieldLimit(LEARN_FIELDS, 'sort'));
        self::assertSame(300, fieldLimit(LEARN_FIELDS, 'question', 'max'));
        self::assertSame(60, fieldLimit(LEARN_FIELDS, 'word', 'max'));
        self::assertSame(100, fieldLimit(LEARN_FIELDS, 'passScore'));
    }

    public function testЧерновикВыбораИАудированияПишетОпции(): void
    {
        $choice = $this->rowOf(learnExerciseDraftToRow($this->draft([
            'type' => 'MULTIPLE_CHOICE',
            'question' => 'Выберите перевод слова «apple»',
            'options_text' => "Яблоко\nАпельсин\nГруша",
            'answer_text' => '0',
            'explanation' => 'apple — яблоко',
            'skill' => 'VOCABULARY',
            'difficulty' => 2,
            'xp_reward' => 5,
        ]), 4));

        self::assertSame('MULTIPLE_CHOICE', $choice['type']);
        self::assertSame(['Яблоко', 'Апельсин', 'Груша'], json_decode((string) $choice['options'], true));
        self::assertSame(0, json_decode((string) $choice['correct_answer'], true));
        self::assertSame(4, $choice['sort']);
        self::assertSame('VOCABULARY', $choice['skill']);
        self::assertSame(2, $choice['difficulty']);
        self::assertSame('apple — яблоко', $choice['explanation']);

        // Строки формы с \r\n делятся так же, как с \n: браузер шлёт первое, форма ждёт второе.
        $windows = $this->rowOf(learnExerciseDraftToRow($this->draft([
            'type' => 'MULTIPLE_CHOICE',
            'options_text' => "am\r\nis\r\nare",
            'answer_text' => '1',
        ]), 1));
        self::assertSame(['am', 'is', 'are'], json_decode((string) $windows['options'], true));

        $listening = $this->rowOf(learnExerciseDraftToRow($this->draft([
            'type' => 'LISTENING_CHOICE',
            'question' => 'Послушайте фразу',
            'options_text' => "I am from Canada.\nI am from Cuba.",
            'answer_text' => '0',
            'speak_text' => 'I am from Canada.',
            'audio_url' => 'https://school.example/audio/canada.mp3',
        ]), 2));

        self::assertSame(
            ['speak' => 'I am from Canada.', 'choices' => ['I am from Canada.', 'I am from Cuba.']],
            json_decode((string) $listening['options'], true)
        );
        self::assertSame(0, json_decode((string) $listening['correct_answer'], true));
        self::assertSame('https://school.example/audio/canada.mp3', $listening['audio_url']);
    }

    public function testЧерновикТекстовыхОтветовПринимаетСписки(): void
    {
        // Заполнение пропуска и перевод принимают несколько форм ответа.
        foreach (['FILL_IN_THE_BLANK', 'TRANSLATION'] as $type) {
            $row = $this->rowOf(learnExerciseDraftToRow($this->draft([
                'type' => $type,
                'question' => 'Вставьте глагол',
                'answer_text' => "was\nwere",
            ]), 1));

            self::assertSame(['was', 'were'], json_decode((string) $row['correct_answer'], true));
            self::assertNull($row['options']);
        }

        // Сборка предложения: банк слов и каноническая строка ответа.
        $sentence = $this->rowOf(learnExerciseDraftToRow($this->draft([
            'type' => 'SENTENCE_BUILDING',
            'question' => 'Соберите предложение',
            'options_text' => 'I like tea',
        ]), 1));

        self::assertSame(['I', 'like', 'tea'], json_decode((string) $sentence['options'], true));
        self::assertSame('I like tea', json_decode((string) $sentence['correct_answer'], true));
    }

    public function testЧерновикПариМозгоШтурмаИТаймера(): void
    {
        $matching = $this->rowOf(learnExerciseDraftToRow($this->draft([
            'type' => 'MATCHING',
            'question' => 'Соедините слово и перевод',
            'options_text' => "apple = яблоко\npear = груша",
        ]), 1));

        self::assertSame([
            'pairs' => [
                ['left' => 'apple', 'right' => 'яблоко'],
                ['left' => 'pear', 'right' => 'груша'],
            ],
        ], json_decode((string) $matching['options'], true));
        self::assertSame(
            ['apple' => 'яблоко', 'pear' => 'груша'],
            json_decode((string) $matching['correct_answer'], true)
        );

        $brainstorm = $this->rowOf(learnExerciseDraftToRow($this->draft([
            'type' => 'BRAINSTORM',
            'question' => 'Назовите фрукты',
            'options_text' => "apple\npear\nplum",
            'answer_text' => '2',
        ]), 1));

        self::assertSame(['minCount' => 2], json_decode((string) $brainstorm['options'], true));
        self::assertSame(['apple', 'pear', 'plum'], json_decode((string) $brainstorm['correct_answer'], true));

        // Таймер держится в границах 5..120 секунд, пустое поле — 20.
        $timed = $this->rowOf(learnExerciseDraftToRow($this->draft([
            'type' => 'TIMED_QUIZ',
            'options_text' => "a\nb",
            'answer_text' => '1',
            'time_limit_sec' => '1',
        ]), 1));
        self::assertSame(['choices' => ['a', 'b'], 'timeLimitSec' => 5], json_decode((string) $timed['options'], true));

        $timedLong = $this->rowOf(learnExerciseDraftToRow($this->draft([
            'type' => 'TIMED_QUIZ',
            'options_text' => "a\nb",
            'answer_text' => '1',
            'time_limit_sec' => '999',
        ]), 1));
        self::assertSame(120, json_decode((string) $timedLong['options'], true)['timeLimitSec']);

        $timedDefault = $this->rowOf(learnExerciseDraftToRow($this->draft([
            'type' => 'TIMED_QUIZ',
            'options_text' => "a\nb",
            'answer_text' => '1',
        ]), 1));
        self::assertSame(20, json_decode((string) $timedDefault['options'], true)['timeLimitSec']);
    }

    public function testЧерновикУпражненияОтказываетКривымДанным(): void
    {
        $broken = [
            'MC без вариантов' => ['type' => 'MULTIPLE_CHOICE', 'options_text' => 'один', 'answer_text' => '0'],
            'MC с индексом вне списка' => ['type' => 'MULTIPLE_CHOICE', 'options_text' => "a\nb", 'answer_text' => '2'],
            'LISTENING без озвучки' => ['type' => 'LISTENING_CHOICE', 'options_text' => "a\nb", 'answer_text' => '0'],
            'FILL без ответа' => ['type' => 'FILL_IN_THE_BLANK', 'answer_text' => ''],
            'BANK из одного слова' => ['type' => 'SENTENCE_BUILDING', 'options_text' => 'tea'],
            'MATCH без знака равенства' => ['type' => 'MATCHING', 'options_text' => "apple\npear = груша"],
            'MATCH из одной пары' => ['type' => 'MATCHING', 'options_text' => 'apple = яблоко'],
            'BRAINSTORM из двух слов' => ['type' => 'BRAINSTORM', 'options_text' => "apple\npear", 'answer_text' => '2'],
            'BRAINSTORM со счётом больше слов' => ['type' => 'BRAINSTORM', 'options_text' => "apple\npear\nplum", 'answer_text' => '5'],
            'TIMED с индексом вне списка' => ['type' => 'TIMED_QUIZ', 'options_text' => "a\nb", 'answer_text' => '7'],
        ];

        foreach ($broken as $label => $overrides) {
            $result = learnExerciseDraftToRow($this->draft($overrides), 1);

            self::assertNull($result['row'], 'Кривой черновик принят: ' . $label);
            self::assertNotSame('', $result['error'], 'Отказ без причины: ' . $label);
        }

        $unknown = learnExerciseDraftToRow($this->draft(['type' => 'НЕИЗВЕСТНЫЙ']), 1);
        self::assertNull($unknown['row']);
        self::assertStringContainsString('неизвестен', $unknown['error']);
    }

    public function testЧерновикУпражненияЧитаетсяОбратноБезПотерь(): void
    {
        $drafts = [
            $this->draft(['type' => 'MULTIPLE_CHOICE', 'options_text' => "am\nis\nare", 'answer_text' => '1']),
            $this->draft([
                'type' => 'LISTENING_CHOICE',
                'options_text' => "one\ntwo",
                'answer_text' => '1',
                'speak_text' => 'one',
            ]),
            $this->draft(['type' => 'FILL_IN_THE_BLANK', 'answer_text' => "was\nwere"]),
            $this->draft(['type' => 'TRANSLATION', 'answer_text' => "I like tea\nI enjoy tea"]),
            $this->draft(['type' => 'SENTENCE_BUILDING', 'options_text' => 'I like tea']),
            $this->draft(['type' => 'MATCHING', 'options_text' => "apple = яблоко\npear = груша"]),
            $this->draft(['type' => 'BRAINSTORM', 'options_text' => "apple\npear\nplum", 'answer_text' => '2']),
            $this->draft([
                'type' => 'TIMED_QUIZ',
                'options_text' => "a\nb",
                'answer_text' => '0',
                'time_limit_sec' => '30',
            ]),
        ];

        foreach ($drafts as $draft) {
            $first = $this->rowOf(learnExerciseDraftToRow($draft, 3));
            $second = $this->rowOf(learnExerciseDraftToRow(learnExerciseRowToDraft($first), 3));

            self::assertSame($first, $second, 'Обратное чтение потеряло данные: ' . (string) $draft['type']);
        }
    }

    public function testГрамматическиеПримерыТудаИОбратно(): void
    {
        $examples = learnTextToGrammarExamples("I am :: Я есть\nHe is\nThey are :: Они есть");

        self::assertSame([
            ['en' => 'I am', 'ru' => 'Я есть'],
            ['en' => 'He is'],
            ['en' => 'They are', 'ru' => 'Они есть'],
        ], $examples);
        self::assertSame("I am :: Я есть\nHe is\nThey are :: Они есть", learnGrammarExamplesToText($examples));
    }

    public function testПроверкаОтветовПоТипам(): void
    {
        $choice = ['type' => 'MULTIPLE_CHOICE', 'options' => '["a","b"]', 'correct_answer' => '1'];

        self::assertTrue(learnGrade($choice, 1)['correct']);
        self::assertTrue(learnGrade($choice, '1')['correct'], 'Индекс из формы приходит строкой');
        self::assertFalse(learnGrade($choice, 0)['correct']);
        self::assertFalse(learnGrade($choice, null)['correct'], 'Пустой ответ не равен нулевому варианту');
        self::assertSame(1, learnGrade($choice, 0)['revealed']);

        // Заполнение и перевод: регистр, лишние пробелы и конечные знаки не решают исход.
        $fill = ['type' => 'FILL_IN_THE_BLANK', 'options' => null, 'correct_answer' => '["was","were"]'];

        self::assertTrue(learnGrade($fill, '  Was. ')['correct']);
        self::assertTrue(learnGrade($fill, 'WERE!')['correct']);
        self::assertFalse(learnGrade($fill, 'is')['correct']);

        // Типографский апостроф приводится к обычному: с клавиатуры ученик ставит '.
        $apostrophe = ['type' => 'TRANSLATION', 'options' => null, 'correct_answer' => '["don\'t"]'];

        self::assertTrue(learnGrade($apostrophe, 'Don’t')['correct']);
        self::assertSame(['don\'t'], learnGrade($apostrophe, 'don’t')['revealed']);

        // Сборка предложения сравнивается канонически, а в ответе показывается нормализованная строка.
        $sentence = ['type' => 'SENTENCE_BUILDING', 'options' => null, 'correct_answer' => '"I like tea"'];

        self::assertTrue(learnGrade($sentence, 'i like tea.')['correct']);
        self::assertFalse(learnGrade($sentence, 'I like coffee')['correct']);
        self::assertSame('i like tea', learnGrade($sentence, 'I like coffee')['revealed']);

        // Пары принимаются только полным набором: лишняя или пропущенная пара — ошибка.
        $matching = [
            'type' => 'MATCHING',
            'options' => '{"pairs":[{"left":"apple","right":"яблоко"},{"left":"pear","right":"груша"}]}',
            'correct_answer' => '{"apple":"яблоко","pear":"груша"}',
        ];

        self::assertTrue(learnGrade($matching, ['apple' => 'яблоко', 'pear' => 'груша'])['correct']);
        self::assertFalse(learnGrade($matching, ['apple' => 'яблоко'])['correct']);
        self::assertFalse(
            learnGrade($matching, ['apple' => 'яблоко', 'pear' => 'груша', 'plum' => 'слива'])['correct']
        );

        // Мозговой штурм: единственное и множественное число — одно совпадение, дубли не считаются.
        $brainstorm = [
            'type' => 'BRAINSTORM',
            'options' => '{"minCount":2}',
            'correct_answer' => '["apple","pear","plum"]',
        ];

        $right = learnGrade($brainstorm, "Apple, pears\napple");
        self::assertTrue($right['correct']);
        self::assertSame(['apple', 'pears'], $right['matched']);
        self::assertSame(2, $right['required']);

        $few = learnGrade($brainstorm, 'apple, apples');
        self::assertFalse($few['correct']);
        self::assertSame(['apple'], $few['matched']);

        // Неизвестный тип не угадывается: ответ неверный, раскрывать нечего.
        $unknown = learnGrade(['type' => 'SOMETHING', 'options' => null, 'correct_answer' => '[]'], 'x');
        self::assertFalse($unknown['correct']);
        self::assertSame('', $unknown['revealed']);
    }

    public function testПубличнаяФормаСкрываетОтветИОбъяснение(): void
    {
        $public = learnPublicExercise([
            'id' => 'ex-1',
            'type' => 'MULTIPLE_CHOICE',
            'question' => 'Выберите перевод',
            'options' => '["яблоко","груша"]',
            'correct_answer' => '0',
            'explanation' => 'apple — яблоко',
            'difficulty' => 2,
            'skill' => 'VOCABULARY',
            'xp_reward' => 5,
            'sort' => 3,
            'audio_url' => null,
        ]);

        self::assertSame('ex-1', $public['id']);
        self::assertSame(['яблоко', 'груша'], $public['options']);
        self::assertSame('VOCABULARY', $public['skill']);
        self::assertSame(3, $public['sort']);
        self::assertArrayNotHasKey('correct_answer', $public);
        self::assertArrayNotHasKey('explanation', $public);
        self::assertArrayNotHasKey('difficulty', $public);
    }

    public function testПодборУпражненийДетерминированИСохраняетНачатые(): void
    {
        $bank = [];
        for ($i = 1; $i <= 12; $i++) {
            $type = 'MULTIPLE_CHOICE';
            if ($i === 2) {
                $type = 'LISTENING_CHOICE';
            } elseif ($i === 4) {
                $type = 'MATCHING';
            } elseif ($i === 6) {
                $type = 'BRAINSTORM';
            } elseif ($i === 8) {
                $type = 'TIMED_QUIZ';
            }

            $bank[] = [
                'id' => 'e' . $i,
                'type' => $type,
                'difficulty' => ($i % 3) + 1,
                'sort' => $i,
            ];
        }

        $first = learnPickExercises($bank, [], 7, 'user-1|lesson-1');
        $second = learnPickExercises($bank, [], 7, 'user-1|lesson-1');
        self::assertSame(array_column($first, 'id'), array_column($second, 'id'), 'Один seed обязан давать один набор');
        self::assertCount(7, $first);

        $other = learnPickExercises($bank, [], 7, 'user-2|lesson-1');
        self::assertNotSame(array_column($first, 'id'), array_column($other, 'id'), 'Разные seed обязаны перемешивать по-разному');

        // Резерв: редкие типы попадают в заход не больше одного раза.
        foreach (['LISTENING_CHOICE', 'MATCHING', 'BRAINSTORM', 'TIMED_QUIZ'] as $type) {
            $count = 0;
            foreach ($first as $exercise) {
                if ($exercise['type'] === $type) {
                    $count++;
                }
            }

            self::assertLessThanOrEqual(1, $count, 'Редкий тип повторился в заходе: ' . $type);
        }

        // Начатые упражнения сохраняются первыми и в порядке урока.
        $started = learnPickExercises($bank, ['e11', 'e10'], 7, 'user-1|lesson-1');
        self::assertSame(['e10', 'e11'], array_slice(array_column($started, 'id'), 0, 2));
        self::assertCount(7, $started);

        // Свежая часть идёт от лёгкого к сложному: хвост после начатых упорядочен.
        $tail = array_slice($started, 2);
        $tailDifficulties = array_column($tail, 'difficulty');
        $sorted = $tailDifficulties;
        sort($sorted);
        self::assertSame($sorted, $tailDifficulties, 'Новые задания обязаны идти от лёгкого к сложному');

        // Банк не больше захода — отдаём всё и в порядке урока.
        $small = array_slice($bank, 0, 5);
        $all = learnPickExercises($small, [], 7, 'any');
        self::assertSame(['e1', 'e2', 'e3', 'e4', 'e5'], array_column($all, 'id'));
    }

    public function testОценкаТестаНаУровеньПоНавыкамИПороги(): void
    {
        $questions = [
            ['skill' => 'VOCABULARY', 'correct' => 0],
            ['skill' => 'VOCABULARY', 'correct' => 1],
            ['skill' => 'GRAMMAR', 'correct' => 2],
            ['skill' => 'GRAMMAR', 'correct' => 0],
            ['skill' => 'READING', 'correct' => 1],
            ['skill' => 'LISTENING', 'correct' => 3],
        ];
        $ids = ['q1', 'q2', 'q3', 'q4', 'q5', 'q6'];
        $answers = [
            ['question_id' => 'q1', 'option_index' => 0],
            ['question_id' => 'q2', 'option_index' => 0],
            ['question_id' => 'q3', 'option_index' => 2],
            ['question_id' => 'q4', 'option_index' => 0],
            ['question_id' => 'q5', 'option_index' => 1],
            ['question_id' => 'q6', 'option_index' => 0],
            ['question_id' => 'чужой', 'option_index' => 0],
        ];

        $scores = learnPlacementScores($questions, $answers, $ids);

        self::assertSame(67, $scores['score']);
        self::assertSame(4, $scores['correct_count']);
        self::assertSame(50, $scores['vocabulary_score']);
        self::assertSame(100, $scores['grammar_score']);
        self::assertSame(100, $scores['reading_score']);
        self::assertSame(0, $scores['listening_score']);

        // Без ответов шкала пустая: ноль баллов и ни одного процента навыка.
        $empty = learnPlacementScores($questions, [], $ids);
        self::assertSame(0, $empty['score']);
        self::assertSame(0, $empty['correct_count']);
        self::assertNull($empty['vocabulary_score']);
        self::assertNull($empty['grammar_score']);
        self::assertNull($empty['reading_score']);
        self::assertNull($empty['listening_score']);

        // Порог берёт самый верхний достигнутый уровень.
        $levels = [
            0 => 'Pre-A1',
            39 => 'Pre-A1',
            40 => 'A1',
            69 => 'A1',
            70 => 'A2',
            79 => 'A2',
            80 => 'B1',
            89 => 'B1',
            90 => 'B2',
            96 => 'B2',
            97 => 'C1',
            100 => 'C1',
        ];
        foreach ($levels as $percent => $code) {
            self::assertSame($code, learnDecideLevel($percent), 'Порог уровня для ' . $percent . '%');
        }
    }

    public function testНаградаЗаУрокПорогиКубкаИПодсказки(): void
    {
        $tally = learnTallySkills([
            ['skill' => 'GRAMMAR', 'correct' => true],
            ['skill' => 'GRAMMAR', 'correct' => false],
            ['skill' => null, 'correct' => true],
            ['skill' => 'VOCABULARY', 'correct' => true],
            ['skill' => 'VOCABULARY', 'correct' => true],
        ]);

        self::assertSame([
            ['skill' => 'GRAMMAR', 'label' => 'Грамматика', 'right' => 1, 'total' => 2],
            ['skill' => 'VOCABULARY', 'label' => 'Лексика', 'right' => 2, 'total' => 2],
        ], $tally);

        // Идеальный урок: ни одной ошибки и тест на 100 — золото и особая подпись.
        $flawless = learnAward([
            'xp' => 60,
            'exercise_right' => 5,
            'exercise_total' => 5,
            'test_score' => 100,
            'module_completed' => true,
            'streak_days' => 3,
            'skills' => [['skill' => 'GRAMMAR', 'label' => 'Грамматика', 'right' => 5, 'total' => 5]],
        ]);

        self::assertSame('gold', $flawless['trophy']);
        self::assertSame('Ни одной ошибки', $flawless['title']);
        self::assertSame(60, $flawless['xp']);
        self::assertContains('Заданий решено верно: 5 из 5', $flawless['wins']);
        self::assertContains('Мини-тест урока: 100%', $flawless['wins']);
        self::assertContains('Модуль закрыт целиком', $flawless['wins']);
        self::assertContains('Серия занятий: 3 дня', $flawless['wins']);
        self::assertNull($flawless['focus']);
        self::assertSame(['label' => 'Грамматика', 'right' => 5, 'total' => 5], $flawless['strength']);

        // 80% и тест на 80 — ещё золото, но без подписи «ни одной ошибки».
        $strong = learnAward([
            'xp' => 50,
            'exercise_right' => 4,
            'exercise_total' => 5,
            'test_score' => 80,
            'module_completed' => false,
            'streak_days' => 0,
            'skills' => [],
        ]);
        self::assertSame('gold', $strong['trophy']);
        self::assertSame('Уверенный результат', $strong['title']);
        self::assertNull($strong['focus']);

        // 60% и тест на 60 — серебро; ниже — бронза.
        $fair = learnAward([
            'xp' => 40,
            'exercise_right' => 3,
            'exercise_total' => 5,
            'test_score' => 60,
            'module_completed' => false,
            'streak_days' => 1,
            'skills' => [],
        ]);
        self::assertSame('silver', $fair['trophy']);
        self::assertSame('Ещё чуть-чуть до идеала', $fair['title']);

        $low = learnAward([
            'xp' => 20,
            'exercise_right' => 2,
            'exercise_total' => 5,
            'test_score' => 59,
            'module_completed' => false,
            'streak_days' => 0,
            'skills' => [],
        ]);
        self::assertSame('bronze', $low['trophy']);
        self::assertSame('Начало положено', $low['title']);

        // Слабый навык — самый низкий процент; при равенстве берём тот, где больше попыток.
        $withFocus = learnAward([
            'xp' => 30,
            'exercise_right' => 3,
            'exercise_total' => 4,
            'test_score' => null,
            'module_completed' => false,
            'streak_days' => 0,
            'skills' => [
                ['skill' => 'GRAMMAR', 'label' => 'Грамматика', 'right' => 1, 'total' => 2],
                ['skill' => 'READING', 'label' => 'Чтение', 'right' => 2, 'total' => 4],
                ['skill' => 'VOCABULARY', 'label' => 'Лексика', 'right' => 2, 'total' => 2],
            ],
        ]);

        self::assertSame('Чтение', $withFocus['focus']['label'] ?? '');
        self::assertSame(2, $withFocus['focus']['right'] ?? 0);
        self::assertSame(4, $withFocus['focus']['total'] ?? 0);
        self::assertStringContainsString('текст', $withFocus['focus']['hint'] ?? '');
        self::assertSame(['label' => 'Лексика', 'right' => 2, 'total' => 2], $withFocus['strength']);

        // Один верный ответ сильной стороной не объявляется: нужно хотя бы два.
        $noStrength = learnAward([
            'xp' => 10,
            'exercise_right' => 1,
            'exercise_total' => 1,
            'test_score' => null,
            'module_completed' => false,
            'streak_days' => 0,
            'skills' => [['skill' => 'GRAMMAR', 'label' => 'Грамматика', 'right' => 1, 'total' => 1]],
        ]);
        self::assertNull($noStrength['strength']);
    }

    public function testФокусКабинета(): void
    {
        // Обращение по времени суток: границы утра, дня, вечера и ночи.
        $greetings = [
            '05:00' => 'Доброе утро',
            '11:59' => 'Доброе утро',
            '12:00' => 'Добрый день',
            '17:59' => 'Добрый день',
            '18:00' => 'Добрый вечер',
            '22:59' => 'Добрый вечер',
            '23:00' => 'Добрая ночь',
            '04:59' => 'Добрая ночь',
        ];
        foreach ($greetings as $time => $expected) {
            self::assertSame($expected, learnDayGreeting(new DateTimeImmutable('2026-11-01 ' . $time)));
        }

        // Реплика о серии: сегодняшнее занятие, пустая серия и «держится до полуночи».
        self::assertSame('Сегодняшнее занятие засчитано, серия — 3 дня.', learnStreakNudge(3, true));
        self::assertSame('Сегодняшнее занятие засчитано, серия — 1 день.', learnStreakNudge(1, true));
        self::assertSame('Серии пока нет — начнём её с одного урока сегодня.', learnStreakNudge(0, false));
        self::assertSame(
            'Серия 5 дней держится до полуночи: сегодня ещё ни одного занятия.',
            learnStreakNudge(5, false)
        );

        // «Сегодня» — это UTC-день: дата из колонки DATE сравнивается с днём сервера.
        self::assertTrue(learnIsToday('2026-11-01', new DateTimeImmutable('2026-11-01 10:00')));
        self::assertFalse(learnIsToday('2026-10-31', new DateTimeImmutable('2026-11-01 10:00')));
        self::assertFalse(learnIsToday(null, new DateTimeImmutable('2026-11-01 10:00')));

        // Слабый навык требует выборки от восьми ответов; идеальные и редкие не трогаем.
        $stats = [
            ['skill' => 'GRAMMAR', 'label' => 'Грамматика', 'right' => 6, 'total' => 10],
            ['skill' => 'VOCABULARY', 'label' => 'Лексика', 'right' => 8, 'total' => 10],
            ['skill' => 'READING', 'label' => 'Чтение', 'right' => 2, 'total' => 8],
            ['skill' => 'LISTENING', 'label' => 'Аудирование', 'right' => 0, 'total' => 3],
        ];
        self::assertSame('READING', learnWeakSkill($stats)['skill'] ?? '');

        // При равной доле слабее тот навык, где больше попыток: вывод надёжнее.
        $tie = [
            ['skill' => 'GRAMMAR', 'label' => 'Грамматика', 'right' => 4, 'total' => 8],
            ['skill' => 'READING', 'label' => 'Чтение', 'right' => 2, 'total' => 4],
        ];
        self::assertSame('GRAMMAR', learnWeakSkill($tie)['skill'] ?? '');

        // Сильная сторона — только стопроцентный навык с достаточной выборкой.
        $strong = [
            ['skill' => 'GRAMMAR', 'label' => 'Грамматика', 'right' => 10, 'total' => 10],
            ['skill' => 'VOCABULARY', 'label' => 'Лексика', 'right' => 8, 'total' => 8],
            ['skill' => 'READING', 'label' => 'Чтение', 'right' => 7, 'total' => 8],
        ];
        self::assertSame('VOCABULARY', learnStrongSkill($strong, 'Грамматика')['skill'] ?? '');
        self::assertSame('GRAMMAR', learnStrongSkill($strong, null)['skill'] ?? '');

        // Фокус кабинета: процент, совет и объём данных за выводом.
        $focus = learnFocusOf(['skill' => 'READING', 'label' => 'Чтение', 'right' => 1, 'total' => 4]);
        self::assertSame('Чтение', $focus['label']);
        self::assertSame(25, $focus['percent']);
        self::assertSame(4, $focus['total']);
        self::assertStringContainsString('текст', $focus['advice']);
    }

    /**
     * Черновик формы с полями по умолчанию.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function draft(array $overrides): array
    {
        return array_merge([
            'type' => 'MULTIPLE_CHOICE',
            'question' => 'Вопрос упражнения',
            'explanation' => '',
            'skill' => '',
            'difficulty' => 1,
            'xp_reward' => 5,
            'options_text' => '',
            'answer_text' => '',
            'speak_text' => '',
            'time_limit_sec' => '',
            'audio_url' => '',
        ], $overrides);
    }

    /**
     * Строка из результата преобразования черновика: отказ — падение теста с причиной.
     *
     * @param array{row: array<string, mixed>|null, error: string} $result
     * @return array<string, mixed>
     */
    private function rowOf(array $result): array
    {
        self::assertSame('', $result['error']);
        self::assertNotNull($result['row']);

        return $result['row'];
    }
}
