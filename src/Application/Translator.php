<?php

declare(strict_types=1);

namespace Broadcast\Application;

interface Translator
{
    public function translate(string $text, string $targetLanguage): string;
}
