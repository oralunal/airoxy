<?php

namespace App\Http\Controllers;

use App\Services\ProxyService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProxyController extends Controller
{
    public function handle(Request $request, ProxyService $proxyService): Response
    {
        $apiKey = $request->attributes->get('api_key');

        return $proxyService->handle($request, $apiKey);
    }
}
