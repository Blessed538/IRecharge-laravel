<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait HttpCaller
{
    public static $baseURL, $urlSet;

    public static function urlRequest()
    {
        HttpCaller::$baseURL = env('BASE_URL');

        HttpCaller::$urlSet = [
            "irechargePowerDisco"   => "get_electric_disco.php?",
            "irechargeVerifyMeter"  => "get_meter_info.php?",
            "irechargeVendPower"    => "vend_power.php?"
        ];
    }

    /**
     * Method to make get request from the third party API
     *
     * @param  array  $urlAddress, @param string $name
     * @return object
     */
    public static function get_disco($urlArray, $ref = null)
    {
        HttpCaller::urlRequest();

        $url = HttpCaller::$baseURL . HttpCaller::$urlSet[$urlArray[0]];

        if (count($ref) > 0) $url = APIcalls::setUrlParameters($url, $ref);

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => [
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
        $response = self::remove_utf8_bom($response);
        // dd($response);
        return json_decode($response, true);
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
}