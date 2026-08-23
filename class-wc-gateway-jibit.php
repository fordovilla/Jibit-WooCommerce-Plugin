<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'JibitHelperClass.php';
require_once plugin_dir_path(__FILE__) . 'JibitSmsHelperClass.php';

function jibit_load_gateway() {
    if (!function_exists('jibit_add_gateway_class') && class_exists('WC_Payment_Gateway') && !class_exists('WC_Jibit')) {
        add_filter('woocommerce_payment_gateways', 'jibit_add_gateway_class');
        function jibit_add_gateway_class($methods) {
            $methods[] = 'WC_Jibit';
            return $methods;
        }

        class WC_Jibit extends WC_Payment_Gateway {
            public $apiKey;
            public $secretKey;
            public $successMessage;
            public $failedMessage;
            public $instructions;
            public $feePayer;
            public $feeTitle;
            public $smsEnabled;
            public $smsBuyerEnabled;
            public $smsApiKey;
            public $smsSecretKey;
            public $smsSender;
            public $smsAdminMobile;
            public $jibit;
            public $jibitSms;

            public function __construct() {
                $this->id = 'WC_Jibit';
                $this->method_title = __('پرداخت جیبیت', 'jibit-woocommerce-payment-gateway');
                $this->method_description = __('تنظیمات درگاه پرداخت جیبیت (Jibit) برای افزونه فروشگاه ساز ووکامرس', 'jibit-woocommerce-payment-gateway');
                $this->icon = apply_filters('jibit_logo', JIBIT_WC_PLUGIN_URL . 'assets/images/logo.png');
                $this->has_fields = false;
                $this->supports = array(
                    'products',
                );

                $this->init_form_fields();
                $this->init_settings();

                // Title & description are fixed by design (not merchant-editable) so the checkout
                // label/branding stays consistent across every store running this gateway.
                $this->title = __('پرداخت آنلاین (جیبیت)', 'jibit-woocommerce-payment-gateway');
                $this->description = __('پرداخت امن به وسیله کلیه کارت‌های عضو شتاب از طریق درگاه جیبیت', 'jibit-woocommerce-payment-gateway');
                $this->apiKey = $this->get_option('api_key');
                $this->secretKey = $this->get_option('secret_key');
                $this->successMessage = $this->get_option('success_message');
                $this->failedMessage = $this->get_option('failed_message');
                $this->instructions = $this->get_option('instructions');
                $this->feePayer = $this->get_option('fee_payer', 'merchant');
                $this->feeTitle = $this->get_option('fee_title', __('کارمزد خدمات', 'jibit-woocommerce-payment-gateway'));
                $this->smsEnabled = $this->get_option('sms_enabled') === 'yes';
                $this->smsBuyerEnabled = $this->get_option('sms_buyer_enabled', 'yes') === 'yes';
                $this->smsApiKey = $this->get_option('sms_api_key');
                $this->smsSecretKey = $this->get_option('sms_secret_key');
                $this->smsSender = $this->get_option('sms_sender');
                $this->smsAdminMobile = $this->get_option('sms_admin_mobile');
                $this->order_button_text = __('پرداخت با جیبیت', 'jibit-woocommerce-payment-gateway');
                $this->jibit = new JibitHelperClass($this->apiKey, $this->secretKey);
                $this->jibitSms = new JibitSmsHelperClass($this->smsApiKey, $this->smsSecretKey);

                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                add_action('woocommerce_receipt_' . $this->id, array($this, 'send_to_jibit_gateway'));
                add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'return_from_jibit_gateway'));
                add_action('woocommerce_email_after_order_table', array($this, 'email_instructions'), 10, 3);
                add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
                add_action('admin_notices', array($this, 'admin_notice_missing_credentials'));
                add_action('admin_notices', array($this, 'admin_notice_missing_sms_credentials'));
                add_filter('allowed_redirect_hosts', array($this, 'allow_jibit_redirect_hosts'));

                add_action('woocommerce_cart_calculate_fees', array($this, 'add_jibit_fee_to_cart'));
                add_action('woocommerce_checkout_create_order', array($this, 'checkout_create_order_fee'), 10, 2);
                add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'blocks_add_fee'), 10, 2);
                add_action('woocommerce_store_api_register_endpoint_data', array($this, 'register_store_api_data'));

                add_filter('woocommerce_order_actions', array($this, 'add_check_status_order_action'));
                add_action('woocommerce_order_action_jibit_check_status', array($this, 'handle_check_status_order_action'));
            }

            public function allow_jibit_redirect_hosts($hosts) {
                $hosts[] = 'napi.jibit.ir';
                $hosts[] = 'pay.jibit.ir';
                $hosts[] = 'jibit.ir';
                return $hosts;
            }

            public function init_form_fields() {
                $form_fields = array(
                    'base_config' => array(
                        'title' => __('تنظیمات پایه ای', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'title',
                        'description' => '',
                    ),
                    'enabled' => array(
                        'title' => __('فعالسازی/غیرفعالسازی', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'checkbox',
                        'label' => __('فعالسازی درگاه جیبیت', 'jibit-woocommerce-payment-gateway'),
                        'description' => __('برای فعالسازی درگاه پرداخت جیبیت باید چک باکس را تیک بزنید', 'jibit-woocommerce-payment-gateway'),
                        'default' => 'yes',
                        'desc_tip' => true,
                    ),
                    'account_config' => array(
                        'title' => __('تنظیمات حساب جیبیت', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'title',
                        'description' => sprintf(
                            /* translators: %s: link to Jibit dashboard */
                            __('کلید API و کلید مخفی را از پنل کاربری جیبیت به آدرس %s دریافت نمایید.', 'jibit-woocommerce-payment-gateway'),
                            '<a href="https://dashboard.jibit.ir" target="_blank" rel="noopener noreferrer">dashboard.jibit.ir</a>'
                        ),
                    ),
                    'api_key' => array(
                        'title' => __('API Key', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'text',
                        'description' => __('کلید API دریافتی از پنل جیبیت', 'jibit-woocommerce-payment-gateway'),
                        'default' => '',
                        'desc_tip' => true,
                    ),
                    'secret_key' => array(
                        'title' => __('Secret Key', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'password',
                        'description' => __('کلید مخفی دریافتی از پنل جیبیت', 'jibit-woocommerce-payment-gateway'),
                        'default' => '',
                        'desc_tip' => true,
                    ),
                    'payment_config' => array(
                        'title' => __('تنظیمات عملیات پرداخت', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'title',
                        'description' => '',
                    ),
                    'fee_payer' => array(
                        'title' => __('کسر کارمزد از', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'select',
                        'description' => __('انتخاب کنید که کارمزد تراکنش از پذیرنده کسر شود یا به خریدار اضافه شود. اگر کسر کارمزد از خریدار انتخاب شود، در صفحه چک‌اوت ردیفی (با عنوان قابل تنظیم در فیلد زیر) به مبلغ سفارش اضافه خواهد شد و همان مبلغ به عنوان wage به جیبیت ارسال می‌شود.', 'jibit-woocommerce-payment-gateway'),
                        'default' => 'merchant',
                        'desc_tip' => true,
                        'options' => array(
                            'merchant' => __('پذیرنده (پیش‌فرض)', 'jibit-woocommerce-payment-gateway'),
                            'customer' => __('خریدار', 'jibit-woocommerce-payment-gateway'),
                        ),
                    ),
                    'fee_title' => array(
                        'title' => __('عنوان ردیف کارمزد', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'text',
                        'description' => __('عنوانی که به خریدار نشان داده می‌شود بابت کسر هزینه کارمزد تراکنش (فقط زمانی که «کسر کارمزد از خریدار» انتخاب شده باشد نمایش داده می‌شود).', 'jibit-woocommerce-payment-gateway'),
                        'default' => __('کارمزد خدمات', 'jibit-woocommerce-payment-gateway'),
                        'desc_tip' => true,
                    ),
                    'success_message' => array(
                        'title' => __('پیام پرداخت موفق', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'textarea',
                        'description' => __('متن پیامی که می‌خواهید بعد از پرداخت موفق به کاربر نمایش دهید را وارد نمایید. می‌توانید از شورت کد {transaction_id} برای نمایش کد رهگیری استفاده کنید.', 'jibit-woocommerce-payment-gateway'),
                        'default' => __('با تشکر از شما. سفارش شما با موفقیت پرداخت شد.', 'jibit-woocommerce-payment-gateway'),
                    ),
                    'failed_message' => array(
                        'title' => __('پیام پرداخت ناموفق', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'textarea',
                        'description' => __('متن پیامی که می‌خواهید بعد از پرداخت ناموفق به کاربر نمایش دهید را وارد نمایید. می‌توانید از شورت کد {fault} برای نمایش دلیل خطای رخ داده استفاده کنید.', 'jibit-woocommerce-payment-gateway'),
                        'default' => __('پرداخت شما ناموفق بوده است. لطفاً مجدداً تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید.', 'jibit-woocommerce-payment-gateway'),
                    ),
                    'instructions' => array(
                        'title' => __('توضیحات پس از خرید', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'textarea',
                        'description' => __('دستورالعمل‌هایی که پس از تکمیل پرداخت به مشتری نمایش داده می‌شود.', 'jibit-woocommerce-payment-gateway'),
                        'default' => '',
                        'desc_tip' => true,
                    ),
                    'sms_config' => array(
                        'title' => __('اطلاع‌رسانی پیامکی (جیبیت پالس)', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'title',
                        'description' => __('پس از تکمیل موفق هر پرداخت، پیامک اطلاع‌رسانی به خریدار و/یا مدیر فروشگاه ارسال می‌شود. این سرویس مجزا از درگاه پرداخت است و کلید API و کلید مخفی جداگانه‌ای دارد که از پنل کاربری جیبیت (بخش Pulse) دریافت می‌کنید.', 'jibit-woocommerce-payment-gateway'),
                    ),
                    'sms_enabled' => array(
                        'title' => __('فعالسازی/غیرفعالسازی', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'checkbox',
                        'label' => __('فعالسازی اطلاع‌رسانی پیامکی پس از پرداخت موفق', 'jibit-woocommerce-payment-gateway'),
                        'default' => 'no',
                        'desc_tip' => true,
                        'description' => __('برای فعالسازی ارسال پیامک باید چک باکس را تیک بزنید.', 'jibit-woocommerce-payment-gateway'),
                    ),
                    'sms_api_key' => array(
                        'title' => __('API Key پیامک (Pulse)', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'text',
                        'description' => __('کلید API سرویس پیامک جیبیت پالس (مجزا از کلید API درگاه پرداخت).', 'jibit-woocommerce-payment-gateway'),
                        'default' => '',
                        'desc_tip' => true,
                    ),
                    'sms_secret_key' => array(
                        'title' => __('Secret Key پیامک (Pulse)', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'password',
                        'description' => __('کلید مخفی سرویس پیامک جیبیت پالس (مجزا از کلید مخفی درگاه پرداخت).', 'jibit-woocommerce-payment-gateway'),
                        'default' => '',
                        'desc_tip' => true,
                    ),
                    'sms_sender' => array(
                        'title' => __('شماره/خط ارسال‌کننده', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'text',
                        'description' => __('شماره یا خط پیامکی که در پنل جیبیت پالس برای ارسال تعریف کرده‌اید.', 'jibit-woocommerce-payment-gateway'),
                        'default' => '',
                        'desc_tip' => true,
                    ),
                    'sms_buyer_enabled' => array(
                        'title' => __('ارسال پیامک به خریدار', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'checkbox',
                        'label' => __('پس از پرداخت موفق، پیامک تاییدیه به شماره موبایل خریدار (ثبت‌شده در سفارش) نیز ارسال شود', 'jibit-woocommerce-payment-gateway'),
                        'default' => 'yes',
                        'desc_tip' => true,
                        'description' => __('در صورت خاموش بودن این گزینه، فقط مدیر فروشگاه پیامک دریافت می‌کند (در صورت تنظیم بودن شماره موبایل مدیر).', 'jibit-woocommerce-payment-gateway'),
                    ),
                    'sms_admin_mobile' => array(
                        'title' => __('شماره موبایل مدیر فروشگاه', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'text',
                        'description' => __('برای ارسال به چند شماره، آن‌ها را با کاما یا فاصله از هم جدا کنید. در صورت خالی بودن، پیامکی به مدیر ارسال نمی‌شود.', 'jibit-woocommerce-payment-gateway'),
                        'default' => '',
                        'desc_tip' => true,
                    ),
                );
                $form_fields = apply_filters('jibit_config', $form_fields);
                $this->form_fields = $form_fields;
            }

            public function admin_options() {
                echo '<h3>' . esc_html__('درگاه پرداخت جیبیت', 'jibit-woocommerce-payment-gateway') . '</h3>';
                echo '<p>' . esc_html__('تنظیمات درگاه پرداخت جیبیت برای ووکامرس', 'jibit-woocommerce-payment-gateway') . '</p>';
                echo '<table class="form-table">';
                $this->generate_settings_html();
                echo '</table>';
            }

            public function get_icon() {
                $icon = '<img src="' . esc_url(JIBIT_WC_PLUGIN_URL . 'assets/images/logo.png') . '" alt="جیبیت" style="max-height:24px;" />';
                return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
            }

            public function payment_fields() {
                if ($this->description) {
                    echo wp_kses_post(wpautop(wptexturize($this->description)));
                }
            }

            public function process_payment($order_id) {
                $order = wc_get_order($order_id);
                return array(
                    'result' => 'success',
                    'redirect' => $order->get_checkout_payment_url(true),
                );
            }

            /**
             * Converts the order total (in the store's active currency) to Rials, the only
             * currency the Jibit PPG API accepts.
             */
            private function get_amount_in_rial($order) {
                $amount = intval($order->get_total());
                $currency = strtolower($order->get_currency());
                if ($currency === 'irt') {
                    $amount *= 10;
                } elseif ($currency === 'irht') {
                    $amount *= 10000;
                } elseif ($currency === 'irhr') {
                    $amount *= 1000;
                }
                return $amount;
            }

            private function convert_rial_to_currency($amount_rial, $currency) {
                $currency = strtolower($currency);
                $amount = $amount_rial;
                if ($currency === 'irt') {
                    $amount /= 10;
                } elseif ($currency === 'irht') {
                    $amount /= 10000;
                } elseif ($currency === 'irhr') {
                    $amount /= 1000;
                }
                return $amount;
            }

            private function fee_row_name() {
                return !empty($this->feeTitle) ? $this->feeTitle : __('کارمزد خدمات', 'jibit-woocommerce-payment-gateway');
            }

            /**
             * Asks Jibit how much the Jibit+Shaparak fee is for a given Rial amount. Returns 0
             * (instead of throwing) on any API failure so a quoting hiccup never blocks checkout.
             */
            private function quote_service_fee_rial($amount_rial) {
                if ($amount_rial < 5000 || empty($this->apiKey) || empty($this->secretKey)) {
                    return 0;
                }
                try {
                    $quote = $this->jibit->quotePurchaseFee($amount_rial);
                    return isset($quote['totalFee']) ? intval($quote['totalFee']) : 0;
                } catch (Exception $e) {
                    return 0;
                }
            }

            private function is_jibit_selected_for_cart() {
                if (WC()->session && WC()->session->get('chosen_payment_method') === $this->id) {
                    return true;
                }
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only informational check (which gateway is selected) used to decide whether to display the service-fee line; WooCommerce's own checkout nonce governs the actual order submission.
                if (isset($_POST['payment_method']) && sanitize_text_field(wp_unslash($_POST['payment_method'])) === $this->id) {
                    return true;
                }
                if (defined('WC_DOING_AJAX') && WC_DOING_AJAX) {
                    $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
                    if (strpos($request_uri, '/wc/store/') !== false) {
                        $input = file_get_contents('php://input');
                        if ($input) {
                            $body = json_decode($input, true);
                            if (isset($body['payment_method']) && $body['payment_method'] === $this->id) {
                                return true;
                            }
                        }
                    }
                }
                return false;
            }

            private function remove_jibit_fees($cart) {
                $fees = $cart->get_fees();
                $fee_removed = false;
                foreach ($fees as $fee_key => $fee) {
                    if ($fee->name === $this->fee_row_name()) {
                        unset($cart->fees[$fee_key]);
                        $fee_removed = true;
                    }
                }
                if ($fee_removed) {
                    $cart->fees = array_values($cart->fees);
                }
            }

            /**
             * Live preview on the cart/checkout pages (classic checkout, and Blocks' Cart/Checkout
             * totals, which both funnel through WC_Cart::calculate_fees()).
             */
            public function add_jibit_fee_to_cart($cart) {
                if (is_admin() && !defined('DOING_AJAX')) {
                    return;
                }

                if ($this->feePayer !== 'customer' || !$this->is_jibit_selected_for_cart()) {
                    $this->remove_jibit_fees($cart);
                    return;
                }

                foreach ($cart->get_fees() as $fee) {
                    if ($fee->name === $this->fee_row_name()) {
                        return;
                    }
                }

                $cart_total = $cart->get_subtotal() + $cart->get_subtotal_tax() + $cart->get_shipping_total() + $cart->get_shipping_tax();
                foreach ($cart->get_fees() as $fee) {
                    $cart_total += $fee->total;
                }
                $cart_total -= $cart->get_discount_total();

                $currency = get_woocommerce_currency();
                $amount_rial = $this->convert_amount_to_rial(intval($cart_total), $currency);
                $fee_rial = $this->quote_service_fee_rial($amount_rial);
                if ($fee_rial <= 0) {
                    return;
                }

                $fee_amount = $this->convert_rial_to_currency($fee_rial, $currency);
                $decimals = wc_get_price_decimals();
                if ($fee_amount < 1000) {
                    $decimals = max($decimals, 3);
                }
                $fee_amount = round($fee_amount, $decimals);
                if ($fee_amount > 0) {
                    $cart->add_fee($this->fee_row_name(), $fee_amount, false);
                }
            }

            private function convert_amount_to_rial($amount, $currency) {
                $currency = strtolower($currency);
                if ($currency === 'irt') {
                    $amount *= 10;
                } elseif ($currency === 'irht') {
                    $amount *= 10000;
                } elseif ($currency === 'irhr') {
                    $amount *= 1000;
                }
                return $amount;
            }

            /**
             * Jibit's API only documents/accepts the local 09XXXXXXXXX mobile format — it rejects
             * +98/0098/bare-98 country-code prefixes with payerMobileNumber.is_invalid. WooCommerce
             * itself doesn't enforce a phone format at checkout, so customers commonly enter
             * +989121111111; normalize that (and the 0098 / bare 98 / missing-leading-zero variants)
             * down to 09121111111 before sending it to Jibit.
             */
            private function normalize_mobile_number($phone) {
                $digits = preg_replace('/\D/', '', (string) $phone);
                if ($digits === '') {
                    return '';
                }
                if (strpos($digits, '0098') === 0) {
                    $digits = substr($digits, 4);
                } elseif (strpos($digits, '98') === 0 && strlen($digits) === 12) {
                    $digits = substr($digits, 2);
                }
                if (strlen($digits) === 10 && strpos($digits, '9') === 0) {
                    $digits = '0' . $digits;
                }
                return $digits;
            }

            private function normalize_mobile_list($value) {
                $parts = preg_split('/[\s,]+/u', trim((string) $value)) ?: array();
                $mobiles = array();
                foreach ($parts as $part) {
                    $mobile = $this->normalize_mobile_number($part);
                    if ($mobile !== '' && preg_match('/^09\d{9}$/', $mobile) === 1) {
                        $mobiles[] = $mobile;
                    }
                }
                return array_values(array_unique($mobiles));
            }

            /**
             * Sends a "your payment succeeded" SMS to the buyer and/or store admin via Jibit Pulse.
             * Guarded by an order-meta flag so a reconciliation retry or a duplicate callback hit
             * never sends the notification twice for the same order. SMS failures are swallowed —
             * a notification hiccup must never affect the order's payment status.
             */
            private function maybe_send_sms_notifications($order, $purchase_id) {
                if (!$this->smsEnabled || $order->get_meta('_jibit_sms_sent')) {
                    return;
                }

                $buyer_mobile = $this->smsBuyerEnabled ? $this->normalize_mobile_number($order->get_billing_phone()) : '';
                $admin_mobiles = $this->normalize_mobile_list($this->smsAdminMobile);
                if ($buyer_mobile === '' && empty($admin_mobiles)) {
                    return;
                }
                if (empty($this->smsApiKey) || empty($this->smsSecretKey) || empty($this->smsSender)) {
                    return;
                }

                // Mark as sent before attempting delivery: if the SMS API itself throws mid-request
                // (timeout, etc), we'd rather risk a missed SMS than risk sending it twice on retry.
                $order->update_meta_data('_jibit_sms_sent', 1);
                $order->save();

                $total = number_format_i18n($order->get_total()) . ' ' . __('ریال', 'jibit-woocommerce-payment-gateway');
                $jalali_datetime = $this->jalali_datetime_now();

                if ($buyer_mobile !== '') {
                    $buyer_message = sprintf(
                        "%s\n\n%s\n%s\n\n%s: %s",
                        get_bloginfo('name'),
                        // translators: 1: order number, 2: formatted order total.
                        sprintf(__('پرداخت شما برای سفارش #%1$s به مبلغ %2$s با موفقیت انجام شد.', 'jibit-woocommerce-payment-gateway'), $order->get_order_number(), $total),
                        __('با تشکر از خرید شما.', 'jibit-woocommerce-payment-gateway'),
                        __('تاریخ و ساعت', 'jibit-woocommerce-payment-gateway'),
                        $jalali_datetime
                    );
                    $this->send_single_sms($buyer_mobile, $buyer_message, 'buyer-' . $order->get_id());
                }
                if (!empty($admin_mobiles)) {
                    $admin_message = sprintf(
                        "جیبیت\nپرداخت سفارش موفق فروشگاه %s\nمبلغ: %s\nشماره تراکنش: %s\nشماره سفارش: %s\nتاریخ و ساعت: %s",
                        get_bloginfo('name'),
                        $total,
                        $purchase_id,
                        $order->get_order_number(),
                        $jalali_datetime
                    );
                    $this->send_single_sms(implode(',', $admin_mobiles), $admin_message, 'admin-' . $order->get_id());
                }
            }

            /**
             * Converts "now" (in the site's local timezone) to a Jalali (Persian) date/time string,
             * since the admin SMS is expected in the calendar Iranian merchants actually use.
             */
            private function jalali_datetime_now() {
                $now = current_datetime();
                list($jy, $jm, $jd) = $this->gregorian_to_jalali((int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'));
                return sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, $now->format('H:i'));
            }

            private function gregorian_to_jalali($gy, $gm, $gd) {
                $g_days_in_month = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
                $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
                $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_days_in_month[$gm - 1];
                $jy = -1595 + (33 * intdiv($days, 12053));
                $days %= 12053;
                $jy += 4 * intdiv($days, 1461);
                $days %= 1461;
                if ($days > 365) {
                    $jy += intdiv($days - 1, 365);
                    $days = ($days - 1) % 365;
                }
                if ($days < 186) {
                    $jm = 1 + intdiv($days, 31);
                    $jd = 1 + ($days % 31);
                } else {
                    $jm = 7 + intdiv($days - 186, 30);
                    $jd = 1 + (($days - 186) % 30);
                }
                return array($jy, $jm, $jd);
            }

            private function send_single_sms($receptor, $message, $client_message_id) {
                try {
                    $this->jibitSms->sendSms($receptor, $message, $this->smsSender, $client_message_id);
                } catch (Exception $e) {
                    // Swallow — SMS is a best-effort courtesy notification, not part of the payment flow.
                }
            }

            /**
             * Safety net for classic checkout: guarantees the fee lands on the order even if it
             * wasn't (re)computed during the cart's last calculate_fees() pass.
             */
            public function checkout_create_order_fee($order, $data) {
                if ($this->feePayer !== 'customer' || !isset($data['payment_method']) || $data['payment_method'] !== $this->id) {
                    return;
                }
                $this->add_fee_to_order($order);
            }

            /**
             * Guarantees the fee lands on the order for Cart & Checkout Blocks, which build the
             * order via the Store API rather than the classic woocommerce_checkout_create_order flow.
             */
            public function blocks_add_fee($order, $request) {
                $payment_method = isset($request['payment_method']) ? $request['payment_method'] : '';
                if ($this->feePayer !== 'customer' || $payment_method !== $this->id) {
                    return;
                }
                $this->add_fee_to_order($order);
            }

            private function add_fee_to_order($order) {
                foreach ($order->get_fees() as $existing_fee) {
                    if ($existing_fee->get_name() === $this->fee_row_name()) {
                        return;
                    }
                }

                $order_total = $order->get_subtotal() + $order->get_total_tax() + $order->get_shipping_total() + $order->get_shipping_tax();
                foreach ($order->get_fees() as $fee) {
                    $order_total += $fee->get_total();
                }
                $order_total -= $order->get_discount_total();

                $currency = $order->get_currency();
                $amount_rial = $this->convert_amount_to_rial(intval($order_total), $currency);
                $fee_rial = $this->quote_service_fee_rial($amount_rial);
                if ($fee_rial <= 0) {
                    return;
                }

                $fee_amount = $this->convert_rial_to_currency($fee_rial, $currency);
                $decimals = wc_get_price_decimals();
                if ($fee_amount < 1000) {
                    $decimals = max($decimals, 3);
                }
                $fee_amount = round($fee_amount, $decimals);
                if ($fee_amount <= 0) {
                    return;
                }

                $fee = new WC_Order_Item_Fee();
                $fee->set_name($this->fee_row_name());
                $fee->set_amount($fee_amount);
                $fee->set_total($fee_amount);
                $fee->set_tax_status('none');
                $order->add_item($fee);
                $order->calculate_totals();

                $order->update_meta_data('_jibit_wage_rial', $fee_rial);
            }

            public function register_store_api_data() {
                if (function_exists('woocommerce_store_api_register_endpoint_data')) {
                    woocommerce_store_api_register_endpoint_data(array(
                        'endpoint' => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
                        'namespace' => 'jibit',
                        'data_callback' => array($this, 'store_api_data_callback'),
                        'schema_callback' => array($this, 'store_api_schema_callback'),
                    ));
                }
            }

            public function store_api_data_callback() {
                return array(
                    'fee_payer' => $this->feePayer,
                    'gateway_id' => $this->id,
                );
            }

            public function store_api_schema_callback() {
                return array(
                    'fee_payer' => array(
                        'description' => __('Who pays the Jibit service fee', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'string',
                        'readonly' => true,
                    ),
                    'gateway_id' => array(
                        'description' => __('Gateway ID', 'jibit-woocommerce-payment-gateway'),
                        'type' => 'string',
                        'readonly' => true,
                    ),
                );
            }

            public function send_to_jibit_gateway($order_id) {
                $order = wc_get_order($order_id);
                $total_amount_rial = $this->get_amount_in_rial($order);

                $wage_rial = 0;
                if ($this->feePayer === 'customer') {
                    $wage_rial = intval($order->get_meta('_jibit_wage_rial'));
                }
                $amount = max(0, $total_amount_rial - $wage_rial);

                $callback_url = add_query_arg('wc_order', $order_id, WC()->api_request_url('WC_Jibit'));
                $mobile = $this->normalize_mobile_number($order->get_billing_phone());

                // clientReferenceNumber is what Jibit's own dashboard shows (and searches by) as the
                // transaction's reference number. The first attempt uses the bare WooCommerce order
                // number so an exact search for e.g. "135" finds it directly. Jibit rejects duplicate
                // reference numbers, though, so if the customer retries a failed payment on the same
                // order we have to disambiguate — only then does a "-2", "-3", ... suffix get added.
                $reference_history = $order->get_meta('_jibit_reference_history');
                $attempt_number = (is_array($reference_history) ? count($reference_history) : 0) + 1;
                $order_number = (string) $order->get_order_number();
                $client_reference_number = $attempt_number > 1 ? ($order_number . '-' . $attempt_number) : $order_number;

                // Description carries the buyer's name and nothing else — no prefixes, labels, or
                // other characters — per explicit merchant request.
                $description = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

                try {
                    $purchase = $this->jibit->requestPayment(
                        $amount,
                        $callback_url,
                        $client_reference_number,
                        $description,
                        $mobile,
                        '',
                        $wage_rial
                    );

                    $order->update_meta_data('_jibit_purchase_id', $purchase['purchaseId']);
                    $order->update_meta_data('_jibit_client_reference_number', $client_reference_number);

                    if (!is_array($reference_history)) {
                        $reference_history = array();
                    }
                    $reference_history[] = $client_reference_number;
                    $order->update_meta_data('_jibit_reference_history', $reference_history);

                    $order->save();
                    // translators: %s: Jibit purchase id.
                    $order->add_order_note(sprintf(__('کاربر به درگاه پرداخت جیبیت هدایت شد. شناسه تراکنش: %s', 'jibit-woocommerce-payment-gateway'), $purchase['purchaseId']));
                    wp_safe_redirect($purchase['pspSwitchingUrl']);
                    exit;
                } catch (Exception $e) {
                    wc_add_notice(__('خطا در اتصال به درگاه پرداخت: ', 'jibit-woocommerce-payment-gateway') . $e->getMessage(), 'error');
                    wp_safe_redirect(wc_get_checkout_url());
                    exit;
                }
            }

            public function return_from_jibit_gateway() {
                // phpcs:disable WordPress.Security.NonceVerification.Recommended -- This is a server-to-server style redirect callback from Jibit's payment page, not a same-origin form submission, so there is no WP nonce to check. Authenticity is instead verified below by matching the returned purchaseId/clientReferenceNumber against the ones stored on the order, and by re-verifying the purchase against Jibit's own API rather than trusting the callback status alone.
                $order_id = isset($_GET['wc_order']) ? absint(wp_unslash($_GET['wc_order'])) : 0;
                $order = wc_get_order($order_id);

                if (!$order) {
                    wc_add_notice(__('سفارش پیدا نشد.', 'jibit-woocommerce-payment-gateway'), 'error');
                    wp_safe_redirect(wc_get_checkout_url());
                    exit;
                }

                if ($order->is_paid()) {
                    wp_safe_redirect($this->get_return_url($order));
                    exit;
                }

                $purchase_id = isset($_GET['purchaseId']) ? sanitize_text_field(wp_unslash($_GET['purchaseId'])) : '';
                if (empty($purchase_id) && isset($_POST['purchaseId'])) {
                    $purchase_id = sanitize_text_field(wp_unslash($_POST['purchaseId']));
                }
                $client_reference_number = isset($_GET['clientReferenceNumber']) ? sanitize_text_field(wp_unslash($_GET['clientReferenceNumber'])) : '';
                if (empty($client_reference_number) && isset($_POST['clientReferenceNumber'])) {
                    $client_reference_number = sanitize_text_field(wp_unslash($_POST['clientReferenceNumber']));
                }

                $stored_purchase_id = $order->get_meta('_jibit_purchase_id');
                $reference_history = $order->get_meta('_jibit_reference_history');

                $is_valid = !empty($stored_purchase_id) && $purchase_id === $stored_purchase_id;
                if (!$is_valid && is_array($reference_history) && in_array($client_reference_number, $reference_history, true)) {
                    $is_valid = true;
                }

                if (!$is_valid || empty($purchase_id)) {
                    $order->add_order_note(__('تلاش برای بازگشت از درگاه با شناسه تراکنش نامعتبر.', 'jibit-woocommerce-payment-gateway'));
                    wc_add_notice(__('اطلاعات پرداخت نامعتبر است. لطفاً مجدداً تلاش کنید.', 'jibit-woocommerce-payment-gateway'), 'error');
                    wp_safe_redirect(wc_get_checkout_url());
                    exit;
                }

                try {
                    $result = $this->jibit->verifyPayment($purchase_id);
                    if ($result['status'] === 'SUCCESSFUL' || $result['status'] === 'ALREADY_VERIFIED') {
                        $order->payment_complete($purchase_id);
                        // translators: %s: Jibit purchase tracking id.
                        $order->add_order_note(sprintf(__('پرداخت با موفقیت انجام شد. شناسه تراکنش: %s', 'jibit-woocommerce-payment-gateway'), $purchase_id));
                        $this->maybe_send_sms_notifications($order, $purchase_id);
                        wc_add_notice(str_replace('{transaction_id}', $purchase_id, $this->successMessage), 'success');
                        if (WC()->cart) {
                            WC()->cart->empty_cart();
                        }
                        wp_safe_redirect($this->get_return_url($order));
                        exit;
                    }
                    throw new Exception(__('تراکنش ناموفق بود.', 'jibit-woocommerce-payment-gateway'));
                } catch (Exception $e) {
                    $order->add_order_note(__('خطا در تایید پرداخت: ', 'jibit-woocommerce-payment-gateway') . $e->getMessage());
                    wc_add_notice(str_replace('{fault}', $e->getMessage(), $this->failedMessage), 'error');
                    wp_safe_redirect(wc_get_checkout_url());
                    exit;
                }
                // phpcs:enable WordPress.Security.NonceVerification.Recommended
            }

            public function add_check_status_order_action($actions) {
                global $theorder;
                if ($theorder && $theorder->get_payment_method() === $this->id && !$theorder->is_paid() && $theorder->get_meta('_jibit_purchase_id')) {
                    $actions['jibit_check_status'] = __('بررسی وضعیت پرداخت در جیبیت', 'jibit-woocommerce-payment-gateway');
                }
                return $actions;
            }

            public function handle_check_status_order_action($order) {
                $this->reconcile_single_order($order);
            }

            /**
             * Periodic reconciliation: catches orders where the customer actually paid on Jibit's
             * side but the browser never made it back to return_from_jibit_gateway() (closed tab,
             * dropped connection, etc), so they'd otherwise be stuck at "Pending payment" forever.
             */
            public function reconcile_pending_orders() {
                if (empty($this->apiKey) || empty($this->secretKey)) {
                    return;
                }
                $after = time() - 2 * DAY_IN_SECONDS;
                $before = time() - 5 * MINUTE_IN_SECONDS;
                $orders = wc_get_orders(array(
                    'status' => array('pending', 'on-hold'),
                    'payment_method' => $this->id,
                    'date_created' => $after . '...' . $before,
                    'limit' => 20,
                    'return' => 'objects',
                ));
                foreach ($orders as $order) {
                    $this->reconcile_single_order($order);
                }
            }

            public function reconcile_single_order($order) {
                if (!$order || $order->is_paid()) {
                    return;
                }
                $purchase_id = $order->get_meta('_jibit_purchase_id');
                if (empty($purchase_id)) {
                    return;
                }
                try {
                    $result = $this->jibit->filterPurchases(array('purchaseId' => $purchase_id));
                    $elements = isset($result['elements']) ? $result['elements'] : array();
                    if (empty($elements)) {
                        return;
                    }
                    $state = isset($elements[0]['state']) ? $elements[0]['state'] : '';
                    if (!in_array($state, array('SUCCESS', 'MANUALLY_SUCCESS'), true)) {
                        return;
                    }

                    $verify = $this->jibit->verifyPayment($purchase_id);
                    if (!isset($verify['status']) || !in_array($verify['status'], array('SUCCESSFUL', 'ALREADY_VERIFIED'), true)) {
                        return;
                    }

                    // Guard against a race with the customer's own return_from_jibit_gateway() request
                    // landing at nearly the same moment (both this cron tick and the real callback can
                    // reach here concurrently): re-fetch the order fresh from the DB, bypassing whatever
                    // possibly-stale copy triggered this check, and re-verify it's still unpaid.
                    $fresh_order = wc_get_order($order->get_id());
                    if (!$fresh_order || $fresh_order->is_paid()) {
                        return;
                    }
                    $lock_key = 'jibit_reconcile_lock_' . $order->get_id();
                    if (get_transient($lock_key)) {
                        return;
                    }
                    set_transient($lock_key, 1, 30);

                    $fresh_order->payment_complete($purchase_id);
                    // translators: %s: Jibit purchase tracking id.
                    $fresh_order->add_order_note(sprintf(__('پرداخت به‌صورت خودکار از طریق بازآشتی دوره‌ای (Reconciliation) تایید و تکمیل شد. شناسه تراکنش: %s', 'jibit-woocommerce-payment-gateway'), $purchase_id));
                    $this->maybe_send_sms_notifications($fresh_order, $purchase_id);
                } catch (Exception $e) {
                    // Silent — the next reconciliation run (or manual order action) retries; a transient
                    // API hiccup shouldn't spam the order with failure notes.
                }
            }

            public function email_instructions($order, $sent_to_admin, $plain_text = false) {
                if ($order->get_payment_method() !== $this->id || $sent_to_admin) {
                    return;
                }
                if ($this->instructions) {
                    echo wp_kses_post(wpautop(wptexturize($this->instructions))) . "\n";
                }
            }

            public function thankyou_page() {
                if ($this->instructions) {
                    echo wp_kses_post(wpautop(wptexturize($this->instructions)));
                }
            }

            public function admin_notice_missing_credentials() {
                if (empty($this->apiKey) || empty($this->secretKey)) {
                    if ('yes' === $this->get_option('enabled')) {
                        echo '<div class="notice notice-error is-dismissible">';
                        echo '<p>' . esc_html__('کلید API یا کلید مخفی درگاه جیبیت خالی است. لطفاً آن‌ها را در تنظیمات درگاه وارد نمایید.', 'jibit-woocommerce-payment-gateway') . '</p>';
                        echo '</div>';
                    }
                }
            }

            public function admin_notice_missing_sms_credentials() {
                if (!$this->smsEnabled) {
                    return;
                }
                if (empty($this->smsApiKey) || empty($this->smsSecretKey) || empty($this->smsSender)) {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p>' . esc_html__('اطلاع‌رسانی پیامکی جیبیت فعال است اما کلید API، کلید مخفی یا شماره ارسال‌کننده پیامک خالی است. لطفاً آن‌ها را در تنظیمات درگاه وارد نمایید.', 'jibit-woocommerce-payment-gateway') . '</p>';
                    echo '</div>';
                }
            }
        }
    }
}
add_action('plugins_loaded', 'jibit_load_gateway', 11);

/**
 * Fires the periodic reconciliation job (scheduled from index.php on plugin activation).
 * Resolved at call time rather than bound at hook-registration time, since the gateway
 * instance only exists once WooCommerce has built its payment gateway list.
 */
function jibit_run_reconciliation() {
    if (!function_exists('WC') || !WC()->payment_gateways) {
        return;
    }
    $gateways = WC()->payment_gateways->payment_gateways();
    if (isset($gateways['WC_Jibit'])) {
        $gateways['WC_Jibit']->reconcile_pending_orders();
    }
}
add_action('jibit_reconcile_pending_purchases', 'jibit_run_reconciliation');
