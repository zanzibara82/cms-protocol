<?php

declare(strict_types=1);

// Идентификатор — 26 символов Crockford base32: первые 10 — миллисекунды, дальше 16 случайных.
// Сортируемость по времени нужна списку материалов и журналу, а известность до вставки — форме,
// которая показывает ссылку на материал сразу после сохранения.
const ID_TIME_LENGTH = 10;
const ID_RANDOM_LENGTH = 16;
const ID_ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

function newId(): string
{
    return encodeTime((int) floor(microtime(true) * 1000)) . encodeRandom(random_bytes(ID_RANDOM_LENGTH));
}

function encodeTime(int $milliseconds): string
{
    $out = '';
    for ($i = 0; $i < ID_TIME_LENGTH; $i++) {
        $out = ID_ALPHABET[$milliseconds % 32] . $out;
        $milliseconds = intdiv($milliseconds, 32);
    }

    return $out;
}

function encodeRandom(string $bytes): string
{
    $out = '';
    for ($i = 0; $i < ID_RANDOM_LENGTH; $i++) {
        $out .= ID_ALPHABET[ord($bytes[$i]) % 32];
    }

    return $out;
}
