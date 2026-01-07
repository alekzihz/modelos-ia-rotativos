<?php
declare(strict_types=1);

namespace App\Modelos;

final class ChatMessage
{
    public function __construct(
        public readonly Role $role,
        public readonly string $content
    ) {
    }
}
