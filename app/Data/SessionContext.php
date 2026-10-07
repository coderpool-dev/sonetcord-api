<?php

namespace App\Data;

use Illuminate\Http\Request;

final readonly class SessionContext
{
    public function __construct(public string $ip, public ?string $userAgent) {}

    public static function fromRequest(Request $request): self
    {
        return new self($request->ip() ?: '127.0.0.1', $request->userAgent());
    }
}
