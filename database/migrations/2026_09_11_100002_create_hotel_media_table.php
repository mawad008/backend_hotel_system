<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotel media — a lightweight custom media table over Laravel Storage
 * (no Spatie Media Library: the project does not use it and there is no
 * architectural reason to add it for three simple image collections).
 *
 * Collections:
 *  - `logo`  : at most one (uploading a new one replaces it)
 *  - `cover` : at most one (the discovery hero image)
 *  - `gallery`: many, ordered by `sort_order`
 *
 * Cardinality is enforced by HotelMediaService, not the schema, so a
 * replace is a delete + insert rather than a failed unique constraint.
 *
 * `path` is a disk-relative path only — never a URL. The disk is stored per
 * row so a later disk migration does not orphan existing files. Deleting a
 * hotel cascades its media rows (the files are removed by the service).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('collection', 32); // logo | cover | gallery
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['hotel_id', 'collection', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_media');
    }
};
