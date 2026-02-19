<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ApiKeyController extends Controller
{
    public function index()
    {
        $client = auth()->user();

        return view('dashboard.api-keys', compact('client'));
    }

    public function regenerate(Request $request)
    {
        $client = auth()->user();
        $oldKey = $client->api_key;

        // Generate new key
        $newKey = Client::generateApiKey();

        $client->update(['api_key' => $newKey]);

        // Invalidate old cache
        Cache::forget("client_key:{$oldKey}");

        return redirect()->route('dashboard.api-keys')
            ->with('success', 'API key regenerated successfully. Make sure to update your integrations.')
            ->with('new_key', $newKey);
    }

    public function revoke(Request $request)
    {
        $client = auth()->user();

        // Invalidate cache
        Cache::forget("client_key:{$client->api_key}");

        // Generate a new key and mark old one as unusable
        $client->update([
            'api_key' => Client::generateApiKey(),
        ]);

        return redirect()->route('dashboard.api-keys')
            ->with('success', 'API key revoked and replaced with a new one.');
    }
}
