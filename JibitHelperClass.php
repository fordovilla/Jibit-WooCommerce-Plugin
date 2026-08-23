<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin wrapper around the Jibit Payment Gateway (PPG) v3 REST API.
 * Docs: https://napi.jibit.ir/ppg/v3/static/docs/index.html
 */
class JibitHelperClass {

    private $apiKey;
    private $secretKey;
    private $baseUrl;
    private $userAgent;
    private $tokenOptionKey;

    public function __construct($apiKey, $secretKey) {
        $this->apiKey = $apiKey;
        $this->secretKey = $secretKey;
        $this->baseUrl = 'https://napi.jibit.ir/ppg/v3/';
        $this->userAgent = 'JibitWooCommercePlugin/' . (defined('JIBIT_WC_VERSION') ? JIBIT_WC_VERSION : '1.0.0') . ' (WooCommerce ' . (function_exists('WC') ? WC()->version : '') . '; WordPress ' . get_bloginfo('version') . '; PHP ' . PHP_VERSION . ')';
        // Tokens are per credential pair so switching API keys never reuses a stale token.
        $this->tokenOptionKey = 'jibit_wc_tokens_' . md5($this->apiKey);
    }

    /**
     * Creates a new purchase and returns the PSP switching URL the customer must be redirected to.
     */
    public function requestPayment($amount, $callbackUrl, $clientReferenceNumber, $description = '', $mobile = '', $userIdentifier = '', $wage = 0) {
        $data = array(
            'amount' => $amount,
            'wage' => $wage > 0 ? $wage : null,
            'currency' => 'IRR',
            'callbackUrl' => $callbackUrl,
            'clientReferenceNumber' => $clientReferenceNumber,
            'description' => $description,
            'payerMobileNumber' => $mobile,
            'userIdentifier' => $userIdentifier,
        );
        $data = $this->recursive_array_filter($data);
        $response = $this->sendRequest('POST', 'purchases', $data);
        if (isset($response['pspSwitchingUrl'])) {
            return $response;
        }
        throw new Exception(esc_html($this->extractErrorMessage($response)));
    }

    /**
     * Quotes the Jibit + Shaparak fee for a purchase amount without creating the purchase,
     * so the merchant can show it to the buyer as a service-cost line item before checkout.
     */
    public function quotePurchaseFee($amount, $wage = null) {
        $data = array(
            'amount' => $amount,
            'wage' => $wage,
        );
        $data = $this->recursive_array_filter($data);
        $response = $this->sendRequest('POST', 'purchases/fee', $data);
        if (isset($response['totalFee'])) {
            return $response;
        }
        throw new Exception(esc_html($this->extractErrorMessage($response)));
    }

    /**
     * Verifies a purchase after the customer returns from the PSP.
     */
    public function verifyPayment($purchaseId) {
        $response = $this->sendRequest('POST', 'purchases/' . rawurlencode($purchaseId) . '/verify', new stdClass());
        if (isset($response['status'])) {
            return $response;
        }
        throw new Exception(esc_html($this->extractErrorMessage($response)));
    }

    /**
     * Looks up purchases by criteria (purchaseId, clientReferenceNumber, status, date range, ...)
     * without mutating anything. Used for reconciling orders whose payment callback never arrived.
     */
    public function filterPurchases($criteria = array()) {
        $criteria = $this->recursive_array_filter($criteria);
        $response = $this->sendRequest('GET', 'purchases', $criteria);
        if (isset($response['elements'])) {
            return $response;
        }
        throw new Exception(esc_html($this->extractErrorMessage($response)));
    }

    /**
     * Confirms API credentials are valid by requesting a fresh access token.
     */
    public function testCredentials() {
        $this->fetchNewToken();
        return true;
    }

    private function extractErrorMessage($response) {
        if (isset($response['errors'][0]['code'])) {
            $code = $response['errors'][0]['code'];
            $message = isset($response['errors'][0]['message']) ? $response['errors'][0]['message'] : '';
            return trim($code . ' ' . $message);
        }
        return 'خطای ناشناخته در ارتباط با درگاه جیبیت';
    }

