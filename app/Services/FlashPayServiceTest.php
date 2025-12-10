<?php

namespace App\Services;

use App\Models\FlashPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FlashPayServiceTest
{
    private string $baseUrl;
    private string $merchantNo;
    private string $appId;
    private string $privateKeyPath;

    public function __construct()
    {
        $this->baseUrl        = env('ABPAY_BASE_URL');
        $this->merchantNo     = env('ABPAY_MERCHANT_NO');
        $this->appId          = env('ABPAY_APP_ID');
        $this->privateKeyPath = storage_path('keys/abpay_private_key.pem');
    }

    /**
     * Create Unified Order
     */
    public function unifiedOrder(array $payload)
    {
        // Add required defaults
        $data = array_merge([
            "mchNo"      => $this->merchantNo,
            "appId"      => $this->appId,
            "version"    => "1.0",
            "reqTime"    => now()->getTimestampMs(),
            "signType"   => "RSA",
        ], $payload);

        // Convert any array values to JSON strings
        array_walk($data, function (&$value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        });

        // Generate RSA signature
        $data["sign"] = $this->rsaSign($data);

        $payment = FlashPayment::create([
            'mch_order_no' => $data['mchOrderNo'] ?? ($payload['mchOrderNo'] ?? Str::uuid()->toString()),
            'mch_no'       => $this->merchantNo,
            'app_id'       => $this->appId,
            'way_code'     => $payload['wayCode'] ?? null,
            'amount'       => $payload['amount'] ?? 0,
            'currency'     => $payload['currency'] ?? null,
            'status'       => 'created',
            'meta'         => $data,
        ]);


        // Send request to Abpay API
        $response = Http::withHeaders(["Content-Type" => "application/json"])
            ->post($this->baseUrl . "/api/pay/unifiedOrder", $data);

        // If response includes payOrderId or state, update record
        if (isset($response['data'])) {
            $respData = $response['data'];
            $payment->update([
                'pay_order_id' => $respData['payOrderId'] ?? $payment->pay_order_id,
                'status'       => $this->mapStateToStatus($respData['orderState'] ?? null),
                'meta'         => array_merge($payment->meta ?? [], ['response' => $response]),
            ]);
        } else {
            $payment->update([
                'meta' => array_merge($payment->meta ?? [], ['response' => $response]),
            ]);
        }

        return $response->json();
    }

    /**
     * RSA Signature Generation
     */
    private function rsaSign(array $params): string
    {
        // Remove sign key if exists
        unset($params["sign"]);

        // Sort parameters by key
        ksort($params);

        // Build string: key=value&key=value...
        $dataString = urldecode(http_build_query($params));

        // Load private key
        $privateKey = file_get_contents($this->privateKeyPath);
        $res = openssl_pkey_get_private($privateKey);

        if (!$res) {
            dd(openssl_error_string(), "Unable to load private key. Check formatting!");
        }

        // Sign the data
        openssl_sign($dataString, $signature, $res, OPENSSL_ALGO_SHA256);

        // Free the key resource
        openssl_free_key($res);

        // Return base64 encoded signature
        return base64_encode($signature);
    }


    public function verifyNotification(array $payload): bool
    {
        $sign = $payload['sign'] ?? null;
        if (!$sign) {
            return false;
        }

        // Build canonical string excluding sign
        $data = $payload;
        unset($data['sign']);

        ksort($data);
        // convert arrays to json strings
        array_walk($data, function (&$v) {
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        });

        $string = urldecode(http_build_query($data));

        // verify with public key
        $privateKey = file_get_contents($this->privateKeyPath);
        $res = openssl_pkey_get_private($privateKey);
        if (!$res) {
            // private key load failed
            return false;
        }

        $verify = openssl_verify($string, base64_decode($sign), $res, OPENSSL_ALGO_SHA256);
        openssl_free_key($res);

        return $verify === 1;
    }

    /**
     * Convert AbPay numeric state to local status
     */
    public function mapStateToStatus($state): string
    {
        // Abpay docs use: 0 created, 1 paying, 2 success, 3 failed, 4 cancelled, 5 refunded, 6 closed
        return match ((int)$state) {
            2 => 'success',
            3 => 'failed',
            4 => 'cancelled',
            5 => 'refunded',
            6 => 'closed',
            1 => 'paying',
            0 => 'created',
            default => 'unknown',
        };
    }

    private function rsaSignFromArray(array $params): string
    {
        unset($params['sign']);
        ksort($params);

        array_walk($params, function (&$value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        });

        return $this->rsaSign($params);
    }


    public function queryOrder(string $mchOrderNo = null, string $payOrderId = null)
    {
        $payload = [];

        if ($payOrderId) {
            $payload['payOrderId'] = $payOrderId;
        }

        if ($mchOrderNo) {
            $payload['mchOrderNo'] = $mchOrderNo;
        }
        $payload = array_merge($payload, [
            'mchNo'    => $this->merchantNo,
            'appId'    => $this->appId,
            'version'  => '1.0',
            'reqTime'  => now()->getTimestampMs(),
            'signType' => 'RSA',
        ]);

        // sign payload
        $payload['sign'] = $this->rsaSignFromArray($payload);

        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post($this->baseUrl . '/api/pay/query', $payload)
            ->json();

        // Update FlashPayment if found locally
        if (isset($response['data'])) {
            $resp = $response['data'];
            $mchNo = $resp['mchNo'] ?? $this->merchantNo;
            $mchOrderNo = $resp['mchOrderNo'] ?? null;
            $payOrderId = $resp['payOrderId'] ?? null;
            $state = $resp['state'] ?? $resp['orderState'] ?? null;

            $payment = null;
            if ($mchOrderNo) {
                $payment = FlashPayment::where('mch_order_no', $mchOrderNo)->first();
            }
            if (!$payment && $payOrderId) {
                $payment = FlashPayment::where('pay_order_id', $payOrderId)->first();
            }
            if ($payment) {
                $payment->update([
                    'status' => $this->mapStateToStatus($state),
                    'meta'   => array_merge($payment->meta ?? [], ['query_response' => $response]),
                ]);
            }
        }
        return $response;
    }
}
