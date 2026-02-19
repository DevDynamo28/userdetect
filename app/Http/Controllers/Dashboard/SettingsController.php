<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    public function index()
    {
        $client = auth()->user();

        return view('dashboard.settings', compact('client'));
    }

    public function update(UpdateSettingsRequest $request)
    {
        $client = auth()->user();

        $domains = $request->input('allowed_domains');
        $allowedDomains = $domains
            ? array_map('trim', explode(',', $domains))
            : null;

        $client->update([
            'company_name' => $request->input('company_name'),
            'email' => $request->input('email'),
            'allowed_domains' => $allowedDomains,
            'webhook_url' => $request->boolean('webhook_enabled')
                ? $request->input('webhook_url')
                : null,
        ]);

        return redirect()->route('dashboard.settings')
            ->with('success', 'Settings updated successfully.');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $client = auth()->user();

        if (!Hash::check($request->input('current_password'), $client->password)) {
            return back()->withErrors([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $client->update([
            'password' => $request->input('password'),
        ]);

        return redirect()->route('dashboard.settings')
            ->with('success', 'Password updated successfully.');
    }
}
