<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PdfExport extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'document_id',
        'user_id',
        'admin_id',
        'payload_hash',
        'status',
        'progress',
        'payload_path',
        'output_path',
        'download_name',
        'output_bytes',
        'error',
        'ip_address',
        'queued_at',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'progress' => 'integer',
            'output_bytes' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public static function directoryFor(string $uuid): string
    {
        return "documents/exports/{$uuid}";
    }

    public function directory(): string
    {
        return self::directoryFor($this->uuid);
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && $this->output_path
            && (! $this->expires_at || $this->expires_at->isFuture())
            && Storage::exists($this->output_path);
    }

    public function setProgress(int $progress): void
    {
        self::query()->whereKey($this->id)
            ->where('progress', '<', $progress)
            ->update(['progress' => $progress, 'updated_at' => now()]);
    }

    /** Removes the payload and the generated PDF; the row stays as the record. */
    public function deleteFiles(): void
    {
        Storage::deleteDirectory($this->directory());
    }
}
