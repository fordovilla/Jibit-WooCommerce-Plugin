<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin wrapper around the Jibit Pulse SMS API (a separate product/credential pair from the
 * PPG payment API — Pulse has its own api_key/secret_key and its own auth endpoints).
 * Docs live under https://napi.jibit.ir/pulse — same host as PPG, different base path.
 */
class JibitSmsHelperClass {

    private $apiKey;
    private $secretKey;
    private $baseUrl;
    private $userAgent;
    private $tokenOptionKey;

    public function __construct($apiKey, $secretKey) {
        $this->apiKey = $apiKey;
        $this->secretKey = $secretKey;
        $this->baseUrl = 'https://napi.jibit.ir/pulse/api/v1/';
        $this->userAgent = 'JibitWooCommercePlugin/' . (defined('JIBIT_WC_VERSION') ? JIBIT_WC_VERSION : '1.0.0') . ' (WooCommerce ' . (function_exists('WC') ? WC()->version : '') . '; WordPress ' . get_bloginfo('version') . '; PHP ' . PHP_VERSION . ')';
        $this->tokenOptionKey = 'jibit_wc_sms_tokens_' . md5($this->apiKey . '|' . $this->secretKey);
    }

    /**
     * Sends a single SMS. $receptor accepts either local (09xxxxxxxxx) or comma-separated
     * multiple numbers; Pulse expects the +98 international form, so each is converted here.
     */
    public function sendSms($receptor, $message, $sender, $clientMessageId) {
        $data = array(
            'receptor' => $this->normalize_receptor($receptor),
            'message' => $message,
            'sender' => $sender,
            'client_message_id' => $clientMessageId,
        );
        $response = $this->sendRequest('POST', 'message/sms/send', $data);
        if (isset($response['sms_orders']) || isset($response['message_id'])) {
            return $response;
        }
        throw new Exception(esc_html($this->extractErrorMessage($response)));
    }

    private function normalize_receptor($receptor) {
        $numbers = array_map('trim', explode(',', (string) $receptor));
        foreach ($numbers as &$number) {
            if (preg_match('/^09\d{9}$/', $number) === 1) {
                $number = '+98' . substr($number, 1);
            }
        }
        unset($number);
        return implode(',', array_filter($numbers));
    }

    private function extractErrorMessage($response) {
        if (isset($response['message']) && is_string($response['message'])) {
            return $response['message'];
        }
        if (isset($response['errors'][0]['code'])) {
            $code = $response['errors'][0]['code'];
            $message = isset($response['errors'][0]['message']) ? $response['errors'][0]['message'] : '';
            return trim($code . ' ' . $message);
        }
        return 'خطای ناشناخته در ارتباط با سرویس پیامک جیبیت';
    }

    private function getAccessToken() {
        $tokens = get_option($this->tokenOptionKey, array());
        if (!empty($tokens['accessToken']) && !empty($tokens['expiresAt']) && $tokens['expiresAt'] > time()) {
            return $tokens['accessToken'];
        }
        if (!empty($tokens['refreshToken'])) {
            $refreshed = $this->refreshToken($tokens['accessToken'], $tokens['refreshToken']);
            if ($refreshed) {
                return $refreshed;
            }
        }
        return $this->fetchNewToken();
    }

    private function fetchNewToken() {
        $response = $this->rawRequest('POST', 'auth/authenticate', array(
            'api_key' => $this->apiKey,
            'secret_key' => $this->secretKey,
        ), false);
        if (empty($response['access_token'])) {
            throw new Exception(esc_html($this->extractErrorMessage($response)));
        }
        $this->storeTokens($response);
        return $response['access_token'];
    }

    private function refreshToken($accessToken, $refreshToken) {
        $response = $this->rawRequest('POST', 'auth/refresh-token', array(
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ), false);
        if (empty($response['access_token'])) {
            delete_option($this->tokenOptionKey);
            return false;
        }
        $this->storeTokens($response);
        return $response['access_token'];
    }

    private function storeTokens($response) {
        update_option($this->tokenOptionKey, array(
            'accessToken' => $response['access_token'],
            'refreshToken' => isset($response['refresh_token']) ? $response['refresh_token'] : '',
            'expiresAt' => time() + HOUR_IN_SECONDS,
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
            throw new Exception('خطا در ارتباط با سرویس پیامک جیبیت: ' . esc_html($response->get_error_message()));
        }
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        if (!is_array($result)) {
            $result = array();
        }
        if (($status === 401 || $status === 403) && empty($result['errors'])) {
            $result['errors'] = array(array('code' => 'security.auth_required'));
        }
        return $result;
    }
}
