<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'avatar' => $this->avatar,
            'birthday' => $this->birthday?->toDateString(),
            'newsletter_subscribed' => $this->newsletter_subscribed,
            'preferences' => $this->preferences,
        ];
    }
}
