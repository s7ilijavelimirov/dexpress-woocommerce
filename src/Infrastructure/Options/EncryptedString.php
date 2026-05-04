<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Options;

use RuntimeException;

/**
 * AES-256-GCM encrypted string value object.
 *
 * Designed for storing the D Express API password at rest.
 * The encryption key is derived from WordPress AUTH_KEY (wp-config.php).
 *
 * Storage format (base64-encoded): nonce[12] | tag[16] | ciphertext[n]
 *
 * If AUTH_KEY changes (e.g. after wp-config.php regeneration), decrypt() will
 * throw a RuntimeException and the password must be re-entered in settings.
 *
 * __toString() is intentionally NOT implemented to prevent accidental logging.
 * Use toString() explicitly when persisting to storage.
 */
final class EncryptedString
{
    private const CIPHER      = 'aes-256-gcm';
    private const NONCE_LEN   = 12;
    private const TAG_LEN     = 16;
    private const KEY_CONTEXT = 'dexpress_v2_key';

    private function __construct(
        private readonly string $stored,
    ) {}

    /**
     * Encrypts a plaintext value and returns an instance ready for toString().
     *
     * @throws RuntimeException if OpenSSL encryption fails.
     */
    public static function encrypt(string $plaintext): self
    {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag   = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::deriveKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LEN,
        );

        if ($ciphertext === false) {
            throw new RuntimeException(
                'DExpress: OpenSSL encryption failed: ' . openssl_error_string()
            );
        }

        return new self(base64_encode($nonce . $tag . $ciphertext));
    }

    /**
     * Constructs an instance from a stored (base64-encoded) value.
     * Does not validate or decrypt at construction time.
     */
    public static function fromString(string $stored): self
    {
        return new self($stored);
    }

    /**
     * Returns the base64-encoded value for storage in wp_options.
     */
    public function toString(): string
    {
        return $this->stored;
    }

    /**
     * True if no value has been stored yet (empty string was passed to fromString).
     */
    public function isEmpty(): bool
    {
        return $this->stored === '';
    }

    /**
     * Decrypts and returns the plaintext credential.
     *
     * @throws RuntimeException if the stored value is corrupted or AUTH_KEY has changed.
     */
    public function decrypt(): string
    {
        if ($this->stored === '') {
            throw new RuntimeException('DExpress: No API password has been saved yet.');
        }

        $raw = base64_decode($this->stored, true);

        $minLen = self::NONCE_LEN + self::TAG_LEN + 1;

        if ($raw === false || strlen($raw) < $minLen) {
            throw new RuntimeException(
                'DExpress: Stored credential is invalid or corrupted. Please re-enter the API password in D Express settings.'
            );
        }

        $nonce      = substr($raw, 0, self::NONCE_LEN);
        $tag        = substr($raw, self::NONCE_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::NONCE_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::deriveKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw new RuntimeException(
                'DExpress: Failed to decrypt API password. If you recently regenerated wp-config.php security keys, re-enter the API password in D Express → Settings → API podešavanja.'
            );
        }

        return $plaintext;
    }

    /**
     * Derives a 32-byte key from WordPress security keys.
     * Falls back through SECURE_AUTH_KEY and LOGGED_IN_KEY if AUTH_KEY is absent.
     *
     * @throws RuntimeException if no WordPress security key is defined.
     */
    private static function deriveKey(): string
    {
        $source = match (true) {
            defined('AUTH_KEY')         => AUTH_KEY,
            defined('SECURE_AUTH_KEY')  => SECURE_AUTH_KEY,
            defined('LOGGED_IN_KEY')    => LOGGED_IN_KEY,
            default                     => '',
        };

        if ($source === '') {
            throw new RuntimeException(
                'DExpress: WordPress security keys (AUTH_KEY) are not defined in wp-config.php.'
            );
        }

        return hash_hmac('sha256', self::KEY_CONTEXT, $source, true);
    }
}
