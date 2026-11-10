<?php

declare(strict_types=1);

/** @var array<string, mixed> $section */

// Форма заявки (§17): слева обещания из items, справа сама форма. Форма — обычный POST без
// JavaScript на маршрут /lead; скрытая ловушка company отсекает ботов, чекбокс согласия обязателен,
// источник страницы собирает сервер (requestPath + якорь), а не window.location. Ответ после
// отправки — PRG с notice: человек остаётся у формы и видит плашку над ней.
$promises = [];

foreach (pagesItemsOf($section['items'] ?? null) as $row) {
    $text = trim((string) ($row['text'] ?? ''));

    if ($text === '') {
        continue;
    }

    $promises[] = $text;
}

$note = trim((string) ($section['note'] ?? ''));
$anchor = (string) $section['anchor'];
$source = requestPath()
    . (queryString('draft') === '1' ? '?draft=1' : '')
    . '#' . ($anchor !== '' ? $anchor : (string) ($section['sec_key'] ?? ''));
$noticeKey = queryString('notice');
?>
<section class="py-12"<?php if ($anchor !== ''): ?> id="<?= e($anchor) ?>"<?php endif; ?>>
    <div class="mx-auto max-w-5xl px-4 sm:px-6">
        <div class="grid gap-8 md:grid-cols-2">
            <div>
                <?= partial('sections/head', ['section' => $section, 'tag' => 'h2']) ?>
                <?php if ($promises !== []): ?>
                    <ul class="mt-6 space-y-3">
                        <?php foreach ($promises as $promise): ?>
                            <li class="flex items-start gap-2 text-ink/80">
                                <span class="mt-2 size-2 shrink-0 rounded-full bg-accent" aria-hidden="true"></span>
                                <span><?= e($promise) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($note !== ''): ?>
                    <p class="mt-6 text-sm text-ink/50"><?= e($note) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <?php if ($noticeKey === 'lead_sent'): ?>
                    <?php $accepted = takeLeadAcceptedContact(); ?>
                    <p class="mb-4 rounded-lg border border-good/30 bg-good/10 px-4 py-3 text-sm text-good" role="status">
                        <?= e((string) noticeText('lead_sent', CORE_NOTICE_TEXT)) ?>
                        <?php if ($accepted !== '' && str_contains($accepted, '@')): ?>
                            Подтверждение придёт на <?= e($accepted) ?>.
                        <?php endif; ?>
                    </p>
                <?php elseif ($noticeKey === 'lead_failed'): ?>
                    <?php $reason = takeLeadFlashReason(); ?>
                    <p class="mb-4 rounded-lg border border-bad/30 bg-bad/10 px-4 py-3 text-sm text-bad" role="alert">
                        <?= e($reason !== '' ? $reason : (string) noticeText('lead_failed', CORE_NOTICE_TEXT)) ?>
                    </p>
                <?php endif; ?>

                <form class="space-y-4" method="post" action="/lead">
                    <?= csrfField() /* @safe html */ ?>
                    <input type="hidden" name="source" value="<?= e($source) ?>">
                    <label class="block text-sm">
                        <span class="font-medium">Как к вам обращаться</span>
                        <input class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" type="text" name="name" autocomplete="name" required
                               minlength="<?= e((string) LEAD_NAME_MIN) ?>" maxlength="<?= e((string) LEAD_NAME_MAX) ?>"
                               placeholder="Мария">
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium">Телефон или почта</span>
                        <input class="mt-1.5 min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm" type="text" name="contact" autocomplete="tel" required
                               minlength="<?= e((string) LEAD_CONTACT_MIN) ?>" maxlength="<?= e((string) LEAD_CONTACT_MAX) ?>"
                               placeholder="+7 900 000-00-00 или maria@example.ru">
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium">Вопрос</span>
                        <textarea class="mt-1.5 h-[96px] min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm"
                                  name="message" rows="3" maxlength="<?= e((string) LEAD_MESSAGE_MAX) ?>"
                                  placeholder="Уровень, сколько человек, удобное время для занятия"></textarea>
                    </label>

                    <div class="sr-only" aria-hidden="true">
                        <label for="lead-company">Название фирмы</label>
                        <input class="sr-only" id="lead-company" type="text" name="company" tabindex="-1" autocomplete="off">
                    </div>

                    <label class="flex min-h-[44px] cursor-pointer items-start gap-2 text-sm select-none">
                        <input class="mt-0.5 h-5 w-5 min-h-[44px] shrink-0 accent-ink" type="checkbox" required>
                        <span>Согласие на обработку персональных данных</span>
                    </label>
                    <p class="text-xs text-ink/50">
                        Данные нужны только чтобы ответить на заявку; подробности — в
                        <a class="inline-flex min-h-[44px] items-center text-accent underline-offset-4 hover:underline" href="/privacy">политике конфиденциальности</a>.
                    </p>

                    <button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 font-medium text-cream hover:opacity-90"
                            type="submit">Отправить заявку</button>
                </form>
            </div>
        </div>
    </div>
</section>
