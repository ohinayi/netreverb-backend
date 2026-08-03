<?php

namespace App\Models;

use Illuminate\Support\Facades\Schema;

trait BelongsToWorkspace
{
    protected static function bootBelongsToWorkspace(): void
    {
        static::creating(function ($model): void {
            if (! Schema::hasTable('workspaces') || $model->workspace_id || ! $model->organization_id) {
                return;
            }

            $model->workspace_id = Workspace::query()
                ->where('organization_id', $model->organization_id)
                ->where('status', 'active')
                ->orderBy('id')
                ->value('id');
        });
    }
}
