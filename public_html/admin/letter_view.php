<?php
/*
 * فایل: public_html/admin/letter_view.php
 * نسخه نهایی: مدیریت هوشمند دسترسی‌ها و اصلاح کامل حاشیه‌های صفحات در پرینت (هدر و فوتر)
 */

ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }

$userId = (int)$_SESSION['user_id'];
$isAdmin = (in_array($_SESSION['role'] ?? '', ['admin', 'manager']));

$letterId = (int)($_GET['id'] ?? 0);
$refId = (int)($_GET['ref_id'] ?? 0);

$msgParam = $_GET['msg'] ?? '';
if ($msgParam === 'sign_success') {
    $msg = "✅ تنظیمات امضا اعمال و شماره نامه صادر شد.";
    $msgType = "success";
} elseif ($msgParam === 'unsign_success') {
    $msg = "🔓 امضای نامه لغو و نامه جهت ویرایش بازگشایی شد.";
    $msgType = "success";
} elseif ($msgParam === 'approve_success') {
    $msg = "✅ نامه با موفقیت تایید و به جریان افتاد.";
    $msgType = "success";
} elseif ($msgParam === 'accepted_for_sign') {
    $msg = "✅ نامه تایید شد و جهت درج امضا به میز کار شما منتقل گردید.";
    $msgType = "success";
} elseif ($msgParam === 'archive_success') {
    $msg = "🗄️ نامه با موفقیت بایگانی شد.";
    $msgType = "success";
} elseif ($msgParam === 'terminate_success') {
    $msg = "✅ ارجاع شما با موفقیت مختومه شد.";
    $msgType = "success";
}

if ($letterId <= 0) die('<div style="text-align:center; padding:50px; font-family:Tahoma;">شناسه نامه نامعتبر است.</div>');

$msg = $msg ?? '';
$msgType = $msgType ?? '';

