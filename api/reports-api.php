<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

session_start();

function getDb() {
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;dbname=pizza_orders;charset=utf8mb4', 'pizza_user', '123789Pizza@');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES utf8mb4");
        return $pdo;
    } catch (PDOException $e) {
        return ['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()];
    }
}

function generateDateRangePHP($startDate, $endDate) {
    $dates = [];
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    
    $end = $end->modify('+1 day');
    
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    
    foreach ($period as $date) {
        $dates[] = $date->format('Y-m-d');
    }
    
    error_log("generateDateRangePHP: $startDate to $endDate = " . implode(', ', $dates));
    return $dates;
}

function processCategory($category) {
    if (is_array($category)) {
        $placeholders = str_repeat('?,', count($category) - 1) . '?';
        return [
            'condition' => "oi.item_type IN ($placeholders)",
            'params' => $category
        ];
    } elseif ($category !== 'all') {
        return [
            'condition' => "oi.item_type = ?",
            'params' => [$category]
        ];
    }
    return null;
}

function getEmployees() {
    $db = getDb();
    if (is_array($db) && isset($db['status']) && $db['status'] === 'error') {
        return $db;
    }
    $pdo = $db;

    try {
        $sql = "
            SELECT 
                o.employee_name as name,
                COUNT(*) as count
            FROM orders o
            WHERE o.employee_name IS NOT NULL 
            AND o.employee_name != ''
            AND DATE(o.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY o.employee_name
            ORDER BY count DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'data' => ['employees' => $employees]
        ];
    } catch (PDOException $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function getSalesData($params = []) {
    $db = getDb();
    if (is_array($db) && isset($db['status']) && $db['status'] === 'error') {
        return $db;
    }
    $pdo = $db;

    error_log("=== getSalesData DEBUG ===");
    error_log("Raw params: " . json_encode($params));
    
    $dates = $params['dates'] ?? [date('Y-m-d')];
    
    if (is_string($dates)) {
        if (strpos($dates, ' to ') !== false) {
            $parts = explode(' to ', $dates);
            if (count($parts) === 2) {
                $startDate = trim($parts[0]);
                $endDate = trim($parts[1]);
                $dates = generateDateRangePHP($startDate, $endDate);
            } else {
                $dates = [trim($dates)];
            }
        } else {
            $dates = [trim($dates)];
        }
    }
    
    if (is_array($dates)) {
        $dates = array_map('trim', $dates);
        $dates = array_filter($dates);
        $dates = array_unique($dates);
        $dates = array_values($dates);
    }

    if (empty($dates)) {
        $dates = [date('Y-m-d')];
    }

    error_log("Processed dates: " . json_encode($dates));
    error_log("Date count: " . count($dates));

    $category = $params['category'] ?? 'all';
    $employee_name = $params['employee_name'] ?? '';
    $payment_method = $params['payment_method'] ?? null;
    $view = $params['view'] ?? 'default';

    if (empty($payment_method)) {
        $payment_method = null;
    }

    $placeholders = str_repeat('?,', count($dates) - 1) . '?';
    error_log("Placeholders: $placeholders");
    
    $result = [];

    try {
        // DEFAULT (základní přehled)
        if ($view === 'default') {
            $sql_base = "
                SELECT
                    SUM(oi.unit_price * oi.quantity) AS total,
                    COUNT(DISTINCT o.id) AS pocet
                FROM orders o
                JOIN order_items oi ON o.id = oi.order_id
                JOIN table_sessions ts ON o.table_session_id = ts.id 
                LEFT JOIN payments p ON ts.id = p.table_session_id
                WHERE DATE(o.created_at) IN ($placeholders)
                AND oi.status = 'paid'
            ";
            
            $daily_params = $dates;
            
            if ($payment_method !== null && $payment_method !== '') {
                $payment_method_db = ($payment_method === 'hotovost') ? 'hotovost' : 'karta';
                $sql_base .= " AND p.payment_method = ?";
                $daily_params[] = $payment_method_db;
            }
            
            $categoryFilter = processCategory($category);
            if ($categoryFilter) {
                $sql_base .= " AND " . $categoryFilter['condition'];
                $daily_params = array_merge($daily_params, $categoryFilter['params']);
            }
            
            if ($employee_name) {
                $sql_base .= " AND o.employee_name = ?";
                $daily_params[] = $employee_name;
            }

            $stmt = $pdo->prepare($sql_base);
            $stmt->execute($daily_params);
            $dailyStats = $stmt->fetch(PDO::FETCH_ASSOC);

            // Nejprodávanější produkty
            $sql_base = "
                SELECT 
                    CASE
                        WHEN oi.item_name LIKE '00.%' THEN REPLACE(oi.item_name, '00. ', '')
                        WHEN oi.item_name LIKE '01.%' THEN REPLACE(oi.item_name, '01. ', '')
                        WHEN oi.item_name LIKE '02.%' THEN REPLACE(oi.item_name, '02. ', '')
                        WHEN oi.item_name LIKE '03.%' THEN REPLACE(oi.item_name, '03. ', '')
                        WHEN oi.item_name LIKE '04.%' THEN REPLACE(oi.item_name, '04. ', '')
                        WHEN oi.item_name LIKE '05.%' THEN REPLACE(oi.item_name, '05. ', '')
                        WHEN oi.item_name LIKE '06.%' THEN REPLACE(oi.item_name, '06. ', '')
                        ELSE oi.item_name
                    END AS nazev,
                    oi.item_type AS kategorie,
                    SUM(oi.quantity) AS pocet,
                    SUM(oi.unit_price * oi.quantity) AS trzba
                FROM orders o 
                JOIN order_items oi ON o.id = oi.order_id 
                JOIN table_sessions ts ON o.table_session_id = ts.id 
                LEFT JOIN payments p ON ts.id = p.table_session_id
                WHERE DATE(o.created_at) IN ($placeholders)
                AND oi.status = 'paid'
            ";
            
            $product_params = $dates;
            
            if ($payment_method !== null && $payment_method !== '') {
                $payment_method_db = ($payment_method === 'hotovost') ? 'hotovost' : 'karta';
                $sql_base .= " AND p.payment_method = ?";
                $product_params[] = $payment_method_db;
            }
            
            $categoryFilter = processCategory($category);
            if ($categoryFilter) {
                $sql_base .= " AND " . $categoryFilter['condition'];
                $product_params = array_merge($product_params, $categoryFilter['params']);
            }
            
            if ($employee_name) {
                $sql_base .= " AND o.employee_name = ?";
                $product_params[] = $employee_name;
            }
            
            $sql = $sql_base . "
                GROUP BY nazev, oi.item_type
                ORDER BY pocet DESC
                LIMIT 100
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($product_params);
            $produkty = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result['dnesni_prodeje'] = [
                'total' => $dailyStats['total'] ?? 0,
                'pocet' => $dailyStats['pocet'] ?? 0,
                'produkty' => $produkty
            ];
        }

        // CATEGORIES
        if ($view === 'categories') {
            $sql_base = "
                SELECT
                    oi.item_type AS kategorie,
                    SUM(oi.unit_price * oi.quantity) AS trzba,
                    SUM(oi.quantity) AS pocet
                FROM orders o
                JOIN order_items oi ON o.id = oi.order_id
                JOIN table_sessions ts ON o.table_session_id = ts.id 
                LEFT JOIN payments p ON ts.id = p.table_session_id
                WHERE DATE(o.created_at) IN ($placeholders)
                AND oi.status = 'paid'
            ";

            $category_params = $dates;
            
            if ($payment_method !== null && $payment_method !== '') {
                $payment_method_db = ($payment_method === 'hotovost') ? 'hotovost' : 'karta';
                $sql_base .= " AND p.payment_method = ?";
                $category_params[] = $payment_method_db;
            }
            
            $categoryFilter = processCategory($category);
            if ($categoryFilter) {
                $sql_base .= " AND " . $categoryFilter['condition'];
                $category_params = array_merge($category_params, $categoryFilter['params']);
            }
            
            if ($employee_name) {
                $sql_base .= " AND o.employee_name = ?";
                $category_params[] = $employee_name;
            }

            $sql = $sql_base . "
                GROUP BY oi.item_type
                ORDER BY trzba DESC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($category_params);
            $kategorie = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result['kategorie'] = $kategorie;
        }

        // TOP_ORDERS
        if ($view === 'top_orders') {
            $sql_base = "
                SELECT 
                    p.id,
                    p.amount,
                    p.payment_method,
                    p.paid_at,
                    ts.table_number,
                    o.employee_name
                FROM payments p
                JOIN table_sessions ts ON p.table_session_id = ts.id
                LEFT JOIN orders o ON ts.id = o.table_session_id
                WHERE DATE(p.paid_at) IN ($placeholders)
            ";

            $top_params = $dates;
            
            if ($payment_method !== null && $payment_method !== '') {
                $payment_method_db = ($payment_method === 'hotovost') ? 'hotovost' : 'karta';
                $sql_base .= " AND p.payment_method = ?";
                $top_params[] = $payment_method_db;
            }
            
            if ($employee_name) {
                $sql_base .= " AND o.employee_name = ?";
                $top_params[] = $employee_name;
            }

            $sql = $sql_base . "
                GROUP BY p.id, p.amount, p.payment_method, p.paid_at, ts.table_number, o.employee_name
                ORDER BY p.amount DESC
                LIMIT 50
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($top_params);
            $top_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result['top_orders'] = $top_orders;
        }

        // TRENDS
        if ($view === 'trends') {
            $sql_base = "
                SELECT
                    DATE(o.created_at) AS den,
                    SUM(oi.unit_price * oi.quantity) AS trzba,
                    COUNT(DISTINCT o.id) AS pocet_objednavek
                FROM orders o
                JOIN order_items oi ON o.id = oi.order_id
                JOIN table_sessions ts ON o.table_session_id = ts.id 
                LEFT JOIN payments p ON ts.id = p.table_session_id
                WHERE DATE(o.created_at) IN ($placeholders)
                AND oi.status = 'paid'
            ";

            $trends_params = $dates;
            
            if ($payment_method !== null && $payment_method !== '') {
                $payment_method_db = ($payment_method === 'hotovost') ? 'hotovost' : 'karta';
                $sql_base .= " AND p.payment_method = ?";
                $trends_params[] = $payment_method_db;
            }
            
            $categoryFilter = processCategory($category);
            if ($categoryFilter) {
                $sql_base .= " AND " . $categoryFilter['condition'];
                $trends_params = array_merge($trends_params, $categoryFilter['params']);
            }
            
            if ($employee_name) {
                $sql_base .= " AND o.employee_name = ?";
                $trends_params[] = $employee_name;
            }

            $sql = $sql_base . "
                GROUP BY den
                ORDER BY den ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($trends_params);
            $trendy = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result['trendy'] = $trendy;
        }

        // ANALYTICS_DATA
        if ($view === 'analytics_data') {
            error_log("=== ANALYTICS_DATA VIEW DEBUG ===");
            
            // Data pro graf platebních metod
            $sql = "
                SELECT 
                    p.payment_method,
                    SUM(p.amount) as amount
                FROM payments p
                JOIN table_sessions ts ON p.table_session_id = ts.id
                WHERE DATE(p.paid_at) IN ($placeholders)
                GROUP BY p.payment_method
                ORDER BY amount DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($dates);
            $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result['payment_methods'] = $payment_methods;

            // Data pro graf zaměstnanců
            $sql = "
                SELECT 
                    o.employee_name,
                    SUM(oi.unit_price * oi.quantity) as revenue,
                    COUNT(DISTINCT o.id) as orders_count
                FROM orders o
                JOIN order_items oi ON o.id = oi.order_id
                WHERE DATE(o.created_at) IN ($placeholders)
                AND oi.status = 'paid'
                AND o.employee_name IS NOT NULL
                AND o.employee_name != ''
                GROUP BY o.employee_name
                ORDER BY revenue DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($dates);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result['employees'] = $employees;
            
            // Data pro graf produktů
            $sql_base = "
                SELECT 
                    CASE
                        WHEN oi.item_name LIKE '00.%' THEN REPLACE(oi.item_name, '00. ', '')
                        WHEN oi.item_name LIKE '01.%' THEN REPLACE(oi.item_name, '01. ', '')
                        WHEN oi.item_name LIKE '02.%' THEN REPLACE(oi.item_name, '02. ', '')
                        WHEN oi.item_name LIKE '03.%' THEN REPLACE(oi.item_name, '03. ', '')
                        WHEN oi.item_name LIKE '04.%' THEN REPLACE(oi.item_name, '04. ', '')
                        WHEN oi.item_name LIKE '05.%' THEN REPLACE(oi.item_name, '05. ', '')
                        WHEN oi.item_name LIKE '06.%' THEN REPLACE(oi.item_name, '06. ', '')
                        ELSE oi.item_name
                    END AS nazev,
                    oi.item_type AS kategorie,
                    SUM(oi.quantity) AS pocet,
                    SUM(oi.unit_price * oi.quantity) AS trzba
                FROM orders o 
                JOIN order_items oi ON o.id = oi.order_id 
                JOIN table_sessions ts ON o.table_session_id = ts.id 
                LEFT JOIN payments p ON ts.id = p.table_session_id
                WHERE DATE(o.created_at) IN ($placeholders)
                AND oi.status = 'paid'
                GROUP BY nazev, oi.item_type
                ORDER BY pocet DESC
                LIMIT 20
            ";

            $stmt = $pdo->prepare($sql_base);
            $stmt->execute($dates);
            $analytics_produkty = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Analytics products count: " . count($analytics_produkty));

            if (!isset($result['dnesni_prodeje'])) {
                $result['dnesni_prodeje'] = [];
            }
            $result['dnesni_prodeje']['produkty'] = $analytics_produkty;
            
            // Základní statistiky pro analytics
            $sql_base = "
                SELECT
                    SUM(oi.unit_price * oi.quantity) AS total,
                    COUNT(DISTINCT o.id) AS pocet
                FROM orders o
                JOIN order_items oi ON o.id = oi.order_id
                JOIN table_sessions ts ON o.table_session_id = ts.id 
                LEFT JOIN payments p ON ts.id = p.table_session_id
                WHERE DATE(o.created_at) IN ($placeholders)
                AND oi.status = 'paid'
            ";
            
            $stmt = $pdo->prepare($sql_base);
            $stmt->execute($dates);
            $analytics_stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $result['dnesni_prodeje']['total'] = $analytics_stats['total'] ?? 0;
            $result['dnesni_prodeje']['pocet'] = $analytics_stats['pocet'] ?? 0;
        }

        // Pokud potřebujeme kategorie pro grafy
        if (in_array($view, ['default', 'analytics', 'analytics_data'])) {
    // KATEGORIE
    $sql_base = "
        SELECT
            oi.item_type AS kategorie,
            SUM(oi.unit_price * oi.quantity) AS trzba,
            SUM(oi.quantity) AS pocet
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN table_sessions ts ON o.table_session_id = ts.id 
        LEFT JOIN payments p ON ts.id = p.table_session_id
        WHERE DATE(o.created_at) IN ($placeholders)
        AND oi.status = 'paid'
    ";

    $category_params = $dates;
    
    if ($payment_method !== null && $payment_method !== '') {
        $payment_method_db = ($payment_method === 'hotovost') ? 'hotovost' : 'karta';
        $sql_base .= " AND p.payment_method = ?";
        $category_params[] = $payment_method_db;
    }
    
    if ($employee_name) {
        $sql_base .= " AND o.employee_name = ?";
        $category_params[] = $employee_name;
    }

    $sql = $sql_base . "
        GROUP BY oi.item_type
        ORDER BY trzba DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($category_params);
    $kategorie = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result['kategorie'] = $kategorie;

    // PRODUKTY pro analytics view (PŘIDÁNO)
    if ($view === 'analytics') {
        error_log("=== ADDING PRODUCTS TO ANALYTICS VIEW ===");
        
        $sql_products = "
            SELECT 
                CASE
                    WHEN oi.item_name LIKE '00.%' THEN REPLACE(oi.item_name, '00. ', '')
                    WHEN oi.item_name LIKE '01.%' THEN REPLACE(oi.item_name, '01. ', '')
                    WHEN oi.item_name LIKE '02.%' THEN REPLACE(oi.item_name, '02. ', '')
                    WHEN oi.item_name LIKE '03.%' THEN REPLACE(oi.item_name, '03. ', '')
                    WHEN oi.item_name LIKE '04.%' THEN REPLACE(oi.item_name, '04. ', '')
                    WHEN oi.item_name LIKE '05.%' THEN REPLACE(oi.item_name, '05. ', '')
                    WHEN oi.item_name LIKE '06.%' THEN REPLACE(oi.item_name, '06. ', '')
                    ELSE oi.item_name
                END AS nazev,
                oi.item_type AS kategorie,
                SUM(oi.quantity) AS pocet,
                SUM(oi.unit_price * oi.quantity) AS trzba
            FROM orders o 
            JOIN order_items oi ON o.id = oi.order_id 
            JOIN table_sessions ts ON o.table_session_id = ts.id 
            LEFT JOIN payments p ON ts.id = p.table_session_id
            WHERE DATE(o.created_at) IN ($placeholders)
            AND oi.status = 'paid'
        ";
        
        $product_params = $dates;
        
        if ($payment_method !== null && $payment_method !== '') {
            $payment_method_db = ($payment_method === 'hotovost') ? 'hotovost' : 'karta';
            $sql_products .= " AND p.payment_method = ?";
            $product_params[] = $payment_method_db;
        }
        
        if ($employee_name) {
            $sql_products .= " AND o.employee_name = ?";
            $product_params[] = $employee_name;
        }
        
        $sql_products .= "
            GROUP BY nazev, oi.item_type
            ORDER BY pocet DESC
            LIMIT 20
        ";

        error_log("Analytics products SQL: $sql_products");
        error_log("Analytics products params: " . json_encode($product_params));

        $stmt = $pdo->prepare($sql_products);
        $stmt->execute($product_params);
        $analytics_produkty = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Analytics products count: " . count($analytics_produkty));
        error_log("Sample products: " . json_encode(array_slice($analytics_produkty, 0, 3)));

        // Přidáme produkty do výsledku
        if (!isset($result['dnesni_prodeje'])) {
            $result['dnesni_prodeje'] = [];
        }
        $result['dnesni_prodeje']['produkty'] = $analytics_produkty;
        
        // Základní statistiky
        $sql_stats = "
            SELECT
                SUM(oi.unit_price * oi.quantity) AS total,
                COUNT(DISTINCT o.id) AS pocet
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN table_sessions ts ON o.table_session_id = ts.id 
            LEFT JOIN payments p ON ts.id = p.table_session_id
            WHERE DATE(o.created_at) IN ($placeholders)
            AND oi.status = 'paid'
        ";
        
        $stmt = $pdo->prepare($sql_stats);
        $stmt->execute($dates);
        $analytics_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $result['dnesni_prodeje']['total'] = $analytics_stats['total'] ?? 0;
        $result['dnesni_prodeje']['pocet'] = $analytics_stats['pocet'] ?? 0;
        
        error_log("Analytics stats: " . json_encode($analytics_stats));
    }
}
if (in_array($view, ['default', 'analytics', 'analytics_data'])) {
    // Základní food cost statistiky
    $sql_foodcost = "
        SELECT 
            -- Jídlo
            SUM(CASE WHEN oi.item_type IN ('pizza', 'pasta', 'predkrm', 'dezert') 
                THEN oi.unit_price * oi.quantity ELSE 0 END) AS food_revenue,
            SUM(CASE WHEN oi.item_type IN ('pizza', 'pasta', 'predkrm', 'dezert') 
                THEN COALESCE(pt.cost_price, 0) * oi.quantity ELSE 0 END) AS food_costs,
            
            -- Nápoje  
            SUM(CASE WHEN oi.item_type IN ('drink', 'pivo', 'vino', 'nealko', 'spritz', 'negroni', 'koktejl', 'digestiv') 
                THEN oi.unit_price * oi.quantity ELSE 0 END) AS drink_revenue,
            SUM(CASE WHEN oi.item_type IN ('drink', 'pivo', 'vino', 'nealko', 'spritz', 'negroni', 'koktejl', 'digestiv') 
                THEN COALESCE(dt.cost_price, 0) * oi.quantity ELSE 0 END) AS drink_costs,
                
            -- Celkem
            SUM(oi.unit_price * oi.quantity) AS total_revenue,
            SUM(COALESCE(pt.cost_price, dt.cost_price, 0) * oi.quantity) AS total_costs
            
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN table_sessions ts ON o.table_session_id = ts.id 
        LEFT JOIN payments p ON ts.id = p.table_session_id
        LEFT JOIN pizza_types pt ON oi.item_type IN ('pizza', 'pasta', 'predkrm', 'dezert') 
            AND oi.item_name LIKE CONCAT('%', pt.name, '%')
        LEFT JOIN drink_types dt ON oi.item_type IN ('drink', 'pivo', 'vino', 'nealko', 'spritz', 'negroni', 'koktejl', 'digestiv') 
            AND oi.item_name LIKE CONCAT('%', dt.name, '%')
        WHERE DATE(o.created_at) IN ($placeholders)
        AND oi.status = 'paid'
    ";
    
    $foodcost_params = $dates;
    
    if ($payment_method !== null && $payment_method !== '') {
        $payment_method_db = ($payment_method === 'hotovost') ? 'hotovost' : 'karta';
        $sql_foodcost .= " AND p.payment_method = ?";
        $foodcost_params[] = $payment_method_db;
    }
    
    if ($employee_name) {
        $sql_foodcost .= " AND o.employee_name = ?";
        $foodcost_params[] = $employee_name;
    }
    
    $stmt = $pdo->prepare($sql_foodcost);
    $stmt->execute($foodcost_params);
    $foodcost_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Vypočítáme food cost procenta
    $food_revenue = floatval($foodcost_data['food_revenue'] ?? 0);
    $food_costs = floatval($foodcost_data['food_costs'] ?? 0);
    $drink_revenue = floatval($foodcost_data['drink_revenue'] ?? 0);
    $drink_costs = floatval($foodcost_data['drink_costs'] ?? 0);
    $total_revenue = floatval($foodcost_data['total_revenue'] ?? 0);
    $total_costs = floatval($foodcost_data['total_costs'] ?? 0);
    
    $food_cost_percent = $food_revenue > 0 ? ($food_costs / $food_revenue * 100) : 0;
    $drink_cost_percent = $drink_revenue > 0 ? ($drink_costs / $drink_revenue * 100) : 0;
    $total_cost_percent = $total_revenue > 0 ? ($total_costs / $total_revenue * 100) : 0;
    
    $result['food_cost_analysis'] = [
        'food' => [
            'revenue' => $food_revenue,
            'costs' => $food_costs,
            'margin' => $food_revenue - $food_costs,
            'cost_percent' => $food_cost_percent
        ],
        'drinks' => [
            'revenue' => $drink_revenue,
            'costs' => $drink_costs,
            'margin' => $drink_revenue - $drink_costs,
            'cost_percent' => $drink_cost_percent
        ],
        'total' => [
            'revenue' => $total_revenue,
            'costs' => $total_costs,
            'margin' => $total_revenue - $total_costs,
            'cost_percent' => $total_cost_percent
        ]
    ];
    
    // Top položky podle marže
    $sql_margin = "
        SELECT 
            CASE
                WHEN oi.item_name LIKE '00.%' THEN REPLACE(oi.item_name, '00. ', '')
                WHEN oi.item_name LIKE '01.%' THEN REPLACE(oi.item_name, '01. ', '')
                WHEN oi.item_name LIKE '02.%' THEN REPLACE(oi.item_name, '02. ', '')
                WHEN oi.item_name LIKE '03.%' THEN REPLACE(oi.item_name, '03. ', '')
                WHEN oi.item_name LIKE '04.%' THEN REPLACE(oi.item_name, '04. ', '')
                WHEN oi.item_name LIKE '05.%' THEN REPLACE(oi.item_name, '05. ', '')
                WHEN oi.item_name LIKE '06.%' THEN REPLACE(oi.item_name, '06. ', '')
                ELSE oi.item_name
            END AS nazev,
            oi.item_type AS kategorie,
            SUM(oi.quantity) AS pocet,
            SUM(oi.unit_price * oi.quantity) AS trzba,
            AVG(COALESCE(pt.cost_price, dt.cost_price, 0)) AS avg_cost_price,
            SUM(oi.unit_price * oi.quantity) - SUM(COALESCE(pt.cost_price, dt.cost_price, 0) * oi.quantity) AS total_margin,
            CASE 
                WHEN SUM(oi.unit_price * oi.quantity) > 0 
                THEN (SUM(COALESCE(pt.cost_price, dt.cost_price, 0) * oi.quantity) / SUM(oi.unit_price * oi.quantity)) * 100
                ELSE 0 
            END AS cost_percent
        FROM orders o 
        JOIN order_items oi ON o.id = oi.order_id 
        JOIN table_sessions ts ON o.table_session_id = ts.id 
        LEFT JOIN payments p ON ts.id = p.table_session_id
        LEFT JOIN pizza_types pt ON oi.item_type IN ('pizza', 'pasta', 'predkrm', 'dezert') 
            AND oi.item_name LIKE CONCAT('%', pt.name, '%')
        LEFT JOIN drink_types dt ON oi.item_type IN ('drink', 'pivo', 'vino', 'nealko', 'spritz', 'negroni', 'koktejl', 'digestiv') 
            AND oi.item_name LIKE CONCAT('%', dt.name, '%')
        WHERE DATE(o.created_at) IN ($placeholders)
        AND oi.status = 'paid'
    ";
    
    $margin_params = $dates;
    
    if ($payment_method !== null && $payment_method !== '') {
        $payment_method_db = ($payment_method === 'hotovost') ? 'hotovost' : 'karta';
        $sql_margin .= " AND p.payment_method = ?";
        $margin_params[] = $payment_method_db;
    }
    
    if ($employee_name) {
        $sql_margin .= " AND o.employee_name = ?";
        $margin_params[] = $employee_name;
    }
    
    $sql_margin .= "
        GROUP BY nazev, oi.item_type
        ORDER BY total_margin DESC
        LIMIT 15
    ";
    
    $stmt = $pdo->prepare($sql_margin);
    $stmt->execute($margin_params);
    $margin_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result['top_margin_items'] = $margin_items;
}
        return [
            'status' => 'success',
            'time' => date('Y-m-d H:i:s'),
            'user' => $_SESSION['username'] ?? 'centycz',
            'params' => $params,
            'data' => $result
        ];

    } catch (PDOException $e) {
        error_log("SQL Error: " . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// Hlavní logika
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['action']) && $_GET['action'] === 'get-employees') {
        echo json_encode(getEmployees());
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Invalid GET request']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $params = json_decode($input, true);
    
    if ($params === null) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
        exit;
    }
    
    $result = getSalesData($params);
    echo json_encode($result);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
?>