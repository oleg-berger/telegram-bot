<?php
declare(strict_types=1);

namespace Broadcast\Application;

interface MailGateway
{
    public function send(string $to, string $subject, string $text): void;
}
