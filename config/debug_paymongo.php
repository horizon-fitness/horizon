<?php
header('Content-Type: text/plain');
require_once '../includes/paymongo-helper.php';

echo "Testing PayMongo Secret Key: " . PAYMONGO_SECRET_KEY . "\n";

// Simple GET request to list payment methods (requires auth)
$response = paymongo_get('payment_methods?limit=1');

echo "HTTP Status: " . $response['status'] . "\n";
echo "Response Body:\n";
print_r($response['body']);

if ($response['status'] === 200) {
    echo "\n[SUCCESS] Secret Key is VALID and connected.\n";
} else {
    echo "\n[ERROR] Secret Key is INVALID or there is a connection issue.\n";
    if (isset($response['body']['errors'])) {
        foreach ($response['body']['errors'] as $err) {
            echo "- " . ($err['detail'] ?? $err['code']) . "\n";
        }
    }
}
