<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    protected function actor(Request $request): string
    {
        return (string) $request->attributes->get('actor', 'anonymous');
    }
}
