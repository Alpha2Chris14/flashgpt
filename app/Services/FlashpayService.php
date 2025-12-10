<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class FlashpayService
{
    protected $url;
    protected $openid;
    protected $token;

    public function __construct()
    {
        // $this->url = "https://www.flashpay.space/Api/Pay/Submit";
        // $this->url = "https://www.flashpay.space/Api/Receive/Submit";
        $this->url = "https://pay.flashpay.fit/api/pay/unifiedOrder";
        $this->openid = env('FLASH_PAY_OPENID');
        $this->token  = env('FLASH_PAY_TOKEN');
    }

    /**
     * Generate signature: md5(md5(query_string + openid + token))
     */

    // private function signature(array $params)
    // {
    //     ksort($params); // sort keys alphabetically
    //     $query = http_build_query($params, '', '&');
    //     return md5(md5($query . $this->openid . $this->token));
    // }

    /**
     * Generate Flashpay signature (ASCII ksort, flatten, & append openid+token)
     */
    private function signature(array $param): string
    {
        $signKey = 'sign';
        $newParam = $param;
        unset($newParam[$signKey]); // remove existing sign

        $flat = $this->flattenParams($newParam);

        // join with &
        $signString = implode('&', $flat);

        // append openid + token
        $signString .= $this->openid . $this->token;

        // double MD5
        return md5(md5($signString));
    }

    /**
     * Recursively flatten param array
     */
    private function flattenParams(array $params, string $prefix = ''): array
    {
        $result = [];
        ksort($params); // ASCII sort

        foreach ($params as $k => $v) {
            if (!is_array($v)) {
                $result[] = "{$k}=" . trim($v);
            } else {
                // recursive flatten
                foreach ($this->flattenParams($v, $k) as $sub) {
                    $result[] = $sub;
                }
            }
        }

        return $result;
    }


    /**
     * Send deposit request for any country/payment type
     */
    public function deposit(array $param)
    {
        // Remove existing sign if present
        unset($param['sign']);

        // Generate new sign
        $param['sign'] = $this->signature($param);

        // Send request as form
        return Http::asForm()->post($this->url, [
            'param' => $param
        ])->json();
    }
}
