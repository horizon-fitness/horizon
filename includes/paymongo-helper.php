<?php
require_once __DIR__ . '/../config/paymongo-config.php';

/**
 * PayMongo Helper Utility
 */

function paymongo_post($endpoint, $data) {
    $url = PAYMONGO_BASE_URL . $endpoint;
    $apiKey = PAYMONGO_SECRET_KEY;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['data' => ['attributes' => $data]]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($apiKey . ':')
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $httpCode,
        'body' => json_decode($response, true)
    ];
}

function paymongo_get($endpoint) {
    $url = PAYMONGO_BASE_URL . $endpoint;
    $apiKey = PAYMONGO_SECRET_KEY;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($apiKey . ':')
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $httpCode,
        'body' => json_decode($response, true)
    ];
}

function create_checkout_session($amount, $description, $success_url, $cancel_url, $billing = [], $metadata = []) {
    $data = [
        'line_items' => [
            [
                'amount' => (int)($amount * 100), // convert to centavos
                'currency' => 'PHP',
                'description' => $description,
                'name' => $description,
                'quantity' => 1
            ]
        ],
        'payment_method_types' => ['gcash', 'grab_pay', 'paymaya', 'card'],
        'success_url' => $success_url,
        'cancel_url' => $cancel_url,
        'description' => $description,
        'metadata' => $metadata
    ];

    if (!empty($billing)) {
        $data['billing'] = $billing;
    }

    return paymongo_post('checkout_sessions', $data);
}

function retrieve_checkout_session($session_id) {
    return paymongo_get('checkout_sessions/' . $session_id);
}
