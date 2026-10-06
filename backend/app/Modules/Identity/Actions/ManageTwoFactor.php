<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;

/**
 * Optional TOTP (M0-09) on top of Fortify's engine: enabling returns the secret to enrol,
 * the factor only becomes active once a first code has been confirmed.
 */
final class ManageTwoFactor
{
    public function __construct(
        private readonly EnableTwoFactorAuthentication $enable,
        private readonly ConfirmTwoFactorAuthentication $confirm,
        private readonly DisableTwoFactorAuthentication $disable,
    ) {}

    /**
     * @return array{secret: string, otpauth_url: string, qr_svg: string}
     */
    public function start(User $user): array
    {
        ($this->enable)($user, true);
        $user->refresh();
        $url = $user->twoFactorQrCodeUrl();

        return [
            'secret' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            'otpauth_url' => $url,
            'qr_svg' => (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($url),
        ];
    }

    /**
     * @return list<string> recovery codes, shown once
     */
    public function confirm(User $user, string $code): array
    {
        ($this->confirm)($user, $code);

        return $user->refresh()->recoveryCodes();
    }

    public function disable(User $user): void
    {
        ($this->disable)($user);
    }
}
