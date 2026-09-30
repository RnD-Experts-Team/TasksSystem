<?php

namespace App\Http\Requests\Roadmap\Admin;

class AbuseEventsRequest extends AdminRequest
{
    public function rules(): array
    {
        return [
            'type' => 'nullable|in:honeypot,too_fast,keyword,duplicate,link_flood,rate_limited,daily_limit,banned_write,bad_token,spike',
            'visitor_id' => 'nullable|string|size:26',
            'ip_hash' => 'nullable|string|max:32',
            'board_id' => 'nullable|integer',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