    private function getAccessToken() {
        $tokens = get_option($this->tokenOptionKey, array());
        if (!empty($tokens['accessToken']) && !empty($tokens['expiresAt']) && $tokens['expiresAt'] > time()) {
            return $tokens['accessToken'];
        }
        if (!empty($tokens['refreshToken']) && !empty($tokens['refreshExpiresAt']) && $tokens['refreshExpiresAt'] > time()) {
            $refreshed = $this->refreshToken($tokens['refreshToken']);
            if ($refreshed) {
                return $refreshed;
            }
        }
        return $this->fetchNewToken();
    }

    private function fetchNewToken() {
        $response = $this->rawRequest('POST', 'tokens', array(
            'apiKey' => $this->apiKey,
            'secretKey' => $this->secretKey,
        ), false);
        if (empty($response['accessToken'])) {
            throw new Exception(esc_html($this->extractErrorMessage($response)));
        }
        $this->storeTokens($response);
        return $response['accessToken'];
    }

    private function refreshToken($refreshToken) {
        $response = $this->rawRequest('POST', 'tokens/refresh', array(
            'refreshToken' => $refreshToken,
        ), false);
        if (empty($response['accessToken'])) {
            return false;
        }
        $this->storeTokens($response);
        return $response['accessToken'];
    }

    private function storeTokens($response) {
        // Access tokens live 24h and refresh tokens 48h; refresh a little early to avoid edge-of-expiry failures.
        update_option($this->tokenOptionKey, array(
            'accessToken' => $response['accessToken'],
            'refreshToken' => isset($response['refreshToken']) ? $response['refreshToken'] : '',
            'expiresAt' => time() + (23 * HOUR_IN_SECONDS),
            'refreshExpiresAt' => time() + (47 * HOUR_IN_SECONDS),
        ), false);
    }

    private function sendRequest($method, $endpoint, $data, $retryOnAuthFailure = true) {
        $accessToken = $this->getAccessToken();
        $response = $this->rawRequest($method, $endpoint, $data, true, $accessToken);

        $errorCode = isset($response['errors'][0]['code']) ? $response['errors'][0]['code'] : '';
        if ($retryOnAuthFailure && in_array($errorCode, array('security.auth_required', 'token.verification_failed'), true)) {
            delete_option($this->tokenOptionKey);
            return $this->sendRequest($method, $endpoint, $data, false);
        }

        return $response;
    }

    private function rawRequest($method, $endpoint, $data, $withAuth = true, $accessToken = '') {
        $url = $this->baseUrl . $endpoint;
        $plugin_version = defined('JIBIT_WC_VERSION') ? JIBIT_WC_VERSION : '1.0.0';
        $wc_version = function_exists('WC') && WC() ? WC()->version : '';
        $headers = array(
            'Content-Type' => 'application/json',
            'User-Agent' => $this->userAgent,
            'X-JIBIT-AGENT' => 'jibit_payment_WPlugin/' . $plugin_version . '/' . $wc_version,
        );
        if ($withAuth) {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
        }
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 20,
        );
        if (strtoupper($method) === 'GET') {
            if (!empty($data)) {
                $url = add_query_arg($data, $url);
            }
        } else {
            $args['body'] = wp_json_encode($data);
            $args['data_format'] = 'body';
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new Exception('خطا در ارتباط با سرور جیبیت: ' . esc_html($response->get_error_message()));
        }
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        if (!is_array($result)) {
            $result = array();
        }
        return $result;
    }

    private function recursive_array_filter($array) {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $value = $this->recursive_array_filter($value);
                if (empty($value)) {
                    unset($array[$key]);
                }
            } elseif (is_null($value) || $value === '') {
                unset($array[$key]);
            }
        }
        return $array;
    }
}
