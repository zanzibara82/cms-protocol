<?php

declare(strict_types=1);

/** @var array<string, mixed> $type */
/** @var array<string, mixed>|null $entry */
/** @var list<array<string, mixed>> $fields */
/** @var array<string, string> $values */
/** @var array<string, string> $data */
/** @var array<string, list<string>> $relationIds */
/** @var array<string, string> $errors */
/** @var list<array<string, mixed>> $schedules */
/** @var int $version */
/** @var bool $canEdit */
/** @var bool $canPublish */
/** @var bool $canSchedule */
/** @var string $notice */
/** @var string $alertTitle */
/** @var string $alertReason */

$input = 'mt-1.5 w-full rounded-lg border border-ink/20 bg-white px-3 py-2 text-sm';
$primary = 'inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90';
$button = 'inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5';
$isNew = $entry === null;
$entryId = $isNew ? '' : (string) $entry['id'];
$target = $isNew
    ? '/admin/content/types/' . rawurlencode((string) $type['id']) . '/entries/new'
    : '/admin/content/entries/' . rawurlencode($entryId);
$back = '/admin/content/types/' . rawurlencode((string) $type['id']) . '/entries';

?>
<?= partial('admin/alert', ['title' => $alertTitle, 'reason' => $alertReason]) /* @safe html */ ?>
<p><a class="inline-flex min-h-[44px] items-center text-sm hover:underline" href="<?= e($back) ?>">← К списку записей</a></p>
<?php if (!$isNew && (string) $entry['status'] === CONTENT_STATUS_PUBLISHED): ?>
    <p><a class="inline-flex min-h-[44px] items-center text-sm hover:underline" href="/<?= e((string) $type['slug']) ?>/<?= e((string) $entry['slug']) ?>" target="_blank" rel="noopener noreferrer">Открыть публичную страницу</a></p>
<?php endif; ?>
<?php if (!$isNew && (string) $entry['status'] !== CONTENT_STATUS_PUBLISHED && roleGrantsManage(currentUser())): ?>
    <p class="text-sm text-ink/60">Черновик виден только сотрудникам редакции по прямому адресу.</p>
<?php endif; ?>

<form class="space-y-4" method="post" action="<?= e($target) ?>">
    <?= csrfField() /* @safe html */ ?>
    <input type="hidden" name="op" value="save-entry">
    <?php if (!$isNew): ?>
        <input type="hidden" name="expected_version" value="<?= e((string) $version) ?>">
        <input type="hidden" name="idempotency_key" value="<?= e(newId()) ?>">
    <?php endif; ?>
    <section class="rounded-lg border border-ink/10 bg-white p-4">
        <h2 class="font-semibold">Заголовок и адрес</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="block min-w-0 text-sm"><span class="block font-medium">Заголовок</span>
                <input class="min-h-[44px] <?= e($input) ?>" type="text" name="title" value="<?= e((string) $values['title']) ?>" required></label>
            <label class="block min-w-0 text-sm"><span class="block font-medium">Адрес записи</span>
                <input class="min-h-[44px] <?= e($input) ?> font-mono" type="text" name="slug" value="<?= e((string) $values['slug']) ?>">
                <span class="mt-1.5 block text-ink/60">Без ввода соберётся из заголовка. Прежний адрес после смены продолжит работать.</span></label>
        </div>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="block min-w-0 text-sm"><span class="block font-medium">SEO-заголовок</span>
                <input class="min-h-[44px] <?= e($input) ?>" type="text" name="seo_title" value="<?= e((string) $values['seo_title']) ?>"></label>
            <label class="block min-w-0 text-sm"><span class="block font-medium">SEO-описание</span>
                <input class="min-h-[44px] <?= e($input) ?>" type="text" name="seo_description" value="<?= e((string) $values['seo_description']) ?>"></label>
        </div>
        <?php if (!$isNew): ?>
            <label class="mt-3 block text-sm"><span class="block font-medium">Комментарий к версии</span>
                <input class="min-h-[44px] <?= e($input) ?>" type="text" name="comment" value=""></label>
        <?php endif; ?>
    </section>
    <section class="rounded-lg border border-ink/10 bg-white p-4">
        <h2 class="font-semibold">Поля записи</h2>
        <div class="mt-3 space-y-3">
            <?= partial('admin/content-fields', [ /* @safe html */
                'fields' => $fields,
                'data' => $data,
                'relationIds' => $relationIds,
                'errors' => $errors,
            ]) ?>
        </div>
        <p class="mt-3"><button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-3 py-1.5 text-sm text-cream hover:opacity-90" type="submit"><?= e($isNew ? 'Создать запись' : 'Сохранить запись') ?></button></p>
    </section>
