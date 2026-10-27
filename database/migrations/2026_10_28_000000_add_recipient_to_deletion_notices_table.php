<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deletion_notices', function (Blueprint $table) {
            $table->string('recipient', 255)->default('')->after('reason');
        });

        // rows written before this column existed get the current address, or stay blank and fail visibly
        if (filled(config('files.notify_email'))) {
            DB::table('deletion_notices')->update(['recipient' => config('files.notify_email')]);
        }
    }

    public function down(): void
    {
        Schema::table('deletion_notices', function (Blueprint $table) {
            $table->dropColumn('recipient');
        });
    }
};
