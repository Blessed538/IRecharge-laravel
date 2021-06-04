<?php

namespace App\Http\Controllers;

use App\Models\OldPowerTransaction;
use App\Models\PowerTransaction;
use App\Services\APICaller;
use App\Services\HistoryService;
use App\Services\ResponseFormats;
use App\Services\ResponseService;
use App\Services\TestFile;
use App\Services\Validations;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PowerController extends Controller
{
	use APICaller, Validations, ResponseFormats, ResponseService, HistoryService;
	// use TestFile, Validations, ResponseFormats, ResponseService, HistoryService;
	private $jos_elec, $admin;

	public function __construct(CommonController $relatedCtrl, AdminController $admin)
	{
		$this->admin = $admin;
		$this->jos_elec = $relatedCtrl;
	}

	private function create(Request $request): PowerTransaction
	{
		$newTransaction = new PowerTransaction([
			"trace_id" 			=> $request->trace_id,
			"transaction_id" 	=> time() . rand(100, 1000),
			"phone_number" 		=> $request->phone_number,
			"meter_type" 		=> $request->meter_type,
			"disco" 			=> $request->disco,
			"meter_number" 		=> $request->meter_number,
			"amount" 			=> $request->amount,
			"email" 			=> $request->email,
			"status" 			=> 'incomplete',
			"request_time" 		=> date('D jS M Y, h:i:sA'),
			"client_request" 	=> json_encode($request->all()),
			"date"				=> date('D jS M Y, h:i:sa')
		]);

		$newTransaction->save();

		return $newTransaction;
	}

	private function update($values, $transactionId)
	{
		$transaction = PowerTransaction::find($transactionId);

		collect($values)->each(function ($value, $key) use ($transaction) {
			$transaction->$key = $value;
		});

		$transaction->update();
	}

	public function start(Request $request)
	{
		$isErrored =  self::validateRequest($request, self::$PowerValidationRule);
		if ($isErrored) return self::returnFailed($isErrored);

		// $provider = $this->commonCtrl::getOneBackupValue($this->commonCtrl::ELECTRICITY_PROVIDER, "shortname", $request->disco);
		// if ($provider == null) return self::returnNotFound("Please provide valid provider");

		$transaction = $this->create($request);

		$apiVerificationRequest = [
			"vreg" 		=> $transaction->transaction_id,
			"meter" 	=> $transaction->meter_number,
			"amount" 	=> $transaction->amount,
		];

		try {
			if ($transaction->meter_type === "prepaid") {
				$apiVerificationResponse = self::get(["verify", "prepaid"], $apiVerificationRequest);
			} else {
				$apiVerificationResponse = self::post(["verify", "postpaid"], $apiVerificationRequest);
			}

			// if ($transaction->meter_type === "prepaid") {
			// 	$apiVerificationResponse = TestFile::verifyPrepaid(["verify", "prepaid"], $apiVerificationRequest);
			// } else {
			// 	$apiVerificationResponse = TestFile::verifyPostpaid(["verify", "postpaid"], $apiVerificationRequest);
			// }

			Log::info("\n\nRESPONSE FROM 3RD PARTY on validation of meter");
			Log::info("METHOD NAME: `start()`");
			Log::info(json_encode($apiVerificationResponse));
		} catch (Exception $e) {
			Log::error($e);
			return self::returnFailed($e->getMessage());
		}

		if ($apiVerificationResponse != null) {
			if (isset($apiVerificationResponse->code)) {
				if ($apiVerificationResponse->code != "100") return self::returnFailed();

				if (isset($apiVerificationResponse->client)) {
					$customer_name = $apiVerificationResponse->client->meter_name;
					$customer_address = $apiVerificationResponse->client->meter_address;
				} else {
					$customer_name = $apiVerificationResponse->name;
					$customer_address = $apiVerificationResponse->address . ", " . $apiVerificationResponse->state;
				}

				$clientResponse = [
					"customer_name" 	=> $customer_name,
					"phone" 			=> $transaction->phone_number,
					"meter_number" 		=> $transaction->meter_number,
					"meter_type" 		=> $transaction->meter_type,
					"customer_address" 	=> $transaction->customer_address,
					"amount" 			=> $transaction->amount,
					"provider" 			=> $transaction->disco,
					"transaction_id" 	=> $transaction->transaction_id,
					"email" 			=> $transaction->email,
					"requested_at" 		=> $transaction->request_time,
					"status" 			=> $transaction->status,
					"date" 				=> $transaction->date
				];

				$dataToUpdate = [
					"customer_name" 	=> $customer_name,
					"customer_address" 	=> $customer_address ?? `N/A`,
					"api_response" 		=> json_encode($apiVerificationResponse),
					"api_request" 		=> json_encode($apiVerificationRequest),
					"client_response" 	=> json_encode($clientResponse),
					"response_time" 	=> date('D jS M Y, h:i:sa'),
				];

				$this->update($dataToUpdate, $transaction->id);
				return self::returnSuccess(self::ResponseThirdParty($clientResponse, "power", "create"));
			}
		}
		Log::error("\n\ERROR ON VERIFYING METER NUMBER");
		Log::error("METHOD NAME: `start()`");
		return self::returnFailed("sorry, service currently unavailable");
	}


	public function vend(Request $request)
	{
		$vendRequestTime = date('D jS M Y, h:i:sa');
		$isErrored =  self::validateRequest($request, self::$PrepaidVendValidationRule);

		if ($isErrored) return self::returnFailed($isErrored);

		$transaction = $this->jos_elec::getOneBackupValue($this->jos_elec::POWER_TRANSACTION['NEW'], "transaction_id", $request->transaction_id);

		if ($transaction == null) return self::returnNotFound("Please provide valid transaction id");

		$apiVendRequest = [
			"amount" 	=> $transaction->amount,
			"vref" 		=> $transaction->transaction_id,
			"wallet_id" => "2DD0D82009D1320781B2D5320",
			"agent_id" 	=> "IFSR_INFOSTRATE",
			"mobile" 	=> $transaction->phone_number,
		];
		if ($transaction->type === "postpaid") {
			$apiVendRequest["account_no"] = $transaction->meter_number;
			$apiVendRequest["posted_on"] = time();
		}
		$apiVendRequest["meter"] = $transaction->meter_number;


		try {
			if ($transaction->meter_type === "prepaid") {
				$apiVendResponse = self::post(["vend", "prepaid"], $apiVendRequest);
			} else {
				$apiVendResponse = self::post(["vend", "postpaid"], $apiVendRequest);
			}

			// if ($transaction->meter_type === "prepaid") {
			// 	$apiVendResponse = TestFile::vendPrepaid(["vend", "prepaid"], $apiVendRequest);
			// } else {
			// 	$apiVendResponse = TestFile::vendPostpaid(["vend", "postpaid"], $apiVendRequest);
			// }

			Log::info("RESPONSE FROM 3RD PARTY on vend()");
			Log::info(json_encode($apiVendResponse));
		} catch (Exception $e) {
			return self::returnFailed($e->getMessage());
			Log::error($e);
		}

		if ($apiVendResponse != null) {
			if (isset($apiVendResponse->code)) {
				// if ($apiVendResponse->code === "014") return self::returnFailed("Transaction is already completed");
				if ($apiVendResponse->code !== "100") return self::returnFailed();

				$status = $apiVendResponse->code == "100" ? "fulfilled" : "pending";

				if ($status == 'fulfilled') $this->admin->resetBalance($transaction->amount);

				$clientVendResponse = [
					"phone" 			=> $transaction->phone_number,
					"amount"			=> $transaction->amount,
					"email"				=> $transaction->email,
					"transaction_id"	=> $transaction->transaction_id,
					"requested_at"		=> $transaction->request_time,
					"status"			=> $status,
					"payment_reference" => $request->payment_reference,
					"address" 			=> $transaction->customer_address,
					"token" 			=> $token = $apiVendResponse->token->pin ?? `N/A`,
					"units" 			=> $units = $apiVendResponse->token->units ?? `N/A`,
					"payment_reference" => $request->payment_reference,
					"date" 				=> $date = date('D jS M Y, h:i:sa'),
					"receiver" 			=> $transaction->meter_number,
					"customer_name"		=> $transaction->customer_name,
					"provider"			=> $transaction->disco,
				];

				$dataToUpdate = [
					"payment_reference" 	=> $request->payment_reference,
					"payment_reference" 	=> $request->payment_reference,
					"payment_reference" 	=> $request->payment_reference,
					"client_vend_request" 	=> json_encode($request->all()),
					"client_vend_response" 	=> json_encode($clientVendResponse),
					"api_vend_request" 		=> json_encode($apiVendRequest),
					"api_vend_response" 	=> json_encode($apiVendResponse),
					"vend_request_time" 	=> $vendRequestTime,
					"status" 				=> $status,
					"token" 				=> $token,
					"units" 				=> $units,
					"date" 					=> $date,
					"vend_response_time" 	=> date('D jS M Y, h:i:sa'),
				];

				$this->update($dataToUpdate, $transaction->id);
				return self::returnSuccess(self::ResponseThirdParty($clientVendResponse, "power", "vend"));
			}
		}

		Log::error("\n\ERROR ON VENDING POWER");
		Log::error("METHOD NAME: `vend()`");
		return self::returnFailed("sorry, service currently unavailable");
	}



	public function history(Request $request)
	{
		$isErrored =  self::validateRequest($request, self::$TransactionHistoryValidationRule);
		if ($isErrored) return self::returnFailed($isErrored);

		$historyData = HistoryService::fetchHistory(new PowerTransaction(), new OldPowerTransaction(), $request->all(), $request->page);

		return self::returnSuccess($historyData);
	}



	public function status($transaction_id)
	{
		$transaction = $this->jos_elec::getOneBackupValue($this->jos_elec::POWER_TRANSACTION['NEW'], "transaction_id", $transaction_id);

		if ($transaction == null) return self::returnNotFound("Please provide valid transaction id");


		try {
			if ($transaction->meter_type == "prepaid") {
				$apiStatusResponse = self::get(["status", "prepaid"], $transaction_id);
			} else {
				$apiStatusResponse = self::get(["status", "postpaid"], $transaction_id);
			}

			// if ($transaction->meter_type == "prepaid") {
			// 	$apiStatusResponse = TestFile::vendPrepaid(["status", "prepaid"], $transaction_id);

			// } else {
			// 	$apiStatusResponse = TestFile::vendPostpaid(["status", "postpaid"], $transaction_id);
			// }
			Log::info("RESPONSE FROM 3RD PARTY on vend()");
			Log::info(json_encode($apiStatusResponse));
		} catch (Exception $e) {
			return self::returnFailed($e->getMessage());
			Log::error($e);
		}

		if ($apiStatusResponse != null) {
			if (isset($apiStatusResponse->code)) {
				if ($apiStatusResponse->code != "100") return self::returnFailed("Invalid Transaction id");

				if (isset($apiStatusResponse->data)) {
					$apiQuery =  [
						"Transaction Date" => $apiStatusResponse->data->timestamp,
						"receipt no" => $apiStatusResponse->data->receipt_no,
						"meter number" => $apiStatusResponse->data->account_no,
						"amount" => $apiStatusResponse->data->amount,
					];
				} else {
					$apiQuery =  [
						"Token" => $apiStatusResponse->token->pin,
						"Units" => $apiStatusResponse->token->units,
						"Status" => $apiStatusResponse->message,
						"purchased at" => $apiStatusResponse->vend_time,
						"credited at" => $apiStatusResponse->time,
					];
				}
				return self::returnSuccess($apiQuery);
			}
		}

		Log::error("\n\ERROR ON VENDING POWER");
		Log::error("METHOD NAME: `vend()`");
		return self::returnFailed("sorry, service currently unavailable");
	}

	public function changeToken($apiTokenResponse)
	{
		try {
			$apiTokenResponse = self::get(["get-token", "attach"], $apiTokenResponse);
			// $apiTokenResponse = TestFile::token(["get-token", "attach"], $apiTokenResponse);
			Log::info("RESPONSE FROM 3RD PARTY on vend()");
			Log::info(json_encode($apiTokenResponse));
		} catch (Exception $e) {
			return self::returnFailed($e->getMessage());
			Log::error($e);
		}


		if ($apiTokenResponse != null) {
			if (isset($apiTokenResponse->code)) {
				if ($apiTokenResponse->code == "601") return self::returnFailed("No Key Change Tokens where found");

				if ($apiTokenResponse->first_code != null && $apiTokenResponse->second_code != null) {
					$token =  [
						"first_code" => $apiTokenResponse->first_code,
						"second_code" => $apiTokenResponse->second_code,
						"time" => $apiTokenResponse->time
					];
				}

				return self::returnSuccess($token);
			}
		}

		Log::error("\n\ERROR");
		Log::error("METHOD NAME: `changeToken()`");
		return self::returnFailed("sorry, service currently unavailable");
	}
}
