<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the application log instead of sending it.
 *
 * This is the honest default until a gateway contract exists: the rest of the
 * system can enable the SMS channel, the preference is respected end to end,
 * and the log makes it verifiable that the right message went to the right
 * number — without pretending a message was delivered.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Log::info('SMS notification', [
            'to' => $to,
            'message' => $message,
        ]);
    }
}
