<?php

namespace App\Integrations\Contracts;

interface MessagingDriver extends Driver
{
    /**
     * Send a message to a configured channel or a target handle.
     */
    public function sendMessage(string $message, ?string $target = null): bool;
}
