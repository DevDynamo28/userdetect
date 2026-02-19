<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ReverseGeocodeService
{
    private array $cities = [];
    private bool $loaded = false;

    public function __construct()
    {
        $this->loadCities();
    }

    private function loadCities(): void
    {
        $path = storage_path('data/indian_cities.json');

        if (!file_exists($path)) {
            Log::channel('detection')->warning("Indian cities database not found at: {$path}");
            return;
        }

        try {
            $data = json_decode(file_get_contents($path), true);
            if (is_array($data) && !empty($data)) {
                $this->cities = $data;
                $this->loaded = true;
            }
        } catch (\Throwable $e) {
            Log::channel('detection')->error("Failed to load cities database: {$e->getMessage()}");
        }
    }

    /**
     * Reverse geocode lat/lng to nearest Indian city.
     */
    public function reverseGeocode(float $latitude, float $longitude): ?array
    {
        if (!$this->loaded || empty($this->cities)) {
            return null;
        }

        $nearestCity = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($this->cities as $city) {
            $distance = $this->haversineDistance(
                $latitude,
                $longitude,
                $city['lat'],
                $city['lng']
            );

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $nearestCity = $city;
            }
        }

        if (!$nearestCity) {
            return null;
        }

        // Calculate confidence based on distance
        $confidence = $this->distanceToConfidence($minDistance);

        $result = [
            'city' => $nearestCity['city'],
            'state' => $nearestCity['state'],
            'country' => 'India',
            'distance_km' => round($minDistance, 2),
            'confidence' => $confidence,
            'method' => 'browser_geolocation',
            'latitude' => round($latitude, 6),
            'longitude' => round($longitude, 6),
        ];

        Log::channel('detection')->info(
            "ReverseGeocode: ({$latitude}, {$longitude}) → {$result['city']}, {$result['state']} " .
            "(distance: {$result['distance_km']}km, confidence: {$confidence})"
        );

        return $result;
    }

    /**
     * Calculate Haversine distance between two points in kilometers.
     */
    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Convert distance to confidence score.
     * GPS is very accurate, so we trust it highly even at moderate distances.
     */
    private function distanceToConfidence(float $distanceKm): int
    {
        return match (true) {
            $distanceKm <= 5 => 98,  // Within city center
            $distanceKm <= 15 => 96,  // Within city limits
            $distanceKm <= 30 => 93,  // Outskirts / nearby suburb
            $distanceKm <= 50 => 88,  // Nearby town, mapped to closest city
            $distanceKm <= 100 => 80,  // Regional — closest major city
            $distanceKm <= 200 => 65,  // Broad area
            default => 45,  // Very far from any known city
        };
    }

    public function isAvailable(): bool
    {
        return $this->loaded;
    }
}
