<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['call_queue_id', 'extension_id', 'priority', 'enabled'])]
class CallQueueMember extends Model
{
    public function queue(): BelongsTo
    {
        return $this->belongsTo(CallQueue::class, 'call_queue_id');
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
