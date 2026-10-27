<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deletion_notices', function (Blueprint $table) {
            $table->id();
            $table->string('original_name', 255);
            $table->unsignedBigInteger('size');
            $table->string('reason', 16);
            $table->timestamp('uploaded_at');
            $table->timestamp('deleted_at');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_notices');
    }
};
