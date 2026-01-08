<?php

declare(strict_types=1);

namespace App\Excepciones;

use RuntimeException;

final class AIServiceException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        public readonly int $httpStatus,
        public readonly ?string $errorCode,
        public readonly ?string $errorType,
        string $message
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function isQuota(): bool
    {
        // OpenAI suele usar code/type "insufficient_quota"
        return ($this->errorCode === 'insufficient_quota' || $this->errorType === 'insufficient_quota');
    }
    public function isModelNotFound(): bool
    {
        //es 200 por que la petición en streaming llega bien, pero el error viene en el payload de datos
        //de openai 
        return ($this->httpStatus === 200 &&
            ($this->errorCode === 'model_not_found' || $this->errorType === 'model_not_found'));
    }

    public function isRateLimit(): bool
    {
        return $this->httpStatus === 429 && !$this->isQuota();
    }

    public function isAuth(): bool
    {
        return $this->httpStatus === 401;
    }
}
