<?php

namespace App\Traits;

trait APICaller
{
    public static $baseURL, $urlSet;

    public static function urlRequest()
    {
        APICaller::$baseURL = env('JED_BASE_URL');

        APICaller::$urlSet = [
            "balance" => [
                "attach" => "wallets",
            ],

            "get-token" => [
                "attach" => "energy/jos/prepaid/live/keytoken/",
            ],

            "verify" => [
                "prepaid" => "energy/jos/prepaid/live/customer/",
                "postpaid" => "energy/jed/postpaid/live/account",
            ],

            "vend" => [
                "prepaid" => "core/energy/jos/prepaid/live/vend",
                "postpaid" => "energy/jed/postpaid/live/payment"
            ],

            "status" => [
                "prepaid" => "energy/jos/prepaid/live/transaction/",
                "postpaid" => "energy/jed/postpaid/live/transaction/"
            ]
        ];
    }


    /**
     * Method to make get request from the third party API
     *
     * @param  array  $urlAddress, @param string $name
     * @return object
     */
    public static function get($urlArray, $ref = null)
    {
        $append = self::urlRequest($urlArray);

        $url = env('BASE_URL') . $append;
        // $url = APICaller::$urlSet[$urlArray[0]][$urlArray[1]];

        if ($ref != null) $url = APICaller::$urlSet[$urlArray[0]][$urlArray[1]] . $ref;

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => [
                "Authorization:" . APICaller::$auth,
                "content-type: application/json",
                "cache-control: no-cache"
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            // If an error occur contacting the thirdParty API
            die("Error #:" . $err);
        }

        return json_decode($response);
    }


    /**
     * Method to call a post request from the third party API
     *
     * @param  array  $urlAddress, @param string $name
     * @return object
     */
    public static function post($urlArray, $data)
    {
        $url = APICaller::$urlSet[$urlArray[0]][$urlArray[1]];

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                "Authorization:" . APICaller::$auth,
                "Content-Type: application/json",
                "Postman-Token: e1ab5b69-217f-4404-9375-eebe2094fc13",
                "cache-control: no-cache"
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            // If an error occur contacting the thirdParty API
            echo "Error #:" . $err;
        }
        return json_decode($response);
    }
}