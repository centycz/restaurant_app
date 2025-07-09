<?php
// Vynutíme vlastní log soubor
ob_start();
session_start();

// Získáme JSON data
$jsonBody = json_decode(file_get_contents('php://input'), true);
// Nastavíme employee_name ze získaných JSON dat
if (isset($jsonBody['employee_name'])) {
    $_SESSION['employee_name'] = mb_convert_encoding($jsonBody['employee_name'], 'UTF-8');
    error_log("Setting employee name from JSON: " . $_SESSION['employee_name']);
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

function getDb() {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;dbname=pizza_orders;charset=utf8mb4', 'pizza_user', '123789Pizza@');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->exec("SET CHARACTER SET utf8mb4");
    } catch (PDOException $e) {
        error_log('Connection failed: ' . $e->getMessage());
        jsend(false, null, 'Database connection error: ' . $e->getMessage());
        exit;
    }
    return $pdo;
}

function jsend($success, $data = null, $error = '') {
    $response = [
        'success' => $success,
        'data' => $data,
        'error' => $success ? null : $error
    ];
    
    echo json_encode($response, 
        JSON_UNESCAPED_UNICODE | 
        JSON_UNESCAPED_SLASHES | 
        JSON_PRETTY_PRINT
    );
    exit; // Přidáno exit pro ukončení skriptu
}

function getJsonBody() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true);
}

$action = $_GET['action'] ?? '';
//error_log("=== API CALLED WITH ACTION: " . $action . " ===");

