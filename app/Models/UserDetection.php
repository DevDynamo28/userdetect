<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDetection extends Model
{
    use HasUuids, MassPrunable;

    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'fingerprint_id',
        'session_id',
        'detected_city',
        'detected_state',
        'detected_country',
        'confidence',
        'detection_method',
        'is_vpn',
        'vpn_confidence',
        'vpn_indicators',
        'ip_address',
        'reverse_dns',
        'isp',
        'asn',
        'connection_type',
        'user_agent',
        'browser',
        'os',
        'device_type',
        'timezone',
        'language',
        'ip_sources_data',
        'verified_city',
        'verified_state',
        'verified_country',
        'is_location_verified',
        'verification_source',
        'verification_received_at',
        'state_disagreement_count',
        'city_disagreement_count',
        'fallback_reason',
        'processing_time_ms',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'is_vpn' => 'boolean',
            'is_location_verified' => 'boolean',
            'vpn_indicators' => 'array',
            'ip_sources_data' => 'array',
            'verification_received_at' => 'datetime',
            'detected_at' => 'datetime',
        ];
    }

    // Relationships

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    // Scopes

    public function scopeForClient(Builder $query, string $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeHighConfidence(Builder $query): Builder
    {
        return $query->where('confidence', '>=', 80);
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('detected_at', '>=', now()->subDays($days));
    }

    public function scopeForPeriod(Builder $query, string $period): Builder
    {
        return match ($period) {
            'last_24_hours' => $query->where('detected_at', '>=', now()->subHours(24)),
            'last_7_days' => $query->where('detected_at', '>=', now()->subDays(7)),
            'last_30_days' => $query->where('detected_at', '>=', now()->subDays(30)),
            default => $query->where('detected_at', '>=', now()->subDays(7)),
        };
    }

    public function prunable(): Builder
    {
        $retentionDays = (int) config('detection.learning.retention_days', 90);

        return static::where('detected_at', '<', now()->subDays($retentionDays));
    }
}
