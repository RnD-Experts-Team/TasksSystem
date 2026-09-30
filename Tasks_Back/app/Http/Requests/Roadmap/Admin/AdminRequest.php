<?php

namespace App\Http\Requests\Roadmap\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Base of the roadmap admin requests. Authorisation is done by the route middleware. */
abstract class AdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
