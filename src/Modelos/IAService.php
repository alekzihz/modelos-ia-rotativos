<?php
declare(strict_types=1);

namespace App\Modelos;

interface IAService
{
    public function name(): string;

    /**
     * @param ChatMessage[] $messages
     * @param callable(string): void $onDelta
     */
    public function stream(array $messages, callable $onDelta): void;
}
