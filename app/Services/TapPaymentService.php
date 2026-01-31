<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Exception;

class TapPaymentService
{
    protected $client;
    protected $secretKey;
    protected $apiUrl;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'verify' => true // Enable SSL verification
        ]);
        $this->secretKey = env('TAP_SECRET_KEY');
        $this->apiUrl = env('TAP_API_URL', 'https://api.tap.company/v2');

        if (!$this->secretKey) {
            throw new Exception('TAP_SECRET_KEY is not configured');
        }
    }

    /**
     * Create a new charge
     */
    public function createCharge($amount, $currency, $customer, $redirectUrl, $metadata = [], $subtotal = null, $taxAmount = null)
    {
        try {
            // Validate inputs
            if (empty($this->secretKey)) {
                throw new Exception('TAP_SECRET_KEY is not configured properly');
            }

            if (empty($this->apiUrl)) {
                throw new Exception('TAP_API_URL is not configured properly');
            }

            // Calculate tax if not provided
            // Note: Prices include 15% VAT (tax is deducted from total, not added)
            if ($subtotal === null) {
                $taxBreakdown = getTaxBreakdown($amount);
                $subtotal = $taxBreakdown['subtotal'];
                $taxAmount = $taxBreakdown['tax_amount'];
            }

            $payload = [
                "amount" => $amount,
                "currency" => $currency,
                "threeDSecure" => true,
                "save_card" => false,
                "description" => "Course Payment",
                "statement_descriptor" => "Course Payment",
                "customer" => $customer,
                "redirect" => [
                    "url" => $redirectUrl
                ],
                "source" => [
                    "id" => "src_all"
                ],
                "receipt" => [
                    "email" => true,
                    "sms" => true
                ]
            ];

            // Add items breakdown to display on payment page
            if ($taxAmount > 0) {
                $payload["items"] = [
                    [
                        "name" => "Subtotal",
                        "description" => "Course price before tax",
                        "quantity" => 1,
                        "amount_per_unit" => $subtotal,
                        "total_amount" => $subtotal
                    ],
                    [
                        "name" => "Tax (VAT 15%)",
                        "description" => "Value Added Tax",
                        "quantity" => 1,
                        "amount_per_unit" => $taxAmount,
                        "total_amount" => $taxAmount
                    ]
                ];
            }

            // Add webhook only if route exists
            try {
                $webhookUrl = route('api.payment.webhook');
                $payload["post"] = ["url" => $webhookUrl];
            } catch (\Exception $routeException) {
                Log::warning('Webhook route not available', ['error' => $routeException->getMessage()]);
                // Continue without webhook
            }

            // Add metadata if provided
            if (!empty($metadata)) {
                $payload['metadata'] = $metadata;
                // Add tax information to metadata for reference
                $payload['metadata']['subtotal'] = $subtotal;
                $payload['metadata']['tax_amount'] = $taxAmount;
                $payload['metadata']['tax_percentage'] = 15;
            }

            Log::info('Sending request to Tap API', [
                'url' => "{$this->apiUrl}/charges",
                'payload' => $payload,
                'secret_key_length' => strlen($this->secretKey),
                'secret_key_prefix' => substr($this->secretKey, 0, 10) . '...'
            ]);

            $response = $this->client->post("{$this->apiUrl}/charges", [
                'headers' => [
                    'Authorization' => "Bearer {$this->secretKey}",
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $payload
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();

            Log::info('Tap API Response', [
                'status_code' => $statusCode,
                'response_body' => $responseBody
            ]);

            $result = json_decode($responseBody, true);

            if ($result === null) {
                throw new Exception('Invalid JSON response from Tap API');
            }

            // Check for API errors
            if (isset($result['errors'])) {
                Log::error('Tap API returned errors', ['errors' => $result['errors']]);
                throw new Exception('API Error: ' . json_encode($result['errors']));
            }

            Log::info('Tap Payment Charge Created Successfully', [
                'charge_id' => $result['id'] ?? null,
                'amount' => $amount,
                'currency' => $currency,
                'status' => $result['status'] ?? 'unknown'
            ]);

            return $result;

        } catch (GuzzleException $e) {
            $responseBody = '';
            $statusCode = 0;

            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $responseBody = $e->getResponse()->getBody()->getContents();
            }

            Log::error('Tap Payment API Error - Detailed', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'status_code' => $statusCode,
                'response_body' => $responseBody,
                'request_url' => "{$this->apiUrl}/charges",
                'secret_key_configured' => !empty($this->secretKey),
                'api_url_configured' => !empty($this->apiUrl)
            ]);

            // Parse error response if available
            if (!empty($responseBody)) {
                $errorData = json_decode($responseBody, true);
                if ($errorData && isset($errorData['errors'])) {
                    throw new Exception('Tap API Error: ' . json_encode($errorData['errors']));
                }
            }

            throw new Exception('Payment service error: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Unexpected error in createCharge', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'stack_trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Retrieve charge details
     */
    public function retrieveCharge($chargeId)
    {
        try {
            Log::info('Retrieving charge details', [
                'charge_id' => $chargeId,
                'api_url' => $this->apiUrl
            ]);

            $response = $this->client->get("{$this->apiUrl}/charges/{$chargeId}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->secretKey}",
                    'Accept' => 'application/json'
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();

            Log::info('Tap Retrieve Charge Response', [
                'charge_id' => $chargeId,
                'status_code' => $statusCode,
                'response_body' => $responseBody
            ]);

            $result = json_decode($responseBody, true);

            if ($result === null) {
                throw new Exception('Invalid JSON response when retrieving charge');
            }

            // Check for API errors
            if (isset($result['errors'])) {
                Log::error('Tap API returned errors when retrieving charge', [
                    'charge_id' => $chargeId,
                    'errors' => $result['errors']
                ]);
                throw new Exception('API Error: ' . json_encode($result['errors']));
            }

            return $result;

        } catch (GuzzleException $e) {
            $responseBody = '';
            $statusCode = 0;

            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $responseBody = $e->getResponse()->getBody()->getContents();
            }

            Log::error('Tap Payment Retrieve Error - Detailed', [
                'charge_id' => $chargeId,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'status_code' => $statusCode,
                'response_body' => $responseBody
            ]);

            throw new Exception('Unable to retrieve payment details: ' . $e->getMessage());
        }
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature($payload, $signature)
    {
        $expectedSignature = hash_hmac('sha256', $payload, $this->secretKey);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Process refund
     */
    public function createRefund($chargeId, $amount = null, $reason = null)
    {
        try {
            $payload = [
                'charge_id' => $chargeId,
                'reason' => $reason ?? 'requested_by_customer'
            ];

            if ($amount) {
                $payload['amount'] = $amount;
            }

            $response = $this->client->post("{$this->apiUrl}/refunds", [
                'headers' => [
                    'Authorization' => "Bearer {$this->secretKey}",
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $payload
            ]);

            return json_decode($response->getBody(), true);

        } catch (GuzzleException $e) {
            Log::error('Tap Payment Refund Error', [
                'charge_id' => $chargeId,
                'error' => $e->getMessage()
            ]);
            throw new Exception('Unable to process refund.');
        }
    }
}