if ($refId > 0) {
    try {
        $stmtCheck = $pdo->prepare("SELECT id, is_read, is_completed FROM letter_referrals WHERE id = ? AND receiver_id = ?");
        $stmtCheck->execute([$refId, $userId]);
        $currentRef = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$currentRef && !$isAdmin) {
            die('<div style="text-align:center; padding:50px; font-family:Tahoma; color:red;">شما دسترسی مشاهده این ارجاع را ندارید.</div>');
        }

        if ($currentRef && $currentRef['is_read'] == 0) {
            $pdo->prepare("UPDATE letter_referrals SET is_read = 1 WHERE id = ?")->execute([$refId]);
            try { $pdo->prepare("UPDATE letter_referrals SET read_at = NOW() WHERE id = ?")->execute([$refId]); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
}

$stmtAnySigned = $pdo->prepare("SELECT COUNT(*) FROM letter_signers WHERE letter_id = ? AND status = 'signed'");
$stmtAnySigned->execute([$letterId]);
$anySigned = ($stmtAnySigned->fetchColumn() > 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) die("خطای امنیتی CSRF.");
    $action = $_POST['action_type'] ?? '';

    try {
        $pdo->beginTransaction();

        if ($action === 'approve_letter') {
            if (!$isAdmin) throw new Exception("فقط مدیریت می‌تواند نامه را تایید کند.");
            $pdo->prepare("UPDATE letters SET status = 'in_referral' WHERE id = ?")->execute([$letterId]);
            
            $stmtSigs = $pdo->prepare("SELECT user_id FROM letter_signers WHERE letter_id = ?");
            $stmtSigs->execute([$letterId]);
            $signersList = $stmtSigs->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($signersList)) {
                $stmtCheckDuplicate = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND receiver_id = ? AND action_type = 'for_signature' AND is_completed = 0");
                $stmtRef = $pdo->prepare("INSERT INTO letter_referrals (letter_id, sender_id, receiver_id, action_type, description, is_completed, created_at) VALUES (?, ?, ?, 'for_signature', 'ارجاع سیستمی جهت بررسی و تایید امضا', 0, NOW())");
                foreach ($signersList as $sigId) {
                    $stmtCheckDuplicate->execute([$letterId, $sigId]);
                    if ($stmtCheckDuplicate->fetchColumn() == 0) {
                        $stmtRef->execute([$letterId, $userId, $sigId]);
                        if (function_exists('send_user_notification')) {
                            send_user_notification($pdo, $sigId, "✍️ درخواست امضا", "یک نامه صادره جهت بررسی و تایید امضا به کارتابل شما ارجاع شد.", "admin/letter_view.php?id={$letterId}", 'letter', $letterId);
                        }
                    }
                }
            }

            $pdo->commit();
            header("Location: letter_view.php?id=$letterId&msg=approve_success");
            exit;
        }
        elseif ($action === 'accept_for_sign') {
            $pdo->prepare("UPDATE letter_referrals SET is_completed = 1, completed_at = NOW(), completion_note = 'تایید جهت درج امضا' WHERE letter_id = ? AND receiver_id = ? AND action_type = 'for_signature'")->execute([$letterId, $userId]);
            $pdo->prepare("UPDATE letter_signers SET status = 'accepted' WHERE letter_id = ? AND user_id = ?")->execute([$letterId, $userId]);
            $pdo->commit();
            header("Location: letter_view.php?id=$letterId&msg=accepted_for_sign");
            exit;
        }
        elseif ($action === 'approve_internal') {
            $stmtL = $pdo->prepare("SELECT type, department_prefix FROM letters WHERE id = ?");
            $stmtL->execute([$letterId]);
            $lData = $stmtL->fetch(PDO::FETCH_ASSOC);
            
            $activeFiscalYear = getActiveFiscalYear();
            $fiscalYearId = $activeFiscalYear ? $activeFiscalYear['id'] : null;
            if (!$fiscalYearId) throw new Exception("سال مالی فعال یافت نشد.");

            $basePrefix = $lData['department_prefix'] ?: 'الف';
            $typeLetter = ($lData['type'] === 'internal') ? 'د' : 'و';
            $fullPrefix = $basePrefix . '/' . $typeLetter;

            $stmtInd = $pdo->prepare("SELECT id, last_sequence FROM letter_indicators WHERE fiscal_year_id = ? AND department_prefix = ? FOR UPDATE");
            $stmtInd->execute([$fiscalYearId, $basePrefix]);
            $indRow = $stmtInd->fetch(PDO::FETCH_ASSOC);

            if ($indRow) {
                $nextSeq = (int)$indRow['last_sequence'] + 1;
                $pdo->prepare("UPDATE letter_indicators SET last_sequence = ? WHERE id = ?")->execute([$nextSeq, $indRow['id']]);
            } else {
                $nextSeq = 1;
                $pdo->prepare("INSERT INTO letter_indicators (fiscal_year_id, department_prefix, last_sequence) VALUES (?, ?, 1)")->execute([$fiscalYearId, $basePrefix]);
            }

            $jy = function_exists('jdate') ? jdate('Y', '', '', '', 'en') : date('Y'); 
            $jm = function_exists('jdate') ? jdate('m', '', '', '', 'en') : date('m'); 
            $datePart = substr($jy, 1, 3) . $jm; 
            $seqPart = str_pad($nextSeq, 3, '0', STR_PAD_LEFT); 
            
            $newIndicatorNumber = "{$fullPrefix}-{$seqPart}-{$datePart}";
            
            $pdo->prepare("UPDATE letters SET status = 'registered', indicator_number = ?, registered_at = NOW() WHERE id = ?")
                ->execute([$newIndicatorNumber, $letterId]);

            $stmtRecs = $pdo->prepare("SELECT receiver_id FROM letter_receivers WHERE letter_id = ? AND receiver_type = 'user'");
            $stmtRecs->execute([$letterId]);
            $receivers = $stmtRecs->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($receivers)) {
                $stmtCheckDuplicate = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND receiver_id = ? AND is_completed = 0");
                $stmtRef = $pdo->prepare("INSERT INTO letter_referrals (letter_id, sender_id, receiver_id, action_type, description, is_completed, created_at) VALUES (?, ?, ?, 'for_action', 'ارجاع خودکار سیستمی پس از صدور شماره', 0, NOW())");
                foreach ($receivers as $recId) {
                    $stmtCheckDuplicate->execute([$letterId, $recId]);
                    if ($stmtCheckDuplicate->fetchColumn() == 0) {
                        $stmtRef->execute([$letterId, $userId, $recId]);
                        if (function_exists('send_user_notification')) {
                            send_user_notification($pdo, $recId, "📥 نامه جدید: {$newIndicatorNumber}", "نامه‌ای جهت بررسی و اقدام به کارتابل شما ارجاع شد.", "admin/letter_view.php?id={$letterId}", 'letter', $letterId);
                        }
                    }
                }
            }

            $pdo->commit();
            header("Location: letter_view.php?id=$letterId&msg=approve_success");
            exit;
        }
        elseif ($action === 'terminate_letter') {
            if ($refId > 0) {
                $pdo->prepare("UPDATE letter_referrals SET is_completed = 1, completed_at = NOW(), completion_note = 'مختومه شده در کارتابل' WHERE id = ?")->execute([$refId]);
            }
            $pdo->commit();
            header("Location: letter_view.php?id=$letterId" . ($refId > 0 ? "&ref_id=$refId" : "") . "&msg=terminate_success");
            exit;
        }
        elseif ($action === 'archive_letter') {
            $stmtCheckArc = $pdo->prepare("SELECT type, indicator_number FROM letters WHERE id = ?");
            $stmtCheckArc->execute([$letterId]);
            $arcData = $stmtCheckArc->fetch(PDO::FETCH_ASSOC);
            
            if (in_array($arcData['type'], ['internal', 'incoming']) && empty($arcData['indicator_number'])) {
                throw new Exception("نامه‌های داخلی و وارده قبل از تایید و شماره‌گذاری قابل بایگانی نیستند.");
            }

            $pdo->prepare("UPDATE letters SET is_archived = 1, status = 'registered' WHERE id = ?")->execute([$letterId]);
            $pdo->commit();
            header("Location: letter_view.php?id=$letterId&msg=archive_success");
            exit;
        }
        elseif ($action === 'delete_letter') {
            $stmtCreator = $pdo->prepare("SELECT created_by, type, indicator_number FROM letters WHERE id = ?");
            $stmtCreator->execute([$letterId]);
            $lData = $stmtCreator->fetch(PDO::FETCH_ASSOC);
            
            if ($anySigned) throw new Exception("این نامه امضا شده و غیرقابل حذف است.");

            $refCountCheck = 0;
            try {
                $stmtRefCheck = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ?");
                $stmtRefCheck->execute([$letterId]);
                $refCountCheck = $stmtRefCheck->fetchColumn();
            } catch (Throwable $e) {}

            if (!$isAdmin) {
                if ($lData['created_by'] != $userId) throw new Exception("شما مجوز حذف این نامه را ندارید.");
                if (!empty($lData['indicator_number']) && in_array($lData['type'], ['incoming', 'internal'])) {
                    throw new Exception("نامه‌های وارده و داخلی پس از دریافت شماره، فقط توسط مدیر قابل حذف هستند.");
                }
                if ($lData['type'] === 'internal' && $refCountCheck > 0) throw new Exception("این نامه داخلی قبلاً ارجاع داده شده است و فقط ادمین حق حذف آن را دارد.");
            }

            $pdo->prepare("UPDATE letters SET is_deleted = 1 WHERE id = ?")->execute([$letterId]);
            $pdo->commit();
            header("Location: cartable_inbox.php");
            exit;
        }
        elseif ($action === 'sign_letter') {
            $stmtCheckType = $pdo->prepare("SELECT type, indicator_number, department_prefix FROM letters WHERE id = ?");
            $stmtCheckType->execute([$letterId]);
            $letterData = $stmtCheckType->fetch();

            if ($letterData['type'] !== 'outgoing') throw new Exception("ثبت امضا فقط برای نامه‌های صادره امکان‌پذیر است.");

            $enteredPin = trim($_POST['pin_code'] ?? '');
            $stmtPinCheck = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'signature_pin_$userId'");
            $hasPin = $stmtPinCheck->fetchColumn();

            if (!$hasPin) {
                $enteredPinEn = faToEn($enteredPin);
                if ($enteredPinEn !== '1234') {
                    throw new Exception("پین‌کد وارد شده اشتباه است! (رمز پیش‌فرض سیستم 1234 است. لطفاً از تنظیمات تغییر دهید)");
                }
            } else {
                if (!verify_user_pin($pdo, $userId, $enteredPin)) {
                    throw new Exception("پین‌کد وارد شده اشتباه است!");
                }
            }

            $signType = $_POST['sign_type'] ?? 'simple';
            $useLetterhead = isset($_POST['use_letterhead']) ? 1 : 0;
            
            $pdo->prepare("UPDATE letter_signers SET status = 'signed', signed_at = NOW(), sign_type = ? WHERE letter_id = ? AND user_id = ?")
                ->execute([$signType, $letterId, $userId]);

            $pdo->prepare("INSERT INTO letter_referrals (letter_id, sender_id, receiver_id, action_type, description, is_completed, completed_at, completion_note) VALUES (?, ?, ?, 'for_signature', 'ثبت امضای دیجیتال', 1, NOW(), 'امضای این شخص با موفقیت ثبت شد.')")
                ->execute([$letterId, $userId, $userId]);

            $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM letter_signers WHERE letter_id = ? AND status != 'signed'");
            $stmtPending->execute([$letterId]);
            $pendingCount = $stmtPending->fetchColumn();

            if ($pendingCount == 0) {
                $newIndicatorNumber = $letterData['indicator_number'];
                if (empty($newIndicatorNumber)) {
                    $activeFiscalYear = getActiveFiscalYear();
                    $fiscalYearId = $activeFiscalYear ? $activeFiscalYear['id'] : null;
                    if (!$fiscalYearId) throw new Exception("سال مالی فعال نیست.");

                    $basePrefix = $letterData['department_prefix'] ?: 'الف';
                    $fullPrefix = $basePrefix . '/ص';

                    $stmtInd = $pdo->prepare("SELECT id, last_sequence FROM letter_indicators WHERE fiscal_year_id = ? AND department_prefix = ? FOR UPDATE");
                    $stmtInd->execute([$fiscalYearId, $basePrefix]);
                    $indRow = $stmtInd->fetch(PDO::FETCH_ASSOC);

                    if ($indRow) {
                        $nextSeq = (int)$indRow['last_sequence'] + 1;
                        $pdo->prepare("UPDATE letter_indicators SET last_sequence = ? WHERE id = ?")->execute([$nextSeq, $indRow['id']]);
                    } else {
                        $nextSeq = 1;
                        $pdo->prepare("INSERT INTO letter_indicators (fiscal_year_id, department_prefix, last_sequence) VALUES (?, ?, 1)")->execute([$fiscalYearId, $basePrefix]);
                    }

                    $jy = function_exists('jdate') ? jdate('Y', '', '', '', 'en') : date('Y'); 
                    $jm = function_exists('jdate') ? jdate('m', '', '', '', 'en') : date('m'); 
                    $datePart = substr($jy, 1, 3) . $jm; 
                    $seqPart = str_pad($nextSeq, 3, '0', STR_PAD_LEFT); 
                    $newIndicatorNumber = "{$fullPrefix}-{$seqPart}-{$datePart}"; 
                }

                $pdo->prepare("UPDATE letters SET signature_status = ?, use_letterhead = ?, indicator_number = COALESCE(indicator_number, ?), status = 'registered', registered_at = COALESCE(registered_at, NOW()) WHERE id = ?")
                    ->execute([$signType, $useLetterhead, $newIndicatorNumber, $letterId]);

                $stmtRecs = $pdo->prepare("SELECT receiver_id FROM letter_receivers WHERE letter_id = ? AND receiver_type = 'user'");
                $stmtRecs->execute([$letterId]);
                $receivers = $stmtRecs->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($receivers)) {
                    $stmtCheckDuplicate = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND receiver_id = ? AND is_completed = 0");
                    $stmtRef = $pdo->prepare("INSERT INTO letter_referrals (letter_id, sender_id, receiver_id, action_type, description, is_completed, created_at) VALUES (?, ?, ?, 'for_action', 'ارجاع خودکار سیستمی پس از امضای نهایی و صدور شماره', 0, NOW())");
                    foreach ($receivers as $recId) {
                        $stmtCheckDuplicate->execute([$letterId, $recId]);
                        if ($stmtCheckDuplicate->fetchColumn() == 0) { 
                            $stmtRef->execute([$letterId, $userId, $recId]);
                            if (function_exists('send_user_notification')) {
                                send_user_notification($pdo, $recId, "📥 نامه جدید: {$newIndicatorNumber}", "نامه‌ای جهت بررسی و اقدام به کارتابل شما ارجاع شد.", "admin/letter_view.php?id={$letterId}", 'letter', $letterId);
                            }
                        }
                    }
                }
            } else {
                $pdo->prepare("UPDATE letters SET signature_status = 'partial', use_letterhead = ? WHERE id = ?")->execute([$useLetterhead, $letterId]);
            }

            $pdo->commit();
            $redirectUrl = "letter_view.php?id=" . $letterId . ($refId > 0 ? "&ref_id=$refId" : "") . "&msg=sign_success";
            header("Location: $redirectUrl");
            exit;
        }
        elseif ($action === 'remove_sign') {
            if (!$isAdmin) throw new Exception("فقط ادمین می‌تواند امضا را لغو کند.");

            $enteredPin = trim($_POST['pin_code_unsign'] ?? '');
            $stmtPinCheck = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'signature_pin_$userId'");
            $hasPin = $stmtPinCheck->fetchColumn();

            if (!$hasPin) {
                $enteredPinEn = faToEn($enteredPin);
                if ($enteredPinEn !== '1234') {
                    throw new Exception("پین‌کد وارد شده اشتباه است! (رمز پیش‌فرض 1234 است)");
                }
            } else {
                if (!verify_user_pin($pdo, $userId, $enteredPin)) {
                    throw new Exception("پین‌کد وارد شده اشتباه است!");
                }
            }

            $pdo->prepare("UPDATE letter_signers SET status = 'pending', signed_at = NULL WHERE letter_id = ?")->execute([$letterId]);
            $pdo->prepare("UPDATE letters SET status = 'pending_action', indicator_number = NULL, signature_status = 'none', use_letterhead = 0 WHERE id = ?")->execute([$letterId]);
            $pdo->commit();
            $redirectUrl = "letter_view.php?id=" . $letterId . ($refId > 0 ? "&ref_id=$refId" : "") . "&msg=unsign_success";
            header("Location: $redirectUrl");
            exit;
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $msg = "خطا: " . $e->getMessage();
        $msgType = "error";
    }
}

