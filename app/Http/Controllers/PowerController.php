<?php

namespace App\Http\Controllers;

use App\Models\PowerProvider;
use App\Models\PowerTransaction;
use App\Traits\APIcalls;
use App\Traits\Histories;
use App\Traits\HttpCaller;
use App\Traits\Response;
use App\Traits\Validations;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class PowerController extends Controller
{
	use HttpCaller;

	private $project, $project_donation, $payment;

	public function getProviders()
	{
		$params = [
			'response_format' => 'json'
		];

		try {
			$response = self::get_disco(['irechargePowerDisco'], $params);
			// Log::info($response);
		} catch (Exception $e) {
			return response()->json($e->getMessage());
		}
		$data = collect($response['bundles']);

		foreach ($data as $datum) {
			// dd($datum);
			$save = new PowerProvider([
				'name' => $datum['description'],
				'code' => $datum['code'],
				'min_purchase' => $datum['minimum_value'],
				'max_purchase' => $datum['maximum_value'],
				'created_at' => Carbon::now(),
				'updated_at' => Carbon::now()
			]);

			// dd($save);
			$save->save();
		}

		$providers = PowerProvider::all();
		return response()->json(['code' => '00', 'message' => 'providers fetched successfully', 'providers' => $providers]);
	}


	private function update($values, $transactionId)
	{
		$transaction = PowerTransaction::find($transactionId);

		collect($values)->each(function ($value, $key) use ($transaction) {
			$transaction->$key = $value;
		});

		$transaction->update();
	}


	private function store(Request $request): PowerTransaction
	{
		$newTransaction = new PowerTransaction([
			"ip"					=> $_SERVER['REMOTE_ADDR'],
			"transaction_id"        => $request->transaction_id,
			"phone_number"          => $request->phone_number,
			"customer_name"         => $request->customer_name,
			"disco_id"              => $request->disco_id,
			"access_token"          => $request->access_token,
			"meter_number"          => $request->meter_no,
			"amount"                => $request->amount,
			"payment_method"		=> $request->payment_method,
			"recipient_name"        => $request->recipient_name,
			"recipient_address"     => $request->recipient_address,
			"status"                => 'incomplete',
			"request_time"          => $request->request_time,
			"response_time"         => $request->response_time,
			"client_verify_request" => json_encode($request->all()),
			"api_verify_request"        => $request->api_verify_request,
			"api_verify_response"       => $request->api_verify_response,
			"client_verify_response"    => $request->client_verify_response,
		]);

		$newTransaction->save();

		return $newTransaction;
	}


	public function create(Request $request)
	{
		$isErrored =  self::validateRequest($request, self::$PowerValidation);

		if ($isErrored) return self::returnFailed($isErrored);

		if (!(self::validate_passcode($request->passcode, $request->phone_number))) return self::returnFailed("invalid passcode");

		$transaction_id = date(time() . rand(11, 99));

		$disco = PowerProvider::find($request->disco_id);

		if ($request->amount < $disco->min_vend) return self::returnFailed("minimum power purchase is " . $disco->min_vend);
		if ($request->amount > $disco->max_vend) return self::returnFailed("maximum power purchase is " . $disco->max_vend);

		$combined_string = env("IRECHARGE_VENDOR_ID") . "|" . $transaction_id . "|" . $request->meter_no . "|" . $disco->code . "|" . env("IRECHARGE_PUB_KEY");

		$hash = self::hashString($combined_string, env("IRECHARGE_PRIV_KEY"));

		$params = [
			"vendor_code"		=> env('IRECHARGE_VENDOR_ID'),
			"reference_id"		=> $transaction_id,
			"meter"		        => $request->meter_no,
			"disco"		        => $disco->code,
			"response_format"   => "json",
			"hash"				=> $hash,
		];

		try {
			$apiVerifyMeter = self::get(["irechargeVerifyMeter"], $params);
			Log::info("\n\nRESPONSE FROM 3RD PARTY on vend");
			Log::info(json_encode($apiVerifyMeter));
		} catch (Exception $e) {
			Log::error($e);
			return self::returnFailed($e->getMessage());
		}

		if ($apiVerifyMeter != null) {
			if (isset($apiVerifyMeter->status)) {
				if ($apiVerifyMeter->status !== "00") return self::returnFailed("");

				$recipient_name = $apiVerifyMeter->customer->name;
				$recipient_address = $apiVerifyMeter->customer->address;
				$access_token = $apiVerifyMeter->access_token;

				$response = [
					"recipient_name" 	=> $recipient_name,
					"recipient_address" => $recipient_address,
					"phone" 			=> $request->phone_number,
					"meter_number" 		=> $request->meter_no,
					"amount" 			=> $request->amount + $disco->service_charge,
					"provider" 			=> $disco->name,
					"transaction_id" 	=> $transaction_id,
					"requested_at" 		=> $request_time = date('D jS M Y, h:i:sA'),
					"responded_at"      => $response_time = date('D jS M Y, h:i:sa'),
				];

				$request->merge([
					"amount"					=> $request->amount,
					"transaction_id"			=> $transaction_id,
					"access_token"              => $access_token,
					"recipient_name"            => $recipient_name,
					"recipient_address"         => $recipient_address,
					"api_verify_request"        => json_encode($params),
					"api_verify_response"       => json_encode($apiVerifyMeter),
					"client_verify_response"    => json_encode($response),
					"request_time"              => $request_time,
					"response_time"             => $response_time,
				]);

				$this->store($request);

				return self::returnSuccess($response);
			}
		}

		Log::error("\n\nERROR ON VERIFYING METER NUMBER");
		Log::error("METHOD NAME: `start()`");
		return Response::returnFailed("sorry, service currently unavailable");
	}



	public function purchase(Request $request)
	{
		$requestTime = date('D jS M Y, h:i:sa');

		$isErrored =  self::validateRequest($request, self::$VendValidation);

		if ($isErrored) return self::returnFailed($isErrored);

		if (!(self::validate_passcode($request->passcode, $request->transaction_id))) return self::returnFailed("invalid passcode");

		$transaction = PowerTransaction::where("transaction_id", $request->transaction_id)->first();

		if ($transaction == null) return self::returnNotFound("transaction does not exist");
		if ($transaction->status === "fulfilled") return self::returnFailed("transaction already completed");

		$combined_string = env("IRECHARGE_VENDOR_ID") . "|" . $transaction->transaction_id . "|" . $transaction->meter_number . "|" . $transaction->provider->code . "|" . $transaction->amount . "|" . $transaction->access_token . "|" . env("IRECHARGE_PUB_KEY");

		$hash = self::hashString($combined_string, env("IRECHARGE_PRIV_KEY"));

		// AT THIS POINT, THE WALLET BALANCE IS CHECKED TO ENSURE THERE IS SUFFICIENT FUND
		// $balance = $this->payment::getBalance($transaction->amount);
		// if($balance < $transaction->amount) return self::returnFailed("opps! please try again");


		$data = array(
			"txref"	=> $request->payment_ref,
			"SECKEY" => env("RAVE_SECRET_TEST")
		);

		$validate_payment = self::verify_payment($data);

		if ($validate_payment != null) {
			if (isset($validate_payment->status) && $validate_payment->status == 'success') {
				if ($validate_payment->data->status == "successful") {

					$amount = $transaction->amount + $charge = $transaction->provider->service_charge;
					if ($validate_payment->data->amount == $amount) {

						$success = $this->payment->createPayment(json_encode($data), $transaction->transaction_id, $request->payment_ref, $requestTime, "Power", json_encode($validate_payment));

						if ($success == false) return self::returnFailed("oops! duplicate payment.");

						$params = [
							"vendor_code"		=> env('IRECHARGE_VENDOR_ID'),
							"meter"		        => $transaction->meter_number,
							"reference_id"		=> $transaction->transaction_id,
							"disco"			    => $transaction->provider->code,
							"access_token"		=> $transaction->access_token,
							"amount"            => $transaction->amount,
							"phone"             => $transaction->phone_number,
							"email"				=> env('VENDCARE_MAIL'),
							"hash"				=> $hash,
							"response_format"   => "json",
						];

						try {
							$apiVendResponse = self::get(["irechargeVendPower"], $params);
							Log::info("\n\nRESPONSE FROM 3RD PARTY on vend");
							Log::info(json_encode($apiVendResponse));
						} catch (Exception $e) {
							Log::error($e);
							return self::returnFailed($e->getMessage());
						}

						if ($apiVendResponse != null) {
							if (isset($apiVendResponse->status)) {
								if ($apiVendResponse->status !== "00") return self::returnFailed("oops! something went wrong, try again");

								$status = $apiVendResponse->status === "00" ? "fulfilled" : "pending";

								if ($status == 'fulfilled') $this->payment->resetBalance($amount);

								$units = $apiVendResponse->units;
								$token = $apiVendResponse->meter_token;

								$response = [
									"payment_reference"	    => $request->payment_ref,
									"transaction_id"	    => $transaction->transaction_id,
									"phone_number"          => $transaction->phone_number,
									"disco"			        => $transaction->provider->name,
									"meter_number"          => $transaction->meter_number,
									"recipient_name"        => $transaction->recipient_name,
									"recipient_address"     => $transaction->recipient_address,
									"amount"				=> $amount,
									"status"			    => $status,
									"token"			        => $token,
									"units"			        => $units,
									"requested_at"		    => $requestTime,
									"responded_at"          => $response_time = date('D jS M Y, h:i:sa'),
								];

								$updateData = [
									"status"				=> $status,
									"token"				    => $token,
									"units"				    => $units,
									"payment_reference"	    => $request->payment_ref,
									"api_vend_request"		=> json_encode($params),
									"api_vend_response"		=> json_encode($apiVendResponse),
									"client_vend_response"	=> json_encode($response),
									"client_vend_request"	=> json_encode($request->all()),
									"request_time"		    => $requestTime,
									"response_time"		    => $response_time,
								];

								$this->update($updateData, $transaction->id);

								$gross_income = ($transaction->amount * $transaction->provider->commission);

								$expenses = env("RAVE_CHARGE") + ($transaction->amount * env("RAVE_COMMISSION"));

								$net_income = $charge + $gross_income - $expenses;

								$project = $this->project_donation->calculate_donation($net_income, $transaction->transaction_id, $transaction->customer_name);
								$response["message"] = "Thanks for donating the sum of NGN" . (float)$net_income . " towards " . $project;

								return self::returnSuccess($response);
							}
						}

						Log::error("\n\nERROR ON VENDING DATA");
						Log::error("METHOD NAME: `vend()`");
						return self::returnFailed("sorry, service currently unavailable");
					}
					return self::returnFailed("amount paid does not match amount entered");
				}
				return self::returnFailed("error occured while validating payment");
			}
			return self::returnFailed("error processing payment");
		}

		Log::error("\n\nERROR ON VENDING DATA");
		Log::error("METHOD NAME: `vend()`");
		return self::returnFailed("sorry, service currently unavailable");
	}


	public function getHistories(Request $request)
	{
		$isErrored =  self::validateRequest($request, self::$GetHistories);
		if ($isErrored) return self::returnFailed($isErrored);
		$history = PowerTransaction::query();
		$response = self::transactionHistories($request, $history);
		$history = $response->latest()->paginate(10);
		return self::returnSuccess($history);
	}
}