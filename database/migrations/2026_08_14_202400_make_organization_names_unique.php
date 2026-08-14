<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Disambiguate existing duplicates the same way
        // CreateOrganization::uniqueName() does for new ones, before the
        // unique index below can be added - the oldest row (by id) keeps
        // its original name, later ones get " (2)", " (3)", etc.
        $duplicateNames = DB::table('organizations')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->pluck('name');

        foreach ($duplicateNames as $name) {
            $rows = DB::table('organizations')
                ->where('name', $name)
                ->orderBy('id')
                ->get(['id']);

            foreach ($rows->skip(1) as $index => $row) {
                DB::table('organizations')
                    ->where('id', $row->id)
                    ->update(['name' => "{$name} (".($index + 2).')']);
            }
        }

        Schema::table('organizations', function (Blueprint $table): void {
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropUnique(['name']);
        });
    }
};
