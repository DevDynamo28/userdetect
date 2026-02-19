<?php

namespace App\Services;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Support\Facades\Log;

class LocalGeoIPService
{
    private ?Reader $reader = null;
    private string $databasePath;
    private bool $available = false;

    public function __construct()
    {
        $this->databasePath = config(
            'detection.methods.local_geoip.database_path',
            storage_path('geoip/GeoLite2-City.mmdb')
        );

        $this->initReader();
    }

    private function initReader(): void
    {
        if (!file_exists($this->databasePath)) {
            Log::channel('detection')->warning("GeoIP database not found at: {$this->databasePath}");
            return;
        }

        try {
            $this->reader = new Reader($this->databasePath);
            $this->available = true;
            Log::channel('detection')->debug("GeoIP database loaded: {$this->databasePath}");
        } catch (\Throwable $e) {
            Log::channel('detection')->error("Failed to load GeoIP database: {$e->getMessage()}");
        }
    }

    /**
     * Check if the local GeoIP database is available.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Look up an IP address in the local GeoIP database.
     */
    public function lookup(string $ip): ?array
    {
        if (!$this->available || !$this->reader) {
            return null;
        }

        try {
            $record = $this->reader->city($ip);

            $city = $record->city->name;
            $state = $record->mostSpecificSubdivision->name;
            $country = $record->country->name;
            $countryCode = $record->country->isoCode;
            $postal = $record->postal->code;
            $latitude = $record->location->latitude;
            $longitude = $record->location->longitude;
            $timezone = $record->location->timeZone;
            $accuracyRadius = $record->location->accuracyRadius;

            // Get ISP/ASN info if available
            $isp = null;
            $asn = null;
            $org = null;

            // Try to get ASN info from the city database traits
            if ($record->traits) {
                $isp = $record->traits->isp ?? null;
                $org = $record->traits->organization ?? null;
                $asn = $record->traits->autonomousSystemNumber ?? null;
                if ($asn) {
                    $asn = 'AS' . $asn;
                }
            }

            // Normalize city name
            $city = $this->normalizeCity($city);

            // Calculate confidence based on accuracy radius and data completeness
            $confidence = $this->calculateConfidence($city, $state, $accuracyRadius);

            $result = [
                'city' => $city,
                'state' => $state,
                'country' => $country,
                'country_code' => $countryCode,
                'postal' => $postal,
                'latitude' => $latitude ? round($latitude, 6) : null,
                'longitude' => $longitude ? round($longitude, 6) : null,
                'timezone' => $timezone,
                'accuracy_radius_km' => $accuracyRadius,
                'isp' => $isp ?? $org,
                'asn' => $asn,
                'confidence' => $confidence,
                'method' => 'local_geoip',
            ];

            Log::channel('detection')->info(
                "LocalGeoIP lookup for {$ip}: city={$city}, state={$state}, " .
                "lat={$latitude}, lng={$longitude}, accuracy_radius={$accuracyRadius}km, confidence={$confidence}"
            );

            return $result;

        } catch (AddressNotFoundException $e) {
            Log::channel('detection')->info("LocalGeoIP: IP {$ip} not found in database");
            return null;
        } catch (\Throwable $e) {
            Log::channel('detection')->error("LocalGeoIP lookup failed for {$ip}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Calculate confidence based on data completeness and accuracy radius.
     */
    private function calculateConfidence(?string $city, ?string $state, ?int $accuracyRadius): int
    {
        $confidence = config('detection.methods.local_geoip.confidence', 92);

        // No city = lower confidence
        if (empty($city)) {
            $confidence -= 30;
        }

        // No state = lower confidence
        if (empty($state)) {
            $confidence -= 15;
        }

        // Adjust based on accuracy radius
        if ($accuracyRadius !== null) {
            if ($accuracyRadius <= 10) {
                $confidence = min(98, $confidence + 3); // Very precise
            } elseif ($accuracyRadius <= 50) {
                // Default — no adjustment
            } elseif ($accuracyRadius <= 200) {
                $confidence -= 5;
            } elseif ($accuracyRadius <= 500) {
                $confidence -= 15;
            } else {
                $confidence -= 25; // Very imprecise
            }
        }

        return max(0, min(100, $confidence));
    }

    /**
     * Normalize common Indian city name variations.
     */
    private function normalizeCity(?string $city): ?string
    {
        if (empty($city)) {
            return null;
        }

        $map = [
            'Bengaluru' => 'Bangalore',
            'Bombay' => 'Mumbai',
            'Calcutta' => 'Kolkata',
            'Madras' => 'Chennai',
            'Poona' => 'Pune',
            'Baroda' => 'Vadodara',
            'Trivandrum' => 'Thiruvananthapuram',
            'Cochin' => 'Kochi',
            'Vizag' => 'Visakhapatnam',
            'Simla' => 'Shimla',
            'Pondicherry' => 'Puducherry',
            'Allahabad' => 'Prayagraj',
            'Mangalore' => 'Mangaluru',
            'Mysore' => 'Mysuru',
            'Benaras' => 'Varanasi',
            'Gurgaon' => 'Gurugram',
        ];

        return $map[$city] ?? $city;
    }

    /**
     * Get database metadata.
     */
    public function getDatabaseInfo(): ?array
    {
        if (!$this->available || !$this->reader) {
            return null;
        }

        try {
            $metadata = $this->reader->metadata();
            return [
                'type' => $metadata->databaseType,
                'build_epoch' => date('Y-m-d H:i:s', $metadata->buildEpoch),
                'ip_version' => $metadata->ipVersion,
                'node_count' => $metadata->nodeCount,
                'binary_format' => $metadata->binaryFormatMajorVersion . '.' . $metadata->binaryFormatMinorVersion,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function __destruct()
    {
        if ($this->reader) {
            try {
                $this->reader->close();
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    }
}