$stmtLetter = $pdo->prepare("SELECT l.*, u.first_name as c_fn, u.last_name as c_ln FROM letters l LEFT JOIN users u ON l.created_by = u.id WHERE l.id = ?");
$stmtLetter->execute([$letterId]);
$letter = $stmtLetter->fetch(PDO::FETCH_ASSOC);

if (!$letter) die("نامه یافت نشد.");

$useLetterhead = (int)($letter['use_letterhead'] ?? 0);
$sigStatus = $letter['signature_status'] ?? 'none';
$hasIndicator = !empty($letter['indicator_number']);
$isIncomingOrInternal = in_array($letter['type'], ['incoming', 'internal']);

$refCount = 0;
try {
    $stmtRefCheckGlobal = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ?");
    $stmtRefCheckGlobal->execute([$letterId]);
    $refCount = $stmtRefCheckGlobal->fetchColumn();
} catch (Throwable $e) {}

// بررسی اینکه آیا نامه در کارتابل شخص دیگری باز و در انتظار است؟
$hasPendingReferrals = false;
try {
    $stmtPendingRefs = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND is_completed = 0");
    $stmtPendingRefs->execute([$letterId]);
    $hasPendingReferrals = ($stmtPendingRefs->fetchColumn() > 0);
} catch (Throwable $e) {}

try {
    if (isset($letter['is_archived']) && $letter['is_archived'] == 0 && isset($letter['is_deleted']) && $letter['is_deleted'] == 0) {
        $stmtLastAct = $pdo->prepare("SELECT MAX(created_at) FROM letter_referrals WHERE letter_id = ?");
        $stmtLastAct->execute([$letterId]);
        $lastInteraction = $stmtLastAct->fetchColumn();
        
        $baseDate = $lastInteraction ?: $letter['created_at'];
        $baseDateTs = strtotime((string)$baseDate);
        
        if ($baseDateTs !== false && $baseDateTs > 0) {
            $daysPassed = (time() - $baseDateTs) / 86400; 
            if ($daysPassed >= 15 && !$hasPendingReferrals) {
                $pdo->prepare("UPDATE letters SET is_archived = 1, status = 'registered' WHERE id = ?")->execute([$letterId]);
                $letter['is_archived'] = 1;
                $letter['status'] = 'registered';
                if (function_exists('logSystem')) logSystem('Letters', 'auto_archive', $letterId, "بایگانی خودکار سیستم");
            }
        }
    }
} catch (Throwable $e) {}

// ---- مدیریت هوشمند دکمه ویرایش ----
$canEdit = false;
if (!$anySigned) {
    if (empty($letter['indicator_number'])) {
        if ($isAdmin || $letter['created_by'] == $userId) $canEdit = true;
        if ($refId > 0 && isset($currentRef) && $currentRef['is_completed'] == 0 && $letter['type'] === 'outgoing') $canEdit = true;
    } else {
        // نامه‌های داخلی و وارده حتی پس از گرفتن شماره، برای نقش ادمین قابل ویرایش باشند
        if ($isAdmin && in_array($letter['type'], ['incoming', 'internal'])) {
            $canEdit = true;
        }
    }
}

