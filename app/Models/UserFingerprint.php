<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFingerprint extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'fingerprint_id',
        'first_seen',
        'last_seen',
        'visit_count',
        'typical_city',
        'typical_state',
        'typical_country',
        'city_visit_counts',
        'state_visit_counts',
        'trust_score',
        'typical_timezone',
        'typical_language',
    ];

    protected function casts(): array
    {
        return [
            'city_visit_counts' => 'array',
            'state_visit_counts' => 'array',
            'first_seen' => 'datetime',
            'last_seen' => 'datetime',
        ];
    }

    // Relationships

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    // Methods

    public function incrementVisit(): void
    {
        $this->increment('visit_count');
        $this->update(['last_seen' => now()]);
        $this->refresh();
    }

    public function updateTypicalLocation(?string $city, ?string $state): void
    {
        if ($city) {
            $cityCounts = $this->city_visit_counts ?? [];
            $cityCounts[$city] = ($cityCounts[$city] ?? 0) + 1;
            $this->city_visit_counts = $cityCounts;

            if (!empty($cityCounts)) {
                $this->typical_city = array_search(max($cityCounts), $cityCounts, true);
            }
        }

        if ($state) {
            $stateCounts = $this->state_visit_counts ?? [];
            $stateCounts[$state] = ($stateCounts[$state] ?? 0) + 1;
            $this->state_visit_counts = $stateCounts;

            if (!empty($stateCounts)) {
                $this->typical_state = array_search(max($stateCounts), $stateCounts, true);
            }
        }

        $this->save();
    }

    public function boostTrustScore(int $amount = 5): void
    {
        $this->trust_score = min(100, $this->trust_score + $amount);
        $this->save();
    }

    public function reduceTrustScore(int $amount = 10): void
    {
        $this->trust_score = max(0, $this->trust_score - $amount);
        $this->save();
    }
}
