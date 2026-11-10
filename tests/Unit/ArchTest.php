<?php

declare(strict_types=1);

arch('every source file declares strict types')
    ->expect('Beziworld\EmailLabs')
    ->toUseStrictTypes();

arch('no debugging statements are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('data objects are immutable')
    ->expect('Beziworld\EmailLabs\Data')
    ->toBeReadonly();

arch('exceptions share the package base exception')
    ->expect('Beziworld\EmailLabs\Exceptions')
    ->toExtend('Beziworld\EmailLabs\Exceptions\EmailLabsException')
    ->ignoring('Beziworld\EmailLabs\Exceptions\EmailLabsException');
