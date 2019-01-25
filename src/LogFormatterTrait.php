<?php

namespace SmsSender;

trait LogFormatterTrait
{
    public function formatForLog(string $message): string
    {
        return str_replace(['\r\n', '\n', '\r'], '<br />', $message);
    }
}
