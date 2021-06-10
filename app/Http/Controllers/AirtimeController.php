<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\POWERAPICaller;

class AirtimeController extends Controller
{
    public function __construct()
	{
	
	}

    public function get_Electric_disco($app)
    {
        POWERAPICaller::get_disco(POWERAPICaller::$urlSet[$app],['format_type'=>'json']);
    }
}