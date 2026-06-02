<?php
/*
 * فایل: public_html/api/chat_api.php
 * نسخه: جامع و نهایی (فیلتر اختصاصی منشن‌ها، تاریخ دقیق، جستجو، پین و ویرایش)
 */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("X-Content-Type-Options: nosniff");
header('Content-Type: application/json; charset=utf-8');

ob_start();
ini_set('display_errors', 0);
session_start();

$dbPath = __DIR__ . '/../../includes/db.php';
$funcPath = __DIR__ . '/../../includes/functions.php';

if (!file_exists($dbPath) || !file_exists($funcPath)) {
    jsonResponse(['status'=>'error', 'message'=>'فایل‌های سیستمی یافت نشدند']);
}

require_once $dbPath;
require_once $funcPath;

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['status'=>'error', 'message'=>'نشست منقضی شده']);
}

$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';
$action = $_REQUEST['action'] ?? '';

// آپدیت وضعیت آنلاین بودن کاربر
try {
    $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")->execute([$userId]);
} catch (Exception $e) {}

// ارتقاء خودکار دیتابیس
try {
    $checkCol = $pdo->query("SHOW COLUMNS FROM `conversation_participants` LIKE 'last_read_message_id'");
    if ($checkCol && $checkCol->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `conversation_participants` ADD COLUMN `last_read_message_id` INT DEFAULT 0 AFTER `role`");
    }
    
    $checkCol2 = $pdo->query("SHOW COLUMNS FROM `messages` LIKE 'is_edited'");
    if ($checkCol2 && $checkCol2->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `messages` ADD COLUMN `is_edited` TINYINT(1) DEFAULT 0 AFTER `is_read`");
    }

    $checkCol3 = $pdo->query("SHOW COLUMNS FROM `conversations` LIKE 'pinned_messages'");
    if ($checkCol3 && $checkCol3->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `conversations` ADD COLUMN `pinned_messages` VARCHAR(1000) DEFAULT '[]' AFTER `creator_id`");
    }
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// تنظیمات رمزنگاری
$chatEncKey = null;
if (isset($settings) && is_array($settings) && !empty($settings['chat_enc_key'])) {
    $chatEncKey = (string)$settings['chat_enc_key'];
}
define('CHAT_ENC_KEY', $chatEncKey ?: 'YourSecureKeyForChat2026_Atena');
define('CHAT_ENC_METHOD', 'AES-256-CBC');

function encryptMsg($text) {
    if (empty($text)) return '';
    $ivLength = openssl_cipher_iv_length(CHAT_ENC_METHOD);
    $iv = openssl_random_pseudo_bytes($ivLength);
    $encrypted = openssl_encrypt($text, CHAT_ENC_METHOD, CHAT_ENC_KEY, 0, $iv);
    return base64_encode($iv) . ':' . $encrypted;
}

function decryptMsg($text) {
    if (empty($text)) return '';
    if (strpos($text, ':') !== false) {
        list($ivB64, $encrypted) = explode(':', $text, 2);
        $iv = base64_decode($ivB64);
        if ($iv !== false) return openssl_decrypt($encrypted, CHAT_ENC_METHOD, CHAT_ENC_KEY, 0, $iv);
    }
    $decoded = base64_decode($text);
    if ($decoded !== false && strpos($decoded, '::') !== false) {
        list($iv, $encrypted) = explode('::', $decoded, 2);
        return openssl_decrypt($encrypted, CHAT_ENC_METHOD, CHAT_ENC_KEY, 0, $iv);
    }
    return $text;
}

function jsonResponse($payload) {
    if (ob_get_length()) @ob_clean();
    $flags = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) $flags |= JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    echo json_encode($payload, $flags);
    exit;
}

function escapeLikeTerm($term) {
    $term = (string)$term;
    $term = str_replace('\\', '\\\\', $term);
    $term = str_replace('%', '\\%', $term);
    $term = str_replace('_', '\\_', $term);
    return $term;
}

function resolveConversationId($pdo, $chatType, $targetId, $userId) {
    if ($chatType === 'private') {
        $sql = "SELECT c.id FROM conversations c
                JOIN conversation_participants p1 ON c.id = p1.conversation_id AND p1.user_id = ?
                JOIN conversation_participants p2 ON c.id = p2.conversation_id AND p2.user_id = ?
                WHERE c.type = 'private' LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $targetId]);
        $convId = $stmt->fetchColumn();
        
        if ($convId) return (int)$convId;

        $pdo->prepare("INSERT INTO conversations (type) VALUES ('private')")->execute();
        $convId = (int)$pdo->lastInsertId();
        
        $pdo->prepare("INSERT INTO conversation_participants (conversation_id, user_id, role) VALUES (?, ?, 'member'), (?, ?, 'member')")
            ->execute([$convId, $userId, $convId, $targetId]);
            
        return $convId;
    }
    return (int)$targetId; 
}

