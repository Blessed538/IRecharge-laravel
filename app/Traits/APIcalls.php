<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait APIcalls
{
    public static $paystackUrl, $urlSet, $irechargeBaseUrl, $auth;

    public static function urlRequest()
    {
        APIcalls::$irechargeBaseUrl = env('IRECHARGE_BASE_URL');
        APIcalls::$paystackUrl = env('PAYSTACK_BASE_URL');
        APIcalls::$auth = env('PAYSTACK_AUTH');

        APIcalls::$urlSet = [
            "irechargeDataBundles"  => "get_data_bundles.php?",
            "irechargeVendData"     => "vend_data.php?",
            "irechargeVerifySmile"  => "get_smile_info.php?",

            "irechargeVendAirtime"  => "vend_airtime.php?",

            "irechargePowerDisco"   => "get_electric_disco.php?",
            "irechargeVerifyMeter"  => "get_meter_info.php?",
            "irechargeVendPower"    => "vend_power.php?",

            "irechargeTvBouquets"   => "get_tv_bouquet.php?",
            "irechargeVerifyCard"   => "get_smartcard_info.php?",
            "irechargeVendTv"       => "vend_tv.php?",

            "irechargeStatus"       => "vend_status.php?",
            "irechargeBalance"      => "get_wallet_balance.php?",

            "verify_payment"    => "transaction/verify",
            "charge-card"       => "transaction/charge_authorization",
        ];
    }

    public static function remove_utf8_bom($text)
    {
        $bom = pack('H*', 'EFBBBF');
        $text = preg_replace("/^$bom/", '', $text);
        return $text;
    }

    public static function hashString($string_to_hash, $privateKey)
    {
        $hashed = hash_hmac("sha1", $string_to_hash,  $privateKey);

        Log::info("\n\nHASHED FOR API: " . $hashed);
        return $hashed;
    }

    public static function setUrlParameters(String $url, array $obj): String
    {
        foreach ($obj as $key => $value) {
            $url = $url . "$key=$value&";
        }
        return substr($url, 0, (strlen($url) - 1));
    }


    public static function validate_passcode($passcode, $string_to_hash)
    {
        $expected = hash_hmac("sha512", $string_to_hash,  env('PASSKEY'));
        Log::info("\n\nCOMPARE PASSKEY AND MANUAL HASH KEY FROM FRONT END \n" . "PASSKEY: " . $passcode . "\nGENHASH: " . $expected);
        if ($passcode != $expected) {
            return false;
        }
        return true;
    }


    /**
     * Method to call the iRecharge API to make transactions
     *
     * @param  array  $urlArray, @param array $append
     * @return object
     */
    public static function get($urlArray = null, $append = [])
    {
        self::urlRequest();

        $url = APIcalls::$irechargeBaseUrl . APIcalls::$urlSet[$urlArray[0]];

        if (count($append) > 0) $url = APIcalls::setUrlParameters($url, $append);

        Log::info("\n\nIRECHARGE URL: " . $url);

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
                "content-type: application/json",
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return $err;
        }

        $data = self::remove_utf8_bom($response);

        return json_decode($data, false);
    }



    /**
     * Method to make a get request to flutter wave to verify payment
     *
     * @param  array  $urlAddress, @param string $name
     * @return object
     */
    public static function verify_payment($payload)
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.ravepay.co/flwv3-pug/getpaidx/api/v2/verify",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return "Error #:" . $err;
        }

        $data = self::remove_utf8_bom($response);

        return json_decode($data);
    }




    public static function post($urlParams, $payload)
    {
        self::urlRequest();

        $url = APIcalls::$paystackUrl . APIcalls::$urlSet[$urlParams[0]];

        Log::info("\n\nTHIRD PARTY URL: " . $url);

        dd($payload);
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
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return "Error #:" . $err;
        }

        $data = self::remove_utf8_bom($response);

        return json_decode($data, true);
    }




    public static function getPlain($urlParams, $id = null)
    {
        self::urlRequest();

        $url = APIcalls::$paystackUrl . APIcalls::$urlSet[$urlParams[0]];

        if ($id != null) $url = $url . '/' . $id;
        Log::info("\n\nTHIRD PARTY URL: " . $url);

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                "Accept: application/json",
                "Authorization: Bearer " . APIcalls::$auth
            ),
        ));

        $response = curl_exec($curl);
        $error = curl_error($curl);

        curl_close($curl);

        if ($error) {
            return $error;
        } else {
            // success
            return json_decode($response);
        }
    }
}
