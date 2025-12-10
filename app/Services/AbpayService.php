<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AbpayService
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

        // Send request to Abpay API
        $response = Http::withHeaders(["Content-Type" => "application/json"])
            ->post($this->baseUrl . "/api/pay/unifiedOrder", $data);

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
}
