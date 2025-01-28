<?php

namespace App\Http\Controllers;

use App\Comment;
use App\Currency;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
    public static function currencies(){

           $currencies = Currency::all();
            $options = '';
            foreach ($currencies as $currency) {
                $options .= '<option value="' . $currency['id'] . '">' . $currency['country'] . ' (' . $currency['name'] . ')</option>';
            }
            return $options;
        }


    }