function isParticipant($pdo, $convId, $userId) {
    $stmt = $pdo->prepare("SELECT role FROM conversation_participants WHERE conversation_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$convId, $userId]);
    return $stmt->fetchColumn(); 
}

function formatPersianTime($datetime) {
    $h = date('H', strtotime($datetime));
    $i = date('i', strtotime($datetime));
    $eng = ['0','1','2','3','4','5','6','7','8','9'];
    $per = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return str_replace($eng, $per, $h . ':' . $i);
}

function formatPersianDateTime($datetime) {
    if (function_exists('jdate')) {
        return jdate('Y/m/d - H:i', strtotime($datetime));
    }
    $eng = ['0','1','2','3','4','5','6','7','8','9'];
    $per = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return str_replace($eng, $per, date('Y/m/d - H:i', strtotime($datetime)));
}

try {
    ob_clean();

    if ($action === 'typing_ping') {
        $targetId = (int)($_POST['target_id'] ?? 0);
        $chatType = $_POST['chat_type'] ?? 'private';
        if ($targetId <= 0) jsonResponse(['status' => 'error']);

        $convId = resolveConversationId($pdo, $chatType, $targetId, $userId);
        $stmt = $pdo->prepare("INSERT INTO conversation_status (user_id, conversation_id, is_typing, last_typed_at) VALUES (?, ?, 1, NOW()) ON DUPLICATE KEY UPDATE is_typing=1, last_typed_at=NOW()");
        $stmt->execute([$userId, $convId]);
        jsonResponse(['status' => 'success']);
    }

    elseif ($action === 'check_new_messages') {
        $targetId = (int)$_REQUEST['target_id'];
        $chatType = $_REQUEST['chat_type'] ?? 'private';
        $lastId   = (int)($_REQUEST['last_id'] ?? 0);
        $typingWindowSec = 5;

        $convId = resolveConversationId($pdo, $chatType, $targetId, $userId);

        if ($role !== 'admin' && !isParticipant($pdo, $convId, $userId)) {
            jsonResponse(['status'=>'error', 'message'=>'Access Denied']);
        }

        $mentionStrLike = '%"mentioned_user_id":' . $userId . '%';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND id > ? AND is_deleted = 0 AND (file_type != 'mention_alert' OR message_text LIKE ?)");
        $stmt->execute([$convId, $lastId, $mentionStrLike]);
        $count = (int)$stmt->fetchColumn();

        $t = $pdo->prepare("SELECT 1 FROM conversation_status WHERE conversation_id = ? AND user_id != ? AND last_typed_at >= (NOW() - INTERVAL {$typingWindowSec} SECOND) LIMIT 1");
        $t->execute([$convId, $userId]);
        $typing = (bool)$t->fetchColumn();

        $seenUpto = 0;
        if ($chatType === 'private') {
            $s = $pdo->prepare("SELECT MAX(id) FROM messages WHERE sender_id = ? AND conversation_id = ? AND is_deleted = 0 AND is_read = 1");
            $s->execute([$userId, $convId]);
            $seenUpto = (int)($s->fetchColumn() ?: 0);
        }

        jsonResponse(['status' => 'success', 'has_new' => $count > 0, 'new_count' => $count, 'typing' => $typing, 'seen_upto' => $seenUpto]);
    }

    // جستجوی درون چت
    elseif ($action === 'search_messages') {
        $convId = (int)($_POST['conv_id'] ?? 0);
        $keyword = trim($_POST['keyword'] ?? '');
        if ($keyword === '') jsonResponse(['status'=>'success', 'data'=>[]]);
        
        $roleInConv = isParticipant($pdo, $convId, $userId);
        if (!$roleInConv && $role !== 'admin') jsonResponse(['status'=>'error', 'message'=>'دسترسی ندارید']);

        $stmt = $pdo->prepare("
            SELECT m.id, m.message_text, m.created_at, u.first_name, u.last_name 
            FROM messages m 
            LEFT JOIN users u ON m.sender_id = u.id 
            WHERE m.conversation_id = ? AND m.is_deleted = 0 
              AND (m.file_type = 'text' OR m.file_type IS NULL OR m.file_type = '') 
            ORDER BY m.id DESC LIMIT 2000
        ");
        $stmt->execute([$convId]);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = [];
        $safeKeyword = str_replace([' ', '‌'], '', mb_strtolower($keyword, 'UTF-8'));
        
        foreach($all as $m) {
            $decrypted = decryptMsg($m['message_text']);
            $safeDecrypted = str_replace([' ', '‌'], '', mb_strtolower($decrypted, 'UTF-8'));
            
            if (mb_stripos($safeDecrypted, $safeKeyword, 0, 'UTF-8') !== false) {
                $results[] = [
                    'id' => $m['id'],
                    'text' => mb_substr($decrypted, 0, 80) . '...',
                    'sender' => trim($m['first_name'] . ' ' . $m['last_name']),
                    'time' => formatPersianTime($m['created_at'])
                ];
                if (count($results) >= 40) break;
            }
        }
        jsonResponse(['status'=>'success', 'data'=>$results]);
    }

    // دریافت اعضای گروه برای سیستم منشن (@)
    elseif ($action === 'get_chat_members') {
        $convId = (int)$_REQUEST['conv_id'];
        
        $stmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.username, u.profile_image 
            FROM conversation_participants cp 
            JOIN users u ON cp.user_id = u.id 
            WHERE cp.conversation_id = ?
        ");
        $stmt->execute([$convId]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach($members as &$m) {
            $m['avatar'] = (!empty($m['profile_image']) && $m['profile_image']!='default.png' && file_exists(__DIR__ . '/../../public_html/uploads/profiles/' . $m['profile_image'])) ? '../uploads/profiles/'.$m['profile_image'] : '../assets/images/profile-icon.png';
            $m['name'] = trim($m['first_name'] . ' ' . $m['last_name']);
        }
        jsonResponse(['status'=>'success', 'data'=>$members]);
    }

    elseif ($action === 'get_messages') {
        $targetId = (int)$_REQUEST['target_id'];
        $lastId   = (int)($_REQUEST['last_id'] ?? 0);
        $chatType = $_REQUEST['chat_type'] ?? 'private';

        $convId = resolveConversationId($pdo, $chatType, $targetId, $userId);

        if ($role !== 'admin' && !isParticipant($pdo, $convId, $userId)) {
            jsonResponse(['status'=>'error', 'message'=>'شما عضو این گفتگو نیستید']);
        }

        $mentionStrLike = '%"mentioned_user_id":' . $userId . '%';

        $lastMsgStmt = $pdo->prepare("SELECT MAX(id) FROM messages WHERE conversation_id = ? AND is_deleted = 0 AND (file_type != 'mention_alert' OR message_text LIKE ?)");
        $lastMsgStmt->execute([$convId, $mentionStrLike]);
        $maxId = (int)$lastMsgStmt->fetchColumn();

        if ($maxId > 0 && db_has_column($pdo, 'conversation_participants', 'last_read_message_id')) {
            $pdo->prepare("UPDATE conversation_participants SET last_read_message_id = ? WHERE conversation_id = ? AND user_id = ? AND last_read_message_id < ?")->execute([$maxId, $convId, $userId, $maxId]);
        }
        $pdo->prepare("UPDATE messages SET is_read = 1 WHERE conversation_id = ? AND sender_id != ? AND is_deleted = 0")->execute([$convId, $userId]);

        $sql = "SELECT m.*, u.first_name, u.last_name, u.username, m2.message_text as reply_text 
                FROM messages m
                LEFT JOIN users u ON m.sender_id = u.id
                LEFT JOIN messages m2 ON m.reply_to_id = m2.id
                WHERE m.conversation_id = ? AND m.is_deleted = 0 AND (m.file_type != 'mention_alert' OR m.message_text LIKE ?) ";
        
        $params = [$convId, $mentionStrLike];
        if ($lastId > 0) { 
            $sql .= " AND m.id > ? ORDER BY m.id ASC"; 
            $params[] = $lastId; 
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $sql .= " ORDER BY m.id DESC LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $msgs = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        
        foreach($msgs as &$m) {
            if (!in_array($m['file_type'], ['sticker', 'event', 'mention_alert'])) {
                $m['message_text'] = decryptMsg($m['message_text']);
            }
            if (!empty($m['reply_text'])) {
                $m['reply_text'] = decryptMsg($m['reply_text']);
            } elseif (!empty($m['reply_to_id'])) {
                $m['reply_text'] = 'فایل / مدیا';
            }
            
            $m['is_me'] = ($m['sender_id'] == $userId);
            $m['time'] = formatPersianTime($m['created_at']); 
            $m['mention_date_time'] = formatPersianDateTime($m['created_at']); // تاریخ کامل برای باکس منشن
            $m['is_edited'] = (int)($m['is_edited'] ?? 0);
            $m['reactions'] = json_decode($m['reactions'] ?? '[]', true) ?: [];
            
            if ($chatType !== 'private') {
                $m['sender_name'] = ($m['first_name'] ?? 'کاربر') . ' ' . ($m['last_name'] ?? '');
            }
        }
        
        $pinnedInfo = [];
        if (db_has_column($pdo, 'conversations', 'pinned_messages')) {
            $stmtC = $pdo->prepare("SELECT pinned_messages FROM conversations WHERE id = ?");
            $stmtC->execute([$convId]);
            $pinnedJson = $stmtC->fetchColumn();
            $pinnedIds = json_decode($pinnedJson ?: '[]', true);
            
            if (is_array($pinnedIds) && count($pinnedIds) > 0) {
                $placeholders = implode(',', array_fill(0, count($pinnedIds), '?'));
                $stmtPin = $pdo->prepare("SELECT m.id, m.message_text, m.file_type, u.first_name, u.last_name 
                                          FROM messages m 
                                          LEFT JOIN users u ON m.sender_id = u.id 
                                          WHERE m.id IN ($placeholders) AND m.is_deleted = 0");
                $stmtPin->execute($pinnedIds);
                $pinnedMsgs = $stmtPin->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($pinnedIds as $pid) {
                    foreach ($pinnedMsgs as $pm) {
                        if ($pm['id'] == $pid) {
                            $pText = in_array($pm['file_type'], ['sticker', 'event', 'mention_alert']) ? $pm['message_text'] : decryptMsg($pm['message_text']);
                            $pinnedInfo[] = [
                                'id' => $pm['id'],
                                'text' => $pm['file_type'] !== 'text' ? 'فایل / رسانه' : mb_substr($pText, 0, 60) . '...',
                                'sender' => trim($pm['first_name'] . ' ' . $pm['last_name'])
                            ];
                            break;
                        }
                    }
                }
            }
        }

        jsonResponse(['status'=>'success', 'data'=>$msgs, 'pinned'=>$pinnedInfo]);
    }

    elseif ($action === 'get_older_messages') {
        $targetId = (int)$_REQUEST['target_id'];
        $firstId  = (int)$_REQUEST['first_id'];
        $chatType = $_REQUEST['chat_type'] ?? 'private';

        $convId = resolveConversationId($pdo, $chatType, $targetId, $userId);

        if ($role !== 'admin' && !isParticipant($pdo, $convId, $userId)) {
            jsonResponse(['status'=>'error', 'message'=>'شما عضو این گفتگو نیستید']);
        }

        $mentionStrLike = '%"mentioned_user_id":' . $userId . '%';

        $sql = "SELECT m.*, u.first_name, u.last_name, m2.message_text as reply_text 
                FROM messages m
                LEFT JOIN users u ON m.sender_id = u.id
                LEFT JOIN messages m2 ON m.reply_to_id = m2.id
                WHERE m.conversation_id = ? AND m.is_deleted = 0 AND m.id < ? 
                AND (m.file_type != 'mention_alert' OR m.message_text LIKE ?) 
                ORDER BY m.id DESC LIMIT 40";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$convId, $firstId, $mentionStrLike]);
        $msgs = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        
        foreach($msgs as &$m) {
            if (!in_array($m['file_type'], ['sticker', 'event', 'mention_alert'])) {
                $m['message_text'] = decryptMsg($m['message_text']);
            }
            if (!empty($m['reply_text'])) {
                $m['reply_text'] = decryptMsg($m['reply_text']);
            } elseif (!empty($m['reply_to_id'])) {
                $m['reply_text'] = 'فایل / مدیا';
            }
            
            $m['is_me'] = ($m['sender_id'] == $userId);
            $m['time'] = formatPersianTime($m['created_at']);
            $m['mention_date_time'] = formatPersianDateTime($m['created_at']);
            $m['is_edited'] = (int)($m['is_edited'] ?? 0);
            $m['reactions'] = json_decode($m['reactions'] ?? '[]', true) ?: [];
            
            if ($chatType !== 'private') {
                $m['sender_name'] = ($m['first_name'] ?? 'کاربر') . ' ' . ($m['last_name'] ?? '');
            }
        }
        jsonResponse(['status'=>'success', 'data'=>$msgs]);
    }

    elseif ($action === 'get_conversations') {
        $tab = $_GET['tab'] ?? 'all';
        $search = trim((string)($_GET['q'] ?? ''));
        if ($search !== '') $search = mb_substr($search, 0, 60, 'UTF-8');
        $data = [];
        
        $hasLastRead = db_has_column($pdo, 'conversation_participants', 'last_read_message_id');
        $lrCol = $hasLastRead ? 'p.last_read_message_id' : '0';
        $mentionStrSQL = "'%\"mentioned_user_id\":{$userId}%'";

        if ($tab === 'all' || $tab === 'groups' || $tab === 'group') {
            $sqlGroups = "
                SELECT c.id, c.title as name, c.type, c.avatar as image, c.description as bio,
                       m.message_text as last_msg, m.file_type as last_file_type,
                       m.created_at as last_time, m.id as last_id,
                       (SELECT COUNT(id) FROM messages WHERE conversation_id = c.id AND id > $lrCol AND sender_id != ? AND is_deleted = 0 AND (file_type != 'mention_alert' OR message_text LIKE $mentionStrSQL)) as unread_count,
                       IF(m.sender_id = ?, 1, 0) as is_my_last_msg
                FROM conversations c 
                JOIN conversation_participants p ON c.id = p.conversation_id AND p.user_id = ?
                LEFT JOIN (
                    SELECT m1.conversation_id, m1.message_text, m1.file_type, m1.created_at, m1.id, m1.sender_id
                    FROM messages m1
                    JOIN (SELECT conversation_id, MAX(id) as max_id FROM messages WHERE is_deleted = 0 AND (file_type != 'mention_alert' OR message_text LIKE $mentionStrSQL) GROUP BY conversation_id) m2
                      ON m1.id = m2.max_id
                ) m ON m.conversation_id = c.id
                WHERE c.type IN ('group', 'channel')
            ";
            $paramsG = [$userId, $userId, $userId];
            if ($search) {
                $sqlGroups .= " AND c.title LIKE ? ESCAPE '\\\\'";
                $paramsG[] = '%' . escapeLikeTerm($search) . '%';
            }
            $stmt = $pdo->prepare($sqlGroups);
            $stmt->execute($paramsG);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach($groups as $g) {
                $g['is_group'] = true;
                if ($g['last_msg']) {
                    $g['last_msg'] = in_array($g['last_file_type'], ['sticker', 'event', 'mention_alert']) ? 'پیام سیستمی/منشن' : decryptMsg($g['last_msg']);
                } else {
                    $g['last_msg'] = $g['bio'] ?: ($g['type'] == 'channel' ? 'کانال' : 'گروه');
                }
                $g['avatar'] = (!empty($g['image']) && $g['image'] != 'default_group.png' && file_exists(__DIR__ . '/../../public_html/uploads/chat_avatars/' . $g['image'])) ? '../uploads/chat_avatars/'.$g['image'] : '../assets/images/profile-icon.png';
                $data[] = $g;
            }
        }
        
        if ($tab === 'all' || $tab === 'members') {
            $sqlUsers = "
                SELECT u.id, u.first_name, u.last_name, u.profile_image, u.role, u.username, 
                       IF(u.last_seen >= NOW() - INTERVAL 1 MINUTE, 1, 0) AS is_online,
                       m.message_text as last_msg, m.file_type as last_file_type,
                       m.created_at as last_time, m.id as last_id,
                       (SELECT COUNT(id) FROM messages WHERE conversation_id = conv_map.conversation_id AND id > COALESCE(conv_map.my_last_read, 0) AND sender_id != ? AND is_deleted = 0 AND (file_type != 'mention_alert' OR message_text LIKE $mentionStrSQL)) as unread_count,
                       IF(m.sender_id = ?, 1, 0) as is_my_last_msg
                FROM users u 
                LEFT JOIN (
                    SELECT other_p.user_id as other_user_id, c.id as conversation_id, " . ($hasLastRead ? "my_p.last_read_message_id" : "0") . " as my_last_read
                    FROM conversations c
                    JOIN conversation_participants my_p ON c.id = my_p.conversation_id AND my_p.user_id = ?
                    JOIN conversation_participants other_p ON c.id = other_p.conversation_id AND other_p.user_id != ?
                    WHERE c.type = 'private'
                ) conv_map ON conv_map.other_user_id = u.id
                LEFT JOIN (
                    SELECT m1.conversation_id, m1.message_text, m1.file_type, m1.created_at, m1.id, m1.sender_id
                    FROM messages m1
                    JOIN (SELECT conversation_id, MAX(id) as max_id FROM messages WHERE is_deleted = 0 AND (file_type != 'mention_alert' OR message_text LIKE $mentionStrSQL) GROUP BY conversation_id) m2
                      ON m1.id = m2.max_id
                ) m ON m.conversation_id = conv_map.conversation_id
                WHERE u.id != ? AND u.status='active'
            ";
            
            $paramsU = [$userId, $userId, $userId, $userId, $userId];
            if ($search) {
                $sqlUsers .= " AND (u.first_name LIKE ? ESCAPE '\\\\' OR u.last_name LIKE ? ESCAPE '\\\\')";
                $paramsU[] = '%' . escapeLikeTerm($search) . '%';
                $paramsU[] = '%' . escapeLikeTerm($search) . '%';
            }
            $sqlUsers .= " LIMIT 50";
            
            $stmt = $pdo->prepare($sqlUsers);
            $stmt->execute($paramsU);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach($users as $u) {
                if ($role !== 'admin' && !hasChatPermission('chat_private')) continue;
                $u['name'] = $u['first_name'] . ' ' . $u['last_name'];
                $u['type'] = 'private';
                $u['is_group'] = false;
                $u['avatar'] = (!empty($u['profile_image']) && $u['profile_image']!='default.png' && file_exists(__DIR__ . '/../../public_html/uploads/profiles/' . $u['profile_image'])) ? '../uploads/profiles/'.$u['profile_image'] : '../assets/images/profile-icon.png';
                if ($u['last_msg']) {
                    $u['last_msg'] = in_array($u['last_file_type'], ['sticker', 'event', 'mention_alert']) ? 'پیام سیستمی/منشن' : decryptMsg($u['last_msg']);
                }
                $data[] = $u;
            }
        }

        usort($data, function($a, $b) {
            $ta = !empty($a['last_time']) ? strtotime($a['last_time']) : 0;
            $tb = !empty($b['last_time']) ? strtotime($b['last_time']) : 0;
            if ($ta === $tb) {
                $ia = isset($a['last_id']) ? (int)$a['last_id'] : 0;
                $ib = isset($b['last_id']) ? (int)$b['last_id'] : 0;
                return $ib <=> $ia;
            }
            return $tb <=> $ta;
        });

        jsonResponse(['status'=>'success', 'data'=>$data]);
    }

    elseif ($action === 'send_message') {
        $now = time();
        $_SESSION['chat_rate_limit'] = $_SESSION['chat_rate_limit'] ?? [];
        $_SESSION['chat_rate_limit'] = array_filter($_SESSION['chat_rate_limit'], function($t) use ($now) { return ($now - $t) < 5; });
        if (count($_SESSION['chat_rate_limit']) >= 5) {
            jsonResponse(['status'=>'error', 'message'=>'ارسال بیش از حد مجاز! لطفاً چند ثانیه صبر کنید.']);
        }
        $_SESSION['chat_rate_limit'][] = $now;

        $targetId = (int)$_POST['target_id'];
        $text = trim($_POST['message'] ?? '');
        $type = $_POST['type'] ?? 'text';
        $chatType = $_POST['chat_type'] ?? 'private';
        $replyTo = !empty($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : null;
        $forwardFromMsgId = !empty($_POST['forward_from_msg_id']) ? (int)$_POST['forward_from_msg_id'] : null;

        $convId = resolveConversationId($pdo, $chatType, $targetId, $userId);
        $userRoleInConv = isParticipant($pdo, $convId, $userId);

        if ($chatType === 'channel' && $role !== 'admin' && !in_array($userRoleInConv, ['owner', 'admin'])) {
            jsonResponse(['status'=>'error', 'message'=>'فقط مدیران کانال می‌توانند پیام ارسال کنند']);
        } elseif ($role !== 'admin' && !$userRoleInConv) {
            jsonResponse(['status'=>'error', 'message'=>'شما عضو این گفتگو نیستید']);
        }

        $filePath = null;
        if ($forwardFromMsgId) {
            if (!canAccessMessageForForward($pdo, $forwardFromMsgId, $userId, $role)) {
                jsonResponse(['status'=>'error', 'message'=>'مجوز فوروارد این پیام را ندارید']);
            }
            $st = $pdo->prepare("SELECT message_text, file_path, file_type FROM messages WHERE id = ? AND is_deleted = 0 LIMIT 1");
            $st->execute([$forwardFromMsgId]);
            $forwardSource = $st->fetch(PDO::FETCH_ASSOC);
            if ($forwardSource) {
                if (empty($text) && !empty($forwardSource['message_text']) && !in_array($forwardSource['file_type'], ['sticker', 'event', 'mention_alert'])) {
                    $text = decryptMsg($forwardSource['message_text']);
                }
                if (empty($_FILES['file']) && !empty($forwardSource['file_path'])) {
                    $filePath = $forwardSource['file_path'];
                    $type = $forwardSource['file_type'];
                }
            }
        }

        if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
             $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/zip', 'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/mp4', 'audio/webm', 'video/mp4', 'video/webm', 'application/octet-stream', 'audio/x-wav'];
             $finfo = new finfo(FILEINFO_MIME_TYPE);
             $mime = $finfo->file($_FILES['file']['tmp_name']);
             
             if (in_array($mime, $allowedMimes)) {
                 $uploadDir = __DIR__ . '/../../public_html/uploads/chat/';
                 if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                 $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                 $newFileName = uniqid('f_').'_'.time().'.'.$ext;
                 if(move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir.$newFileName)) {
                     $filePath = $newFileName;
                     if (strpos($mime, 'image') !== false) $type = 'image';
                     elseif (strpos($mime, 'audio') !== false) $type = 'voice';
                     elseif (strpos($mime, 'video') !== false) $type = 'video';
                     else $type = 'file';
                 }
             } else {
                 jsonResponse(['status'=>'error', 'message'=>'فرمت فایل مجاز نیست']);
             }
        }

        if ($text || $filePath) {
            $encText = in_array($type, ['sticker', 'event', 'mention_alert']) ? $text : encryptMsg($text);
            $sql = "INSERT INTO messages (conversation_id, sender_id, message_text, file_path, file_type, reply_to_id, forward_from_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$convId, $userId, $encText, $filePath, $type, $replyTo, $forwardFromMsgId]);
            $newId = (int)$pdo->lastInsertId();
            
            if (db_has_column($pdo, 'conversation_participants', 'last_read_message_id')) {
                $pdo->prepare("UPDATE conversation_participants SET last_read_message_id = ? WHERE conversation_id = ? AND user_id = ? AND last_read_message_id < ?")->execute([$newId, $convId, $userId, $newId]);
            }
            $pdo->prepare("UPDATE conversation_status SET is_typing = 0 WHERE user_id = ? AND conversation_id = ?")->execute([$userId, $convId]);
            
            // --- پردازش منشن‌ها ---
            if (!empty($text) && preg_match_all('/@([^\s<]+)/u', strip_tags($text), $matches)) {
                $mentionedUsernames = array_unique($matches[1]);
                $inClause = implode(',', array_fill(0, count($mentionedUsernames), '?'));
                $stmtU = $pdo->prepare("SELECT id, username FROM users WHERE username IN ($inClause)");
                $stmtU->execute($mentionedUsernames);
                $mentionedUsers = $stmtU->fetchAll(PDO::FETCH_ASSOC);

                $mConvId = null;
                try {
                    $mConvId = (int)$pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'mentions_channel_id'")->fetchColumn();
                } catch(Exception $e){}

                $stmtSender = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $stmtSender->execute([$userId]);
                $senderData = $stmtSender->fetch(PDO::FETCH_ASSOC);
                $senderName = $senderData ? trim($senderData['first_name'] . ' ' . $senderData['last_name']) : 'یکی از همکاران';

                $chatTitle = $pdo->query("SELECT title FROM conversations WHERE id = $convId")->fetchColumn() ?: 'یک گفتگو';

                foreach ($mentionedUsers as $mu) {
                    $mId = $mu['id'];
                    if ($mId == $userId) continue; 

                    try {
                        if (db_has_column($pdo, 'user_notifications', 'id')) {
                            $nUrl = "chat.php?jump_conv={$convId}&jump_msg={$newId}";
                            $pdo->prepare("INSERT INTO user_notifications (user_id, title, body, url, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())")
                                ->execute([$mId, "شما منشن شدید!", "{$senderName} شما را در «{$chatTitle}» منشن کرد.", $nUrl]);
                        }
                    } catch(Exception $e){}

                    // 2. ارسال پیام اخطار اختصاصی به کانال مرکزی منشن‌ها
                    if ($mConvId && $mConvId != $convId) {
                        $payload = json_encode([
                            'target_conv_id' => $convId, 
                            'target_msg_id' => $newId, 
                            'username' => $mu['username'],
                            'mentioned_user_id' => $mId, // فیلتر امنیتی اختصاصی کاربر
                            'chat_title' => $chatTitle,
                            'sender_name' => $senderName
                        ], JSON_UNESCAPED_UNICODE);
                        
                        $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_text, file_type) VALUES (?, ?, ?, 'mention_alert')")
                            ->execute([$mConvId, $userId, $payload]);
                    }
                }
            }
            
            jsonResponse(['status'=>'success', 'id'=>$newId, 'is_read'=>0, 'file_type'=>$type, 'file_path'=>$filePath]);
        } else {
            jsonResponse(['status'=>'error', 'message'=>'پیام خالی است']);
        }
    }

    elseif ($action === 'edit_message') {
        $msgId = (int)$_POST['msg_id'];
        $newText = trim($_POST['message'] ?? '');
        
        if (empty($newText)) {
            jsonResponse(['status'=>'error', 'message'=>'متن پیام نمی‌تواند خالی باشد']);
        }
        
        $stmt = $pdo->prepare("SELECT sender_id, file_type FROM messages WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$msgId]);
        $msg = $stmt->fetch();
        
        if (!$msg || $msg['sender_id'] != $userId) {
            jsonResponse(['status'=>'error', 'message'=>'شما فقط می‌توانید پیام‌های متنی خود را ویرایش کنید']);
        }
        
        if (in_array($msg['file_type'], ['sticker', 'event', 'mention_alert'])) {
            jsonResponse(['status'=>'error', 'message'=>'این نوع پیام قابل ویرایش نیست']);
        }
        
        $encText = encryptMsg($newText);
        $pdo->prepare("UPDATE messages SET message_text = ?, is_edited = 1 WHERE id = ?")->execute([$encText, $msgId]);
        jsonResponse(['status'=>'success']);
    }
    
    elseif ($action === 'pin_message') {
        $msgId = (int)$_POST['msg_id'];
        $stmt = $pdo->prepare("SELECT conversation_id FROM messages WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$msgId]);
        $convId = $stmt->fetchColumn();
        
        if ($convId) {
            $roleInConv = isParticipant($pdo, $convId, $userId);
            $stmtC = $pdo->query("SELECT type, pinned_messages FROM conversations WHERE id = $convId");
            $convData = $stmtC->fetch(PDO::FETCH_ASSOC);
            $chatType = $convData['type'];
            
            if ($chatType === 'private' || in_array($roleInConv, ['owner', 'admin']) || $role === 'admin') {
                $pinned = json_decode($convData['pinned_messages'] ?: '[]', true);
                if (!is_array($pinned)) $pinned = [];
                
                if (!in_array($msgId, $pinned)) {
                    $pinned[] = $msgId;
                    if (count($pinned) > 5) array_shift($pinned); 
                    $pdo->prepare("UPDATE conversations SET pinned_messages = ? WHERE id = ?")->execute([json_encode(array_values($pinned)), $convId]);
                }
                jsonResponse(['status'=>'success']);
            } else {
                jsonResponse(['status'=>'error', 'message'=>'فقط مدیران می‌توانند پیام سنجاق کنند']);
            }
        }
        jsonResponse(['status'=>'error', 'message'=>'پیام یافت نشد']);
    }

    elseif ($action === 'unpin_message') {
        $convId = (int)$_POST['conv_id'];
        $msgId = isset($_POST['msg_id']) ? (int)$_POST['msg_id'] : 0;
        $roleInConv = isParticipant($pdo, $convId, $userId);
        
        $stmtC = $pdo->query("SELECT type, pinned_messages FROM conversations WHERE id = $convId");
        $convData = $stmtC->fetch(PDO::FETCH_ASSOC);
        $chatType = $convData['type'];
        
        if ($chatType === 'private' || in_array($roleInConv, ['owner', 'admin']) || $role === 'admin') {
            if ($msgId > 0) {
                $pinned = json_decode($convData['pinned_messages'] ?: '[]', true);
                if (is_array($pinned)) {
                    $pinned = array_values(array_filter($pinned, function($id) use ($msgId) { return $id != $msgId; }));
                    $pdo->prepare("UPDATE conversations SET pinned_messages = ? WHERE id = ?")->execute([json_encode($pinned), $convId]);
                }
            } else {
                 $pdo->prepare("UPDATE conversations SET pinned_messages = '[]' WHERE id = ?")->execute([$convId]);
            }
            jsonResponse(['status'=>'success']);
        } else {
            jsonResponse(['status'=>'error', 'message'=>'مجوز برداشتن سنجاق را ندارید']);
        }
    }

    elseif ($action === 'delete_conversation') {
        $targetId = (int)($_POST['target_id'] ?? 0);
        $chatType = $_POST['chat_type'] ?? 'private';
        $convId = resolveConversationId($pdo, $chatType, $targetId, $userId);
        $pdo->prepare("UPDATE messages SET is_deleted = 1 WHERE conversation_id = ? AND sender_id = ?")->execute([$convId, $userId]);
        jsonResponse(['status'=>'success']);
    }
    
    elseif ($action === 'delete_group') {
        $convId = (int)($_POST['group_id'] ?? 0);
        $roleInConv = isParticipant($pdo, $convId, $userId);
        if ($roleInConv !== 'owner' && $role !== 'admin') {
            jsonResponse(['status'=>'error', 'message'=>'مجوز حذف ندارید']);
        }
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM conversation_participants WHERE conversation_id=?")->execute([$convId]);
        $pdo->prepare("DELETE FROM messages WHERE conversation_id=?")->execute([$convId]);
        $pdo->prepare("DELETE FROM conversations WHERE id=?")->execute([$convId]);
        $pdo->commit();
        jsonResponse(['status'=>'success']);
    }
    
    elseif ($action === 'leave_group') {
        $convId = (int)($_POST['group_id'] ?? 0);
        
        $myRole = isParticipant($pdo, $convId, $userId);
        if (!$myRole) {
            jsonResponse(['status'=>'error', 'message'=>'شما عضو این گفتگو نیستید.']);
        }
        if ($myRole === 'owner') {
            jsonResponse(['status'=>'error', 'message'=>'شما سازنده گروه هستید. باید گروه را حذف کنید یا مالکیت را انتقال دهید.']);
        }
        
        $pdo->prepare("DELETE FROM conversation_participants WHERE conversation_id=? AND user_id=?")->execute([$convId, $userId]);
        
        $fullName = $_SESSION['fullname'] ?? 'کاربر';
        $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_text, file_type) VALUES (?, ?, ?, 'event')")
            ->execute([$convId, $userId, "$fullName گروه را ترک کرد."]);
            
        jsonResponse(['status'=>'success']);
    }

    elseif ($action === 'create_chat') {
        $chatType = $_POST['type'] ?? 'group';
        $title = trim($_POST['name'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $members = $_POST['members'] ?? [];
        if(!is_array($members)) $members = json_decode($members, true) ?? [];

        if (empty($title)) jsonResponse(['status'=>'error', 'message'=>'نام الزامی است']);

        $imagePath = 'default_group.png';
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            if(in_array($ext, ['jpg','jpeg','png'])) {
                $uploadDir = __DIR__ . '/../../public_html/uploads/chat_avatars/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $newName = uniqid('g_') . '.' . $ext;
                if(move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $newName)) {
                    $imagePath = $newName;
                }
            }
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO conversations (type, title, description, avatar, creator_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$chatType, $title, $bio, $imagePath, $userId]);
        $convId = $pdo->lastInsertId();
        
        $stmtMem = $pdo->prepare("INSERT INTO conversation_participants (conversation_id, user_id, role) VALUES (?, ?, ?)");
        $stmtMem->execute([$convId, $userId, 'owner']); 
        
        foreach($members as $mid) {
            if($mid != $userId) $stmtMem->execute([$convId, $mid, 'member']);
        }

        $sysMsg = ($chatType == 'channel') ? 'کانال ایجاد شد' : 'گروه ایجاد شد';
        $pdo->prepare("INSERT INTO messages (conversation_id, sender_id, message_text, file_type) VALUES (?, ?, ?, 'event')")->execute([$convId, $userId, $sysMsg]);
        
        $pdo->commit();
        jsonResponse(['status'=>'success', 'message'=>'ایجاد شد', 'id'=>$convId]);
    }

    elseif ($action === 'edit_group') {
        $convId = (int)$_POST['group_id'];
        $title = trim($_POST['name']);
        $bio = trim($_POST['bio'] ?? '');

        if (empty($title)) jsonResponse(['status'=>'error', 'message'=>'نام الزامی است']);

        $roleInConv = isParticipant($pdo, $convId, $userId);
        if (!in_array($roleInConv, ['owner', 'admin']) && $role !== 'admin') {
            jsonResponse(['status'=>'error', 'message'=>'دسترسی ویرایش ندارید']);
        }

        $stmtCheck = $pdo->prepare("SELECT avatar FROM conversations WHERE id=?");
        $stmtCheck->execute([$convId]);
        $imagePath = $stmtCheck->fetchColumn();

        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            if(in_array($ext, ['jpg','jpeg','png'])) {
                $uploadDir = __DIR__ . '/../../public_html/uploads/chat_avatars/';
                $newName = uniqid('g_') . '.' . $ext;
                if(move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadDir . $newName)) $imagePath = $newName;
            }
        }
        $pdo->prepare("UPDATE conversations SET title=?, description=?, avatar=? WHERE id=?")->execute([$title, $bio, $imagePath, $convId]);
        jsonResponse(['status'=>'success', 'message'=>'تغییرات ذخیره شد']);
    }

    elseif ($action === 'add_group_members') {
        $convId = (int)$_POST['group_id'];
        $members = $_POST['members'] ?? [];
        if (!is_array($members)) $members = json_decode($members, true) ?? [];

        $roleInConv = isParticipant($pdo, $convId, $userId);
        if (!in_array($roleInConv, ['owner', 'admin']) && $role !== 'admin') {
            jsonResponse(['status'=>'error', 'message'=>'فقط مدیران می‌توانند عضو اضافه کنند.']);
        }

        $stmtAdd = $pdo->prepare("INSERT IGNORE INTO conversation_participants (conversation_id, user_id, role) VALUES (?, ?, 'member')");
        foreach($members as $mid) {
            $stmtAdd->execute([$convId, $mid]);
        }
        jsonResponse(['status'=>'success', 'message'=>'اعضای جدید با موفقیت اضافه شدند.']);
    }

    elseif ($action === 'manage_group_member') {
        $convId = (int)$_POST['group_id'];
        $targetUserId = (int)$_POST['user_id'];
        $do = $_POST['do'] ?? ''; 

        $myRoleInConv = isParticipant($pdo, $convId, $userId);
        if ($role !== 'admin' && !in_array($myRoleInConv, ['owner', 'admin'])) {
            jsonResponse(['status'=>'error', 'message'=>'شما مدیر این گروه/کانال نیستید!']);
        }

        $targetRole = isParticipant($pdo, $convId, $targetUserId);
        if ($targetRole === 'owner') {
            jsonResponse(['status'=>'error', 'message'=>'عملیات روی مالک گروه امکان‌پذیر نیست!']);
        }

        if ($do === 'promote') {
            if ($myRoleInConv !== 'owner' && $role !== 'admin') jsonResponse(['status'=>'error', 'message'=>'فقط مالک گروه می‌تواند مدیر تعیین کند.']);
            $pdo->prepare("UPDATE conversation_participants SET role = 'admin' WHERE conversation_id = ? AND user_id = ?")->execute([$convId, $targetUserId]);
            jsonResponse(['status'=>'success', 'message'=>'کاربر به مدیر ارتقا یافت.']);
        } 
        elseif ($do === 'demote') {
            if ($myRoleInConv !== 'owner' && $role !== 'admin') jsonResponse(['status'=>'error', 'message'=>'فقط مالک گروه می‌تواند مدیر را عزل کند.']);
            $pdo->prepare("UPDATE conversation_participants SET role = 'member' WHERE conversation_id = ? AND user_id = ?")->execute([$convId, $targetUserId]);
            jsonResponse(['status'=>'success', 'message'=>'کاربر به عضو عادی تبدیل شد.']);
        } 
        elseif ($do === 'kick') {
            $pdo->prepare("DELETE FROM conversation_participants WHERE conversation_id = ? AND user_id = ?")->execute([$convId, $targetUserId]);
            jsonResponse(['status'=>'success', 'message'=>'کاربر با موفقیت اخراج شد.']);
        }
    }

    elseif ($action === 'delete_message') {
        $msgId = (int)$_POST['msg_id'];
        $stmt = $pdo->prepare("SELECT sender_id FROM messages WHERE id = ?");
        $stmt->execute([$msgId]);
        $msg = $stmt->fetch();
        if ($msg && ($msg['sender_id'] == $userId || $role === 'admin')) {
            $pdo->prepare("UPDATE messages SET is_deleted = 1 WHERE id = ?")->execute([$msgId]);
            jsonResponse(['status'=>'success']);
        } else {
            jsonResponse(['status'=>'error', 'message'=>'مجوز حذف ندارید']);
        }
    }
    
    elseif ($action === 'react_message') {
        $msgId = (int)$_POST['msg_id'];
        $emoji = $_POST['emoji'];
        $stmt = $pdo->prepare("SELECT reactions FROM messages WHERE id=?");
        $stmt->execute([$msgId]);
        $current = json_decode($stmt->fetchColumn() ?? '[]', true);
        if(!is_array($current)) $current = [];
        
        $found = false;
        foreach($current as $k => $r) {
            if($r['user'] == $userId) {
                if($r['emoji'] == $emoji) unset($current[$k]); 
                else $current[$k]['emoji'] = $emoji; 
                $found = true; break;
            }
        }
        if(!$found) $current[] = ['user'=>$userId, 'emoji'=>$emoji];
        $pdo->prepare("UPDATE messages SET reactions=? WHERE id=?")->execute([json_encode(array_values($current)), $msgId]);
        jsonResponse(['status'=>'success']);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['status'=>'error', 'message'=>$e->getMessage()]);
}
?>