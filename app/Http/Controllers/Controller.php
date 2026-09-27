<?php

namespace App\Http\Controllers;

use App\Support\Api\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use ApiResponse, AuthorizesRequests;
}
