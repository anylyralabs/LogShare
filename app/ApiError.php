<?php

namespace App;

class ApiError extends \Exception implements \JsonSerializable
{
    protected int $httpCode;

    public function __construct(int $httpCode, string $message)
    {
        $this->httpCode = $httpCode;
        parent::__construct($message, $httpCode);
    }

    /**
     * @return array{success: false, error: string, code: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'success' => false,
            'error' => $this->message,
            'code' => $this->httpCode,
        ];
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
