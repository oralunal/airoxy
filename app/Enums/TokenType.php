<?php

namespace App\Enums;

enum TokenType: string
{
    case OAuth = 'oauth';
    case ApiKey = 'api_key';

    public static function detectFromToken(string $token): self
    {
        return str_starts_with($token, 'sk-ant-oat')
            ? self::OAuth
            : self::ApiKey;
    }
}
