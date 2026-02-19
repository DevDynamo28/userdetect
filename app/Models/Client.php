<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

class Client extends Authenticatable
{
    use HasUuids;

    protected $fillable = [
        'company_name',
        'email',
        'password',
        'api_key',
        'api_secret',
        'allowed_domains',
        'webhook_url',
        'status',
        'plan_type',
        'last_api_call',
    ];

    protected $hidden = [
        'password',
        'api_secret',
    ];

    protected function casts(): array
    {
        return [
            'allowed_domains' => 'array',
            'password' => 'hashed',
            'last_api_call' => 'datetime',
        ];
    }

    // Relationships

    public function fingerprints(): HasMany
    {
        return $this->hasMany(UserFingerprint::class);
    }

    public function detections(): HasMany
    {
        return $this->hasMany(UserDetection::class);
    }

    // Helpers

    public static function generateApiKey(string $prefix = 'sk_live_'): string
    {
        return $prefix . Str::random(56);
    }

    public static function generateApiSecret(): string
    {
        return Str::random(64);
    }

    public function isDomainAllowed(string $domain): bool
    {
        $allowed = $this->allowed_domains;

        if (empty($allowed)) {
            return true; // No restriction if no domains configured
        }

        return in_array($domain, $allowed, true);
    }

    public function getRateLimit(): int
    {
        return config("detection.rate_limits.{$this->plan_type}", 100);
    }
}
