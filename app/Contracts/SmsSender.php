<?php

namespace App\Contracts;

/**
 * Stage 23 — the seam between the SMS channel and whatever actually sends.
 *
 * The organisation has no gateway wired up yet, so the bound implementation is
 * LogSmsSender. Swapping in a real provider is a single binding change in
 * AppServiceProvider; nothing in the notification classes changes.
 */
interface SmsSender
{
    /**
     * @param  string  $to  The recipient's phone number, as stored on the user
     * @param  string  $message  Plain text, already localised
     */
    public function send(string $to, string $message): void;
}
