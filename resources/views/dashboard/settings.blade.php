@extends('layouts.dashboard')

@section('title', 'Settings')
@section('page-title', 'Settings')

@section('content')
<div class="max-w-3xl space-y-6">
    {{-- Company Information --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-900 mb-4">Company Information</h3>
        <form method="POST" action="{{ route('dashboard.settings.update') }}">
            @csrf
            @method('PUT')

            <div class="space-y-4">
                <div>
                    <label for="company_name" class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                    <input type="text" id="company_name" name="company_name" value="{{ old('company_name', $client->company_name) }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
                    @error('company_name')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" id="email" name="email" value="{{ old('email', $client->email) }}"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
                    @error('email')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Current Plan</label>
                    <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium bg-indigo-50 text-indigo-700">
                        {{ ucfirst($client->plan_type) }}
                    </span>
                </div>
            </div>

            <hr class="my-6">

            {{-- API Configuration --}}
            <h3 class="text-sm font-medium text-gray-900 mb-4">API Configuration</h3>
            <div class="space-y-4">
                <div>
                    <label for="allowed_domains" class="block text-sm font-medium text-gray-700 mb-1">Allowed Domains</label>
                    <textarea id="allowed_domains" name="allowed_domains" rows="3"
                              placeholder="example.com, www.example.com, app.example.com"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">{{ old('allowed_domains', $client->allowed_domains ? implode(', ', $client->allowed_domains) : '') }}</textarea>
                    <p class="text-xs text-gray-400 mt-1">Comma-separated list. Leave empty to allow all domains.</p>
                </div>

                <div x-data="{ enabled: {{ $client->webhook_url ? 'true' : 'false' }} }">
                    <div class="flex items-center gap-2 mb-2">
                        <input type="checkbox" id="webhook_enabled" name="webhook_enabled" value="1"
                               x-model="enabled"
                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <label for="webhook_enabled" class="text-sm font-medium text-gray-700">Enable Webhook</label>
                    </div>
                    <div x-show="enabled" x-cloak>
                        <input type="url" name="webhook_url" value="{{ old('webhook_url', $client->webhook_url) }}"
                               placeholder="https://your-app.com/webhook/detection"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
                    </div>
                </div>
            </div>

            <div class="mt-6">
                <button type="submit"
                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 transition">
                    Save Changes
                </button>
            </div>
        </form>
    </div>

    {{-- Change Password --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-900 mb-4">Change Password</h3>
        <form method="POST" action="{{ route('dashboard.settings.password') }}">
            @csrf
            @method('PUT')

            <div class="space-y-4 max-w-md">
                <div>
                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
                    @error('current_password')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                    <input type="password" id="password" name="password" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
                    @error('password')
                        <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
                </div>
            </div>

            <div class="mt-6">
                <button type="submit"
                        class="px-4 py-2 bg-gray-800 text-white rounded-lg text-sm hover:bg-gray-900 transition">
                    Update Password
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
