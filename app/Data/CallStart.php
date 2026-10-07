<?php

namespace App\Data;

/** Результат старта звонка в канале. */
final readonly class CallStart
{
    public function __construct(
        public CallData $call,
        // false — в канале уже шёл звонок, пользователь просто подключается к нему.
        public bool $created,
    ) {}
}
