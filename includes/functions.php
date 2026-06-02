<?php
/*
 * فایل: includes/functions.php
 */

// تبدیل اعداد فارسی و عربی به انگلیسی
function faToEn($string) {
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','۸','٩'];
    $english = ['0','1','2','3','4','5','6','7','8','9'];

    $string = str_replace($persian, $english, $string);
    $string = str_replace($arabic, $english, $string);
    
    return $string;
}

// اعتبارسنجی امن پین‌کد
function verify_user_pin($pdo, $userId, $enteredPin) {
    $enteredPin = trim(faToEn($enteredPin));
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute(["signature_pin_$userId"]);
    $realPinHash = $stmt->fetchColumn();

    if (!$realPinHash) return false;
    return password_verify($enteredPin, $realPinHash);
}

// آپلود امن فایل با بررسی MIME Type واقعی
function upload_secure_file($fileTmpName, $originalName, $uploadDir, $allowedMimes = [], $prefix = 'file_') {
    if (!is_uploaded_file($fileTmpName)) return false;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($fileTmpName);

    if (!empty($allowedMimes) && !in_array($mime, $allowedMimes, true)) {
        return false; 
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $newName = $prefix . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    if (move_uploaded_file($fileTmpName, $uploadDir . $newName)) {
        return ['name' => $newName, 'ext' => $ext, 'mime' => $mime];
    }
    return false;
}

// تبدیل تاریخ میلادی به شمسی
function jdate($format, $timestamp = '', $none = '', $time_zone = 'Asia/Tehran', $tr_num = 'fa') {
    $T_sec = 0;
    if ($timestamp === '') {
        $timestamp = time();
    } elseif (!is_numeric($timestamp)) {
        $timestamp = strtotime($timestamp);
    }
    if ($time_zone != 'local') date_default_timezone_set(($time_zone === '') ? 'Asia/Tehran' : $time_zone);
    $ts = $timestamp;
    $date = explode('_', date('H_i_j_n_O_P_s_w_Y', $ts));
    list($H, $i, $j, $n, $O, $P, $s, $w, $Y) = $date;
    $j = (int)$j; $n = (int)$n; $Y = (int)$Y;
    $d_4 = $Y % 4;
    $g_a = array(0, 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
    $doy_g = $g_a[$n] + $j;
    if ($d_4 == 0 and $n > 2) $doy_g++;
    $d_33 = (int)((($Y - 16) % 132) * .0305);
    $a = ($d_33 == 3 or $d_33 < ($d_4 - 1) or $d_4 == 0) ? 286 : 287;
    $b = (($d_33 == 1 or $d_33 == 2) and ($d_33 == $d_4 or $d_4 == 1)) ? 78 : (($d_33 == 3 and $d_4 == 0) ? 80 : 79);
    if ((int)(($Y - 10) / 63) == 30) { $a--; $b++; }
    if ($doy_g > $b) { $jy = $Y - 621; $doy_j = $doy_g - $b; } else { $jy = $Y - 622; $doy_j = $doy_g + $a; }
    if ($doy_j < 187) { $jm = (int)(($doy_j - 1) / 31); $jd = $doy_j - (31 * $jm++); } else { $jm = (int)(($doy_j - 187) / 30); $jd = $doy_j - 186 - ($jm * 30); $jm += 7; }
    $persian_months = array('', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند');
    $persian_days = array('یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه');
    $output = "";
    $len = strlen($format);
    for ($i = 0; $i < $len; $i++) {
        $char = $format[$i];
        switch ($char) {
            case 'Y': $output .= $jy; break;
            case 'm': $output .= ($jm < 10) ? "0".$jm : $jm; break;
            case 'd': $output .= ($jd < 10) ? "0".$jd : $jd; break;
            case 'l': $output .= $persian_days[$w]; break;
            case 'F': $output .= $persian_months[$jm]; break;
            case 'H': $output .= $H; break;
            case 'i': $output .= $i; break;
            case 's': $output .= $s; break;
            default: $output .= $char;
        }
    }
    if ($tr_num == 'fa') {
        $output = str_replace(['0','1','2','3','4','5','6','7','8','9'], ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], $output);
    }
    return $output;
}

function jalali_to_gregorian($jy, $jm, $jd, $mod = '') {
    $jy = (int)$jy; $jm = (int)$jm; $jd = (int)$jd; 
    $jy += 1595;
    $days = -355668 + (365 * $jy) + ((int)($jy / 33) * 8) + (int)((($jy % 33) + 3) / 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * (int)($days / 146097);
    $days %= 146097;
    if ($days > 36524) {
        $days--;
        $gy += 100 * (int)($days / 36524);
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * (int)($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = [0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 0; $gm < 13; $gm++) {
        $v = $sal_a[$gm];
        if ($gd <= $v) break;
        $gd -= $v;
    }
    return ($mod == '') ? array($gy, $gm, $gd) : $gy . $mod . $gm . $mod . $gd;
}

function logSystem($section, $action, $record_id, $description) {
    global $pdo;
    try {
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        $sql = "INSERT INTO system_logs (user_id, ip_address, section, action, record_id, description) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $ip, $section, $action, $record_id, $description]);
    } catch (Exception $e) { error_log("Logging Error: " . $e->getMessage()); }
}

function hasPermission($perm) {
    global $pdo;
    if (!isset($_SESSION['user_id'])) return false;
    static $userPermissions = null;
    static $isAdmin = false;
    if ($userPermissions === null) {
        try {
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $userRole = $stmt->fetchColumn();
            if ($userRole === 'admin') {
                $isAdmin = true;
                $userPermissions = ['all'];
            } else {
                $stmtRole = $pdo->prepare("SELECT permissions FROM roles WHERE name = ?");
                $stmtRole->execute([$userRole]);
                $roleData = $stmtRole->fetch();
                $userPermissions = $roleData ? json_decode($roleData['permissions'], true) : [];
            }
        } catch (Exception $e) { $userPermissions = []; }
    }
    if ($isAdmin) return true;
    if (is_array($userPermissions) && in_array('all', $userPermissions)) return true;
    return is_array($userPermissions) && in_array($perm, $userPermissions);
}

function hasChatPermission($perm) {
    global $pdo;
    if (!isset($_SESSION['user_id'])) return false;
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') return true;

    static $cachedPerms = null;
    if ($cachedPerms === null) {
        try {
            $stmt = $pdo->prepare("SELECT chat_permissions FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $json = $stmt->fetchColumn();
            $cachedPerms = json_decode($json ?: '[]', true);
            if (!is_array($cachedPerms)) $cachedPerms = [];
        } catch (Exception $e) {
            $cachedPerms = [];
        }
    }
    return in_array($perm, $cachedPerms);
}

function canUserChat($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT role, chat_permissions FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    
    $perms = json_decode($user['chat_permissions'] ?? '[]', true);
    return is_array($perms) && in_array('chat_private', $perms);
}

function getUserProfileImage($pdo, $userId, $basePath = '') {
    $stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $physicalPath = __DIR__ . '/../public_html/uploads/profiles/';
    if ($user && !empty($user['profile_image']) && $user['profile_image'] !== 'default.png') {
        if (file_exists($physicalPath . $user['profile_image'])) {
            return $basePath . 'uploads/profiles/' . $user['profile_image'];
        }
    }
    return $basePath . 'assets/images/profile-icon.png';
}

function getActiveFiscalYear() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM fiscal_years WHERE is_current = 1 AND status = 'active' LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function csrf_token() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    $t = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify($token) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token']) || empty($token)) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

function sanitize_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    if (strpos($url, '/') === 0) return $url;
    return '';
}

function db_has_column($pdo, $table, $column) {
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    } catch (Exception $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function send_otp_sms($mobile, $otp_code) {
    $config = require __DIR__ . '/../Config/config.php';
    $url = "https://api2.ippanel.com/api/v1/sms/pattern/normal/send";
    
    $apiKey  = $config['sms_api_key'];
    $from    = $config['sms_from_number'];
    $pattern = $config['sms_pattern_code'];
    
    if (substr($mobile, 0, 1) === '0') {
        $mobile = '+98' . substr($mobile, 1);
    }

    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $apiKey,
    ];

    $body_rest = [
        "code"      => $pattern,
        "sender"    => $from,
        "recipient" => $mobile,
        "variable"  => [
            "verification-code" => strval($otp_code)
        ]
    ];

    $handler = curl_init($url);
    curl_setopt($handler, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($handler, CURLOPT_POST, true);
    curl_setopt($handler, CURLOPT_POSTFIELDS, json_encode($body_rest));
    curl_setopt($handler, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handler, CURLOPT_TIMEOUT, 2); 
    
    $response = curl_exec($handler);
    $http_code = curl_getinfo($handler, CURLINFO_HTTP_CODE);
    curl_close($handler);

    if ($http_code == 200 || $http_code == 201) {
        return true; 
    } else {
        error_log("SMS Error: Code $http_code - Response: $response");
        return false;
    }
}

function send_sms_pattern($mobile, $patternCode, $variables) {
    $config = require __DIR__ . '/../Config/config.php';
    $url = "https://api2.ippanel.com/api/v1/sms/pattern/normal/send";
    
    $apiKey = $config['sms_api_key'];
    $from   = $config['sms_from_number'];

    if (substr($mobile, 0, 1) === '0') {
        $mobile = '+98' . substr($mobile, 1);
    }

    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $apiKey,
    ];

    $body = [
        "code"      => $patternCode,
        "sender"    => $from,
        "recipient" => $mobile,
        "variable"  => $variables
    ];

    try {
        $handler = curl_init($url);
        curl_setopt($handler, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handler, CURLOPT_POST, true);
        curl_setopt($handler, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($handler, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handler, CURLOPT_TIMEOUT, 2); 
        $response = curl_exec($handler);
        $http_code = curl_getinfo($handler, CURLINFO_HTTP_CODE);
        curl_close($handler);
        return ($http_code == 200 || $http_code == 201);
    } catch (Exception $e) {
        error_log("Pattern SMS Error: " . $e->getMessage());
        return false;
    }
}

if (!function_exists('send_user_notification')) {
    function send_user_notification($pdo, $user_id, $title, $body, $url = null, $type = null, $ref_id = null) {
        $icon_url = 'assets/images/profile-icon.png'; 
        
        if (isset($_SESSION['user_id'])) {
            try {
                $stmt = $pdo->prepare("SELECT profile_image FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $img = $stmt->fetchColumn();
                if ($img && $img !== 'default.png') {
                    $icon_url = 'uploads/profiles/' . $img;
                }
            } catch (Exception $e) {}
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO user_notifications (user_id, title, body, url, type, ref_id, icon_url, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())");
            $stmt->execute([$user_id, $title, $body, $url, $type, $ref_id, $icon_url]);
        } catch (Exception $e) {
            error_log("Notification Insert Error: " . $e->getMessage());
        }
    }
}

if (!function_exists('create_system_announcement')) {
    function create_system_announcement($pdo, $title, $content, $link = null, $targetUserIds = []) {
        $creator = $_SESSION['user_id'] ?? 1; 
        
        $stmt = $pdo->prepare("INSERT INTO announcements (title, content, priority, status, created_by, type, link, category_id) 
                               VALUES (?, ?, 'high', 'published', ?, 'system', ?, 1)");
        $stmt->execute([$title, $content, $creator, $link]);
        $announcement_id = $pdo->lastInsertId();

        if (!empty($targetUserIds) && $announcement_id) {
            $targetUserIds = array_unique($targetUserIds);
            
            $tStmt = $pdo->prepare("INSERT INTO announcement_targets (announcement_id, user_id) VALUES (?, ?)");
            foreach ($targetUserIds as $uid) {
                if ($uid > 0) {
                    $tStmt->execute([$announcement_id, $uid]);
                }
            }
        }
        return $announcement_id;
    }
}

// ---------------------------------------------------------
// توابع شمارشگر برای منوی کناری (Sidebar Badges)
// ---------------------------------------------------------

if (!function_exists('getUnreadCartableCount')) {
    function getUnreadCartableCount($pdo, $userId) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals r JOIN letters l ON r.letter_id = l.id WHERE r.receiver_id = ? AND r.is_read = 0 AND r.is_completed = 0 AND (l.is_deleted = 0 OR l.is_deleted IS NULL)");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('getPendingSignCount')) {
    function getPendingSignCount($pdo, $userId) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM letter_signers ls JOIN letters l ON ls.letter_id = l.id WHERE ls.user_id = ? AND ls.status = 'pending' AND l.status IN ('approved_for_sign', 'registered') AND (l.is_deleted = 0 OR l.is_deleted IS NULL)");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('getPendingActionCount')) {
    function getPendingActionCount($pdo, $userId, $isAdmin = false) {
        try {
            $sql = "SELECT COUNT(*) FROM letters l WHERE (l.is_archived = 0 OR l.is_archived IS NULL) AND (l.is_deleted = 0 OR l.is_deleted IS NULL) AND ((l.type IN ('internal', 'incoming') AND l.status IN ('pending_action', 'registered', 'in_referral')) OR (l.type = 'outgoing' AND l.status IN ('pending_action', 'in_referral', 'approved_for_sign')))";
            if (!$isAdmin) {
                $sql .= " AND (l.created_by = $userId OR EXISTS(SELECT 1 FROM letter_referrals r2 WHERE r2.letter_id = l.id AND r2.receiver_id = $userId AND r2.is_completed = 0))";
            }
            $stmt = $pdo->query($sql);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

// ---------------------------------------------------------
// تابع جامع برای استخراج تمامی بج‌های سایدبار (هسته اصلی سایدبار)
// ---------------------------------------------------------
if (!function_exists('getLetterBadges')) {
    function getLetterBadges($pdo, $userId, $isAdmin = false) {
        $badges = [
            'inbox' => 0,    // کارتابل ورودی (ارجاعات خوانده نشده)
            'pending' => 0,  // در دست اقدام
            'approved' => 0,  // در انتظار امضا
            'chat_unread' => 0 // شمارشگر چت‌های خوانده نشده
        ];

        // 1. کارتابل ورودی
        try {
            $sql1 = "SELECT COUNT(r.id) FROM letter_referrals r 
                     JOIN letters l ON r.letter_id = l.id 
                     WHERE r.receiver_id = ? AND r.is_read = 0 AND r.is_completed = 0 
                     AND (l.is_archived = 0 OR l.is_archived IS NULL) 
                     AND (l.is_deleted = 0 OR l.is_deleted IS NULL)";
            $stmt1 = $pdo->prepare($sql1);
            $stmt1->execute([$userId]);
            $badges['inbox'] = (int)$stmt1->fetchColumn();
        } catch (Throwable $e) {}

        // 2. در دست اقدام
        try {
            $sql2 = "SELECT COUNT(id) FROM letters WHERE status IN ('pending_action', 'in_referral') 
                     AND (is_archived = 0 OR is_archived IS NULL) 
                     AND (is_deleted = 0 OR is_deleted IS NULL)";
            if (!$isAdmin) {
                $sql2 .= " AND created_by = ?";
                $stmt2 = $pdo->prepare($sql2);
                $stmt2->execute([$userId]);
            } else {
                $stmt2 = $pdo->query($sql2);
            }
            $badges['pending'] = (int)$stmt2->fetchColumn();
        } catch (Throwable $e) {}

        // 3. صف امضا
        try {
            $sql3 = "SELECT COUNT(l.id) FROM letters l
                     JOIN letter_signers ls ON l.id = ls.letter_id
                     WHERE ls.user_id = ? AND ls.status = 'pending' 
                     AND l.status IN ('approved_for_sign', 'registered') 
                     AND (l.is_archived = 0 OR l.is_archived IS NULL) 
                     AND (l.is_deleted = 0 OR l.is_deleted IS NULL)";
            $stmt3 = $pdo->prepare($sql3);
            $stmt3->execute([$userId]);
            $badges['approved'] = (int)$stmt3->fetchColumn();
        } catch (Throwable $e) {}

        // 4. محاسبه پیام‌های خوانده نشده چت
        try {
            $sqlChat = "SELECT COUNT(m.id) 
                        FROM messages m
                        JOIN conversations c ON m.conversation_id = c.id
                        JOIN conversation_participants p ON c.id = p.conversation_id
                        WHERE p.user_id = ? 
                        AND m.sender_id != ? 
                        AND m.is_read = 0 
                        AND m.is_deleted = 0";
            $stmtChat = $pdo->prepare($sqlChat);
            $stmtChat->execute([$userId, $userId]);
            $badges['chat_unread'] = (int)$stmtChat->fetchColumn();
        } catch (Throwable $e) {
            error_log("Error fetching chat badges: " . $e->getMessage());
        }

        return $badges;
    }
}
?>