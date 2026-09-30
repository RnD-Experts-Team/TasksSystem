<?php

namespace App\Http\Resources\Roadmap\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** AbuseEvent. Only the short ip hash leaves the server. @mixin \App\Models\Roadmap\AbuseEvent */
class AdminAbuseEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'visitor_id' => $this->visitor_id,
            'ip_hash_short' => AdminSupport::shortHash($this->ip_hash),
            'board_id' => $this->board_id,
            'meta' => $this->meta,
            'created_at' => AdminSupport::iso($this->created_at),
        ];
    }
}
