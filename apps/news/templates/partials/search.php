<?php

declare(strict_types=1);

/** @var string $q */
?>
<form class="flex flex-col gap-2 sm:flex-row" action="/news" method="get" role="search">
    <label class="sr-only" for="news-q">Поиск по материалам</label>
    <input class="min-h-[44px] w-full rounded-lg border border-ink/10 bg-cream px-3 py-2"
           id="news-q"
           type="search"
           name="q"
           maxlength="<?= e((string) SEARCH_MAX_LENGTH) ?>"
           value="<?= e($q) ?>"
           placeholder="Что ищем в материалах?">
    <button class="min-h-[44px] rounded-lg bg-accent px-5 py-2 text-cream hover:opacity-90" type="submit">Найти</button>
</form>