</form>

<?php if (!$isNew): ?>
    <?php if (!$canEdit): ?>
        <p class="text-sm text-ink/60">Правка полей доступна редактору; вы видите запись в режиме чтения.</p>
    <?php endif; ?>
    <?php if ($schedules !== []): ?>
        <section class="rounded-lg border border-ink/10 bg-white p-4">
            <h2 class="font-semibold">Запланированная публикация</h2>
            <ul class="mt-2">
                <?php foreach ($schedules as $schedule): ?>
                    <li class="flex flex-wrap items-center justify-between gap-2 border-b border-ink/10 py-2 text-sm">
                        <span><?= e(formatRuDateTime(fromDb((string) $schedule['publish_at']))) ?> · <?= e((string) $schedule['timezone']) ?> · <?= e((string) $schedule['status']) ?></span>
                        <form method="post" action="<?= e($target) ?>">
                            <?= csrfField() /* @safe html */ ?>
                            <input type="hidden" name="op" value="cancel-schedule">
                            <input type="hidden" name="schedule" value="<?= e((string) $schedule['id']) ?>">
                            <button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5" type="submit">Отменить расписание</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="mt-2 text-sm text-ink/60">Пока есть расписание, статус записи меняется только после его отмены.</p>
        </section>
    <?php endif; ?>
    <section class="rounded-lg border border-ink/10 bg-white p-4">
        <h2 class="font-semibold">Статус</h2>
        <form class="mt-3 flex flex-wrap items-end gap-3" method="post" action="<?= e($target) ?>">
            <?= csrfField() /* @safe html */ ?>
            <input type="hidden" name="op" value="status">
            <input type="hidden" name="expected_version" value="<?= e((string) $version) ?>">
            <label class="block min-w-0 text-sm"><span class="block font-medium">Состояние</span>
                <select class="min-h-[44px] <?= e($input) ?>" name="status">
                    <?php foreach ([CONTENT_STATUS_DRAFT, CONTENT_STATUS_PUBLISHED, CONTENT_STATUS_ARCHIVED] as $status): ?>
                        <option value="<?= e($status) ?>"<?= e((string) $entry['status'] === $status ? ' selected' : '') ?>><?= e(CONTENT_STATUS_LABELS[$status]) ?></option>
                    <?php endforeach; ?>
                </select></label>
            <button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5" type="submit"<?= e($canPublish ? '' : ' disabled') ?>>Изменить статус</button>
        </form>
        <p class="mt-1.5 text-sm text-ink/60">Публикация и архив требуют права «публикация». Отложенный вывод настраивается отдельной формой ниже.</p>
        <p class="mt-1.5"><a class="inline-flex min-h-[44px] items-center text-sm hover:underline" href="/admin/editorial?<?= e(http_build_query(['type' => 'entry', 'id' => $entryId])) ?>">История, сравнение и комментарии</a></p>
    </section>
    <?php if ($canSchedule && (string) $entry['status'] !== CONTENT_STATUS_ARCHIVED): ?>
        <section class="rounded-lg border border-ink/10 bg-white p-4">
            <h2 class="font-semibold">Отложенная публикация</h2>
            <form class="mt-3 flex flex-wrap items-end gap-3" method="post" action="<?= e($target) ?>">
                <?= csrfField() /* @safe html */ ?>
                <input type="hidden" name="op" value="schedule">
                <label class="block min-w-0 text-sm"><span class="block font-medium">Дата и время</span>
                    <input class="min-h-[44px] <?= e($input) ?>" type="datetime-local" name="publish_at" required></label>
                <label class="block min-w-0 text-sm"><span class="block font-medium">Часовой пояс</span>
                    <select class="min-h-[44px] <?= e($input) ?>" name="timezone">
                        <?php foreach (scheduleTimezones() as $zone): ?>
                            <option value="<?= e($zone) ?>"><?= e($zone) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 bg-white px-3 py-1.5 text-sm hover:bg-ink/5" type="submit">Запланировать</button>
            </form>
        </section>
    <?php endif; ?>
<?php endif; ?>
