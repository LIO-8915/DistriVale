<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row table: the one Google account connected for Drive backups.
 * `access_token`/`refresh_token` are transparently encrypted/decrypted via
 * the `encrypted` cast (Laravel's Crypt facade, AES-256-CBC keyed by
 * APP_KEY) — see the migration for why.
 */
class GoogleDriveToken extends Model
{
    protected $fillable = [
        'access_token',
        'refresh_token',
        'expires_at',
        'account_email',
        'folder_id',
        'backup_file_id',
        'last_backup_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'last_backup_at' => 'datetime',
    ];

    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->subSeconds(60)->isPast();
    }
}
