<?php

namespace App\Observers;

use App\Enums\MessageType;
use App\Exceptions\Messaging\InvalidVoiceMessageException;
use App\Models\Message;

class MessageObserver
{
    public function saving(Message $message): void
    {
        if ($message->type === MessageType::VoiceNote && blank($message->attachment_path)) {
            throw new InvalidVoiceMessageException('A voice message requires an attachment_path.');
        }
    }
}
