<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DetectRequest;
use App\Http\Resources\DetectionResource;
use App\Services\DetectionService;

class DetectionController extends Controller
{
    public function __construct(
        private DetectionService $detectionService
    ) {}

    public function detect(DetectRequest $request)
    {
        $client = $request->attributes->get('client');

        $result = $this->detectionService->detect($request, $client);

        return new DetectionResource($result);
    }
}
