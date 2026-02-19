<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoIPUpdateCommand extends Command
{
    protected $signature = 'geoip:update {--force : Force re-download even if database exists}';
    protected $description = 'Download/update the MaxMind GeoLite2-City database';

    private string $storagePath;

    public function __construct()
    {
        parent::__construct();
        $this->storagePath = storage_path('geoip');
    }

    public function handle(): int
    {
        $licenseKey = config('detection.methods.local_geoip.maxmind_license_key');

        if (empty($licenseKey)) {
            $this->error('❌ MAXMIND_LICENSE_KEY not set in .env');
            $this->info('Get a free key at: https://www.maxmind.com/en/geolite2/signup');
            return Command::FAILURE;
        }

        $dbPath = $this->storagePath . '/GeoLite2-City.mmdb';

        if (file_exists($dbPath) && !$this->option('force')) {
            $age = time() - filemtime($dbPath);
            $ageDays = round($age / 86400);
            $this->info("📊 Database exists (age: {$ageDays} days)");

            if ($ageDays < 7) {
                $this->info('✅ Database is up to date. Use --force to re-download.');
                return Command::SUCCESS;
            }

            $this->info('⏳ Database is older than 7 days, updating...');
        }

        // Ensure storage directory exists
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }

        $this->info('📥 Downloading GeoLite2-City database...');

        $url = "https://download.maxmind.com/app/geoip_download"
            . "?edition_id=GeoLite2-City"
            . "&license_key={$licenseKey}"
            . "&suffix=tar.gz";

        $tempFile = $this->storagePath . '/GeoLite2-City.tar.gz';

        try {
            $response = Http::timeout(120)
                ->withOptions(['sink' => $tempFile])
                ->get($url);

            if (!$response->successful()) {
                $this->error("❌ Download failed: HTTP {$response->status()}");
                if ($response->status() === 401) {
                    $this->error('Invalid license key. Check MAXMIND_LICENSE_KEY in .env');
                }
                @unlink($tempFile);
                return Command::FAILURE;
            }

            $this->info('📦 Extracting database...');

            // Extract the .mmdb file from tar.gz
            $extracted = $this->extractMmdb($tempFile, $dbPath);

            // Clean up temp file
            @unlink($tempFile);

            if (!$extracted) {
                $this->error('❌ Failed to extract .mmdb file from archive');
                return Command::FAILURE;
            }

            $size = round(filesize($dbPath) / 1024 / 1024, 1);
            $this->info("✅ GeoLite2-City database downloaded ({$size} MB)");
            $this->info("📁 Location: {$dbPath}");

            // Verify the database
            $this->verifyDatabase($dbPath);

            Log::channel('detection')->info("GeoIP database updated: {$dbPath} ({$size} MB)");

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->error("❌ Download failed: {$e->getMessage()}");
            @unlink($tempFile);
            return Command::FAILURE;
        }
    }

    private function extractMmdb(string $tarGzPath, string $outputPath): bool
    {
        try {
            // Use PharData for tar.gz extraction
            $phar = new \PharData($tarGzPath);

            // Decompress .tar.gz → .tar
            $tarPath = str_replace('.tar.gz', '.tar', $tarGzPath);
            if (file_exists($tarPath)) {
                @unlink($tarPath);
            }
            $phar->decompress();

            // Open .tar and find .mmdb file
            $tar = new \PharData($tarPath);
            foreach (new \RecursiveIteratorIterator($tar) as $file) {
                if (str_ends_with($file->getPathname(), '.mmdb')) {
                    $contents = file_get_contents($file->getPathname());
                    if ($contents !== false) {
                        file_put_contents($outputPath, $contents);
                        @unlink($tarPath);
                        return true;
                    }
                }
            }

            @unlink($tarPath);
            return false;

        } catch (\Throwable $e) {
            $this->error("Extraction error: {$e->getMessage()}");
            return false;
        }
    }

    private function verifyDatabase(string $dbPath): void
    {
        try {
            $reader = new \GeoIp2\Database\Reader($dbPath);
            $metadata = $reader->metadata();

            $this->info("📊 Database info:");
            $this->info("   Type: {$metadata->databaseType}");
            $this->info("   Built: " . date('Y-m-d', $metadata->buildEpoch));
            $this->info("   Records: " . number_format($metadata->nodeCount));

            // Test with a known Indian IP
            try {
                $record = $reader->city('106.215.153.11');
                $this->info("   Test lookup (106.215.153.11): {$record->city->name}, {$record->mostSpecificSubdivision->name}");
            } catch (\Throwable $e) {
                $this->warn("   Test lookup failed: {$e->getMessage()}");
            }

            $reader->close();

        } catch (\Throwable $e) {
            $this->warn("⚠️ Database verification failed: {$e->getMessage()}");
        }
    }
}
