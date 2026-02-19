<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyDomainRequest;

class ClientController extends Controller
{
    public function verifyDomain(VerifyDomainRequest $request)
    {
        $client = $request->attributes->get('client');
        $domain = $request->input('domain');

        return response()->json([
            'success' => true,
            'domain_allowed' => $client->isDomainAllowed($domain),
        ]);
    }
}