try {
    $pdo = getDb();
    $pdo->exec("SET NAMES 'utf8mb4'"); // Nastaven kdovn pro spojen
    $today = date('Y-m-d'); // Define $today here

    // Check if daily statistics exist for today
    $q = $pdo->prepare("SELECT COUNT(*) FROM daily_stats WHERE date = ?");
    $q->execute([$today]);
    $count = $q->fetchColumn();

    //error_log("Pocet dennich statistik pro dnes: " . $count); // Logovn potu

    // If no daily statistics exist, create them
    if ($count == 0) {
        //error_log("Denn statistiky pro dnes neexistuji, vytvarm..."); // Logovn vytven
        $pdo->prepare("INSERT INTO daily_stats (date, total_orders, total_pizzas, total_drinks, total_revenue, avg_preparation_time, burnt_items) VALUES (?, 0, 0, 0, 0, 0, 0)")
            ->execute([$today]);
        //error_log("Denn statistiky pro dnes vytvoeny."); // Logovn vytvoen
    }

    // Vpis stol
    if ($action === 'tables') {
    try {
        $q = $pdo->query("
    SELECT 
        rt.table_number,
        rt.table_code,
        rt.status,
        rt.notes,
        tl.name as location_name,
        tl.id as location_id,
        tc.name as category_name,   -- přidáno
        tc.id as category_id,       -- přidáno
        tl.display_order as location_order,
        tc.display_order as category_order
    FROM restaurant_tables rt
    LEFT JOIN table_locations tl ON rt.location_id = tl.id
    LEFT JOIN table_categories tc ON rt.category_id = tc.id
    ORDER BY COALESCE(tl.display_order,999), COALESCE(tc.display_order,999), rt.table_number
");
        
        if (!$q) {
            throw new Exception("Chyba při načítání stolů");
        }
        
        $tables = $q->fetchAll(PDO::FETCH_ASSOC);
        jsend(true, ['tables' => $tables]);
    } catch (Exception $e) {
        //error_log('Error in tables endpoint: ' . $e->getMessage());
        jsend(false, null, 'Chyba při načítání stolů: ' . $e->getMessage());
    }
}

if ($action === 'drink-categories') {
    $sql = "SELECT DISTINCT category FROM drink_types WHERE is_active = 1 ORDER BY display_order, category";
    $q = $pdo->query($sql);
    $categories = $q->fetchAll(PDO::FETCH_COLUMN);
    jsend(true, ['categories' => $categories]);
}
// Načtení kategorií pizzy
if ($action === 'pizza-categories') {
    $sql = "SELECT DISTINCT category FROM pizza_types WHERE is_active = 1 ORDER BY display_order, category";
    $q = $pdo->query($sql);
    $categories = $q->fetchAll(PDO::FETCH_COLUMN);
    jsend(true, ['categories' => $categories]);
}
    // Vpis menu pizz
    if ($action === 'pizza-menu') {
    $q = $pdo->query("SELECT * FROM pizza_types WHERE is_active=1 ORDER BY display_order, name");
    $pizzas = $q->fetchAll(PDO::FETCH_ASSOC);
    jsend(true, ['pizzas' => $pizzas]);
}
// Vpis menu pizz pro administraci (vetn neaktivnch)
    if ($action === 'pizza-menu-admin') {
        $sql = "SELECT * FROM pizza_types ORDER BY name"; // Bez WHERE podmnky
        error_log('SQL query: ' . $sql); // Pidno
        $q = $pdo->query($sql);
        $pizzas
         = $q->fetchAll(PDO::FETCH_ASSOC);
        error_log('Pizza menu data: ' . json_encode($pizzas, JSON_UNESCAPED_UNICODE)); // Logovn dat
        jsend(true, ['pizzas' => $pizzas]);
    }
  // Akce pro sprvu npoj
    // Akce pro správu nápojů
if ($action === 'add-drink') {
    $body = getJsonBody();
    $type = $body['type'] ?? '';
    $name = $body['name'] ?? '';
    $description = $body['description'] ?? '';
    $price = $body['price'] ?? 0;
    $cost_price = $body['cost_price'] ?? 0;  // ← PŘIDÁNO
    $is_active = $body['is_active'] ?? 0;
    $category = $body['category'] ?? '';

    if (!$type || !$name) jsend(false, null, 'Chybí typ nebo název nápoje!');

    try {
        $stmt = $pdo->prepare("INSERT INTO drink_types (type, name, description, price, cost_price, is_active, category) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$type, $name, $description, $price, $cost_price, $is_active, $category]);
        jsend(true);
    } catch (Exception $e) {
        error_log('Add drink failed: ' . $e->getMessage());
        jsend(false, null, 'Přidání nápoje selhalo: ' . $e->getMessage());
    }
}
    if ($action === 'toggle-drink') {
        $type = $_GET['type'] ?? '';

        if (!$type) jsend(false, null, 'Chybí typ nápoje!');

        try {
            // Zjištění aktuálního stavu
            $stmt = $pdo->prepare("SELECT is_active FROM drink_types WHERE type=?");
            $stmt->execute([$type]);
            $drink = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$drink) {
                jsend(false, null, 'Nápoj nenalezen!');
                return;
            }

            $new_status = $drink['is_active'] == 1 ? 0 : 1;

            $stmt = $pdo->prepare("UPDATE drink_types SET is_active=? WHERE type=?");
            $stmt->execute([$new_status, $type]);
            jsend(true);
        } catch (Exception $e) {
            error_log('Toggle drink failed: ' . $e->getMessage());
            jsend(false, null, 'Změna stavu nápoje selhala: ' . $e->getMessage());
        }
    }
// V sekci pro editaci nápoje (edit-drink)
if ($action === 'edit-drink') {
    $type = $_GET['type'] ?? '';
    $body = getJsonBody();
    $name = $body['name'] ?? '';
    $description = $body['description'] ?? '';
    $price = $body['price'] ?? 0;
    $cost_price = $body['cost_price'] ?? 0;  // ← PŘIDÁNO
    $is_active = $body['is_active'] ?? 0;
    $category = $body['category'] ?? '';

    if (!$type || !$name) jsend(false, null, 'Chybí typ nebo název nápoje!');

    try {
        $stmt = $pdo->prepare("UPDATE drink_types SET name=?, description=?, price=?, cost_price=?, is_active=?, category=? WHERE type=?");
        $stmt->execute([$name, $description, $price, $cost_price, $is_active, $category, $type]);
        jsend(true);
    } catch (Exception $e) {
        error_log('Edit drink failed: ' . $e->getMessage());
        jsend(false, null, 'Úprava nápoje selhala: ' . $e->getMessage());
    }
}

// V sekci pro výpis nápojů (drink-menu-admin)
if ($action === 'drink-menu-admin') {
    $sql = "SELECT * FROM drink_types ORDER BY category, name";  // Změněno řazení
    error_log('SQL query: ' . $sql);
    $q = $pdo->query($sql);
    $drinks = $q->fetchAll(PDO::FETCH_ASSOC);
    error_log('Drink menu data: ' . json_encode($drinks, JSON_UNESCAPED_UNICODE));
    jsend(true, ['drinks' => $drinks]);
}

// V sekci pro výpis aktivních nápojů (drink-menu)
if ($action === 'drink-menu') {
    $q = $pdo->query("SELECT * FROM drink_types WHERE is_active=1 ORDER BY display_order, name");
    $drinks = $q->fetchAll(PDO::FETCH_ASSOC);
    jsend(true, ['drinks' => $drinks]);
}

      // Pidn novho stolu
if ($action === 'add-table') {
    $body = getJsonBody();
    $table_number = intval($body['table_number'] ?? 0); // Zmnno na int
    $table_code = $body['table_code'] ?? '';
    $location_name = $body['location_name'] ?? 'Auto';
    $category_name = $body['category_name'] ?? 'Auto';

    if (!$table_number || !$table_code) jsend(false, null, "Chyb číslo stolu nebo kód stolu!");

    try {
        // Najdi ID lokace
        $stmt = $pdo->prepare("SELECT id FROM table_locations WHERE name = ?");
        $stmt->execute([$location_name]);
        $location = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$location) {
            // Pokud lokace neexistuje, vytvo ji
            $pdo->prepare("INSERT INTO table_locations (name) VALUES (?)")->execute([$location_name]);
            $location_id = $pdo->lastInsertId();
        } else {
            $location_id = $location['id'];
        }

        // Najdi ID kategorie
        $stmt = $pdo->prepare("SELECT id FROM table_categories WHERE name = ?");
        $stmt->execute([$category_name]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$category) {
            // Pokud kategorie neexistuje, vytvo ji
            $pdo->prepare("INSERT INTO table_categories (name) VALUES (?)")->execute([$category_name]);
            $category_id = $pdo->lastInsertId();
        } else {
            $category_id = $category['id'];
        }

        // Vlo stl
        $stmt = $pdo->prepare("INSERT INTO restaurant_tables (table_number, table_code, status, location_id, category_id) VALUES (?, ?, 'free', ?, ?)");
        $stmt->execute([$table_number, $table_code, $location_id, $category_id]);
        jsend(true);

    } catch (Exception $e) {
        error_log('Add table failed: ' . $e->getMessage());
        jsend(false, null, 'Pidn stolu selhalo: ' . $e->getMessage());
    }
}

  if ($action === 'kitchen-stats') {
    $q = $pdo->query("
        SELECT 
            oi.item_type,
            oi.quantity
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE oi.item_type IN ('pizza', 'pasta', 'predkrm', 'dezert', 'drink')
        AND oi.status IN ('pending','preparing','ready')  -- Včetně 'ready' pro statistiky
        ORDER BY o.created_at DESC
    ");
    
    if (!$q) {
        jsend(false, null, "Chyba při načítání statistik kuchyně");
        return;
    }

    $items = $q->fetchAll(PDO::FETCH_ASSOC);
    jsend(true, ['items' => $items]);
}  // Pidn objednvky pro JEDEN stl (vytvo session, pokud nen aktivn)
 if ($action === 'add-order') {
    $body = getJsonBody();
    $table_number = intval($body['table'] ?? 0);
    $items = $body['items'] ?? [];
    $customer_name = $body['customer_name'] ?? '';
    $employee_name = $body['employee_name'] ?? ''; // Získáme employee_name z JSON dat
    
    // Log pro debugging
    error_log("Received data in add-order:");
    error_log("employee_name from body: " . $employee_name);
    error_log("employee_name from session: " . ($_SESSION['employee_name'] ?? 'not set'));
    
    if ($table_number <= 0) jsend(false, null, "Není vybrán stůl!");
    if (!is_array($items) || count($items) < 1) jsend(false, null, "Chybí položky objednávky!");

    $s = $pdo->prepare("SELECT id FROM table_sessions WHERE table_number=? AND is_active=1 LIMIT 1");
    $s->execute([$table_number]);
    $row = $s->fetch();
    if ($row) {
        $table_session_id = $row['id'];
    } else {
        $pdo->prepare("INSERT INTO table_sessions (table_number, start_time, is_active) VALUES (?, NOW(), 1)")
            ->execute([$table_number]);
        $table_session_id = $pdo->lastInsertId();
        $pdo->prepare("UPDATE restaurant_tables SET status='occupied', session_start=NOW() WHERE table_number=?")->execute([$table_number]);
    }

    // Použijeme employee_name z JSON dat nebo ze session jako zálohu
    $final_employee_name = $employee_name ?: ($_SESSION['employee_name'] ?? '');
    error_log("Final employee name being used: " . $final_employee_name);

    $stmt = $pdo->prepare("INSERT INTO orders (table_session_id, created_at, status, order_type, customer_name, employee_name) VALUES (?, NOW(), 'pending', 'other', ?, ?)");
    $stmt->execute([$table_session_id, $customer_name, $final_employee_name]);
    $order_id = $pdo->lastInsertId();

    // Log the inserted order
    error_log("Inserted order ID: " . $order_id . " with employee_name: " . $final_employee_name);

        $stmt = $pdo->prepare("
            UPDATE daily_stats 
            SET total_orders = total_orders + 1
            WHERE date = ?
        ");
        $stmt->execute([$today]);

        foreach ($items as $item) {
            $pdo->prepare("INSERT INTO order_items (order_id, item_type, item_name, quantity, unit_price, note, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')")
                ->execute([
                    $order_id,
                    $item['type'] ?? 'pizza',
                    $item['name'] ?? '',
                    intval($item['quantity'] ?? 1),
                    floatval($item['unit_price'] ?? 0),
                    $item['note'] ?? ''
                ]);
        }

        jsend(true, ['order_id' => $order_id]);
    }

if ($action === 'pay-items') {
    $body = getJsonBody();
    $items = $body['items'] ?? [];
    $discount = floatval($body['discount'] ?? 0);
    $payment_method = $body['payment_method'] ?? 'hotovost';

    if (!is_array($items) || count($items) == 0) jsend(false, null, "Chybí položky!");

    $pdo->beginTransaction();
    try {
        $sessions = [];
        $totalRevenue = 0;
        $totalPizzas = 0;
        $totalDrinks = 0;
        $today = date('Y-m-d');
        $paymentAmount = 0;

        foreach ($items as $x) {
            $item_id = intval($x['id']);
            $pay_qty = intval($x['quantity']);
            if ($item_id <= 0 || $pay_qty <= 0) continue;

            // Zamkni řádek pro úpravu + zjisti session_id
            $q = $pdo->prepare("SELECT oi.*, o.table_session_id FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE oi.id=? FOR UPDATE");
            $q->execute([$item_id]);
            $item = $q->fetch(PDO::FETCH_ASSOC);
            if (!$item) continue;
            if (in_array($item['status'], ['paid','cancelled'])) continue;

            $session_id = $item['table_session_id'];
            if (!in_array($session_id, $sessions)) $sessions[] = $session_id;

            $orig_qty = intval($item['quantity']);
            if ($pay_qty < $orig_qty) {
                // Sníží původní řádek o pay_qty
                $pdo->prepare("UPDATE order_items SET quantity=? WHERE id=?")
                    ->execute([$orig_qty - $pay_qty, $item_id]);
                // Vytvoří nový řádek s quantity = pay_qty a status = paid
                $pdo->prepare("INSERT INTO order_items (order_id, item_type, item_name, quantity, unit_price, note, status, parent_id)
                    VALUES (?, ?, ?, ?, ?, ?, 'paid', ?)")
                    ->execute([
                        $item['order_id'],
                        $item['item_type'],
                        $item['item_name'],
                        $pay_qty,
                        $item['unit_price'],
                        $item['note'],
                        $item_id
                    ]);

                $paymentAmount += $item['unit_price'] * $pay_qty;
                $totalRevenue += $item['unit_price'] * $pay_qty;
                if ($item['item_type'] === 'pizza') {
                    $totalPizzas += $pay_qty;
                } elseif ($item['item_type'] === 'drink') {
                    $totalDrinks += $pay_qty;
                }

            } else {
                // Platíme vše: nastav status na paid
                $pdo->prepare("UPDATE order_items SET status='paid' WHERE id=?")->execute([$item_id]);

                $paymentAmount += $item['unit_price'] * $item['quantity'];
                $totalRevenue += $item['unit_price'] * $item['quantity'];
                if ($item['item_type'] === 'pizza') {
                    $totalPizzas += $item['quantity'];
                } elseif ($item['item_type'] === 'drink') {
                    $totalDrinks += $item['quantity'];
                }
            }
        }

        // Zaznamenáme platbu do tabulky payments
        if ($paymentAmount > 0 && !empty($sessions)) {
            $session_id = $sessions[0];
            $stmt = $pdo->prepare("
                INSERT INTO payments (table_session_id, amount, payment_method, paid_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$session_id, $paymentAmount, $payment_method]);
        }

        // Aktualizace daily_stats
        $stmt = $pdo->prepare("
            UPDATE daily_stats
            SET total_revenue = total_revenue + ?,
                total_pizzas = total_pizzas + ?,
                total_drinks = total_drinks + ?
            WHERE date = ?
        ");
        $stmt->execute([$totalRevenue, $totalPizzas, $totalDrinks, $today]);

        // Po zaplacení zkontroluj sessions, zda nejsou všechny položky zaplacené
        foreach ($sessions as $session_id) {
            $q3 = $pdo->prepare("
                SELECT COUNT(*) FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE o.table_session_id=? AND oi.status NOT IN ('paid','cancelled')
            ");
            $q3->execute([$session_id]);
            $unpaid = $q3->fetchColumn();
            if ($unpaid == 0) {
                // Uzavři session a uvolni stůl
                $q4 = $pdo->prepare("SELECT table_number FROM table_sessions WHERE id=? LIMIT 1");
                $q4->execute([$session_id]);
                $table_number = $q4->fetchColumn();
                $pdo->prepare("UPDATE restaurant_tables SET status='to_clean' WHERE table_number=?")->execute([$table_number]);
                $pdo->prepare("UPDATE table_sessions SET is_active=0, end_time=NOW() WHERE id=?")->execute([$session_id]);
            }
        }
        $pdo->commit();
        jsend(true);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsend(false, null, 'Transaction error: ' . $e->getMessage());
    }
}

    // Vpis objednvek pro konkrtn stl (podle nejnovj aktivn session)
    if ($action === 'table-orders') {
    $table_number = intval($_GET['table_number'] ?? 0);
    $q = $pdo->prepare("
        SELECT ts.*, rt.table_code 
        FROM table_sessions ts
        JOIN restaurant_tables rt ON ts.table_number = rt.table_number
        WHERE ts.table_number=? AND ts.is_active=1 
        ORDER BY ts.id DESC LIMIT 1
    ");
    $q->execute([$table_number]);
    $session = $q->fetch();
    if (!$session) jsend(true, ['orders'=>[]]);
    $session_id = $session['id'];
    
    $q2 = $pdo->prepare("
        SELECT o.*, rt.table_code
        FROM orders o
        JOIN table_sessions ts ON o.table_session_id = ts.id
        JOIN restaurant_tables rt ON ts.table_number = rt.table_number
        WHERE o.table_session_id=? 
        ORDER BY o.created_at DESC
    ");
    $q2->execute([$session_id]);
    $orders = $q2->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orders as &$o) {
        $q3 = $pdo->prepare("SELECT * FROM order_items WHERE order_id=?");
        $q3->execute([$o['id']]);
        $o['items'] = $q3->fetchAll(PDO::FETCH_ASSOC);
    }
    jsend(true, ['orders' => $orders]);
}

    // Kuchy - poloky k pprav (jen pizzy, kter ekaj nebo se pipravuj)
  if ($action === 'kitchen-items') {
    $q = $pdo->query("
        SELECT 
            oi.*,
            o.table_session_id,
            o.created_at,
            o.printed_at,
            o.id as order_id,
            ts.table_number,
            rt.table_code,
            rt.notes,
            rt.status as table_status
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN table_sessions ts ON o.table_session_id = ts.id
        JOIN restaurant_tables rt ON ts.table_number = rt.table_number
        WHERE oi.item_type = 'pizza'
        AND oi.status IN ('pending','preparing')
        ORDER BY 
            CASE WHEN o.printed_at IS NULL THEN 0 ELSE 1 END,
            o.created_at DESC
    ");
    
    if (!$q) {
        //error_log("Error executing kitchen-items query");
        jsend(false, null, "Chyba při načítání položek pro kuchyň");
        return;
    }

    $items = $q->fetchAll(PDO::FETCH_ASSOC);
    //error_log("Kitchen items: " . json_encode($items));
    jsend(true, ['items' => $items]);
}

    // Bar - poloky k pprav (jen drinky, kter ekaj nebo se pipravuj)
 if ($action === 'bar-items') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                oi.*,
                o.table_session_id,
                o.created_at,
                o.printed_at,
                o.id as order_id,
                ts.table_number,
                rt.table_code,
                rt.notes,
                rt.status as table_status
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN table_sessions ts ON o.table_session_id = ts.id
            JOIN restaurant_tables rt ON ts.table_number = rt.table_number
            WHERE oi.item_type IN ('drink', 'pivo', 'vino', 'nealko', 'spritz', 'negroni', 'koktejl', 'digestiv')
            AND oi.status IN ('pending','preparing')
            ORDER BY 
                CASE WHEN o.printed_at IS NULL THEN 0 ELSE 1 END,
                o.created_at DESC
        ");
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsend(true, ['items' => $items]);
    } catch (Exception $e) {
        jsend(false, null, 'Chyba při načítání bar items: ' . $e->getMessage());
    }
}

    // Hotov poloky k vydn (kuchy i bar)
    if ($action === 'ready-items') {
    $q = $pdo->query(
        "SELECT oi.*, o.table_session_id, ts.table_number, rt.table_code
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN table_sessions ts ON o.table_session_id = ts.id
        JOIN restaurant_tables rt ON ts.table_number = rt.table_number
        WHERE oi.status = 'ready'
        ORDER BY oi.id"
    );
    $items = $q->fetchAll(PDO::FETCH_ASSOC);
    jsend(true, ['items' => $items]);
}

   if ($action === 'item-status') {
    //error_log("=== ITEM-STATUS AKCE BYLA ZAVOLÁNA ===");
    //error_log("POST data: " . file_get_contents('php://input'));
    $body = getJsonBody();
    $item_id = intval($body['item_id'] ?? 0);
    $status = $body['status'] ?? '';
    $note = $body['note'] ?? '';
//error_log("Raw note from request: '" . $note . "'");
//error_log("Note length: " . strlen($note));
//error_log("Note bytes: " . bin2hex($note));
    if (!$item_id || !$status) jsend(false, null, "Chybí položka nebo status!");

    // DEBUG: Logování všech příchozích dat
    //error_log("=== ITEM-STATUS DEBUG ===");
   //error_log("Received data: " . json_encode($body));
    //error_log("item_id: " . $item_id);
    //error_log("status: " . $status);
    //error_log("note: " . $note);

    // Zjištění typu položky před aktualizací statusu
    $q = $pdo->prepare("SELECT item_name, unit_price, item_type, quantity, note FROM order_items WHERE id = ?");
    $q->execute([$item_id]);
    $item = $q->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        jsend(false, null, "Položka nenalezena!");
        return;
    }

    // DEBUG: Logování načtené položky
    //error_log("Loaded item: " . json_encode($item));
    //error_log("Checking burnt pizza condition...");
    //error_log("item_type: " . $item['item_type']);
    //error_log("status: " . $status);
    //error_log("note: " . $note);
    //error_log("Condition result: " . ($item['item_type'] === 'pizza' && $status === 'pending' && $note === 'Spalena' ? 'TRUE' : 'FALSE'));

    try {
        // Speciální případ: Pokud se má pizza označit jako spálená
        if ($item['item_type'] === 'pizza' && $status === 'pending' && $note === 'Spalena') {
            //error_log("Spalujeme pizzu s ID: " . $item_id);
            //error_log("Název pizzy: " . $item['item_name']);
            //error_log("Cena pizzy: " . $item['unit_price']);

            // Zaznamenáme spálenou pizzu
            $stmt = $pdo->prepare("INSERT INTO burnt_pizzas_log (pizza_id, pizza_name, total, burnt_at) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$item_id, $item['item_name'], $item['unit_price']]);

            if ($stmt->rowCount() > 0) {
                //error_log("Spálená pizza úspěšně zaznamenána do burnt_pizzas_log!");
            } else {
                //error_log("CHYBA: Nepodařilo se zaznamenat spálenou pizzu do burnt_pizzas_log!");
            }

            // Aktualizujeme denní statistiky
            $stmt = $pdo->prepare("UPDATE daily_stats SET burnt_items = burnt_items + 1 WHERE date = ?");
            $stmt->execute([$today]);
        }

        // Aktualizujeme status položky včetně prepared_at pro hotové položky
        if ($status === 'ready') {
            $stmt = $pdo->prepare("UPDATE order_items SET status = ?, note = ?, prepared_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $note, $item_id]);
            //error_log("Položka označena jako hotová s prepared_at: " . $item_id);
        } else {
            $stmt = $pdo->prepare("UPDATE order_items SET status = ?, note = ? WHERE id = ?");
            $stmt->execute([$status, $note, $item_id]);
        }

        // DEBUG LOG
        //error_log("Status updated: item_id=" . $item_id . ", status=" . $status . ", note=" . $note);
        if ($status === 'ready') {
            //error_log("Prepared_at should be set for item: " . $item_id);
        }
if ($item['item_type'] === 'pizza' && $status === 'pending' && $note === 'Spalena') {
    // Vynucení okamžitého zápisu
    //error_log("FORCE FLUSH: Spálená pizza zapsána");
    if (function_exists('//error_log_flush')) {
        //error_log_flush();
    }
    // Nebo přímý zápis do souboru
    file_put_contents('/tmp/force_burnt.log', "Burnt pizza ID: " . $item_id . " at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
}
        jsend(true);
    } catch (Exception $e) {
        //error_log("Error updating item status: " . $e->getMessage());
        jsend(false, null, "Chyba při aktualizaci statusu");
    }
}

    // Vpis tu pro stl (vechny poloky, kter nejsou zruen) - aktuln session
    if ($action === 'session-bill') {
        $table_number = intval($_GET['table_number'] ?? 0);
        $q = $pdo->prepare("SELECT id FROM table_sessions WHERE table_number=? AND is_active=1 ORDER BY id DESC LIMIT 1");
        $q->execute([$table_number]);
        $session = $q->fetch();
        if (!$session) jsend(true, ['items'=>[]]);
        $session_id = $session['id'];
        $q2 = $pdo->prepare("
            SELECT oi.* FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.table_session_id=? AND oi.status NOT IN ('cancelled')
            ORDER BY oi.item_name, oi.id
        ");
        $q2->execute([$session_id]);
        $items = $q2->fetchAll(PDO::FETCH_ASSOC);
        jsend(true, ['items' => $items]);
    }

  // Zaplacen vybranch poloek vetn "Zaplatit ve"
    if ($action === 'pay-items') {
        $body = getJsonBody();
        $items = $body['items'] ?? [];
        if (!is_array($items) || count($items) == 0) jsend(false, null, "Chyb poloky!");

        $pdo->beginTransaction();
        try {
            $sessions = []; // seznam session_id, kterch se platby tk
            $totalRevenue = 0;
            $totalPizzas = 0;
            $totalDrinks = 0;
            $today = date('Y-m-d');

            foreach ($items as $x) {
                $item_id = intval($x['id']);
                $pay_qty = intval($x['quantity']);
                if ($item_id <= 0 || $pay_qty <= 0) continue;

                // Zamkni dek pro pravu + zjisti session_id
                $q = $pdo->prepare("SELECT oi.*, o.table_session_id FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE oi.id=? FOR UPDATE");
                $q->execute([$item_id]);
                $item = $q->fetch(PDO::FETCH_ASSOC);
                if (!$item) continue;
                if (in_array($item['status'], ['paid','cancelled'])) continue;

                $session_id = $item['table_session_id'];
                if (!in_array($session_id, $sessions)) $sessions[] = $session_id;

                $orig_qty = intval($item['quantity']);
                if ($pay_qty < $orig_qty) {
                    // Snit pvodn dek o pay_qty
                    $pdo->prepare("UPDATE order_items SET quantity=? WHERE id=?")
                        ->execute([$orig_qty - $pay_qty, $item_id]);
                    // Vytvoit nov dek s quantity = pay_qty a status = paid
                    $pdo->prepare("INSERT INTO order_items (order_id, item_type, item_name, quantity, unit_price, note, status, parent_id)
                        VALUES (?, ?, ?, ?, ?, ?, 'paid', ?)")
                        ->execute([
                            $item['order_id'],
                            $item['item_type'],
                            $item['item_name'],
                            $pay_qty,
                            $item['unit_price'],
                            $item['note'],
                            $item_id
                        ]);

                    $totalRevenue += $item['unit_price'] * $pay_qty;
                    if ($item['item_type'] === 'pizza') {
                        $totalPizzas += $pay_qty;
                    } elseif ($item['item_type'] === 'drink') {
                        $totalDrinks += $pay_qty;
                    }

                } else {
                    // Platme ve: nastav status na paid (i kdy je delivered!)
                    $pdo->prepare("UPDATE order_items SET status='paid' WHERE id=?")->execute([$item_id]);

                    $totalRevenue += $item['unit_price'] * $item['quantity'];
                    if ($item['item_type'] === 'pizza') {
                        $totalPizzas += $item['quantity'];
                    } elseif ($item['item_type'] === 'drink') {
                        $totalDrinks += $item['quantity'];
                    }
                }
            }

            // Aktualizace daily_stats
            $stmt = $pdo->prepare("
                UPDATE daily_stats
                SET total_revenue = total_revenue + ?,
                    total_pizzas = total_pizzas + ?,
                    total_drinks = total_drinks + ?
                WHERE date = ?
            ");
            $stmt->execute([$totalRevenue, $totalPizzas, $totalDrinks, $today]);

            // Po zaplacen zkontroluj sessions, zda nejsou vechny poloky zaplacen
            foreach ($sessions as $session_id) {
                $q3 = $pdo->prepare("
                    SELECT COUNT(*) FROM order_items oi
                    JOIN orders o ON oi.order_id = o.id
                    WHERE o.table_session_id=? AND oi.status NOT IN ('paid','cancelled')
                ");
                $q3->execute([$session_id]);
                $unpaid = $q3->fetchColumn();
                if ($unpaid == 0) {
                    // Uzavi session a uvolni stl
                    $q4 = $pdo->prepare("SELECT table_number FROM table_sessions WHERE id=? LIMIT 1");
                    $q4->execute([$session_id]);
                    $table_number = $q4->fetchColumn();
                    $pdo->prepare("UPDATE restaurant_tables SET status='to_clean' WHERE table_number=?")->execute([$table_number]);
                    $pdo->prepare("UPDATE table_sessions SET is_active=0, end_time=NOW() WHERE id=?")->execute([$session_id]);
                }
            }
            $pdo->commit();
            jsend(true);
        } catch (Exception $e) {
            $pdo->rollBack();
            //error_log('Transaction failed: ' . $e->getMessage());
            jsend(false, null, 'Transaction error: ' . $e->getMessage());
        }
    }

    // Oznaen stolu jako uklizenho (pidna nov akce)
    if ($action === 'mark-table-as-cleaned') {
        $body = getJsonBody();
        $table_number = intval($body['table_number'] ?? 0);
        if ($table_number <= 0) jsend(false, null, "Chyb slo stolu!");
        try {
            $stmt = $pdo->prepare("UPDATE restaurant_tables SET status='free' WHERE table_number=?");
            $stmt->execute([$table_number]);

            // Check if the update was successful
            if ($stmt->rowCount() > 0) {
                jsend(true);
            } else {
                jsend(false, null, "Table not found or already free.");
            }
        } catch (Exception $e) {
           //error_log('Mark as cleaned failed: ' . $e->getMessage());
            jsend(false, null, 'Mark as cleaned failed: ' . $e->getMessage());
        }
    }
    if ($action === 'add-order') {
        $body = getJsonBody();
        $table_number = intval($body['table'] ?? 0);
        $items = $body['items'] ?? [];
        $employee_id = intval($body['employee_id'] ?? 0);
        $customer_name = $body['customer_name'] ?? '';
        $discount = floatval($body['discount'] ?? 0);

        if ($table_number <= 0) jsend(false, null, "Není vybrán stůl!");
        if (!is_array($items) || count($items) < 1) jsend(false, null, "Chybí položky objednávky!");
        if ($employee_id <= 0) jsend(false, null, "Není vybrán zaměstnanec!");

        $s = $pdo->prepare("SELECT id FROM table_sessions WHERE table_number=? AND is_active=1 LIMIT 1");
        $s->execute([$table_number]);
        $row = $s->fetch();
        if ($row) {
            $table_session_id = $row['id'];
        } else {
            $pdo->prepare("INSERT INTO table_sessions (table_number, start_time, is_active) VALUES (?, NOW(), 1)")
                ->execute([$table_number]);
            $table_session_id = $pdo->lastInsertId();
            $pdo->prepare("UPDATE restaurant_tables SET status='occupied', session_start=NOW() WHERE table_number=?")->execute([$table_number]);
        }

        $pdo->prepare("INSERT INTO orders (table_session_id, created_at, status, order_type, employee_id, customer_name, discount) VALUES (?, NOW(), 'pending', 'other', ?, ?, ?)")
            ->execute([$table_session_id, $employee_id, $customer_name, $discount]);
            $pdo->exec("SET NAMES utf8mb4");
$pdo->exec("SET CHARACTER SET utf8mb4");
        $order_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            UPDATE daily_stats 
            SET total_orders = total_orders + 1
            WHERE date = ?
        ");
        $stmt->execute([$today]);

        foreach ($items as $item) {
            $pdo->prepare("INSERT INTO order_items (order_id, item_type, item_name, quantity, unit_price, note, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')")
                ->execute([
                    $order_id,
                    $item['type'] ?? 'pizza',
                    $item['name'] ?? '',
                    intval($item['quantity'] ?? 1),
                    floatval($item['unit_price'] ?? 0),
                    $item['note'] ?? ''
                ]);
        }

        jsend(true, ['order_id' => $order_id]);
    }

    if ($action === 'get-employees') {
        try {
            $q = $pdo->query("SELECT id, name FROM employees");
            $employees = $q->fetchAll(PDO::FETCH_ASSOC);
            jsend(true, ['employees' => $employees]);
        } catch (Exception $e) {
            error_log('Get employees failed: ' . $e->getMessage());
            jsend(false, null, 'Načtení zaměstnanců selhalo: ' . $e->getMessage());
        }
    }
    // Akce pro sprvu pizz
  if ($action === 'add-pizza') {
    $body = getJsonBody();
    $type = $body['type'] ?? '';
    $name = $body['name'] ?? '';
    $description = $body['description'] ?? '';
    $price = $body['price'] ?? 0;
    $cost_price = $body['cost_price'] ?? 0;  // ← PŘIDÁNO
    $is_active = $body['is_active'] ?? 0;
    $category = $body['category'] ?? 'pizza';

    if (!$type || !$name) jsend(false, null, 'Chybí typ nebo název položky!');

    try {
        $stmt = $pdo->prepare("INSERT INTO pizza_types (type, name, description, price, cost_price, is_active, category) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$type, $name, $description, $price, $cost_price, $is_active, $category]);  // ← PŘIDÁNO cost_price
        jsend(true);
    } catch (Exception $e) {
        error_log('Add pizza failed: ' . $e->getMessage());
        jsend(false, null, 'Přidání položky selhalo: ' . $e->getMessage());
    }
}

// V sekci pro editaci pizzy/jídla (edit-pizza)
if ($action === 'edit-pizza') {
    $type = $_GET['type'] ?? '';
    $body = getJsonBody();
    $name = $body['name'] ?? '';
    $description = $body['description'] ?? '';
    $price = $body['price'] ?? 0;
    $cost_price = $body['cost_price'] ?? 0;  // ← PŘIDÁNO
    $is_active = $body['is_active'] ?? 0;
    $category = $body['category'] ?? 'pizza';

    if (!$type || !$name) jsend(false, null, 'Chybí typ nebo název položky!');

    try {
        $stmt = $pdo->prepare("UPDATE pizza_types SET name=?, description=?, price=?, cost_price=?, is_active=?, category=? WHERE type=?");
        $stmt->execute([$name, $description, $price, $cost_price, $is_active, $category, $type]);  // ← PŘIDÁNO cost_price
        jsend(true);
    } catch (Exception $e) {
        error_log('Edit pizza failed: ' . $e->getMessage());
        jsend(false, null, 'Úprava položky selhala: ' . $e->getMessage());
    }
}

// V sekci pro výpis menu (pizza-menu-admin)
if ($action === 'pizza-menu-admin') {
    $sql = "SELECT * FROM pizza_types ORDER BY category, name";  // Přidáno řazení podle kategorie
    error_log('SQL query: ' . $sql);
    $q = $pdo->query($sql);
    $pizzas = $q->fetchAll(PDO::FETCH_ASSOC);
    error_log('Pizza menu data: ' . json_encode($pizzas, JSON_UNESCAPED_UNICODE));
    jsend(true, ['pizzas' => $pizzas]);
}

// V sekci pro výpis aktivních položek (pizza-menu)
if ($action === 'pizza-menu') {
    $q = $pdo->query("SELECT * FROM pizza_types WHERE is_active=1 ORDER BY category, name");  // Přidáno řazení podle kategorie
    $pizzas = $q->fetchAll(PDO::FETCH_ASSOC);
    jsend(true, ['pizzas' => $pizzas]);
}

    if ($action === 'toggle-pizza') {
    $type = $_GET['type'] ?? '';

    if (!$type) jsend(false, null, 'Chyb typ pizzy!');

    //error_log('toggle-pizza called for type: ' . $type); // Pidno

    try {
        // Zjitn aktulnho stavu
        $stmt = $pdo->prepare("SELECT is_active FROM pizza_types WHERE type=?");
        $stmt->execute([$type]);
        $pizza = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pizza) {
            jsend(false, null, 'Pizza nenalezena!');
            return;
        }

        $new_status = $pizza['is_active'] == 1 ? 0 : 1;

        $stmt = $pdo->prepare("UPDATE pizza_types SET is_active=? WHERE type=?");
        $stmt->execute([$new_status, $type]);
        jsend(true);
    } catch (Exception $e) {
        //error_log('Toggle pizza failed: ' . $e->getMessage());
        jsend(false, null, 'Zmna stavu pizzy selhala: ' . $e->getMessage());
    }
}

    if ($action === 'delete-pizza') {
        $type = $_GET['type'] ?? '';

        if (!$type) jsend(false, null, 'Chyb typ pizzy!');

        try {
            $stmt = $pdo->prepare("DELETE FROM pizza_types WHERE type=?");
            $stmt->execute([$type]);
            jsend(true);
        } catch (Exception $e) {
            //error_log('Delete pizza failed: ' . $e->getMessage());
            jsend(false, null, 'Smazn pizzy selhalo: ' . $e->getMessage());
        }
    }

if ($action === 'get-order-details') {
    $order_id = intval($_GET['id'] ?? 0);
    if (!$order_id) jsend(false, null, "Chybí ID objednávky!");

    try {
        // Upravený SQL dotaz pro správné načtení kódu stolu
        $orderSql = "
            SELECT o.*, ts.table_number, rt.table_code 
            FROM orders o 
            LEFT JOIN table_sessions ts ON o.table_session_id = ts.id 
            LEFT JOIN restaurant_tables rt ON ts.table_number = rt.table_number 
            WHERE o.id = ?
        ";
        $stmt = $pdo->prepare($orderSql);
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            jsend(false, null, "Objednávka nenalezena!");
            return;
        }

        // Přidáme debug výpis
       //error_log("Order details: " . json_encode($order));

        // Načteme položky objednávky
        $itemsSql = "SELECT * FROM order_items WHERE order_id = ?";
        $stmt = $pdo->prepare($itemsSql);
        $stmt->execute([$order_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $order['items'] = $items;
        
        jsend(true, $order);
    } catch (Exception $e) {
        //error_log("Error getting order details: " . $e->getMessage());
        jsend(false, null, "Chyba při načítání detailů objednávky");
    }
}

if ($action === 'delete-drink') {
    $type = $_GET['type'] ?? '';

    if (!$type) jsend(false, null, 'Chybí typ nápoje!');

    try {
        $stmt = $pdo->prepare("DELETE FROM drink_types WHERE type = ?");
        $result = $stmt->execute([$type]);
        
        if ($result) {
            jsend(true);
        } else {
            jsend(false, null, 'Nápoj se nepodařilo smazat');
        }
    } catch (Exception $e) {
        error_log('Delete drink failed: ' . $e->getMessage());
        jsend(false, null, 'Smazání nápoje selhalo: ' . $e->getMessage());
    }
}
   // Statistics actions
if ($action === 'calculate-statistics') {
    $today = date('Y-m-d');
    
    try {
        // JEDEN kombinovaný SQL dotaz místo více samostatných
        $combinedSql = "
            SELECT 
                -- Průměrné časy
                AVG(CASE WHEN oi.item_type = 'pizza' AND oi.prepared_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(MINUTE, o.created_at, oi.prepared_at) END) as avg_kitchen_time,
                AVG(CASE WHEN oi.item_type = 'drink' AND oi.prepared_at IS NOT NULL 
                    THEN TIMESTAMPDIFF(MINUTE, o.created_at, oi.prepared_at) END) as avg_bar_time,
                -- Spálené pizzy
                (SELECT COUNT(*) FROM burnt_pizzas_log WHERE DATE(burnt_at) = CURDATE()) as burnt_pizzas
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE DATE(oi.prepared_at) = ?
        ";
        
        $stmt = $pdo->prepare($combinedSql);
        $stmt->execute([$today]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $avg_kitchen_time = round($result['avg_kitchen_time'] ?? 0);
        $avg_bar_time = round($result['avg_bar_time'] ?? 0);
        $burnt_pizzas = intval($result['burnt_pizzas'] ?? 0);
        
        // Rychlá aktualizace daily_stats
        $pdo->prepare("
            UPDATE daily_stats 
            SET avg_kitchen_time = ?, avg_bar_time = ?, burnt_items = ?
            WHERE date = ?
        ")->execute([$avg_kitchen_time, $avg_bar_time, $burnt_pizzas, $today]);

        // Rychlé načtení daily_stats
        $dailyStats = $pdo->prepare("SELECT * FROM daily_stats WHERE date = ? LIMIT 1");
        $dailyStats->execute([$today]);
        $stats = $dailyStats->fetch(PDO::FETCH_ASSOC) ?: [];

        jsend(true, [
            'pizzas_today' => intval($stats['total_pizzas'] ?? 0),
            'drinks_today' => intval($stats['total_drinks'] ?? 0),
            'revenue_today' => floatval($stats['total_revenue'] ?? 0),
            'burnt_pizzas' => $burnt_pizzas,
            'efficiency' => $stats['total_pizzas'] > 0 ? round(($stats['total_pizzas'] / ($stats['total_pizzas'] + $burnt_pizzas)) * 100, 2) : 0,
            'avg_kitchen_time' => $avg_kitchen_time,
            'avg_bar_time' => $avg_bar_time
        ]);

    } catch (Exception $e) {
        jsend(false, null, "Chyba při výpočtu statistik: " . $e->getMessage());
    }
}

    if ($action === 'clear-daily-stats') {
    try {
        // Ensure clean output buffer
        ob_clean();
        
        $today = date('Y-m-d');
        //error_log("Starting clear-daily-stats for date: " . $today);
        
        // Reset denních statistik pro dnešek
        $stmt = $pdo->prepare("
            UPDATE daily_stats 
            SET total_orders = 0,
                total_pizzas = 0,
                total_drinks = 0,
                total_revenue = 0,
                burnt_items = 0,
                avg_preparation_time = 0,
                avg_kitchen_time = 0,
                avg_bar_time = 0
            WHERE date = ?
        ");
        $stmt->execute([$today]);
        $rowCount = $stmt->rowCount();
        //error_log("Update daily_stats affected rows: " . $rowCount);

        // PŘIDÁNO: Smazání dnešních spálených pizz
        $stmt = $pdo->prepare("DELETE FROM burnt_pizzas_log WHERE DATE(burnt_at) = ?");
        $stmt->execute([$today]);
        $burntDeleted = $stmt->rowCount();
        //error_log("Deleted burnt pizzas: " . $burntDeleted);

        // Přímý výstup JSON odpovědi místo použití jsend()
        $response = [
            'success' => true,
            'data' => [
                'message' => "Denní statistiky byly vymazány! (Smazáno {$burntDeleted} spálených pizz)",
                'deleted_burnt_pizzas' => $burntDeleted
            ],
            'error' => null
        ];
        
        //error_log("Sending response: " . json_encode($response));
        
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, 
            JSON_UNESCAPED_UNICODE | 
            JSON_UNESCAPED_SLASHES | 
            JSON_PRETTY_PRINT
        );
        exit;

    } catch (Exception $e) {
        //error_log("Clear daily stats error: " . $e->getMessage());
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'data' => null,
            'error' => 'Smazání denních statistik selhalo: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
// Všechny položky kuchyně pro statistiky (včetně všech typů jídel)
if ($action === 'all-kitchen-items') {
    $q = $pdo->query("
        SELECT 
            oi.*,
            o.table_session_id,
            o.created_at,
            o.printed_at,
            o.id as order_id,
            ts.table_number,
            rt.table_code,
            rt.notes,
            rt.status as table_status
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN table_sessions ts ON o.table_session_id = ts.id
        JOIN restaurant_tables rt ON ts.table_number = rt.table_number
        WHERE oi.item_type IN ('pizza', 'pasta', 'predkrm', 'dezert', 'drink')
        AND oi.status IN ('pending','preparing')  -- ← Odstranil jsem 'ready'!
        ORDER BY 
            CASE WHEN o.printed_at IS NULL THEN 0 ELSE 1 END,
            o.created_at DESC
    ");
    
    if (!$q) {
        jsend(false, null, "Chyba při načítání všech položek pro kuchyň");
        return;
    }

    $items = $q->fetchAll(PDO::FETCH_ASSOC);
    jsend(true, ['items' => $items]);
}

// Aktualizace poznámky u stolu
if ($action === 'update-table-note') {
    $table_number = intval($_GET['table_number'] ?? 0);
    $order_id = intval($_GET['order_id'] ?? 0);

    if (!$table_number || !$order_id) {
        jsend(false, null, "Chybí číslo stolu nebo ID objednávky!");
    }

    try {
        $pdo->beginTransaction();

        // Debug log
        //error_log("Updating print status for table: {$table_number}, order: {$order_id}");

        // Aktualizace poznámky u stolu - OPRAVENO kódování
        $currentTime = date('H:i:s');
        $printNote = " - Tisk: " . $currentTime; // Změněna poznámka na jednodušší formát
        
        $stmt = $pdo->prepare("
            UPDATE restaurant_tables 
            SET notes = IF(notes IS NULL OR notes = '', ?, CONCAT(notes, ?))
            WHERE table_number = ?
        ");
        $stmt->execute([$printNote, $printNote, $table_number]);

        // Aktualizace příznaku vytištění v objednávce
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET printed_at = NOW()
            WHERE id = ?
        ");
        $result = $stmt->execute([$order_id]);
        
        // Debug log
        //error_log("Update result: " . ($result ? "success" : "failed"));

        // Ověření aktualizace
        $check = $pdo->prepare("SELECT printed_at, (SELECT notes FROM restaurant_tables WHERE table_number = ?) as notes FROM orders WHERE id = ?");
        $check->execute([$table_number, $order_id]);
        $result = $check->fetch(PDO::FETCH_ASSOC);
        
        //error_log("Update result: " . json_encode($result));

        $pdo->commit();
        jsend(true, [
            'printed_at' => $result['printed_at'],
            'notes' => $result['notes'],
            'table_number' => $table_number,
            'order_id' => $order_id
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        //error_log('Update print status failed: ' . $e->getMessage());
        jsend(false, null, 'Aktualizace stavu tisku selhala: ' . $e->getMessage());
    }

} else {
    // Default case pro neznámé akce
    jsend(false, null, 'Neznámá akce');
}

} catch (Exception $e) {
    //error_log('General error: ' . $e->getMessage());
    jsend(false, null, $e->getMessage());
}

?>