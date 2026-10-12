<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WebsiteChatSession extends Model
{
    use HasUlids;

    protected $fillable = ['messages', 'lead_name', 'lead_contact', 'lead_need', 'lead_notified_at', 'ip', 'last_message_at'];

    protected function casts(): array
    {
        return ['messages' => 'array', 'lead_notified_at' => 'datetime', 'last_message_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
