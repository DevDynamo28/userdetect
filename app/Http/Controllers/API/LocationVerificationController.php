<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyLocationRequest;
use App\Services\LocationVerificationService;

class LocationVerificationController extends Controller
{
    public function __construct(
        private LocationVerificationService $verificationService
    ) {
    }

    public function store(VerifyLocationRequest $request, string $fingerprintId)
    {
        $client = $request->attributes->get('client');

        $summary = $this->verificationService->verifyFingerprintLocation(
            $client,
            $fingerprintId,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }
}
