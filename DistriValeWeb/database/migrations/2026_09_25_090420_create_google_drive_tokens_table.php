<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A single row holding the one connected Google account's OAuth tokens
     * for the Drive backup feature. Token values are stored via Laravel's
     * `encrypted` cast (AES-256 via APP_KEY) — plain text at rest would let
     * anyone who can read database.sqlite also read a token that grants
     * ongoing access to the user's Drive backup folder.
     */
    public function up(): void
    {
        Schema::create('google_drive_tokens', function (Blueprint $table) {
            $table->id();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->string('account_email')->nullable();
            $table->string('folder_id')->nullable();
            $table->string('backup_file_id')->nullable();
            $table->timestamp('last_backup_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_drive_tokens');
    }
};
