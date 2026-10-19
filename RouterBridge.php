<?php
// RouterBridge.php - GLOW NET MikroTik RouterOS API & Hardware Gateway
// Inasaidia MikroTik RouterOS v6 & v7 API (Port 8728/8729), REST API (v7), na Generic Captive Portal

class RouterBridge {

    /**
     * Jaribu kuunganisha na router kuona kama ipo hewani (Ping / TCP Socket Test)
     */
    public static function pingRouter($ip, $port = 8728, $timeout = 1.5) {
        if (empty($ip) || $ip === '127.0.0.1' || $ip === 'localhost') {
            return true;
        }
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($ip, (int)$port, $errno, $errstr, $timeout);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    /**
     * Sajili au ruhusu mteja kwenye MikroTik Hotspot (Active Session / IP Binding)
     */
    public static function authorizeClient($station, $mac, $ip, $durationMinutes, $speedLimit = '5M/5M', $usernameRef = '') {
        $hardwareType = $station['hardware_type'] ?? 'mikrotik';
        $routerIp     = $station['router_ip'] ?? '192.168.88.1';
        $routerPort   = (int)($station['router_port'] ?? 8728);
        $routerUser   = $station['router_user'] ?? 'admin';
        $routerPass   = $station['router_pass'] ?? '';

        // Kama ni Generic AP / Controller (k.m. UniFi / Omada / OpenWRT)
        if ($hardwareType === 'generic_ap') {
            return self::authorizeGenericAp($station, $mac, $ip, $durationMinutes);
        }

        // Jaribu kuwasiliana na MikroTik kupitia Socket API
        $isOnline = self::pingRouter($routerIp, $routerPort, 1.0);
        if (!$isOnline) {
            // Router ipo offline au ni eneo la majaribio (Localhost) - ruhusu kupitia mfumo wa ndani (Fallback Graceful)
            error_log("[RouterBridge] Router {$routerIp}:{$routerPort} haipatikani kwa sasa. Imeruhusiwa kwenye mfumo wa GLOW NET.");
            return true;
        }

        try {
            $api = new RouterOSApi();
            if ($api->connect($routerIp, $routerUser, $routerPass, $routerPort, 2)) {
                
                // 1. Ongeza Hotspot User au Badilisha Profile yenye rate-limit
                $userCheck = $api->comm('/ip/hotspot/user/print', [
                    '?name' => $usernameRef ?: $mac
                ]);

                if (empty($userCheck)) {
                    $api->comm('/ip/hotspot/user/add', [
                        'name'         => $usernameRef ?: $mac,
                        'password'     => $usernameRef ?: 'glownet123',
                        'mac-address'  => $mac,
                        'limit-uptime' => ($durationMinutes * 60) . 's',
                        'rate-limit'   => $speedLimit,
                        'comment'      => "GLOW_NET: {$station['station_code']}"
                    ]);
                }

                // 2. Unganisha kwenye Active Sessions
                $api->comm('/ip/hotspot/active/login', [
                    'user'        => $usernameRef ?: $mac,
                    'password'    => $usernameRef ?: 'glownet123',
                    'ip'          => $ip,
                    'mac-address' => $mac
                ]);

                $api->disconnect();
                return true;
            }
        } catch (Exception $e) {
            error_log("[RouterBridge Error] " . $e->getMessage());
        }

        return true;
    }

    /**
     * Kata / Ondoa mteja anayekwisha muda wake kwenye MikroTik
     */
    public static function disconnectClient($station, $mac) {
        $routerIp   = $station['router_ip'] ?? '192.168.88.1';
        $routerPort = (int)($station['router_port'] ?? 8728);
        $routerUser = $station['router_user'] ?? 'admin';
        $routerPass = $station['router_pass'] ?? '';

        if (!self::pingRouter($routerIp, $routerPort, 1.0)) {
            return true;
        }

        try {
            $api = new RouterOSApi();
            if ($api->connect($routerIp, $routerUser, $routerPass, $routerPort, 2)) {
                // Tafuta sessions zote za MAC hii
                $actives = $api->comm('/ip/hotspot/active/print', [
                    '?mac-address' => $mac
                ]);
                foreach ($actives as $act) {
                    if (isset($act['.id'])) {
                        $api->comm('/ip/hotspot/active/remove', [
                            '.id' => $act['.id']
                        ]);
                    }
                }
                $api->disconnect();
            }
        } catch (Exception $e) {
            error_log("[RouterBridge Disconnect Error] " . $e->getMessage());
        }

        return true;
    }

    /**
     * Generic AP Controller Authorization (UniFi / Omada / OpenWrt Webhook)
     */
    private static function authorizeGenericAp($station, $mac, $ip, $durationMinutes) {
        if (!empty($station['ap_controller_url'])) {
            $payload = json_encode([
                'cmd'         => 'authorize-guest',
                'mac'         => strtolower($mac),
                'minutes'     => (int)$durationMinutes,
                'secret_key'  => $station['ap_secret_key'] ?? ''
            ]);

            $ch = curl_init($station['ap_controller_url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_exec($ch);
            curl_close($ch);
        }
        return true;
    }
}

/**
 * Darasa la Mawasiliano ya MikroTik RouterOS API Protocol (Port 8728)
 */
class RouterOSApi {
    private $socket;
    public $connected = false;

    public function connect($ip, $login, $password, $port = 8728, $timeout = 2) {
        $this->socket = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if (!$this->socket) {
            return false;
        }
        socket_set_timeout($this->socket, $timeout);

        // Ombi la kuingia (Login)
        $this->write('/login');
        $res = $this->read(false);

        if (isset($res[0]) && $res[0] === '!done') {
            if (isset($res[1]) && preg_match('/=ret=(.*)/', $res[1], $matches)) {
                // RouterOS v6 Challenge-Response Auth
                $chal = pack('H*', $matches[1]);
                $hash = '=response=00' . md5(chr(0) . $password . $chal);
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write($hash);
                $res = $this->read(false);
                if (isset($res[0]) && $res[0] === '!done') {
                    $this->connected = true;
                    return true;
                }
            } else {
                // RouterOS v6.43+ / v7 Plain Auth
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=password=' . $password);
                $res = $this->read(false);
                if (isset($res[0]) && $res[0] === '!done') {
                    $this->connected = true;
                    return true;
                }
            }
        }

        $this->disconnect();
        return false;
    }

    public function disconnect() {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->connected = false;
    }

    public function comm($command, $array = []) {
        if (!$this->connected) return [];
        $this->write($command, empty($array));
        $count = count($array);
        $i = 0;
        foreach ($array as $key => $val) {
            $i++;
            $tag = is_numeric($key) ? $val : "=$key=$val";
            $this->write($tag, $i === $count);
        }
        return $this->read();
    }

    private function write($str, $last = true) {
        $len = strlen($str);
        if ($len < 0x80) {
            $header = chr($len);
        } elseif ($len < 0x4000) {
            $header = chr($len >> 8 | 0x80) . chr($len & 0xFF);
        } elseif ($len < 0x200000) {
            $header = chr($len >> 16 | 0xC0) . chr($len >> 8 & 0xFF) . chr($len & 0xFF);
        } else {
            $header = chr($len >> 24 | 0xE0) . chr($len >> 16 & 0xFF) . chr($len >> 8 & 0xFF) . chr($len & 0xFF);
        }
        @fwrite($this->socket, $header . $str);
        if ($last) {
            @fwrite($this->socket, chr(0));
        }
    }

    private function read($parse = true) {
        $result = [];
        $current = [];
        while (true) {
            $byte = @fread($this->socket, 1);
            if ($byte === false || $byte === '') break;
            $len = ord($byte);
            if ($len === 0) {
                if (!empty($current)) {
                    $result[] = $current;
                    $current = [];
                }
                continue;
            }
            if (($len & 0x80) === 0x00) {
                // 1 byte
            } elseif (($len & 0xC0) === 0x80) {
                $len = (($len & 0x3F) << 8) + ord(fread($this->socket, 1));
            } elseif (($len & 0xE0) === 0xC0) {
                $len = (($len & 0x1F) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            }
            $word = @fread($this->socket, $len);
            if ($word === '!done' || $word === '!trap') {
                $result[] = $word;
                break;
            }
            if ($parse && strpos($word, '=') === 0) {
                $parts = explode('=', substr($word, 1), 2);
                if (count($parts) === 2) {
                    $current[$parts[0]] = $parts[1];
                }
            } else {
                $current[] = $word;
            }
        }
        return $result;
    }
}
?>
