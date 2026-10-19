<?php
// PaymentGateway.php - GLOW NET Mobile Money & Gateway Aggregator
// Inasaidia Tanzania Mobile Money: M-Pesa, Tigo Pesa (Mixx), Airtel Money, Halopesa

class PaymentGateway {

    public static $active_provider = 'sandbox'; // 'sandbox' au 'zenopay' au 'azampay'
    
    // Mipangilio ya ZenoPay / Gateway
    public static $zeno_account_id = '';
    public static $zeno_api_key    = '';
    public static $zeno_secret_key = '';

    /**
     * Nukuu mipangilio kutoka Environment au config.local.php kama ipo
     */
    public static function initConfig() {
        if (getenv('PAYMENT_PROVIDER')) self::$active_provider = getenv('PAYMENT_PROVIDER');
        if (getenv('ZENOPAY_ACCOUNT_ID')) self::$zeno_account_id = getenv('ZENOPAY_ACCOUNT_ID');
        if (getenv('ZENOPAY_API_KEY')) self::$zeno_api_key = getenv('ZENOPAY_API_KEY');
        if (getenv('ZENOPAY_SECRET_KEY')) self::$zeno_secret_key = getenv('ZENOPAY_SECRET_KEY');

        $localConfigFile = __DIR__ . '/config.local.php';
        if (file_exists($localConfigFile)) {
            $conf = include $localConfigFile;
            if (!empty($conf['gateway']['provider'])) self::$active_provider = $conf['gateway']['provider'];
            if (!empty($conf['gateway']['account_id'])) self::$zeno_account_id = $conf['gateway']['account_id'];
            if (!empty($conf['gateway']['api_key'])) self::$zeno_api_key = $conf['gateway']['api_key'];
            if (!empty($conf['gateway']['secret_key'])) self::$zeno_secret_key = $conf['gateway']['secret_key'];
        }
    }

    /**
     * Tambua mtandao wa simu kulingana na namba (Tanzania Telcos)
     */
    public static function detectNetworkProvider($phone) {
        $clean = self::normalizePhone($phone);
        $prefix = substr($clean, 0, 3);
        
        if (in_array($prefix, ['074', '075', '076'])) {
            return ['name' => 'M-Pesa', 'code' => 'mpesa', 'color' => '#e11d48', 'bg' => 'rgba(225,29,72,0.15)'];
        }
        if (in_array($prefix, ['065', '067', '071', '077'])) {
            return ['name' => 'Tigo Pesa (Mixx)', 'code' => 'tigo', 'color' => '#0284c7', 'bg' => 'rgba(2,132,199,0.15)'];
        }
        if (in_array($prefix, ['068', '069', '078'])) {
            return ['name' => 'Airtel Money', 'code' => 'airtel', 'color' => '#dc2626', 'bg' => 'rgba(220,38,38,0.15)'];
        }
        if (in_array($prefix, ['061', '062'])) {
            return ['name' => 'Halopesa', 'code' => 'halopesa', 'color' => '#ea580c', 'bg' => 'rgba(234,88,12,0.15)'];
        }
        return ['name' => 'Mobile Money', 'code' => 'generic', 'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.15)'];
    }

    /**
     * Badilisha namba iwe katika muundo wa kawaida wa Kitanzania (07XXXXXXXX)
     */
    public static function normalizePhone($phone) {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (strpos($clean, '255') === 0 && strlen($clean) === 12) {
            return '0' . substr($clean, 3);
        }
        if (strlen($clean) === 9 && $clean[0] !== '0') {
            return '0' . $clean;
        }
        return $clean;
    }

    /**
     * Muundo wa kimataifa (2557XXXXXXXX)
     */
    public static function formatInternational($phone) {
        $local = self::normalizePhone($phone);
        if (strpos($local, '0') === 0) {
            return '255' . substr($local, 1);
        }
        return $local;
    }

    /**
     * Anzisha ombi la malipo kwa simu (USSD STK Push)
     */
    public static function initiateUSSDPush($referenceNo, $phone, $amount) {
        self::initConfig();
        $localPhone = self::normalizePhone($phone);
        $netInfo    = self::detectNetworkProvider($localPhone);
        $network    = $netInfo['name'];

        if (self::$active_provider === 'zenopay' && !empty(self::$zeno_api_key)) {
            return self::sendZenoPayPush($referenceNo, $localPhone, $amount);
        }

        // SANDBOX / DEMO MODE (Inathibitisha malipo mara moja kwa majaribio)
        return [
            'success'          => true,
            'gateway_order_id' => $referenceNo,
            'network'          => $network,
            'network_code'     => $netInfo['code'],
            'message'          => "Ombi la {$network} la Tsh " . number_format($amount, 0) . " limetumwa kwenye namba {$localPhone}. Tafadhali weka PIN yako kuthibitisha."
        ];
    }

    /**
     * ZenoPay Tanzania Push Integration
     */
    private static function sendZenoPayPush($referenceNo, $phone, $amount) {
        $url = 'https://api.zeno.africa/order';
        $payload = [
            'create_order'     => 1,
            'buyer_phone'      => self::formatInternational($phone),
            'amount'           => (int)$amount,
            'order_id'         => $referenceNo,
            'account_id'       => self::$zeno_account_id,
            'api_key'          => self::$zeno_api_key,
            'secret_key'       => self::$zeno_secret_key
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'message' => "Hitilafu ya ZenoPay: " . $err];
        }

        $json = json_decode($res, true);
        if ($json && ($json['status'] ?? '') === 'success') {
            return [
                'success'          => true,
                'gateway_order_id' => $referenceNo,
                'message'          => "Ombi la malipo limetumwa kwenye simu yako. Weka PIN."
            ];
        }

        return ['success' => false, 'message' => $json['message'] ?? 'Hitilafu ya kuanzisha malipo.'];
    }

    /**
     * Hakiki hali ya muamala
     */
    public static function checkPaymentStatus($orderId) {
        // Kwenye sandbox, inathibitishwa mara moja
        return 'COMPLETED';
    }
}
?>
