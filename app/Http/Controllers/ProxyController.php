<?php

namespace App\Http\Controllers;

use App\Services\ProxyService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProxyController extends Controller
{
    public function handle(Request $request, ProxyService $proxyService): Response
    {
        $accessToken = $request->attributes->get('access_token');

        return $proxyService->handle($request, $accessToken);
    }
}