// ---- مدیریت هوشمند دکمه حذف ----
$canDelete = false;
if (!$anySigned) {
    if ($isAdmin) {
        $canDelete = true;
    } else {
        // کاربر عادی فقط نامه‌های شماره نخورده و ارجاع نشده خودش را می‌تواند حذف کند
        if ($letter['created_by'] == $userId && !$hasIndicator && $refCount == 0) {
            $canDelete = true;
        }
    }
}

// ---- مدیریت هوشمند دکمه مختومه ----
$canTerminate = false;
if ($refId > 0 && isset($currentRef) && $currentRef['is_completed'] == 0) {
    $canTerminate = true;
    if ($letter['type'] === 'outgoing' && empty($letter['indicator_number'])) {
        $canTerminate = false;
    }
}

// ---- مدیریت هوشمند دکمه بایگانی ----
$canArchive = false;
if ((isset($letter['is_archived']) && $letter['is_archived'] == 0) && !$hasPendingReferrals) {
    if ($isAdmin || $letter['created_by'] == $userId) {
        if (in_array($letter['type'], ['incoming', 'internal']) && !empty($letter['indicator_number'])) {
            $canArchive = true;
        } elseif ($letter['type'] === 'outgoing' && $sigStatus !== 'none' && !empty($letter['indicator_number'])) {
            $canArchive = true;
        }
    }
}

$canApproveOutgoing = empty($letter['indicator_number']) && $letter['type'] === 'outgoing' && ($isAdmin || $letter['created_by'] == $userId) && !in_array($letter['status'], ['approved_for_sign', 'in_referral', 'registered']);
$canApproveInternal = empty($letter['indicator_number']) && in_array($letter['type'], ['internal', 'incoming']) && ($isAdmin || $letter['created_by'] == $userId);

$isSignerPending = false;
$isSignerAccepted = false;
$hasSigned = false;
if ($letter['type'] === 'outgoing') {
    $stmtCheckSig = $pdo->prepare("SELECT status FROM letter_signers WHERE letter_id = ? AND user_id = ?");
    $stmtCheckSig->execute([$letterId, $userId]);
    $sigRowStatus = $stmtCheckSig->fetchColumn();
    
    if ($sigRowStatus === 'pending') {
        $stmtCheckRef = $pdo->prepare("SELECT COUNT(*) FROM letter_referrals WHERE letter_id = ? AND receiver_id = ? AND action_type = 'for_signature' AND is_completed = 0");
        $stmtCheckRef->execute([$letterId, $userId]);
        if ($stmtCheckRef->fetchColumn() > 0) $isSignerPending = true;
    } elseif ($sigRowStatus === 'accepted') {
        $isSignerAccepted = true;
    } elseif ($sigRowStatus === 'signed') {
        $hasSigned = true;
    }
}

$senderDisplay = 'نامشخص';
try {
    if ($letter['type'] === 'incoming') {
        $senderDisplay = $letter['sender_external_name'] ?: 'مشتری نامشخص';
    } else {
        $sId = $letter['sender_id'] ?: $letter['created_by'];
        $stmtS = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id=?");
        $stmtS->execute([$sId]);
        $sU = $stmtS->fetch();
        if ($sU) $senderDisplay = $sU['first_name'] . ' ' . $sU['last_name'];
    }
} catch (Throwable $e) {}

$recNames = [];
try {
    $stmtRecs = $pdo->prepare("SELECT u.first_name, u.last_name, c.company_name FROM letter_receivers lr LEFT JOIN users u ON lr.receiver_type = 'user' AND lr.receiver_id = u.id LEFT JOIN customers c ON lr.receiver_type = 'external' AND lr.receiver_id = c.id WHERE lr.letter_id = ?");
    $stmtRecs->execute([$letterId]);
    $recList = $stmtRecs->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach($recList as $r) {
        if (!empty($r['company_name'])) $recNames[] = $r['company_name'];
        elseif (!empty($r['first_name'])) $recNames[] = $r['first_name'] . ' ' . $r['last_name'];
    }
} catch (Throwable $e) {}
$receiverDisplay = !empty($recNames) ? implode('، ', $recNames) : 'نامشخص';

$timeline = [];
try {
    $sqlTimeline = "SELECT r.*, s.first_name as s_fn, s.last_name as s_ln, rec.first_name as r_fn, rec.last_name as r_ln FROM letter_referrals r LEFT JOIN users s ON r.sender_id = s.id LEFT JOIN users rec ON r.receiver_id = rec.id WHERE r.letter_id = ? ORDER BY r.created_at ASC";
    $stmtTimeline = $pdo->prepare($sqlTimeline);
    $stmtTimeline->execute([$letterId]);
    $timeline = $stmtTimeline->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$attachments = [];
try {
    $stmtAttach = $pdo->prepare("SELECT * FROM letter_attachments WHERE letter_id = ?");
    $stmtAttach->execute([$letterId]);
    $attachments = $stmtAttach->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$printIndicator = $letter['indicator_number'] ?: '---';
$printIndicator = str_replace(['0','1','2','3','4','5','6','7','8','9'], ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], $printIndicator);

$printDate = '---';
if (!empty($letter['registered_at'])) {
    $ts = strtotime((string)$letter['registered_at']);
    $printDate = ($ts !== false && function_exists('jdate')) ? jdate('Y/m/d', $ts, '', '', 'fa') : $letter['registered_at'];
} elseif (!empty($letter['created_at'])) {
    $ts = strtotime((string)$letter['created_at']);
    $printDate = ($ts !== false && function_exists('jdate')) ? jdate('Y/m/d', $ts, '', '', 'fa') : $letter['created_at'];
}

$stmtSigners = $pdo->prepare("SELECT ls.user_id, ls.sign_type FROM letter_signers ls WHERE ls.letter_id = ? AND ls.status = 'signed' ORDER BY ls.id ASC");
$stmtSigners->execute([$letterId]);
$signedUsers = $stmtSigners->fetchAll(PDO::FETCH_ASSOC);

function getUserSigSettings($pdo, $uid) {
    $st = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE '%_$uid'");
    $res = [];
    while($r = $st->fetch()) {
        $key = str_replace("_$uid", '', $r['setting_key']);
        $res[$key] = $r['setting_value'];
    }
    return $res;
}

$signatureHtml = '';
if (count($signedUsers) > 0 && $letter['type'] === 'outgoing') {
    // تنظیم فاصله بالا برای امضا (نزدیک شدن به پاراگراف آخر)
    $signatureHtml = '<table style="width: 100%; margin-top: 5px; page-break-inside: avoid; border: none !important; background:transparent;" class="signature-table"><tr>';
    
    $signerHtmls = [];
    foreach ($signedUsers as $su) {
        $uid = $su['user_id'];
        $uSet = getUserSigSettings($pdo, $uid);
        
        $sName = $uSet['signer_name'] ?? 'نامشخص';
        $sTitle = $uSet['signer_title'] ?? 'سمت نامشخص';
        $sType = $su['sign_type'];
        
        $h = '<div style="position: relative; display: inline-block; text-align: center; min-width: 150px; background:transparent;">';
        $h .= '<div style="display:flex; justify-content:center; align-items:flex-end; gap:10px; margin-bottom: 10px; height: 120px; background:transparent;">';
        if (in_array($sType, ['sign', 'both']) && !empty($uSet['signature_img'])) {
            $h .= '<img src="../uploads/settings/'.$uSet['signature_img'].'" style="max-height:100px; object-fit: contain; mix-blend-mode: multiply;">';
        }
        if (in_array($sType, ['stamp', 'both']) && !empty($uSet['stamp_img'])) {
            $h .= '<img src="../uploads/settings/'.$uSet['stamp_img'].'" style="max-height:100px; object-fit: contain; mix-blend-mode: multiply;">';
        }
        $h .= '</div>';
        $h .= '<strong style="font-size:12pt; color:#000; display:block; background:transparent;">' . htmlspecialchars($sName) . '</strong>';
        $h .= '<strong style="font-size:10pt; color:#000; display:block; background:transparent;">' . htmlspecialchars($sTitle) . '</strong>';
        $h .= '</div>';
        $signerHtmls[] = $h;
    }

    if (count($signerHtmls) == 1) {
        $signatureHtml .= '<td style="width: 50%; text-align: right; border: none !important; background:transparent;"></td>';
        $signatureHtml .= '<td style="width: 50%; text-align: left; border: none !important; background:transparent; padding-left:2cm;">' . $signerHtmls[0] . '</td>';
    } else {
        $signatureHtml .= '<td style="width: 50%; text-align: right; border: none !important; background:transparent; padding-right:2cm;">' . $signerHtmls[1] . '</td>';
        $signatureHtml .= '<td style="width: 50%; text-align: left; border: none !important; background:transparent; padding-left:2cm;">' . $signerHtmls[0] . '</td>';
    }
    $signatureHtml .= '</tr></table>';
}

$stmtLh = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'letterhead_img'");
$globalLetterhead = $stmtLh->fetchColumn();

$pageTitle = 'مشاهده نامه: ' . htmlspecialchars($letter['indicator_number'] ?? 'پیش‌نویس');
$basePath = '../';

$printBackgroundCss = '';
if ($useLetterhead == 1 && !empty($globalLetterhead)) {
    $letterheadUrl = "../uploads/settings/" . $globalLetterhead;
    $printBackgroundCss = '
    #print-container {
        position: relative;
        background: #fff !important;
    }
    #print-container::before {
        content: "";
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-image: url("' . $letterheadUrl . '") !important;
        background-size: 210mm 297mm !important;
        background-position: center top !important;
        background-repeat: no-repeat !important;
        background-attachment: scroll !important;
        z-index: -1;
        pointer-events: none;
    }
    .print-layout td, .print-layout div, .print-layout * {
        background: transparent !important;
    }
    ';
}

