<?php

namespace App\Http\Requests\Roadmap\Public;

use Illuminate\Foundation\Http\FormRequest;

/** Base for every public FormRequest: anonymous callers are allowed, validation is the gate. */
abstract class PublicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Wrap a scalar query value into an array (?status=a and ?status[]=a both work). */
    protected function arrayify(string $key): void
    {
        $value = $this->query($key, $this->input($key));
        if (is_string($value) && $value !== '') {
            $this->merge([$key => array_values(array_filter(array_map('trim', explode(',', $value))))]);
        }
    }
}
