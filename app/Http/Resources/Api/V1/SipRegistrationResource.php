<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SipRegistrationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'extension_id' => $this->public_id,
            'username' => $this->dialableNumber->number,
            'authorization_username' => $this->dialableNumber->number,
            'password' => $this->credential->password,
            'realm' => $this->dialableNumber->realm,
            'sip_uri' => 'sip:'.$this->dialableNumber->number.'@'.$this->dialableNumber->realm,
            'sip_server' => config('telephony.sip_server'),
            'sip_port' => config('telephony.sip_port'),
            'websocket_url' => config('telephony.websocket_url'),
            'secure_websocket_url' => config('telephony.secure_websocket_url'),
            'provisioning_status' => $this->provisioningState->status,
        ];
    }
}
