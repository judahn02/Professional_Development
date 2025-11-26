<?php

final class ApiRequestSigner
{
    private \OpenSSLAsymmetricKey $privateKey;
    private string $keyId;

    /**
     * @param string $privateKeyPath Path to PEM-encoded RSA private key
     * @param string $keyId          Identifier for this key (e.g. "wp-plugin")
     */
    public function __construct(string $privateKeyPath, string $keyId)
    {
        $pem = @file_get_contents($privateKeyPath);
        if ($pem === false) {
            throw new \RuntimeException("Unable to read private key from {$privateKeyPath}");
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new \RuntimeException("Invalid RSA private key PEM");
        }

        $this->privateKey = $key;
        $this->keyId      = $keyId;
    }

    /**
     * Generate timestamp matching Python: datetime.datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")
     */
    public function generateTimestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * Build canonical message: method \n path \n timestamp \n + raw body
     *
     * $body must be the exact string that will be sent over the wire.
     */
    public function buildMessage(string $method, string $path, string $timestamp, string $body): string
    {
        // Do NOT trim or normalize; must match server logic exactly
        return $method . "\n" . $path . "\n" . $timestamp . "\n" . $body;
    }

    /**
     * Sign an already-built canonical message.
     *
     * Returns base64-encoded signature.
     */
    public function signMessage(string $message): string
    {
        $signature = '';
        $ok = openssl_sign(
            $message,
            $signature,
            $this->privateKey,
            OPENSSL_ALGO_SHA256 // RSA PKCS#1 v1.5 with SHA-256
        );

        if (!$ok || $signature === '') {
            throw new \RuntimeException("Failed to sign message with RSA private key");
        }

        return base64_encode($signature);
    }

    /**
     * High-level helper: given method, path, and body, compute
     * timestamp + signature + key id.
     *
     * @return array{timestamp:string, signature:string, key_id:string}
     */
    public function signRequest(string $method, string $path, string $body, ?string $timestamp = null): array
    {
        $timestamp = $timestamp ?? $this->generateTimestamp();
        $message   = $this->buildMessage($method, $path, $timestamp, $body);
        $signature = $this->signMessage($message);

        return [
            'timestamp' => $timestamp,
            'signature' => $signature,
            'key_id'    => $this->keyId,
        ];
    }

    /**
     * Optional convenience: build HTTP headers to attach to a cURL request.
     *
     * Adjust header names to match what your FastAPI verifier expects.
     *
     * @return string[]
     */
    public function buildSignedHeaders(string $method, string $path, string $body, ?string $timestamp = null): array
    {
        $signed = $this->signRequest($method, $path, $body, $timestamp);

        return [
            'Content-Type: application/json',
            'X-Timestamp: ' . $signed['timestamp'],
            'X-Signature: ' . $signed['signature'],
            'X-Key-Id: '   . $signed['key_id'],
        ];
    }
}
