<?php

declare(strict_types=1);

// Пределы полей и гард регистрации ученика: такие же цифры, что у доски, но свой журнал
// попыток и свой набор допустимых ответов онбординга.
const LEARN_REGISTER_EMAIL_MAX = 190;
const LEARN_REGISTER_NAME_MAX = 120;
const LEARN_REGISTER_PASSWORD_MIN = 8;
const LEARN_REGISTER_LIMIT_PER_IP = 10;
const LEARN_REGISTER_WINDOW_MINUTES = 60;
// Колонка email журнала шириной 120 символов — длинный адрес не попадает в INSERT.
const LEARN_REGISTER_LOG_EMAIL_MAX = 120;

/**
 * Валидация формы регистрации ученика: почта, имя, пароль. Занятость почты и гард по IP
 * проверяет вызывающий обработчик — здесь только формы и пределы полей.
 *
 * @param array<string, string> $data
 * @return array<string, string> ошибки по имени поля, пусто — полени корректны
 */
function learnValidateRegistration(array $data): array
{
    $errors = [];
    $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
    $name = trim((string) ($data['name'] ?? ''));
    $password = (string) ($data['password'] ?? '');

    if ($email === '') {
        $errors['email'] = 'Почта пустая — без неё не восстановить доступ.';
    } elseif (mb_strlen($email) > LEARN_REGISTER_EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Это не похоже на почту — пример: ivan@example.ru.';
    }

    if ($name === '') {
        $errors['name'] = 'Напишите имя — его увидят в кабинете.';
    } elseif (mb_strlen($name) > LEARN_REGISTER_NAME_MAX) {
        $errors['name'] = 'Имя длиннее ' . LEARN_REGISTER_NAME_MAX . ' символов — сократите.';
    }

    if (mb_strlen($password) < LEARN_REGISTER_PASSWORD_MIN) {
        $errors['password'] = 'Пароль короче ' . LEARN_REGISTER_PASSWORD_MIN . ' символов — так проще подобрать.';
    }

    return $errors;
}

/**
 * Валидация ответов онбординга: самооценка, цель и нагрузка должны быть из перечней
 * констант, иначе профиль не пишется.
 *
 * @param array<string, mixed> $data
 * @return array<string, string> ошибки по имени поля, пусто — ответы корректны
 */
function learnValidateOnboarding(array $data): array
{
    $errors = [];
    $selfAssessment = (string) ($data['self_assessment'] ?? '');
    $goal = (string) ($data['goal'] ?? '');
    $dailyMinutes = (string) ($data['daily_minutes'] ?? '');

    if (!in_array($selfAssessment, array_column(LEARN_ONBOARDING_SELF, 'value'), true)) {
        $errors['self_assessment'] = 'Выберите вариант из списка.';
    }

    if (!in_array($goal, array_column(LEARN_ONBOARDING_GOALS, 'value'), true)) {
        $errors['goal'] = 'Выберите вариант из списка.';
    }

    $allowedMinutes = array_map(static fn (int $m): string => (string) $m, LEARN_ONBOARDING_MINUTES);
    if (!in_array($dailyMinutes, $allowedMinutes, true)) {
        $errors['daily_minutes'] = 'Выберите нагрузку из списка.';
    }

    return $errors;
}
