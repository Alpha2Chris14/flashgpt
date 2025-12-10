<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\FlashpayService;
use Illuminate\Support\Str;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected $flashpay;

    public function __construct(FlashpayService $flashpay)
    {
        $this->flashpay = $flashpay;
    }

    public function deposit(Request $request)
    {
        $request->validate([
            'country'      => 'required|string|in:india,australia,credit_card',
            'merchantId'   => 'required|string',
            'amount'       => 'required|numeric|min:1',
            'notifyUrl'    => 'required|url',
            'callbackUrl'  => 'nullable|url',
            'wayCode'     => 'nullable|string',
        ]);

        $merchantOrderId = Str::uuid()->toString(); // Generates unique order ID
        $param = $this->buildParams($request->all(), $merchantOrderId);

        $response = $this->flashpay->deposit($param);

        return response()->json([
            'reference' => $merchantOrderId,
            'flashpay_response' => $response,
        ]);
    }

    /**
     * Build parameters dynamically based on country
     */
    private function buildParams(array $data, string $merchantOrderId): array
    {
        // return $data;
        $base = [
            'merchantId'      => $data['merchantId'],
            'merchantOrderId' => $merchantOrderId,
            'amount'          => $data['amount'],
            'notifyUrl'       => $data['notifyUrl'],
            'callbackUrl'     => $data['callbackUrl'] ?? env('APP_URL') . '/api/flashpay/callback',
            'wayCode' => $data['wayCode'] ?? "GA_CARD",
        ];

        /** Additional parameters based on country selected by the country passed */
        switch (strtolower($data['country'])) {
            case 'india':
                $extra = [
                    'name'   => $data['name'] ?? 'Test',
                    'email'  => $data['email'] ?? 'test@gmail.com',
                    'mobile' => $data['mobile'] ?? '1234567890',
                ];
                break;

            case 'australia':
                $extra = [
                    'paytypeid' => $data['paytypeid'] ?? 68, // default 68
                    'mobile'    => $data['mobile'] ?? null,
                    'firstName' => $data['firstName'] ?? null,
                    'lastName'  => $data['lastName'] ?? null,
                    'type'      => $data['type'] ?? null,
                    'taxId'     => $data['taxId'] ?? null,
                ];
                break;

            case 'credit_card':
                $extra = [
                    'currency'  => $data['currency'] ?? 'USD',
                    'payType'   => $data['payType'] ?? 1, // card
                    'ip'        => $data['ip'] ?? request()->ip(),
                    'firstName' => $data['firstName'] ?? null,
                    'lastName'  => $data['lastName'] ?? null,
                    'numberId'  => $data['numberId'] ?? null,
                    'kycToken'  => $data['kycToken'] ?? null,
                    'email'     => $data['email'] ?? null,
                    'mobile'    => $data['mobile'] ?? null,
                    'country'   => $data['country_code'] ?? 'DE',
                ];
                break;

            default:
                $extra = [];
        }
        // return $base;
        return array_merge($base, $extra);
    }

    /** this handle callback from flashpay */
    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('Flashpay Webhook Received', $payload);

        // Extract reference and status
        $merchantOrderId = $payload['merchantorderid'] ?? null;
        $status = $payload['status'] ?? null;

        if (!$merchantOrderId) {
            return response()->json(['error' => 'Missing merchantorderid'], 400);
        }

        // Map Flashpay status to our payment status
        // Flashpay example: 1 = success, 2/3/4 = pending or confirmed
        $statusMap = [
            1 => 'success',
            // 4 => 'success', // feedback received, result successful
        ];

        $paymentStatus = $statusMap[$status] ?? 'pending';

        $payment = Payment::where('reference', $merchantOrderId)->first();

        if (!$payment) {
            // Optionally create a record if it doesn't exist
            $payment = Payment::create([
                'reference' => $merchantOrderId,
                'country' => $payload['country'] ?? 'unknown',
                'amount' => $payload['amount'] ?? 0,
                'status' => $paymentStatus,
                'meta' => $payload,
            ]);
        } else {
            $payment->update([
                'status' => $paymentStatus,
                'meta'   => $payload,
            ]);
        }

        return response()->json(['status' => 'success']);
    }
}
