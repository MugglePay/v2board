<?php

/*
 *
 * Mugglepay for V2Board
 * Author: @tokumeikoi
 *
 */

namespace App\Payments;

class Mugglepay {
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'app_secret' => [
                'label' => '应用密钥',
                'description' => '',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $params = [
            'merchant_order_id' => $order['trade_no'],
            'price_amount' => $order['total_amount'] / 100,
            'price_currency' => 'CNY',
            'title' => '支付单号：' . $order['trade_no'],
            'description' => '充值：' . $order['total_amount'] / 100 . ' 元',
            'callback_url' => $order['notify_url'],
            'success_url' => $order['return_url'],
            'cancel_url' => $order['return_url']
        ];

        $strToSign = $this->prepareSignId($params['merchant_order_id']);
        $params['token'] = $this->sign($strToSign);
        $result = $this->mprequest($params);
        $paymentUrl = isset($result['payment_url']) ? $result['payment_url'] : false;
        if (!$paymentUrl) abort(500, '请求失败');
        return [
            'type' => 1,
            'data' => $paymentUrl
        ];
    }

    public function notify($params)
    {
        $inputString = file_get_contents('php://input', 'r');
        // Log::info('bitpayXNotifyData: ' . $inputString);
        $inputStripped = str_replace(array("\r", "\n", "\t", "\v"), '', $inputString);
        $inputJSON = json_decode($inputStripped, true); //convert JSON into array

        $params = [
            'status' => $inputJSON['status'],
            'order_id' => $inputJSON['order_id'],
            'merchant_order_id' => $inputJSON['merchant_order_id'],
            'price_amount' => $inputJSON['price_amount'],
            'price_currency' => $inputJSON['price_currency'],
            'pay_amount' => $inputJSON['pay_amount'],
            'pay_currency' => $inputJSON['pay_currency'],
            'created_at_t' => $inputJSON['created_at_t']
        ];
        $strToSign = $this->prepareSignId($inputJSON['merchant_order_id']);
        if (!$this->verify($strToSign, $inputJSON['token'])) {
            abort(500, 'sign error');
        }
        if ($params['status'] !== 'PAID') {
            abort(500, 'order is not paid');
        }

        return [
            'trade_no' => $inputJSON['merchant_order_id'],
            'callback_no' => $inputJSON['order_id']
        ];
    }

    private function prepareSignId($tradeno)
    {
        $data_sign = array();
        $data_sign['merchant_order_id'] = $tradeno;
        $data_sign['secret'] = $this->config['app_secret'];
        $data_sign['type'] = 'FIAT';
        ksort($data_sign);
        return http_build_query($data_sign);
    }

    private function sign($data)
    {
        return strtolower(md5(md5($data) . $this->config['app_secret']));
    }

    private function mprequest($data)
    {
        $headers = array('content-type: application/json', 'token: ' . $this->config['app_secret']);
        $curl = curl_init();
        $url = 'https://api.mugglepay.com/v1/orders';
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, 1);
        $data_string = json_encode($data);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        $data = curl_exec($curl);
        curl_close($curl);
        return json_decode($data, true);
    }

    private function verify($data, $signature)
    {
        $mySign = $this->sign($data);
        return $mySign === $signature;
    }
}
