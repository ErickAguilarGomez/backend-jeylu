<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Helpers are a seller subtype; clear legacy flags from all other roles.
     */
    public function up(): void
    {
        DB::table('users')
            ->where('role_id', '<>', 2)
            ->where('is_helper', true)
            ->update(['is_helper' => false]);
    }

    /**
     * The previous role/flag combination is invalid and cannot be restored safely.
     */
    public function down(): void
    {
        // Intentionally irreversible: original role/flag combinations are not tracked.
    }
};
