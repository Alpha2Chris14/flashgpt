<?php

namespace App\Http\Controllers;

use App\Services\AbpayService;
use Illuminate\Http\Request;

class AbpayControllerXXXXX extends Controller
{
    private AbpayService $abpay;

    public function __construct(AbpayService $abpay)
    {
        $this->abpay = $abpay;
    }

    /**
     * Handle Unified Order Request
     */
    public function unifiedOrder(Request $request)
    {
        // Validate incoming request
        $validated = $request->validate([
            "mchOrderNo"    => "required|string",
            "wayCode"       => "required|string",
            "amount"        => "required|integer",
            "currency"      => "required|string",
            "subject"       => "required|string",
            "body"          => "required|string",
            "clientIp"      => "nullable|string",
            "notifyUrl"     => "nullable|string",
            "returnUrl"     => "nullable|string",
            "expiredTime"   => "nullable|integer",
            "divisionMode"  => "nullable|integer",
            "extParam"      => "nullable|string",
            "channelExtra"  => "nullable|array", // Accept JSON object
        ]);

        // Pass validated data to the service
        $response = $this->abpay->unifiedOrder($validated);

        return response()->json($response);
    }
}
