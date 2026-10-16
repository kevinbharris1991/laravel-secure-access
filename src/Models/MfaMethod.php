<?php

namespace SecureAccess\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SecureAccess\SecureAccess;

/**
 * @property int $id
 * @property int|string $user_id
 * @property string $type
 * @property string $name
 * @property string|null $secret
 * @property string|null $credential_id
 * @property string|null $credential_hash
 * @property string|null $public_key
 * @property array|null $transports
 * @property int $counter
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
class MfaMethod extends Model
{
    public const TYPE_SMS = 'sms';

    public const TYPE_TOTP = 'totp';

    public const TYPE_BIOMETRIC = 'biometric';

    public const TYPE_SECURITY_KEY = 'security_key';

    public const TYPES = [
        self::TYPE_SMS,
        self::TYPE_TOTP,
        self::TYPE_BIOMETRIC,
        self::TYPE_SECURITY_KEY,
    ];

    protected $table = 'mfa_methods';

    protected $fillable = [
        'user_id',
        'type',
        'name',
        'secret',
        'credential_id',
        'public_key',
        'transports',
        'counter',
        'last_used_at',
    ];

    protected $hidden = [
        'secret',
        'public_key',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'transports' => 'array',
            'counter' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(SecureAccess::userModel(), 'user_id');
    }

    /**
     * Store the WebAuthn credential identifier (base64url) together with its lookup hash.
     */
    public function setCredentialIdAttribute(?string $value): void
    {
        $this->attributes['credential_id'] = $value;
        $this->attributes['credential_hash'] = $value === null ? null : static::hashCredentialId($value);
    }

    public static function hashCredentialId(string $credentialId): string
    {
        return hash('sha256', $credentialId);
    }

    public function isWebAuthn(): bool
    {
        return in_array($this->type, [self::TYPE_BIOMETRIC, self::TYPE_SECURITY_KEY], true);
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_SMS => __('Text message (SMS)'),
            self::TYPE_TOTP => __('Authenticator app'),
            self::TYPE_BIOMETRIC => __('Biometrics'),
            self::TYPE_SECURITY_KEY => __('Hardware security key'),
            default => $type,
        };
    }

    public static function typeDescription(string $type): string
    {
        return match ($type) {
            self::TYPE_SMS => __('Receive a one-time code by text message on your phone.'),
            self::TYPE_TOTP => __('Use a time-based code from an authenticator app such as Google Authenticator, Microsoft Authenticator, 1Password or Authy.'),
            self::TYPE_BIOMETRIC => __('Use your fingerprint, face or device screen lock (Touch ID, Face ID, Windows Hello, Android).'),
            self::TYPE_SECURITY_KEY => __('Use a hardware token such as a YubiKey or another FIDO2 / U2F security key.'),
            default => '',
        };
    }

    public function label(): string
    {
        return static::typeLabel($this->type);
    }

    /**
     * Phone number with all but the last two digits hidden.
     */
    public function maskedPhone(): ?string
    {
        if ($this->type !== self::TYPE_SMS || ! $this->secret) {
            return null;
        }

        return str_repeat('•', max(strlen($this->secret) - 2, 0)).substr($this->secret, -2);
    }
}