$extraCss = '<style>
    @font-face { font-family: "Vazirmatn"; src: url("../assets/fonts/Vazirmatn-Regular.ttf") format("truetype"); font-weight: normal; }
    .letter-layout { display: flex; gap: 20px; align-items: flex-start; }
    .letter-body-col { flex: 2; min-width: 0; }
    .letter-timeline-col { flex: 1; min-width: 300px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
    .card-letter { background: #fff; border-radius: 12px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e5e7eb; margin-bottom: 20px; }
    .lh-header { display: flex; justify-content: space-between; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
    .lh-logo { width: 60px; height: 60px; object-fit: contain; }
    .lh-title { flex: 1; text-align: center; }
    .lh-title h2 { margin: 0 0 5px 0; font-weight: 900; color: #0f172a; font-size: 1.4rem; }
    .lh-meta { text-align: left; font-size: 0.85rem; color: #475569; line-height: 1.8; background: #f8fafc; padding: 10px 15px; border-radius: 8px; border: 1px dashed #cbd5e1; }
    .lh-info-row { display: flex; gap: 20px; margin-bottom: 20px; background: #eff6ff; padding: 15px; border-radius: 8px; border-right: 4px solid #3b82f6; }
    .lh-info-col { flex: 1; }
    .lh-label { font-size: 0.8rem; color: #64748b; margin-bottom: 3px; }
    .lh-value { font-weight: bold; color: #1e293b; font-size: 0.95rem; }
    .letter-content { font-family: "Vazirmatn", Tahoma, sans-serif; font-size: 15px; line-height: 2; color: #1f2937; min-height: 200px; text-align: justify; overflow-x: auto; }
    .letter-content table { width: 100% !important; max-width: 100%; border-collapse: collapse; }
    .attach-box { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; border-top: 1px solid #f1f5f9; padding-top: 20px; }
    .attach-item { background: #f8fafc; border: 1px solid #e2e8f0; padding: 8px 15px; border-radius: 8px; display: flex; align-items: center; gap: 10px; font-size: 0.85rem; transition: 0.2s; text-decoration: none; color: #334155; font-weight: bold; }
    .attach-item:hover { background: #e0f2fe; border-color: #7dd3fc; color: #0284c7; }
    .timeline { position: relative; padding-right: 20px; margin-top: 20px; }
    .timeline::before { content: ""; position: absolute; top: 0; bottom: 0; right: 5px; width: 2px; background: #e2e8f0; }
    .tl-item { position: relative; margin-bottom: 20px; }
    .tl-dot { position: absolute; right: -21px; top: 0; width: 14px; height: 14px; border-radius: 50%; background: #3b82f6; border: 3px solid #fff; box-shadow: 0 0 0 1px #3b82f6; }
    .tl-dot.completed { background: #10b981; box-shadow: 0 0 0 1px #10b981; }
    .tl-content { background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 8px; font-size: 0.85rem; }
    .tl-header { display: flex; justify-content: space-between; font-weight: bold; color: #1e293b; margin-bottom: 5px; border-bottom: 1px dashed #cbd5e1; padding-bottom: 5px; }
    .tl-note { color: #475569; line-height: 1.6; font-style: italic; }
    .tl-meta { font-size: 0.75rem; color: #94a3b8; margin-top: 5px; }

    @media (max-width: 992px) { .letter-layout { flex-direction: column; } .letter-timeline-col { width: 100%; } }
    @media (max-width: 768px) { .page-header { flex-direction: column; align-items: stretch; text-align: center; gap: 15px; } .card-letter { padding: 15px; } .lh-info-row { flex-direction: column; } }

    #print-container { display: none; }
    
    @media print {
        /* یکسان‌سازی کاغذ A4 و حذف حاشیه مرورگر برای حفظ تصویر سربرگ */
        @page { size: A4; margin: 0 !important; }
        
        html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; direction: rtl !important; }
        
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* مخفی کردن عناصر اضافی سایت (سایدبار، دکمه ها و ...) */
        body * { visibility: hidden !important; }
        header, footer, nav, aside, .sidebar, .main-header, .main-footer, .page-header, 
        .page-actions, .letter-layout, .alert, .modal-overlay, .print-header, .ops-box {
            display: none !important;
        }
        
        .content-wrapper { padding: 0 !important; margin: 0 !important; border: none !important; box-shadow: none !important; background: transparent !important; }
        #print-container, #print-container * { visibility: visible !important; }
        
        #print-container {
            display: block !important; position: absolute !important; top: 0 !important; left: 0 !important; right: 0 !important;
            width: 100% !important; min-height: 297mm !important; margin: 0 auto !important; z-index: 999999 !important;
            background: #fff; 
        }
        
        .watermark-print {
            position: fixed !important; top: 45% !important; left: 50% !important; transform: translate(-50%, -50%) rotate(-45deg) !important;
            font-size: 80pt !important; color: rgba(239, 68, 68, 0.15) !important; font-family: "Vazirmatn", Tahoma, sans-serif !important;
            font-weight: 900 !important; z-index: 9999 !important; pointer-events: none !important; text-align: center !important; line-height: 1.2 !important;
        }

        <?php echo $printBackgroundCss; ?>

        /* 
         ========================================================
         بخش تنظیم فواصل (Margins) بالا و پایین برای صفحات
         ========================================================
        */
        .print-layout { 
            width: 100% !important; 
            margin: 0 auto !important; 
            border-collapse: separate !important; 
            border-spacing: 0 !important; 
            table-layout: fixed; 
            direction: rtl; 
            border: none !important;
        }

        /* 
         1. تنظیم فاصله بالا برای "تمامی صفحات" (صفحه دوم به بعد)
         مقدار height در اینجا، فاصله از بالای کاغذ را مشخص می‌کند.
        */
        .print-layout thead { 
            display: table-header-group !important; 
            height: 4cm !important; /* <--- فاصله بالا برای صفحات 2 به بعد (4 سانتی‌متر) */
        }

        /* 
         2. تنظیم فاصله پایین برای "تمامی صفحات"
         مقدار height در اینجا، فاصله از پایین کاغذ را مشخص می‌کند.
        */
        .print-layout tfoot { 
            display: table-footer-group !important; 
            height: 3cm !important; /* <--- فاصله پایین برای تمامی صفحات (2 سانتی‌متر) */
        }
        
        /* 
         3. تنظیم فاصله بالای اختصاصی برای "صفحه اول"
         چون 4 سانت در هدر (thead) دادیم، برای اینکه صفحه اول 7 سانت بشود، 3 سانت دیگر به بدنه اضافه می‌کنیم (4 + 3 = 7)
        */
        .letter-text-content { 
            padding-top: 3cm !important; /* <--- اضافه کردن 3 سانتی‌متر دیگر برای صفحه اول تا بشود 7 سانت */
            padding-left: 1.5cm !important; /* حاشیه از سمت چپ متن */
            padding-right: 1.5cm !important; /* حاشیه از سمت راست متن */
        }

        /* ======================================================== */

        .print-layout > thead > tr > td, 
        .print-layout > tbody > tr > td, 
        .print-layout > tfoot > tr > td { 
            border: none !important;
            outline: none !important;
        }
        
        .letter-text-content table:not(.signature-table) {
            border-collapse: collapse !important;
            width: 100% !important;
            margin: 15px 0 !important;
        }
        .letter-text-content table:not(.signature-table), 
        .letter-text-content table:not(.signature-table) th, 
        .letter-text-content table:not(.signature-table) td {
            border: 1px solid #000 !important;
            padding: 8px !important;
        }
        
        .signature-table, .signature-table tr, .signature-table td {
            border: none !important;
        }

        #print-container * { max-width: 100% !important; overflow-wrap: break-word !important; word-break: break-word !important; box-sizing: border-box !important; white-space: normal !important; }
        
        /* جایگاه اطلاعات نامه (شماره، تاریخ، پیوست) در صفحه اول */
        .letter-meta-print {
            position: absolute !important;
            left: 1.5cm !important; /* فاصله از چپ کاغذ */
            top: 3.5cm !important;  /* فاصله اطلاعات نامه از بالای کاغذ */
            width: 4.8cm !important;
            text-align: right !important;
            direction: rtl !important;
            font-family: "Vazirmatn", Tahoma, sans-serif !important;
            font-size: 11pt !important;
            line-height: 2 !important;
            z-index: 100;
            color: #000 !important;
            margin: 0 !important;
        }
        
        .letter-meta-print div { display: block !important; direction: rtl !important; text-align: right !important; }
        .letter-meta-print .meta-label { display: inline-block; width: 55px; }
        .letter-meta-print .meta-val { direction: ltr !important; display: inline-block; text-align: right; }
        .letter-meta-print span { font-family: "Vazirmatn", Tahoma, "Courier New", monospace !important; letter-spacing: 0.5px; }

        /* 
         ========================================================
         استایل‌های اختصاصی چاپ فشرده (Compact Print)
         اگر می‌خواهید در چاپ فشرده هم فواصل تغییر کند از اینجا اقدام کنید
         ======================================================== 
        */
        
        /* به صورت پیشفرض، فواصل بالا را برای چاپ فشرده هم همان 7 و 4 و 2 در نظر گرفتیم */
        body.compact-print .print-layout thead { height: 4cm !important; } 
        body.compact-print .print-layout tfoot { height: 2cm !important; }
        body.compact-print .letter-text-content { padding-top: 3cm !important; } 
        body.compact-print .letter-meta-print { top: 3.5cm !important; } 
        
        /* اعمال فشردگی حداکثری در فونت‌ها برای جا شدن در یک صفحه */
        body.compact-print .letter-text-content,
        body.compact-print .letter-text-content * { 
            font-size: 10.5pt !important; 
            line-height: 1.5 !important; 
            margin-top: 0 !important;
            margin-bottom: 3px !important;
        }
        
        body.compact-print .signature-table { margin-top: 2px !important; transform: scale(0.8); transform-origin: top center; }
        body.compact-print .print-layout td { padding-top: 2px !important; padding-bottom: 2px !important; }
    }
</style>';

include __DIR__ . '/../../templates/header.php';
include __DIR__ . '/../../templates/sidebar.php';
?>

<main class="main-content" id="mainContent">
    <div class="content-wrapper">
        <div class="page-header page-actions d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div><span class="page-title fw-bold" style="font-size:1.2rem;">📄 مشاهده نامه و گردش کار</span></div>
            <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content: flex-end;">
                <?php if (isset($letter['is_archived']) && $letter['is_archived'] == 0 && isset($letter['is_deleted']) && $letter['is_deleted'] == 0): ?>
                    
                    <?php if ($canEdit): ?>
                        <a href="letter_edit.php?id=<?php echo $letterId; ?>" class="btn" style="background-color: #f59e0b; border-color: #f59e0b; color: #fff; font-weight:bold;">✏️ ویرایش نامه</a>
                    <?php endif; ?>

                    <?php if ($canDelete): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از حذف نامه اطمینان دارید؟');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action_type" value="delete_letter">
                            <button type="submit" class="btn btn-danger" style="font-weight:bold;">🗑️ حذف نامه</button>
                        </form>
                    <?php endif; ?>

                    <?php if (!empty($letter['indicator_number']) || in_array($letter['status'], ['approved_for_sign', 'registered', 'terminated'])): ?>
                        <a href="letter_refer.php?id=<?php echo $letterId; ?><?php echo $refId > 0 ? '&ref_id='.$refId : ''; ?>" class="btn btn-info" style="font-weight:bold; background:#8b5cf6; border-color:#8b5cf6; color:white;">📤 ارجاع</a>
                    <?php endif; ?>

                    <button onclick="printLetter('standard')" class="btn btn-outline" style="font-weight:bold;">🖨️ چاپ استاندارد</button>
                    <button onclick="printLetter('compact')" class="btn btn-outline" style="font-weight:bold; color:#0f766e; border-color:#a5f3fc; background:#ecfeff;" title="فشرده‌سازی متن در یک صفحه">🖨️ چاپ فشرده</button>

                    <?php if ($canApproveInternal): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از صدور شماره اندیکاتور و ارجاع نامه به گیرندگان اطمینان دارید؟');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action_type" value="approve_internal">
                            <button type="submit" class="btn btn-success" style="font-weight:bold;">✔️ صدور شماره و ارجاع</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canApproveOutgoing): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از تایید و ارسال این نامه به کارتابل امضاکنندگان اطمینان دارید؟');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action_type" value="approve_outgoing">
                            <button type="submit" class="btn btn-success" style="font-weight:bold;">✔️ تایید و ارسال برای امضا</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canTerminate): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('آیا از مختومه کردن ارجاع خود اطمینان دارید؟');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action_type" value="terminate_letter">
                            <button type="submit" class="btn btn-primary" style="font-weight:bold; background:#0f766e; border-color:#0f766e;">✅ مختومه کردن ارجاع</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canArchive): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('تمامی ارجاعات مختومه شده است. آیا از بایگانی نهایی پرونده اطمینان دارید؟');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action_type" value="archive_letter">
                            <button type="submit" class="btn btn-success" style="font-weight:bold; background:#10b981;">🗄️ بایگانی نهایی</button>
                        </form>
                    <?php endif; ?>

                    <?php if (in_array($letter['status'], ['approved_for_sign', 'registered']) && $letter['type'] === 'outgoing'): ?>
                        <?php if ($isSignerPending): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('آیا نامه را جهت امضا تایید می‌کنید؟ (نامه به میز کار امضا منتقل خواهد شد)');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action_type" value="accept_for_sign">
                                <button type="submit" class="btn btn-success" style="font-weight:bold;">✔️ تایید برای امضا</button>
                            </form>
                        <?php elseif ($isSignerAccepted): ?>
                            <button onclick="document.getElementById('signModal').style.display='flex'" class="btn btn-success" style="font-weight:bold;">✍️ ثبت امضا</button>
                        <?php elseif ($hasSigned): ?>
                            <button onclick="document.getElementById('signModal').style.display='flex'" class="btn btn-primary" style="font-weight:bold;">🔄 تغییر امضا</button>
                            <button onclick="document.getElementById('unsignModal').style.display='flex'" class="btn btn-danger" style="font-weight:bold;">🔓 لغو امضا</button>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
                <button onclick="history.back()" class="btn btn-secondary" style="font-weight:bold;">بازگشت</button>
            </div>
        </div>

        <?php if($msg): ?>
            <div id="alertMsg" class="alert alert-<?php echo $msgType; ?>"><?php echo $msg; ?></div>
        <?php endif; ?>

        <div class="letter-layout">
            <div class="letter-body-col">
                <div class="card-letter">
                    <div class="lh-header">
                        <img src="../assets/images/logo.png" class="lh-logo" alt="Logo">
                        <div class="lh-title">
                            <h2>آتنا زیست درمان</h2>
                            <div style="color:#64748b; font-size:0.9rem;">اتوماسیون جامع مکاتبات اداری</div>
                        </div>
                        <div class="lh-meta">
                            <div><b>شماره:</b> <span style="direction:ltr; display:inline-block;"><?php echo $printIndicator; ?></span></div>
                            <div><b>تاریخ ثبت:</b> <?php echo $printDate; ?></div>
                            <div><b>پیوست:</b> <?php echo count($attachments) > 0 ? count($attachments) . ' مورد' : 'ندارد'; ?></div>
                        </div>
                    </div>
                    <div class="lh-info-row">
                        <div class="lh-info-col"><div class="lh-label">فرستنده:</div><div class="lh-value"><?php echo htmlspecialchars($senderDisplay); ?></div></div>
                        <div class="lh-info-col"><div class="lh-label">گیرنده:</div><div class="lh-value"><?php echo htmlspecialchars($receiverDisplay); ?></div></div>
                        <div class="lh-info-col"><div class="lh-label">موضوع:</div><div class="lh-value" style="color:#2563eb;"><?php echo htmlspecialchars($letter['subject']); ?></div></div>
                    </div>
                    <div class="letter-content"><?php echo $letter['content'] ?? ''; ?></div>
                    
                    <?php if($sigStatus !== 'none' && $letter['type'] === 'outgoing'): ?>
                        <div style="margin-top:40px; padding-top:20px; border-top:1px dashed #cbd5e1; text-align:left;">
                            <span style="background:#ecfeff; color:#0f766e; border:1px solid #a5f3fc; padding:4px 8px; border-radius:6px; font-size:0.8rem; font-weight:bold;">✍️ این نامه توسط مدیریت تایید و امضا شده است.</span>
                        </div>
                    <?php endif; ?>

                    <?php if(count($attachments) > 0): ?>
                    <div class="attach-box">
                        <div style="width:100%; font-weight:bold; color:#475569; margin-bottom:5px;">📎 فایل‌های پیوست:</div>
                        <?php foreach($attachments as $att): ?>
                            <a href="../uploads/letters/<?php echo htmlspecialchars($att['file_path']); ?>" target="_blank" class="attach-item" title="دانلود / مشاهده"><span>📄 <?php echo htmlspecialchars($att['file_name']); ?></span></a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="letter-timeline-col">
                <h3 style="font-size:1.1rem; margin:0 0 15px 0; color:#1e293b; border-bottom:2px solid #f1f5f9; padding-bottom:10px;">⏳ گردش نامه (ارجاعات)</h3>
                <?php if(count($timeline) > 0): ?>
                    <div class="timeline">
                        <?php foreach($timeline as $t): $isCompleted = ($t['is_completed'] == 1); $dotClass = $isCompleted ? 'completed' : ''; 
                              $desc = $t['description'] ?? ''; 
                              $compNote = $t['completion_note'] ?? '';
                        ?>
                        <div class="tl-item">
                            <div class="tl-dot <?php echo $dotClass; ?>"></div>
                            <div class="tl-content">
                                <div class="tl-header"><span>از: <?php echo htmlspecialchars($t['s_fn'].' '.$t['s_ln']); ?></span><span style="color:#3b82f6;">➔ به: <?php echo htmlspecialchars($t['r_fn'].' '.$t['r_ln']); ?></span></div>
                                <div class="tl-note">
                                    <?php echo $desc ? '💬 ' . nl2br(htmlspecialchars($desc)) : '<span style="color:#cbd5e1;">بدون هامش</span>'; ?>
                                    <?php if (!empty($t['private_note']) && ($userId == $t['receiver_id'] || $userId == $t['sender_id'] || $isAdmin)): ?>
                                        <div style="margin-top:8px; padding:8px; background:#fff1f2; border:1px dashed #fecaca; color:#be123c; border-radius:6px; font-size:0.8rem;">
                                            🔒 <b>پیام خصوصی:</b> <?php echo nl2br(htmlspecialchars($t['private_note'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="tl-meta">
                                    <div>📅 ارجاع: <span style="direction:ltr; display:inline-block;"><?php echo date('H:i:s', strtotime($t['created_at'])) . ' - ' . (function_exists('jdate') ? jdate('Y/m/d', strtotime($t['created_at'])) : date('Y/m/d', strtotime($t['created_at']))); ?></span></div>
                                    <?php if($t['is_read'] && !empty($t['read_at'])): ?><div style="color:#10b981; margin-top:3px;">👀 مشاهده: <span style="direction:ltr; display:inline-block;"><?php echo date('H:i:s', strtotime($t['read_at'])); ?></span></div><?php endif; ?>
                                    <?php if($isCompleted): ?><div style="color:#059669; font-weight:bold; margin-top:5px; padding-top:5px; border-top:1px solid #e2e8f0;">✅ اقدام شد: <?php echo $compNote ? htmlspecialchars($compNote) : ''; ?></div><?php else: ?><div style="color:#f59e0b; margin-top:5px;">⏳ در دست اقدام...</div><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align:center; color:#94a3b8; font-size:0.9rem; padding:20px;">این نامه هنوز ارجاع داده نشده است.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<div id="print-container" class="<?php echo ($useLetterhead == 1) ? 'with-letterhead' : ''; ?>">
    <?php if (!$hasIndicator): ?>
        <div class="watermark-print">چاپ<br>آزمایشی</div>
    <?php endif; ?>

    <?php if ($useLetterhead == 1 && !empty($globalLetterhead)): ?>
        <div style="display:none;"><img src="../uploads/settings/<?php echo $globalLetterhead; ?>" onload="this.loaded=true"></div>
    <?php endif; ?>
    
    <div class="letter-meta-print">
        <div><span class="meta-label">شماره:</span> <span class="meta-val"><?php echo $printIndicator; ?></span></div>
        <div><span class="meta-label">تاریخ:</span> <span class="meta-val"><?php echo $printDate; ?></span></div>
        <div><span class="meta-label">پیوست:</span> <span class="meta-val" style="direction: rtl !important;"><?php echo count($attachments) > 0 ? count($attachments) . ' مورد' : 'ندارد'; ?></span></div>
    </div>
    
    <table class="print-layout">
        <thead>
            <tr><td style="border: none; padding: 0;">&nbsp;</td></tr>
        </thead>
        <tfoot>
            <tr><td style="border: none; padding: 0;">&nbsp;</td></tr>
        </tfoot>
        <tbody>
            <tr>
                <td style="border: none; padding: 0; vertical-align: top;">
                    <div class="letter-text-content" style="font-family: 'Vazirmatn', Tahoma, sans-serif; text-align: justify; direction: rtl; width: 100%;">
                        <?php 
                        $rawContent = $letter['content'] ?? '';
                        $contentClean = preg_replace('/(<p>\s*(?:&nbsp;|<br\s*\/?>|\s)*<\/p>\s*)+$/i', '', $rawContent);
                        if ($contentClean === null) $contentClean = $rawContent;
                        
                        $paragraphs = array_filter(explode('</p>', (string)$contentClean), function($val) {
                            return trim(strip_tags((string)$val)) !== '' || strpos((string)$val, '<img') !== false;
                        });
                        $paragraphs = array_values($paragraphs); 
                        $totalP = count($paragraphs);
                        
                        $mainContent = '';
                        $tailContent = '';
                        
                        $tailCount = min(2, $totalP); 
                        
                        for ($i = 0; $i < $totalP - $tailCount; $i++) { 
                            $mainContent .= $paragraphs[$i] . '</p>'; 
                        }
                        for ($i = $totalP - $tailCount; $i < $totalP; $i++) { 
                            $tailContent .= $paragraphs[$i] . '</p>'; 
                        }

                        echo $mainContent;
                        
                        echo '<div style="page-break-inside: avoid !important; break-inside: avoid !important; display: inline-block; width: 100%;">';
                        echo $tailContent;
                        
                        echo $signatureHtml;
                        echo '</div>';
                        ?>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div id="signModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
    <div style="background:#fff; width:90%; max-width:400px; border-radius:12px; overflow:hidden;">
        <div style="padding:15px 20px; background:#f8fafc; font-weight:bold; display:flex; justify-content:space-between; border-bottom:1px solid #e2e8f0;">
            <span>✍️ تنظیمات امضا</span><span style="cursor:pointer; color:#ef4444;" onclick="document.getElementById('signModal').style.display='none'">&times;</span>
        </div>
        <div style="padding:20px;">
            <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action_type" value="sign_letter">
                <div style="margin-bottom:15px;"><label style="font-weight:bold; font-size:0.85rem;">نوع درج امضا</label><select name="sign_type" class="form-control" style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1;"><option value="simple">متن ساده</option><option value="sign">تصویر امضا</option><option value="stamp">مهر</option><option value="both">امضا و مهر</option></select></div>
                <div style="margin-bottom:15px;">
                    <label style="font-weight:bold; font-size:0.85rem; cursor:pointer;">
                        <input type="checkbox" name="use_letterhead" value="1" <?php echo ($useLetterhead == 1) ? 'checked' : ''; ?>> 
                        چاپ روی سربرگ
                    </label>
                </div>
                <div style="margin-bottom:20px;"><label style="font-weight:bold; font-size:0.85rem;">پین‌کد امنیتی</label><input type="password" name="pin_code" required class="form-control" style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1; text-align:center; letter-spacing:5px;"></div>
                <button type="submit" class="btn btn-success" style="width:100%; justify-content:center; padding:12px;">🔐 ثبت و صدور شماره</button>
            </form>
        </div>
    </div>
</div>

<div id="unsignModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
    <div style="background:#fff; width:90%; max-width:400px; border-radius:12px; overflow:hidden;">
        <div style="padding:15px 20px; background:#f8fafc; font-weight:bold; display:flex; justify-content:space-between; border-bottom:1px solid #e2e8f0;">
            <span>🔓 لغو امضا</span><span style="cursor:pointer; color:#ef4444;" onclick="document.getElementById('unsignModal').style.display='none'">&times;</span>
        </div>
        <div style="padding:20px;">
            <form method="POST"><?php echo csrf_field(); ?><input type="hidden" name="action_type" value="remove_sign">
                <p style="font-size:0.85rem; color:#b91c1c; background:#fef2f2; padding:10px; border-radius:5px;">با لغو امضا، نامه مجدداً قابل ویرایش خواهد بود.</p>
                <div style="margin-bottom:20px;"><label style="font-weight:bold; font-size:0.85rem;">پین‌کد مدیر</label><input type="password" name="pin_code_unsign" required class="form-control" style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1; text-align:center; letter-spacing:5px;"></div>
                <button type="submit" class="btn btn-danger" style="width:100%; justify-content:center; padding:12px;">🔓 لغو امضا</button>
            </form>
        </div>
    </div>
</div>

<script>
    function printLetter(type) {
        if (type === 'compact') {
            document.body.classList.add('compact-print');
        } else {
            document.body.classList.remove('compact-print');
        }
        
        <?php if ($useLetterhead == 1): ?>
        document.body.classList.add('with-letterhead');
        <?php else: ?>
        document.body.classList.remove('with-letterhead');
        <?php endif; ?>
        
        var container = document.getElementById('print-container');
        if (container) {
            <?php if ($useLetterhead == 1): ?>
            container.classList.add('with-letterhead');
            <?php else: ?>
            container.classList.remove('with-letterhead');
            <?php endif; ?>
        }
        
        var imgs = container ? container.querySelectorAll('img') : [];
        var total = imgs.length;
        if (total === 0) {
            window.print();
            return;
        }
        var loaded = 0;
        for (var i = 0; i < total; i++) {
            if (imgs[i].complete) {
                loaded++;
                if (loaded === total) setTimeout(function() { window.print(); }, 100);
            } else {
                imgs[i].addEventListener('load', function() {
                    loaded++;
                    if (loaded === total) setTimeout(function() { window.print(); }, 100);
                });
            }
        }
    }
</script>

<?php include __DIR__ . '/../../templates/footer.php'; ?>