<?php

declare(strict_types=1);

addRoute('GET', '/future', 'futureFeed', ['app' => 'future']);

/** @param array<string, string> $params */
function futureFeed(array $params = []): void
{
    respond(200, 'Лента раздела из будущего', ['Content-Type' => 'text/plain; charset=utf-8']);
}
