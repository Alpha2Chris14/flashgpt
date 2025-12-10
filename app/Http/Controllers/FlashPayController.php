<?php

namespace App\Http\Controllers;

use App\Models\FlashPayment;
use App\Services\FlashPayServiceTest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FlashPayController extends Controller
{
    private FlashPayServiceTest $abpay;

    public function __construct(FlashPayServiceTest $abpay)
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

    public function notify(Request $request)
    {
        // Abpay sends form-data; convert to array
        $payload = $request->all();

        // verify signature
        $isValid = $this->abpay->verifyNotification($payload);

        if (! $isValid) {
            Log::warning('Abpay notify signature invalid', $payload);
            return response()->json(['code' => 1, 'msg' => 'signature invalid'], 400);
        }

        // find local payment by mchOrderNo or payOrderId
        $mchOrderNo = $payload['mchOrderNo'] ?? null;
        $payOrderId  = $payload['payOrderId'] ?? null;
        $state       = $payload['state'] ?? null; // numeric

        $payment = null;
        if ($mchOrderNo) {
            $payment = FlashPayment::where('mch_order_no', $mchOrderNo)->first();
        }
        if (!$payment && $payOrderId) {
            $payment = FlashPayment::where('pay_order_id', $payOrderId)->first();
        }

        $status = $this->abpay->mapStateToStatus($state);

        if ($payment) {
            $payment->update([
                'status' => $status,
                'meta'   => array_merge($payment->meta ?? [], ['notify_payload' => $payload]),
            ]);
        } else {
            // create fallback record
            FlashPayment::create([
                'mch_order_no' => $mchOrderNo ?? 'unknown-' . $payOrderId,
                'pay_order_id' => $payOrderId ?? null,
                'mch_no'       => $payload['mchNo'] ?? null,
                'app_id'       => $payload['appId'] ?? null,
                'amount'       => $payload['amount'] ?? 0,
                'currency'     => $payload['currency'] ?? null,
                'status'       => $status,
                'meta'         => $payload,
            ]);
        }

        // Return Option B: JSON { code: 0, msg: "success" }
        return response()->json(['code' => 0, 'msg' => 'success']);
    }

    /**
     * Query order endpoint (our API) - forwards to abpay
     * Accepts either payOrderId or mchOrderNo
     */
    public function query(Request $request)
    {
        $request->validate([
            'mchOrderNo' => 'nullable|string',
            'payOrderId' => 'nullable|string',
        ]);

        $mchOrderNo = $request->input('mchOrderNo');
        $payOrderId  = $request->input('payOrderId');

        $response = $this->abpay->queryOrder($mchOrderNo, $payOrderId);

        return response()->json($response);
    }
}
