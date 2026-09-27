<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room / Room Type media — same lightweight custom media approach as
 * `hotel_media` (see that migration), but polymorphic: a hotel wants
 * photos shared across a Room Type (the common case — identical rooms of
 * one type) and, sometimes, on one specific physical Room. One table
 * keyed by `mediable_type`/`mediable_id` covers both instead of two
 * near-identical tables.
 *
 * Collections:
 *  - `gallery`: many, ordered by `sort_order` (the only collection today;
 *    the column exists so a future single collection, e.g. a "primary
 *    photo", does not need a schema change).
 *
 * `path` is a disk-relative path only — never a URL. The disk is stored
 * per row so a later disk migration does not orphan existing files.
 * Rooms/Room Types are never hard-deleted in this app (only deactivated /
 * transitioned), so no cascade-delete is defined here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mediable_id');
            $table->string('mediable_type');
            $table->string('collection', 32)->default('gallery');
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['mediable_type', 'mediable_id', 'collection', 'sort_order'], 'room_media_mediable_collection_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_media');
    }
};
