<?php

class KeyManager
{
    // Define this in wp-config.php, not here:
    // define('ASLTA_KEY_ENC_KEY', '32-bytes-random-hex-or-binary');

    public static function newKeys(): void
    {
        // 1. Generate keypair
        $config = [
            'private_key_bits' => 2048, // or 4096 if you accept the cost
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        if ($res === false) {
            throw new RuntimeException('Failed to generate RSA keypair');
        }

        // Export private key PEM
        $privateKeyPem = null;
        if (!openssl_pkey_export($res, $privateKeyPem)) {
            throw new RuntimeException('Failed to export private key');
        }

        // Extract public key PEM
        $details = openssl_pkey_get_details($res);
        if ($details === false || empty($details['key'])) {
            throw new RuntimeException('Failed to extract public key');
        }
        $publicKeyPem = $details['key'];

        // 2. Store private key (encrypted) in DB
        self::storePrivateKey($privateKeyPem);

        // 3. Stream public key as a download
        self::outputPublicKeyDownload($publicKeyPem);
    }

    protected static function storePrivateKey(string $privateKeyPem): void
    {
        if (!defined('ASLTA_KEY_ENC_KEY')) {
            throw new RuntimeException('ASLTA_KEY_ENC_KEY is not defined');
        }

        $encrypted = self::encrypt($privateKeyPem, ASLTA_KEY_ENC_KEY);
        $keyId     = bin2hex(random_bytes(16));

        global $wpdb;
        $table = $wpdb->prefix . 'pdts_keys';

        $wpdb->insert(
            $table,
            [
                'key_id'      => $keyId,
                'private_key' => $encrypted,
                'created_at'  => current_time('mysql'),
                'active'      => 1,
            ],
            [
                '%s',
                '%s',
                '%s',
                '%d',
            ]
        );
    }

    protected static function encrypt(string $plaintext, string $encKey): string
    {
        // AES-256-GCM. $encKey must be 32 raw bytes; if stored as hex, convert first.
        $key = strlen($encKey) === 32 ? $encKey : hex2bin($encKey);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Invalid encryption key length');
        }

        $iv  = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed');
        }

        // Store IV + TAG + CIPHERTEXT together, base64-encoded
        return base64_encode($iv . $tag . $ciphertext);
    }

    protected static function decrypt(string $blob, string $encKey): string
    {
        $key = strlen($encKey) === 32 ? $encKey : hex2bin($encKey);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Invalid encryption key length');
        }

        $data = base64_decode($blob, true);
        if ($data === false || strlen($data) < 12 + 16) {
            throw new RuntimeException('Invalid encrypted key blob');
        }

        $iv         = substr($data, 0, 12);
        $tag        = substr($data, 12, 16);
        $ciphertext = substr($data, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed');
        }

        return $plaintext;
    }

    public static function getActivePrivateKey(): string
    {
        if (!defined('ASLTA_KEY_ENC_KEY')) {
            throw new RuntimeException('ASLTA_KEY_ENC_KEY is not defined');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pdts_keys';

        $row = $wpdb->get_row(
            "SELECT private_key FROM {$table} WHERE active = 1 ORDER BY created_at DESC LIMIT 1",
            ARRAY_A
        );

        if (!$row || empty($row['private_key'])) {
            throw new RuntimeException('No active private key found');
        }

        return self::decrypt($row['private_key'], ASLTA_KEY_ENC_KEY);
    }

    protected static function outputPublicKeyDownload(string $publicKeyPem): void
    {
        // Ensure no prior output before this point
        $filename = 'public-key-' . date('Ymd-His') . '.pem';

        header('Content-Type: application/x-pem-file');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($publicKeyPem));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        echo $publicKeyPem;
        exit;
    }
}
