<?php
defined('ABSPATH') || exit;

/**
 * Low-level helper: send a signed SQL query to the ASLTA API.
 *
 * @param string $sql SQL string to execute remotely (e.g. "CALL ...").
 * @return array{status:int, body:string}
 * @throws \RuntimeException on signing/cURL failure.
 */
function aslta_signed_query(string $sql): array {
    // Resolve plugin root robustly, independent of current working directory.
    $plugin_root    = dirname(__DIR__); // .../wp-content/plugins/Professional_Development
    $privateKeyPath = $plugin_root . '/rsa-private.pem';
    // Prefer plugin constant when available
    $keyId          = defined('ASLTA_API_CLIENT_ID') ? ASLTA_API_CLIENT_ID : 'wp-plugin';

    if (!is_readable($privateKeyPath)) {
        throw new \RuntimeException('RSA private key not readable at: ' . $privateKeyPath);
    }

    // Ensure signer class is available (defensive in case include order changes)
    if (!class_exists('ApiRequestSigner')) {
        $signer_path = $plugin_root . '/includes/ApiRequestSigner.php';
        if (is_readable($signer_path)) {
            require_once $signer_path;
        }
    }

    $signer = new ApiRequestSigner($privateKeyPath, $keyId);

    $method = 'POST';
    $path   = '/dev/query';

    // IMPORTANT: $bodyJson must be the exact bytes sent via cURL
    $bodyArray = [
        'query' => $sql,
    ];
    $bodyJson = json_encode($bodyArray, JSON_UNESCAPED_SLASHES);

    // Sign using the exact body string
    $headers = $signer->buildSignedHeaders($method, $path, $bodyJson);

    $base = defined('ASLTA_API_BASE_URL') ? ASLTA_API_BASE_URL : 'https://aslta.parallelsolvit.com';
    $url  = rtrim($base, '/') . $path;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $bodyJson, // same string used in signing
        // CURLOPT_SSL_VERIFYPEER => true, // enable in production with valid certs
    ]);

    $responseBody = curl_exec($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($responseBody === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new \RuntimeException('cURL error: ' . $err);
    }

    curl_close($ch);

    return [
        'status' => (int) $httpCode,
        'body'   => (string) $responseBody,
    ];
}

/**
 * Simple connectivity test admin page: runs a trivial query via the signed API.
 */
function Testing_Connection_To_DB() {
    try {
        $result = aslta_signed_query('SELECT 1;');
        echo '<pre>'; // simple debug-style output in the admin page
        echo 'HTTP Status: ' . (int) $result['status'] . "\n";
        echo 'Body: ' . esc_html($result['body']);
        echo '</pre>';
    } catch (\Throwable $e) {
        echo '<pre>Connection test failed: ' . esc_html($e->getMessage()) . '</pre>';
    }
}
