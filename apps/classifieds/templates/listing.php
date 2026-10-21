<?php

declare(strict_types=1);

/**
 * Страница объявления: фото, галерея, характеристики, цена, описание и место.
 *
 * @var array<string, mixed> $listing
 * @var string $priceLabel
 * @var string $dateLabel
 * @var string $categoryHref
 * @var string $categoryTitle
 * @var bool $preview
 * @var list<array{id: string, href: string}> $gallery
 * @var list<array{id: string, title: string, display: string}> $attributes
 * @var array{id: string, name: string, city: string, phone: string, about: string, show_phone: bool, member_since: ?DateTimeImmutable}|null $seller
 * @var string $sellerHref
 * @var bool $canFavorite
 * @var bool $isFavorite
 * @var string $favoriteAction
 * @var string $favoriteLogin
 * @var bool $canContact
 * @var string $contactAction
 * @var string $contactLogin
 * @var string $reportHref
 * @var string $reportLogin
 * @var string $viewsLabel
 */
?>
<article class="space-y-6">
    <nav aria-label="Хлебные крошки" class="flex flex-wrap items-center gap-2 text-sm text-ink/60">
        <a class="inline-flex min-h-[44px] items-center hover:underline" href="/classifieds">Доска</a>
        <?php if ($categoryHref !== ''): ?>
            <span aria-hidden="true">/</span>
            <a class="inline-flex min-h-[44px] items-center hover:underline" href="<?= e($categoryHref) ?>"><?= e($categoryTitle) ?></a>
        <?php endif; ?>
    </nav>

    <?php if ($preview): ?>
        <p class="rounded-lg border border-ink/10 bg-accent/10 p-4 text-sm">
            Предпросмотр: объявление ещё не опубликовано — его видите только вы.
        </p>
        <?php if ((string) $listing['status'] === 'REJECTED' && (string) ($listing['moderation_note'] ?? '') !== ''): ?>
            <p class="rounded-lg border border-ink/20 bg-cream p-4 text-sm">
                Проверка не пройдена: <?= e((string) $listing['moderation_note']) ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <header class="space-y-2">
        <h1 class="text-3xl font-bold"><?= e((string) $listing['title']) ?></h1>
        <p class="text-2xl font-semibold tabular-nums"><?= e($priceLabel) ?></p>
        <p class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink/60">
            <?php if ((string) ($listing['location'] ?? '') !== ''): ?>
                <span><?= e((string) $listing['location']) ?></span>
            <?php endif; ?>
            <?php if ($dateLabel !== ''): ?>
                <span><?= e($dateLabel) ?></span>
            <?php endif; ?>
            <?php if ($viewsLabel !== ''): ?>
                <span><?= e($viewsLabel) ?></span>
            <?php endif; ?>
        </p>
    </header>

    <?php if ((string) ($listing['image'] ?? '') !== ''): ?>
        <img class="w-full rounded-xl object-cover" src="<?= e((string) $listing['image']) ?>" alt="" loading="lazy">
    <?php endif; ?>

    <?php if ($gallery !== []): ?>
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3" aria-label="Дополнительные изображения">
            <?php foreach ($gallery as $image): ?>
                <li>
                    <img class="h-40 w-full rounded-lg object-cover" src="<?= e($image['href']) ?>" alt="" loading="lazy">
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($attributes !== []): ?>
        <section class="space-y-2">
            <h2 class="text-xl font-semibold">Характеристики</h2>
            <dl class="divide-y divide-ink/10 rounded-xl border border-ink/10 bg-white">
                <?php foreach ($attributes as $attribute): ?>
                    <div class="flex flex-wrap justify-between gap-3 px-4 py-3 text-sm">
                        <dt class="text-ink/60"><?= e($attribute['title']) ?></dt>
                        <dd class="font-medium"><?= e($attribute['display']) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    <?php endif; ?>

    <section class="space-y-2">
        <h2 class="text-xl font-semibold">Описание</h2>
        <p class="whitespace-pre-line text-ink/80"><?= e((string) $listing['body']) ?></p>
    </section>

    <?php if ($seller !== null): ?>
        <section class="space-y-3 rounded-xl border border-ink/10 bg-white p-4">
            <h2 class="text-xl font-semibold">Продавец</h2>
            <a class="inline-flex min-h-[44px] items-center font-medium underline underline-offset-4 hover:text-accent"
               href="<?= e($sellerHref) ?>"><?= e($seller['name']) ?></a>
            <div class="space-y-1 text-sm text-ink/70">
                <?php if ($seller['city'] !== ''): ?>
                    <p>Город: <?= e($seller['city']) ?></p>
                <?php endif; ?>
                <?php if ($seller['member_since'] !== null): ?>
                    <p>На доске с <?= e(formatRuDate($seller['member_since'])) ?></p>
                <?php endif; ?>
                <?php if ($seller['phone'] !== ''): ?>
                    <p>Телефон: <a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"
                                   href="tel:<?= e($seller['phone']) ?>"><?= e($seller['phone']) ?></a></p>
                <?php endif; ?>
            </div>
            <?php if ($seller['about'] !== ''): ?>
                <p class="whitespace-pre-line text-sm text-ink/80"><?= e($seller['about']) ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($canFavorite): ?>
        <div class="flex flex-wrap gap-2">
            <?php if ($isFavorite): ?>
                <form method="post" action="<?= e($favoriteAction) ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="op" value="remove">
                    <button class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5"
                            type="submit">Убрать из избранного</button>
                </form>
            <?php elseif ($favoriteLogin !== ''): ?>
                <a class="inline-flex min-h-[44px] items-center rounded-lg border border-ink/20 px-4 py-2 text-sm hover:bg-ink/5"
                   href="<?= e($favoriteLogin) ?>">Войти, чтобы сохранить</a>
            <?php else: ?>
                <form method="post" action="<?= e($favoriteAction) ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="op" value="add">
                    <button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"
                            type="submit">В избранное</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($canContact): ?>
        <section class="space-y-3 rounded-xl border border-ink/10 bg-white p-4">
            <h2 class="text-xl font-semibold">Написать продавцу</h2>
            <form class="space-y-2" method="post" action="<?= e($contactAction) ?>">
                <?= csrfField() ?>
                <label class="block" for="message-body">
                    <span class="mb-1 block text-sm text-ink/70">Сообщение</span>
                    <textarea class="min-h-[44px] w-full rounded-lg border border-ink/20 bg-white px-3 py-2" id="message-body"
                              name="body" maxlength="<?= e((string) CLASSIFIEDS_MESSAGE_MAX) ?>" required></textarea>
                </label>
                <button class="inline-flex min-h-[44px] items-center rounded-lg bg-ink px-4 py-2 text-sm font-medium text-cream hover:bg-ink/90"
                        type="submit">Отправить</button>
            </form>
            <p class="text-sm text-ink/60">
                <a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"
                   href="<?= e($reportHref) ?>">Пожаловаться на объявление</a>
            </p>
        </section>
    <?php elseif ($contactLogin !== ''): ?>
        <p class="rounded-xl border border-ink/10 bg-cream p-4 text-sm text-ink/60">
            <a class="inline-flex min-h-[44px] items-center underline underline-offset-4 hover:text-accent"
               href="<?= e($contactLogin) ?>">Войдите</a>, чтобы написать продавцу или пожаловаться на объявление.
        </p>
    <?php endif; ?>

    <footer class="rounded-xl border border-ink/10 bg-cream p-4 text-sm text-ink/60">
        Объявление размещено на доске. Договоритесь с продавцом о встрече и осмотрите товар до оплаты.
    </footer>
</article>
