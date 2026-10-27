<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deletion_notices', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('deletion_notices', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
