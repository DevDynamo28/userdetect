<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IpRangeLearning extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'ip_range',
        'learned_city',
        'learned_state',
        'sample_count',
        'success_rate',
        'average_confidence',
        'primary_isp',
        'primary_asn',
        'reverse_dns_pattern',
        'last_seen',
        'first_seen',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'success_rate' => 'decimal:2',
            'average_confidence' => 'decimal:2',
            'last_seen' => 'datetime',
            'first_seen' => 'datetime',
        ];
    }

    // Scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('sample_count', '>=', config('detection.learning.min_samples_for_active', 10))
            ->where('success_rate', '>=', config('detection.learning.min_success_rate', 70));
    }
}
