<?php

namespace App\Http\Requests\Roadmap\Admin;

class UploadAssetRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'type' => 'required|in:logo,logo_dark,favicon',
            'file' => 'required|image|mimes:png,jpg,jpeg,webp|max:1024',
        ];
    }
}
