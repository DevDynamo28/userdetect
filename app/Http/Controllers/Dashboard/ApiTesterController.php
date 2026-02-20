<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\DetectionService;
use Illuminate\Http\Request;

class ApiTesterController extends Controller
{
    public function __construct(
        private DetectionService $detectionService
    ) {}

    public function index()
    {
        return view('dashboard.api-tester', [
            'apiKey' => auth()->user()->api_key,
        ]);
    }

    public function test(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
        ]);

        $client = auth()->user();
        $testIp = $request->input('ip');

        // Build a fake detection request with the test IP and minimal signals
        $fakeRequest = Request::create('/api/v1/detect', 'POST', [
            'signals' => [
                'fingerprint' => 'dashboard-test-' . now()->timestamp . '-' . substr(md5($testIp), 0, 8),
                'timezone' => $request->input('timezone', 'Asia/Kolkata'),
                'user_agent' => $request->userAgent(),
            ],
        ]);

        $fakeRequest->headers->set('X-Test-IP', $testIp);
        $fakeRequest->headers->set('Content-Type', 'application/json');

        try {
            $result = $this->detectionService->detect($fakeRequest, $client);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DETECTION_ERROR',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }
}
