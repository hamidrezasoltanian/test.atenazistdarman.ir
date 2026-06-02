<?php
/*
 * فایل: public_html/api/sync_receiver.php
 * نسخه دیباگ و اصلاح شده: پشتیبانی کامل از کلیدهای ترکیبی (Composite Keys) در عملیات حذف و یکپارچه‌سازی دقیق داده‌ها
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

try {
    $API_SECRET = "YourStrongSecretKey2026"; 

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method Not Allowed', 405);
    }

    $headers = getallheaders();
    $sentKey = $headers['X-API-KEY'] ?? ($_POST['api_key'] ?? '');

    if ($sentKey !== $API_SECRET) {
        throw new Exception('Invalid API Key', 403);
    }

    require_once __DIR__ . '/../../includes/db.php';
    
    if (!isset($pdo)) {
        throw new Exception("متغیر PDO در فایل db.php ساخته نشده است. اتصال به دیتابیس مشکل دارد.");
    }

    $type = $_GET['type'] ?? 'customers'; 
    $action = $_GET['action'] ?? 'sync'; 

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !is_array($data)) {
        throw new Exception('No valid data received');
    }

    function parseSqlDate($dateStr) {
        if(empty($dateStr)) return null;
        return date('Y-m-d H:i:s', strtotime($dateStr));
    }

    $pdo->beginTransaction();
    $count = 0;

    // ==========================================
    // 1. عملیات SYNC 
    // ==========================================
    if ($action === 'sync') {
        if ($type === 'stuffs') {
            $stmt = $pdo->prepare("INSERT INTO stuffs (stuff_num, parent_num, stuff_name, stuff_code, technical_code, iran_code, barcode, price, active, is_delete, save_date, edit_date, delete_date) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE 
                                   stuff_name=VALUES(stuff_name), stuff_code=VALUES(stuff_code), technical_code=VALUES(technical_code), iran_code=VALUES(iran_code), barcode=VALUES(barcode), price=VALUES(price), active=VALUES(active), is_delete=VALUES(is_delete), save_date=VALUES(save_date), edit_date=VALUES(edit_date), delete_date=VALUES(delete_date)");
            foreach ($data as $r) {
                $stmt->execute([
                    $r['StuffNum'], $r['ParentNum'] ?? null, $r['StuffName'] ?? '', $r['StuffCode'] ?? '', $r['TechnicalCode'] ?? '', $r['IranCode'] ?? '', $r['Barcode'] ?? '', 
                    $r['Price'] ?? 0, (isset($r['Active']) && $r['Active']) ? 1 : 0, (isset($r['IsDelete']) && $r['IsDelete']) ? 1 : 0, 
                    parseSqlDate($r['SaveDate']??''), parseSqlDate($r['EditDate']??''), parseSqlDate($r['DeleteDate']??'')
                ]);
                $count++;
            }
        }
        elseif ($type === 'stores') {
            $stmt = $pdo->prepare("INSERT INTO stores (store_num, store_code, store_name, status, is_delete, save_date) 
                                   VALUES (?, ?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE store_code=VALUES(store_code), store_name=VALUES(store_name), status=VALUES(status), is_delete=VALUES(is_delete)");
            foreach ($data as $r) {
                $stmt->execute([$r['StoreNum'], $r['StoreCode'] ?? '', $r['StoreName'] ?? '', $r['Status'] ?? 1, $r['IsDelete'] ?? 0, parseSqlDate($r['SaveDate']??'')]);
                $count++;
            }
        }
        elseif ($type === 'store_stuffs') {
            $stmt = $pdo->prepare("INSERT INTO store_stuffs (store_stuff_num, store_num, stuff_num, available_count, reserved_count, is_delete) 
                                   VALUES (?, ?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE available_count=VALUES(available_count), reserved_count=VALUES(reserved_count), is_delete=VALUES(is_delete)");
            foreach ($data as $r) {
                $stmt->execute([$r['StoreStuffNum'], $r['StoreNum'], $r['StuffNum'], $r['AvailableCount'] ?? 0, $r['ReservedCount'] ?? 0, $r['IsDelete'] ?? 0]);
                $count++;
            }
        }
        elseif ($type === 'stuff_price_list') {
            $stmt = $pdo->prepare("INSERT INTO stuff_price_list (id, person_name, person_type, type, stuff_name, stuff_code, technical_code, price, total_inventory, price_imed, price_faradis, price_dermazon, central_store, virtual_store, scrap_store, sobhiyeh_store, motamedfar_store) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE person_name=VALUES(person_name), person_type=VALUES(person_type), type=VALUES(type), stuff_name=VALUES(stuff_name), stuff_code=VALUES(stuff_code), technical_code=VALUES(technical_code), price=VALUES(price), total_inventory=VALUES(total_inventory), price_imed=VALUES(price_imed), price_faradis=VALUES(price_faradis), price_dermazon=VALUES(price_dermazon), central_store=VALUES(central_store), virtual_store=VALUES(virtual_store), scrap_store=VALUES(scrap_store), sobhiyeh_store=VALUES(sobhiyeh_store), motamedfar_store=VALUES(motamedfar_store)");
            foreach ($data as $r) {
                $stmt->execute([
                    $r['id'], $r['person_name']??'', $r['person_type']??'', $r['type']??'', $r['stuff_name']??'', $r['stuff_code']??'', $r['technical_code']??'', 
                    $r['price']??0, $r['total_inventory']??0, $r['price_imed']??0, $r['price_faradis']??0, $r['price_dermazon']??0, 
                    $r['central_store']??0, $r['virtual_store']??0, $r['scrap_store']??0, $r['sobhiyeh_store']??0, $r['motamedfar_store']??0
                ]);
                $count++;
            }
        }
        elseif ($type === 'customers') {
            $checkStmt = $pdo->prepare("SELECT id FROM customers WHERE company_num = ? LIMIT 1");
            $updateStmt = $pdo->prepare("UPDATE customers SET company_name = ?, company_code = ?, manager_name = ?, phone = ?, mobile = ?, state = ?, city = ?, address = ?, postal_code = ?, type_name = ? WHERE company_num = ?");
            $insertStmt = $pdo->prepare("INSERT INTO customers (company_num, company_name, company_code, manager_name, phone, mobile, state, city, address, postal_code, type_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($data as $row) {
                $companyNum = $row['CompanyNum'] ?? 0;
                if (empty($companyNum)) continue;
                $checkStmt->execute([$companyNum]);
                $exists = $checkStmt->fetchColumn();
                $saverName = isset($row['Saver']) ? trim($row['Saver']) : null;

                if ($exists) {
                    $updateStmt->execute([$row['CompanyName'] ?? 'بدون نام', $row['CompanyCode'] ?? null, $saverName, $row['Phone1'] ?? null, $row['Mobile1'] ?? null, $row['StateName1'] ?? null, $row['CityName1'] ?? null, $row['Address1'] ?? null, $row['PostCode1'] ?? null, $row['TypeName'] ?? null, $companyNum]);
                } else {
                    $insertStmt->execute([$companyNum, $row['CompanyName'] ?? 'بدون نام', $row['CompanyCode'] ?? null, $saverName, $row['Phone1'] ?? null, $row['Mobile1'] ?? null, $row['StateName1'] ?? null, $row['CityName1'] ?? null, $row['Address1'] ?? null, $row['PostCode1'] ?? null, $row['TypeName'] ?? null]);
                }
                $count++;
            }
        } 
        elseif ($type === 'phones') {
            $deleteStmt = $pdo->prepare("DELETE FROM customer_phones WHERE company_num = ? AND phone_number = ?");
            $insertStmt = $pdo->prepare("INSERT INTO customer_phones (company_num, phone_number, description, phone_type, is_default, is_sms, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $updateMainStmt = $pdo->prepare("UPDATE customers SET phone = ? WHERE company_num = ?");
            $updateMobileStmt = $pdo->prepare("UPDATE customers SET mobile = ? WHERE company_num = ?");

            foreach ($data as $row) {
                $companyNum = $row['RowNum'] ?? 0; 
                $phone = isset($row['Phone']) ? trim($row['Phone']) : '';
                if (empty($companyNum) || empty($phone)) continue;
                
                $deleteStmt->execute([$companyNum, $phone]);
                $isDefault = !empty($row['IsDefault']) ? 1 : 0;
                $isSms = !empty($row['IsSms']) ? 1 : 0;

                $insertStmt->execute([$companyNum, $phone, (string)($row['Description'] ?? ''), (string)($row['Type'] ?? '0'), $isDefault, $isSms]);
                if ($isDefault === 1) {
                    if (preg_match('/^09/', $phone)) {
                        $updateMobileStmt->execute([$phone, $companyNum]);
                    } else {
                        $updateMainStmt->execute([$phone, $companyNum]);
                    }
                }
                $count++;
            }
        }
        elseif ($type === 'addresses') {
            $deleteStmt = $pdo->prepare("DELETE FROM customer_addresses WHERE company_num = ? AND address_text = ?");
            $insertStmt = $pdo->prepare("INSERT INTO customer_addresses (company_num, country, state, city, region, address_text, postal_code, is_default, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $updateMainAddrStmt = $pdo->prepare("UPDATE customers SET state = ?, city = ?, address = ?, postal_code = ? WHERE company_num = ?");

            foreach ($data as $row) {
                $companyNum = $row['CompanyNum'] ?? 0;
                $address = isset($row['Address']) ? trim($row['Address']) : '';
                if (empty($companyNum) || empty($address)) continue;
                
                $deleteStmt->execute([$companyNum, $address]);
                $isDefault = !empty($row['IsDefault']) ? 1 : 0;
                $insertStmt->execute([$companyNum, (string)($row['CountryName'] ?? ''), (string)($row['StateName'] ?? ''), (string)($row['CityName'] ?? ''), (string)($row['RegionName'] ?? ''), $address, (string)($row['PostCode'] ?? ''), $isDefault]);

                if ($isDefault === 1) {
                    $updateMainAddrStmt->execute([(string)($row['StateName'] ?? ''), (string)($row['CityName'] ?? ''), $address, (string)($row['PostCode'] ?? ''), $companyNum]);
                }
                $count++;
            }
        }
        elseif ($type === 'followers') {
            $deleteStmt = $pdo->prepare("DELETE FROM customer_followers WHERE company_num = ? AND username = ?");
            $insertStmt = $pdo->prepare("INSERT INTO customer_followers (company_num, username, full_name, updated_at) VALUES (?, ?, ?, NOW())");
            foreach ($data as $row) {
                $companyNum = $row['CompanyNum'] ?? 0;
                $username = isset($row['UserName']) ? trim($row['UserName']) : '';
                $fullName = isset($row['FullName']) ? trim($row['FullName']) : '';
                if (empty($companyNum) || empty($username)) continue;
                
                $deleteStmt->execute([$companyNum, $username]);
                $insertStmt->execute([$companyNum, $username, $fullName]);
                $count++;
            }
        }
    }
    // ==========================================
    // 2. عملیات DELETE (اصلاح شده برای کلیدهای ترکیبی)
    // ==========================================
    elseif ($action === 'delete') {
        if ($type === 'followers') {
            $stmt = $pdo->prepare("DELETE FROM customer_followers WHERE company_num = ? AND username = ?");
            foreach ($data as $row) {
                if (isset($row['CompanyNum'], $row['UserName'])) {
                    $stmt->execute([$row['CompanyNum'], $row['UserName']]);
                    $count++;
                }
            }
        } 
        elseif ($type === 'phones') {
            $stmt = $pdo->prepare("DELETE FROM customer_phones WHERE company_num = ? AND phone_number = ?");
            foreach ($data as $row) {
                if (isset($row['RowNum'], $row['Phone'])) {
                    $stmt->execute([$row['RowNum'], $row['Phone']]);
                    $count++;
                }
            }
        } 
        elseif ($type === 'addresses') {
            $stmt = $pdo->prepare("DELETE FROM customer_addresses WHERE company_num = ? AND address_text = ?");
            foreach ($data as $row) {
                if (isset($row['CompanyNum'], $row['Address'])) {
                    $stmt->execute([$row['CompanyNum'], $row['Address']]);
                    $count++;
                }
            }
        } 
        else {
            // برای جداول با یک کلید اصلی (تکی)
            $pkCol = 'company_num'; $tbl = 'customers';
            if($type === 'stuffs') { $pkCol = 'stuff_num'; $tbl = 'stuffs'; }
            if($type === 'stores') { $pkCol = 'store_num'; $tbl = 'stores'; }
            if($type === 'store_stuffs') { $pkCol = 'store_stuff_num'; $tbl = 'store_stuffs'; }
            if($type === 'stuff_price_list') { $pkCol = 'id'; $tbl = 'stuff_price_list'; }

            $stmt = $pdo->prepare("DELETE FROM $tbl WHERE $pkCol = ?");
            foreach ($data as $row) {
                $key = array_values($row)[0] ?? null;
                if ($key) { $stmt->execute([$key]); $count++; }
            }
        }
    }
    // ==========================================
    // 3. عملیات GARBAGE COLLECTOR
    // ==========================================
    elseif ($action === 'garbage_collect') {
        // نادیده گرفتن جداول با کلید ترکیبی در زباله‌روب (زیرا عملیات Delete صریح آنها کاملا دقیق کار میکند)
        if (!in_array($type, ['followers', 'phones', 'addresses'])) {
            $pkCol = 'company_num'; $tbl = 'customers';
            if($type === 'stuffs') { $pkCol = 'stuff_num'; $tbl = 'stuffs'; }
            if($type === 'stores') { $pkCol = 'store_num'; $tbl = 'stores'; }
            if($type === 'store_stuffs') { $pkCol = 'store_stuff_num'; $tbl = 'store_stuffs'; }
            if($type === 'stuff_price_list') { $pkCol = 'id'; $tbl = 'stuff_price_list'; }

            $validKeys = [];
            foreach ($data as $row) { 
                $k = array_values($row)[0] ?? null; 
                if($k) $validKeys[] = $k; 
            }
            
            $dbRows = $pdo->query("SELECT id, $pkCol FROM $tbl")->fetchAll(PDO::FETCH_ASSOC);
            $deleteIds = [];
            foreach ($dbRows as $dbRow) { 
                if (!in_array($dbRow[$pkCol], $validKeys)) $deleteIds[] = $dbRow['id']; 
            }
            
            if (!empty($deleteIds)) {
                $chunks = array_chunk($deleteIds, 500);
                foreach($chunks as $chunk) {
                    $in = implode(',', $chunk);
                    $pdo->query("DELETE FROM $tbl WHERE id IN ($in)");
                }
                $count = count($deleteIds);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => "Processed $count records for type: $type (Action: $action)"]);

} catch (Throwable $e) { 
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code); 
    
    echo json_encode([
        'status' => 'error', 
        'message' => 'PHP/DB Error: ' . $e->getMessage() . ' (Line: ' . $e->getLine() . ')'
    ]);
}
?>