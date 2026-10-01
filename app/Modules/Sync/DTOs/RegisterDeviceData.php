<?php

namespace App\Modules\Sync\DTOs;

final readonly class RegisterDeviceData
{
    public function __construct(
        public string $ulid,
        public string $name,
        public ?string $platform,
        public ?string $appVersion,
        public ?string $userAgent,
    ) {}
}
