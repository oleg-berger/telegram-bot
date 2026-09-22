<?php
declare(strict_types=1);

namespace Broadcast\Application;

interface UserExporter
{
    public function export(array $users): string;
}
