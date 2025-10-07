<?php
// بدء الجلسة في بداية الملف
session_start();

// order_details.php - نسخة معدلة بإضافة الدوال الناقصة وتعديلها للعمل بدون جدول production_lines
require_once "includes/db.php";
include "includes/navbar.php";

// إضافة حماية CSRF
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// توليد رمز CSRF للصفحة
$csrf_token = generateCSRFToken();

// دالة حساب تكلفة العمالة - نسخة معدلة للعمل بدون جدول production_lines
function calculateLaborCost(mysqli $conn, int $product_id, int $quantity): array {
    $result = [
        'labor_cost_per_unit' => 0,
        'total_labor_cost' => 0,
        'details' => []
    ];

    // جلب إعدادات النظام
    $settings = $conn->query("SELECT * FROM settings LIMIT 1")->fetch_assoc();
    $labor_cost_per_hour = $settings['labor_cost_per_hour'] ?? 50;

    // جلب بيانات المنتج
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();

    // استخدام قيم المنتج الأساسية
    $production_hours = $product['production_hours'] ?? 0;
    $labor_cost = $product['labor_cost'] ?? 0;

    // إذا كانت هناك تكلفة عمالة محددة للمنتج، استخدمها
    if ($labor_cost > 0) {
        $total_labor_cost = $labor_cost * $quantity;
        $labor_cost_per_unit = $labor_cost;
    } else {
        // حساب تكلفة العمالة بناءً على ساعات الإنتاج وتكلفة الساعة
        $total_labor_cost = $production_hours * $labor_cost_per_hour * $quantity;
        $labor_cost_per_unit = $production_hours * $labor_cost_per_hour;
    }

    // إضافة التفاصيل
    $result['details'][] = [
        'machine' => 'الإنتاج العام',
        'minutes' => $production_hours * 60,
        'hours' => $production_hours,
        'workers' => 1,
        'rate' => $labor_cost_per_hour,
        'cost' => $total_labor_cost
    ];

    $result['labor_cost_per_unit'] = $labor_cost_per_unit;
    $result['total_labor_cost'] = $total_labor_cost;

    return $result;
}

// دالة حساب تكلفة الماكينات - نسخة معدلة للعمل بدون جدول production_lines
function calculateMachineCost(mysqli $conn, int $product_id, int $quantity): array {
    $result = [
        'machine_cost_per_unit' => 0,
        'total_machine_cost' => 0,
        'details' => []
    ];

    // جلب إعدادات النظام
    $settings = $conn->query("SELECT * FROM settings LIMIT 1")->fetch_assoc();
    $electricity_cost_per_hour = $settings['electricity_cost_per_hour'] ?? 5;
    $monthly_maintenance_cost = $settings['monthly_maintenance_cost'] ?? 1000;
    $working_days_per_month = $settings['working_days_per_month'] ?? 24;
    $working_hours_per_day = $settings['working_hours_per_day'] ?? 8;

    // جلب بيانات المنتج
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();

    // استخدام قيم المنتج الأساسية
    $production_hours = $product['production_hours'] ?? 0;

    // حساب تكلفة الكهرباء
    $electricity_cost = $production_hours * $electricity_cost_per_hour * $quantity;

    // حساب تكلفة الصيانة (توزيع التكلفة الشهرية على ساعات العمل)
    $total_monthly_hours = $working_hours_per_day * $working_days_per_month;
    $maintenance_cost_per_hour = $monthly_maintenance_cost / $total_monthly_hours;
    $maintenance_cost = $production_hours * $maintenance_cost_per_hour * $quantity;

    // حساب إجمالي تكلفة الماكينات
    $total_machine_cost = $electricity_cost + $maintenance_cost;
    $machine_cost_per_unit = $quantity > 0 ? $total_machine_cost / $quantity : 0;

    // إضافة التفاصيل
    $result['details'][] = [
        'machine' => 'الإنتاج العام',
        'qty' => $production_hours,
        'rate' => $electricity_cost_per_hour + $maintenance_cost_per_hour,
        'cost_unit' => $machine_cost_per_unit,
        'cost' => $total_machine_cost,
        'basis' => 'hour',
        'hourly_cost_base' => $electricity_cost_per_hour + $maintenance_cost_per_hour,
        'electricity_per_hour' => $electricity_cost_per_hour,
        'maintenance_per_hour' => $maintenance_cost_per_hour,
        'capacity_hours' => $total_monthly_hours,
        'line_machines' => 1,
        'minute_cost_converted' => false
    ];

    $result['machine_cost_per_unit'] = $machine_cost_per_unit;
    $result['total_machine_cost'] = $total_machine_cost;

    return $result;
}

// التأكد من وجود الأعمدة المطلوبة في جدول work_orders
function addColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if (!$res || $res->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

// إضافة الأعمدة المطلوبة
addColumnIfMissing($conn, 'work_orders', 'project_name', 'VARCHAR(255) DEFAULT NULL');
addColumnIfMissing($conn, 'work_orders', 'serial_number', 'VARCHAR(50) DEFAULT NULL');
addColumnIfMissing($conn, 'work_orders', 'month', 'INT DEFAULT NULL');
addColumnIfMissing($conn, 'work_orders', 'year', 'INT DEFAULT NULL');
addColumnIfMissing($conn, 'products', 'price', 'DECIMAL(10,2) DEFAULT 0');

// التحقق من وجود معرف الأمر
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: ./work_orders_out.php");
    exit;
}
$order_id = intval($_GET['id']);

// معالجة طلب إعادة حساب الأسعار
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalculate_prices'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        die("طلب غير صالح");
    }

    // جلب إعدادات النظام
    $settings = $conn->query("SELECT * FROM settings LIMIT 1")->fetch_assoc();
    $profit_margin = $settings['profit_margin'] ?? 10;

    // جلب سجلات الإنتاج
    $stmt = $conn->prepare("
       SELECT l.*, p.name as product_name, COALESCE(p.price, 0) as unit_price,
              p.industrial_wood_cost, p.natural_wood_cost, p.accessories_cost,
              p.industrial_waste_pct, p.natural_waste_pct, p.production_hours
       FROM production_log l
       JOIN products p ON l.product_id = p.id
       WHERE l.order_id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $production_result = $stmt->get_result();

    // تحديث أسعار البيع لكل سجل إنتاج
    while ($row = $production_result->fetch_assoc()) {
        $quantity = $row['quantity'];

        // حساب تكلفة المواد الخام مع حساب الهالك
        $industrial_wood_cost = $row['industrial_wood_cost'] ?? 0;
        $industrial_waste_pct = $row['industrial_waste_pct'] ?? 0;
        $natural_wood_cost = $row['natural_wood_cost'] ?? 0;
        $natural_waste_pct = $row['natural_waste_pct'] ?? 0;
        $accessories_cost = $row['accessories_cost'] ?? 0;

        $industrial_wood_with_waste = $industrial_wood_cost * (1 + $industrial_waste_pct / 100);
        $natural_wood_with_waste = $natural_wood_cost * (1 + $natural_waste_pct / 100);
        $material_cost_per_unit = $industrial_wood_with_waste + $natural_wood_with_waste + $accessories_cost;

        $item_material_cost = $material_cost_per_unit * $quantity;

        // استخدام القيم المحسوبة مسبقاً من جدول production_log إذا كانت متاحة
        if ($row['material_cost'] > 0) {
            $item_material_cost = $row['material_cost'];
        }

        // حساب تكلفة العمالة باستخدام الدالة الموحدة
        $laborCalc = calculateLaborCost($conn, intval($row['product_id']), intval($quantity));
        $item_labor_cost = $laborCalc['total_labor_cost'];

        // تطبيق معامل الوردية الثانية إن كانت مفعلة
        $order_shift2 = $conn->query("SELECT shift2 FROM work_orders WHERE id = $order_id")->fetch_assoc()['shift2'] ?? 0;
        if (intval($order_shift2) === 1) {
            $item_labor_cost *= (1 + ($settings['shift2_multiplier'] ?? 1.5));
        }

        // حساب تكلفة الماكينات باستخدام الدالة الموحدة
        $machineCalc = calculateMachineCost($conn, intval($row['product_id']), intval($quantity));
        $item_machine_cost = $machineCalc['total_machine_cost'];

        // حساب تكلفة الكهرباء
        $item_electricity_cost = $row['electricity_cost'];

        // حساب تكلفة الصيانة
        $item_maintenance_cost = $row['maintenance_cost'];

        // حساب التكاليف غير المباشرة الأساسية
        $base_cost_total = $item_material_cost + $item_labor_cost + $item_machine_cost;
        $indirect_cost1 = $base_cost_total * ($settings['indirect_percent'] / 100);
        $indirect_cost2 = $base_cost_total * ($settings['indirect_cost_percentage'] / 100);
        $item_indirect_cost = $indirect_cost1 + $indirect_cost2;

        // حساب حصة المنتج من التكاليف الثابتة الشهرية (الإيجار وتكاليف أخرى)
        $production_hours = $row['production_hours'] ?? 0;
        $monthly_fixed_costs = $settings['monthly_rent_cost'] + $settings['monthly_other_costs'];
        $total_monthly_hours = $settings['working_hours_per_day'] * $settings['working_days_per_month'];
        $cost_per_hour = $monthly_fixed_costs / $total_monthly_hours;
        $item_fixed_cost = $cost_per_hour * ($production_hours * $quantity);

        // حساب تكلفة الصيانة الإضافية كنسبة من تكلفة العمالة
        $item_additional_maintenance_cost = $item_labor_cost * ($settings['maintenance_cost_percentage'] / 100);

        // حساب التكلفة الإجمالية مع تطبيق معامل المخاطر
        $item_total_cost = ($base_cost_total + $item_indirect_cost + $item_fixed_cost + $item_additional_maintenance_cost) * $settings['risk_factor'];

        // حساب سعر البيع الجديد
        $new_final_price = $item_total_cost * (1 + $profit_margin / 100);

        // تحديث سجل الإنتاج
        $update_stmt = $conn->prepare("UPDATE production_log SET final_price = ? WHERE id = ?");
        $update_stmt->bind_param("di", $new_final_price, $row['id']);
        $update_stmt->execute();
    }

    // إعادة توجيه إلى نفس الصفحة لعرض البيانات المحدثة
    header("Location: order_details.php?id=$order_id&recalculated=1");
    exit;
}

// جلب بيانات الأمر
$stmt = $conn->prepare("SELECT * FROM work_orders WHERE id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order_result = $stmt->get_result();
if ($order_result->num_rows == 0) {
    header("Location: ./work_orders_out.php");
    exit;
}
$order = $order_result->fetch_assoc();

// جلب منتجات الأمر مع التحقق من وجود عمود السعر
try {
    $stmt = $conn->prepare("
       SELECT i.*, p.name as product_name, COALESCE(p.price, 0) as unit_price
       FROM work_order_items i
       JOIN products p ON i.product_id = p.id
       WHERE i.order_id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $items_result = $stmt->get_result();
} catch (mysqli_sql_exception $e) {
    // إذا فشل الاستعلام بسبب عدم وجود عمود السعر، نستخدم استعلام بديل
    $stmt = $conn->prepare("
       SELECT i.*, p.name as product_name, 0 as unit_price
       FROM work_order_items i
       JOIN products p ON i.product_id = p.id
       WHERE i.order_id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $items_result = $stmt->get_result();
}

// جلب سجلات الإنتاج مع بيانات مالية مفصلة وبيانات المنتجات
try {
    $stmt = $conn->prepare("
       SELECT l.*, p.name as product_name, COALESCE(p.price, 0) as unit_price,
              p.industrial_wood_cost, p.natural_wood_cost, p.accessories_cost,
              p.industrial_waste_pct, p.natural_waste_pct, p.production_hours
       FROM production_log l
       JOIN products p ON l.product_id = p.id
       WHERE l.order_id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $production_result = $stmt->get_result();
} catch (mysqli_sql_exception $e) {
    // إذا فشل الاستعلام، نستخدم استعلام بديل
    $stmt = $conn->prepare("
       SELECT l.*, p.name as product_name, 0 as unit_price,
              0 as industrial_wood_cost, 0 as natural_wood_cost, 0 as accessories_cost,
              0 as industrial_waste_pct, 0 as natural_waste_pct, 0 as production_hours
       FROM production_log l
       JOIN products p ON l.product_id = p.id
       WHERE l.order_id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $production_result = $stmt->get_result();
}

// جلب إعدادات النظام
$settings = $conn->query("SELECT * FROM settings LIMIT 1")->fetch_assoc();
$profit_margin = $settings['profit_margin'] ?? 10;
$indirect_percent = $settings['indirect_percent'] ?? 5;
$indirect_cost_percentage = $settings['indirect_cost_percentage'] ?? 5;
$risk_factor = $settings['risk_factor'] ?? 1;
$labor_cost_per_hour = $settings['labor_cost_per_hour'] ?? 50;
$shift2_multiplier = $settings['shift2_multiplier'] ?? 1.5;
$electricity_cost_per_hour = $settings['electricity_cost_per_hour'] ?? 5;
$monthly_maintenance_cost = $settings['monthly_maintenance_cost'] ?? 1000;
$monthly_rent_cost = $settings['monthly_rent_cost'] ?? 2000;
$monthly_other_costs = $settings['monthly_other_costs'] ?? 500;
$maintenance_cost_percentage = $settings['maintenance_cost_percentage'] ?? 3;
$working_hours_per_day = $settings['working_hours_per_day'] ?? 8;
$working_days_per_month = $settings['working_days_per_month'] ?? 24;
$default_material_cost = $settings['default_material_cost'] ?? 0;

// حساب إجمالي التكاليف الثابتة الشهرية
$monthly_fixed_costs = $monthly_rent_cost + $monthly_other_costs;

// حساب إجمالي ساعات العمل في الشهر
$total_monthly_hours = $working_hours_per_day * $working_days_per_month;

// حساب تكلفة الساعة الواحدة للتوزيع
$cost_per_hour = $monthly_fixed_costs / $total_monthly_hours;

// حساب التكاليف الإجمالية والمؤشرات المالية
$total_material_cost = 0;
$total_labor_cost = 0;
$total_machine_cost = 0;
$total_electricity_cost = 0;
$total_maintenance_cost = 0;
$total_indirect_cost = 0;
$total_fixed_cost = 0;
$total_additional_maintenance_cost = 0;
$total_cost = 0;
$total_final_price = 0;
$total_profit = 0;
$total_quantity = 0;
$total_unit_price = 0;

// Refactored: Calculate all item costs first and store in an array
$production_data = [];
$production_result->data_seek(0);

while ($row = $production_result->fetch_assoc()) {
    $quantity = $row['quantity'];

    // Material cost
    $industrial_wood_cost = $row['industrial_wood_cost'] ?? 0;
    $industrial_waste_pct = $row['industrial_waste_pct'] ?? 0;
    $natural_wood_cost = $row['natural_wood_cost'] ?? 0;
    $natural_waste_pct = $row['natural_waste_pct'] ?? 0;
    $accessories_cost = $row['accessories_cost'] ?? 0;
    $industrial_wood_with_waste = $industrial_wood_cost * (1 + $industrial_waste_pct / 100);
    $natural_wood_with_waste = $natural_wood_cost * (1 + $natural_waste_pct / 100);
    $material_cost_per_unit = $industrial_wood_with_waste + $natural_wood_with_waste + $accessories_cost;
    if ($material_cost_per_unit == 0) {
        $material_cost_per_unit = $default_material_cost;
    }
    $item_material_cost = $material_cost_per_unit * $quantity;
    if ($row['material_cost'] > 0) {
        $item_material_cost = $row['material_cost'];
    }

    // Labor cost
    $laborCalc = calculateLaborCost($conn, intval($row['product_id']), intval($quantity));
    $item_labor_cost = $laborCalc['total_labor_cost'];
    if (intval($order['shift2']) === 1) {
        $item_labor_cost *= (1 + $shift2_multiplier);
    }

    // Machine, electricity, and maintenance costs
    $machineCalc = calculateMachineCost($conn, intval($row['product_id']), intval($quantity));
    $item_machine_cost = $machineCalc['total_machine_cost'];
    $item_electricity_cost = $row['electricity_cost']; // Keep for display
    $item_maintenance_cost = $row['maintenance_cost']; // Keep for display

    // Other costs
    $base_cost_total = $item_material_cost + $item_labor_cost + $item_machine_cost;
    $indirect_cost1 = $base_cost_total * ($indirect_percent / 100);
    $indirect_cost2 = $base_cost_total * ($indirect_cost_percentage / 100);
    $item_indirect_cost = $indirect_cost1 + $indirect_cost2;

    $production_hours = $row['production_hours'] ?? 0;
    $item_fixed_cost = $cost_per_hour * ($production_hours * $quantity);
    $item_additional_maintenance_cost = $item_labor_cost * ($maintenance_cost_percentage / 100);

    // Total cost
    $item_total_cost = ($base_cost_total + $item_indirect_cost + $item_fixed_cost + $item_additional_maintenance_cost) * $risk_factor;

    // Final price and profit
    $item_final_price = $row['final_price'] ?? 0;
    if ($item_final_price == 0) {
        $item_final_price = $item_total_cost * (1 + $profit_margin / 100);
    }
    $item_profit = $item_final_price - $item_total_cost;

    // Store all calculated data
    $production_data[] = [
        'product_name' => $row['product_name'],
        'quantity' => $quantity,
        'item_material_cost' => $item_material_cost,
        'item_labor_cost' => $item_labor_cost,
        'item_machine_cost' => $item_machine_cost,
        'item_electricity_cost' => $item_electricity_cost,
        'item_maintenance_cost' => $item_maintenance_cost,
        'item_indirect_cost' => $item_indirect_cost,
        'item_fixed_cost' => $item_fixed_cost,
        'item_additional_maintenance_cost' => $item_additional_maintenance_cost,
        'item_total_cost' => $item_total_cost,
        'item_final_price' => $item_final_price,
        'item_profit' => $item_profit,
        'unit_price' => $row['unit_price']
    ];
}

// Refactored: Aggregate totals from the pre-calculated data array
foreach ($production_data as $data) {
    $total_material_cost += $data['item_material_cost'];
    $total_labor_cost += $data['item_labor_cost'];
    $total_machine_cost += $data['item_machine_cost'];
    $total_electricity_cost += $data['item_electricity_cost'];
    $total_maintenance_cost += $data['item_maintenance_cost'];
    $total_indirect_cost += $data['item_indirect_cost'];
    $total_fixed_cost += $data['item_fixed_cost'];
    $total_additional_maintenance_cost += $data['item_additional_maintenance_cost'];
    $total_cost += $data['item_total_cost'];
    $total_final_price += $data['item_final_price'];
    $total_profit += $data['item_profit'];
    $total_quantity += $data['quantity'];
    $total_unit_price += $data['unit_price'] * $data['quantity'];
}

// حساب المؤشرات المالية
$average_unit_cost = $total_quantity > 0 ? $total_cost / $total_quantity : 0;
$average_selling_price = $total_quantity > 0 ? $total_final_price / $total_quantity : 0;
$profit_margin_percent = $total_final_price > 0 ? ($total_profit / $total_final_price) * 100 : 0;
$cost_efficiency = $total_cost > 0 ? ($total_final_price / $total_cost) * 100 : 0;

// تنسيق الورديات
$shifts = "";
if ($order['shift1']) $shifts .= "وردية أولى ";
if ($order['shift2']) $shifts .= "وردية ثانية ";

// تنسيق الشهر والسنة
$month_year = "-";
if ($order['month'] && $order['year']) {
    $month_year = $order['month'] . '/' . $order['year'];
}
?>
<style>
    .detail-card {
        margin-bottom: 20px;
        border-radius: 10px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    .card-header {
        font-weight: bold;
        font-size: 1.1rem;
    }

    .table th {
        background-color: #f8f9fa;
        font-weight: bold;
    }

    .badge {
        font-size: 0.9rem;
        padding: 5px 10px;
    }

    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #eee;
    }

    .info-row:last-child {
        border-bottom: none;
    }

    .info-label {
        font-weight: bold;
        color: #555;
    }

    .info-value {
        color: #333;
    }

    .summary-row {
        background-color: #f8f9fa;
        font-weight: bold;
    }

    .financial-metric {
        text-align: center;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 10px;
    }

    .metric-value {
        font-size: 1.5rem;
        font-weight: bold;
        margin: 10px 0;
    }

    .metric-positive {
        color: #28a745;
    }

    .metric-negative {
        color: #dc3545;
    }

    .metric-neutral {
        color: #6c757d;
    }

    .cost-breakdown {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }

    .profit-analysis {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        color: white;
    }

    .efficiency-metrics {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        color: white;
    }

    .cost-source {
        font-size: 0.8rem;
        opacity: 0.8;
        margin-top: 5px;
    }

    .price-warning {
        background-color: #fff3cd;
        border: 1px solid #ffeeba;
        color: #856404;
        padding: 10px 15px;
        border-radius: 5px;
        margin-bottom: 15px;
    }

    .calculation-info {
        background-color: #e7f3ff;
        border: 1px solid #b6d7ff;
        color: #0c5460;
        padding: 10px 15px;
        border-radius: 5px;
        margin-bottom: 15px;
    }
</style>
<div class="container mt-4" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3>تفاصيل أمر العمل #<?= htmlspecialchars($order_id) ?></h3>
        <div>
            <a href="./work_orders_out.php" class="btn btn-secondary">⬅️ العودة للأوامر الصادرة</a>
            <button onclick="window.print()" class="btn btn-primary">🖨️ طباعة</button>
        </div>
    </div>

    <?php if (isset($_GET['recalculated'])): ?>
        <div class="alert alert-success">
            تم إعادة حساب أسعار البيع بنجاح بناءً على التكاليف الفعلية وهامش الربح المحدد في الإعدادات.
        </div>
    <?php endif; ?>

    <?php if ($total_profit < 0): ?>
        <div class="price-warning">
            <strong>⚠️ تحذير:</strong> هذا الأمر يعاني من خسارة.
            التكلفة الإجمالية (<?= number_format($total_cost, 2) ?> جنيه) أعلى من إجمالي الإيرادات (<?= number_format($total_final_price, 2) ?> جنيه).
            يمكنك استخدام الزر أدناه لإعادة حساب أسعار البيع بناءً على التكاليف الفعلية وهامش الربح المحدد في الإعدادات.
        </div>
    <?php endif; ?>

    <div class="calculation-info">
        <strong>ℹ️ معلومات الحساب:</strong> يتم حساب التكاليف في هذه الصفحة باستخدام نفس الدوال المستخدمة في صفحة التقارير الرئيسية لضمان اتساق النتائج.
        التكاليف محسوبة بناءً على: تكلفة المواد مع الهالك، تكلفة العمالة، تكلفة الماكينات، التكاليف غير المباشرة، التكاليف الثابتة، وتكلفة الصيانة الإضافية.
    </div>

    <!-- معلومات الأمر -->
    <div class="card detail-card">
        <div class="card-header bg-primary text-white">معلومات الأمر</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="info-row">
                        <span class="info-label">اسم المشروع:</span>
                        <span class="info-value"><?= htmlspecialchars($order['project_name'] ?? '-') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">الرقم التسلسلي:</span>
                        <span class="info-value"><?= htmlspecialchars($order['serial_number'] ?? '-') ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">الشهر/السنة:</span>
                        <span class="info-value"><?= htmlspecialchars($month_year) ?></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-row">
                        <span class="info-label">مدة التنفيذ:</span>
                        <span class="info-value"><?= htmlspecialchars($order['duration_days']) ?> يوم</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">الورديات:</span>
                        <span class="info-value"><?= htmlspecialchars($shifts) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">تاريخ الإنشاء:</span>
                        <span class="info-value"><?= htmlspecialchars($order['created_at']) ?></span>
                    </div>
                </div>
            </div>
            <div class="info-row">
                <span class="info-label">ملاحظات:</span>
                <span class="info-value"><?= htmlspecialchars($order['notes'] ?? '-') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">الحالة:</span>
                <span class="info-value">
                    <?php
                    $status_class = '';
                    $status_text = '';

                    switch ($order['status']) {
                        case 'draft':
                            $status_class = 'bg-secondary';
                            $status_text = 'مسودة';
                            break;
                        case 'started':
                            $status_class = 'bg-primary';
                            $status_text = 'قيد التنفيذ';
                            break;
                        case 'completed':
                            $status_class = 'bg-success';
                            $status_text = 'مكتمل';
                            break;
                        case 'expired':
                            $status_class = 'bg-warning';
                            $status_text = 'منتهي الصلاحية';
                            break;
                        default:
                            $status_class = 'bg-secondary';
                            $status_text = $order['status'];
                    }
                    ?>
                    <span class="badge <?= $status_class ?>"><?= $status_text ?></span>
                </span>
            </div>
        </div>
    </div>

    <!-- المؤشرات المالية -->
    <div class="row">
        <div class="col-md-4">
            <div class="card financial-metric cost-breakdown">
                <div class="card-body">
                    <h6>التكلفة الإجمالية</h6>
                    <div class="metric-value"><?= number_format($total_cost, 2) ?> جنيه</div>
                    <small>لـ <?= $total_quantity ?> وحدة</small>
                    <div class="cost-source">
                        ⚡ مصدر البيانات: سجلات الإنتاج
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card financial-metric profit-analysis">
                <div class="card-body">
                    <h6>صافي الربح</h6>
                    <div class="metric-value <?= $total_profit >= 0 ? 'metric-positive' : 'metric-negative' ?>">
                        <?= number_format($total_profit, 2) ?> جنيه
                    </div>
                    <small>هامش ربح: <?= number_format($profit_margin_percent, 2) ?>%</small>
                    <div class="cost-source">
                        💰 الإيرادات - التكاليف
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card financial-metric efficiency-metrics">
                <div class="card-body">
                    <h6>كفاءة التكلفة</h6>
                    <div class="metric-value"><?= number_format($cost_efficiency, 2) ?>%</div>
                    <small>العائد على التكلفة</small>
                    <div class="cost-source">
                        📊 (الإيرادات ÷ التكلفة) × 100
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- تحليل التكاليف التفصيلي -->
    <div class="card detail-card">
        <div class="card-header bg-warning text-dark">تحليل التكاليف التفصيلي</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="info-row">
                        <span class="info-label">تكلفة المواد:</span>
                        <span class="info-value"><?= number_format($total_material_cost, 2) ?> جنيه</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">تكلفة العمالة:</span>
                        <span class="info-value"><?= number_format($total_labor_cost, 2) ?> جنيه</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">تكلفة الماكينات:</span>
                        <span class="info-value"><?= number_format($total_machine_cost, 2) ?> جنيه</span>
                        <div class="cost-source">
                            ⚙️ مجموع تكلفة الكهرباء والصيانة
                        </div>
                    </div>
                    <div class="info-row">
                        <span class="info-label">تكلفة الكهرباء:</span>
                        <span class="info-value"><?= number_format($total_electricity_cost, 2) ?> جنيه</span>
                        <div class="cost-source">
                            ⚡ من جدول production_log - electricity_cost
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-row">
                        <span class="info-label">تكلفة الصيانة:</span>
                        <span class="info-value"><?= number_format($total_maintenance_cost, 2) ?> جنيه</span>
                        <div class="cost-source">
                            🔧 من جدول production_log - maintenance_cost
                        </div>
                    </div>
                    <div class="info-row">
                        <span class="info-label">التكاليف غير المباشرة:</span>
                        <span class="info-value"><?= number_format($total_indirect_cost, 2) ?> جنيه</span>
                        <div class="cost-source">
                            📊 نسبة من التكاليف الأساسية
                        </div>
                    </div>
                    <div class="info-row">
                        <span class="info-label">التكاليف الثابتة (الإيجار وغيرها):</span>
                        <span class="info-value"><?= number_format($total_fixed_cost, 2) ?> جنيه</span>
                        <div class="cost-source">
                            🏢 حصة من التكاليف الثابتة الشهرية
                        </div>
                    </div>
                    <div class="info-row">
                        <span class="info-label">تكلفة الصيانة الإضافية:</span>
                        <span class="info-value"><?= number_format($total_additional_maintenance_cost, 2) ?> جنيه</span>
                        <div class="cost-source">
                            🔧 نسبة إضافية من تكلفة العمالة
                        </div>
                    </div>
                </div>
            </div>
            <div class="alert alert-info mt-3">
                <h6>🔍 مصادر بيانات التكاليف:</h6>
                <ul class="mb-0">
                    <li><strong>تكلفة المواد:</strong> محسوبة بناءً على تكلفة الخشب مع إضافة نسبة الهالك</li>
                    <li><strong>تكلفة العمالة:</strong> محسوبة باستخدام دالة calculateLaborCost</li>
                    <li><strong>تكلفة الماكينات:</strong> مجموع تكلفة الكهرباء والصيانة المسجلة</li>
                    <li><strong>تكلفة الكهرباء:</strong> مُسجلة في حقل <code>electricity_cost</code> بجدول <code>production_log</code></li>
                    <li><strong>تكلفة الصيانة:</strong> مُسجلة في حقل <code>maintenance_cost</code> بجدول <code>production_log</code></li>
                    <li><strong>التكاليف غير المباشرة:</strong> محسوبة كنسبة من التكاليف الأساسية</li>
                    <li><strong>التكاليف الثابتة:</strong> حصة من التكاليف الثابتة الشهرية (الإيجار وتكاليف أخرى)</li>
                    <li><strong>تكلفة الصيانة الإضافية:</strong> نسبة إضافية من تكلفة العمالة</li>
                    <li><strong>جميع التكاليف:</strong> مُسجلة تلقائياً عند إضافة سجلات الإنتاج</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- منتجات الأمر -->
    <div class="card detail-card">
        <div class="card-header bg-info text-white">منتجات الأمر</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>سعر الوحدة</th>
                            <th>القيمة الإجمالية</th>
                            <th>ملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $i = 1;
                        $items_result->data_seek(0); // إعادة تعيين المؤشر
                        while ($item = $items_result->fetch_assoc()) {
                            $total_value = $item['quantity'] * $item['unit_price'];
                            echo "<tr>";
                            echo "<td>" . $i++ . "</td>";
                            echo "<td>" . htmlspecialchars($item['product_name']) . "</td>";
                            echo "<td>" . htmlspecialchars($item['quantity']) . "</td>";
                            echo "<td>" . number_format($item['unit_price'], 2) . " جنيه</td>";
                            echo "<td>" . number_format($total_value, 2) . " جنيه</td>";
                            echo "<td>" . htmlspecialchars($item['notes'] ?? '-') . "</td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- سجلات الإنتاج -->
    <div class="card detail-card">
        <div class="card-header bg-success text-white">سجلات الإنتاج والتحليل المالي</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>تكلفة المواد</th>
                            <th>تكلفة العمالة</th>
                            <th>تكلفة الماكينات</th>
                            <th>تكلفة الكهرباء</th>
                            <th>تكلفة الصيانة</th>
                            <th>التكلفة الإجمالية</th>
                            <th>سعر البيع</th>
                            <th>الربح</th>
                            <th>هامش الربح %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $i = 1;
                        foreach ($production_data as $data) {
                            $item_profit_margin = $data['item_final_price'] > 0 ? ($data['item_profit'] / $data['item_final_price']) * 100 : 0;

                            echo "<tr>";
                            echo "<td>" . $i++ . "</td>";
                            echo "<td>" . htmlspecialchars($data['product_name']) . "</td>";
                            echo "<td>" . htmlspecialchars($data['quantity']) . "</td>";
                            echo "<td>" . number_format($data['item_material_cost'], 2) . " جنيه</td>";
                            echo "<td>" . number_format($data['item_labor_cost'], 2) . " جنيه</td>";
                            echo "<td>" . number_format($data['item_machine_cost'], 2) . " جنيه</td>";
                            echo "<td>" . number_format($data['item_electricity_cost'], 2) . " جنيه</td>";
                            echo "<td>" . number_format($data['item_maintenance_cost'], 2) . " جنيه</td>";
                            echo "<td>" . number_format($data['item_total_cost'], 2) . " جنيه</td>";
                            echo "<td>" . number_format($data['item_final_price'], 2) . " جنيه</td>";
                            echo "<td class='" . ($data['item_profit'] >= 0 ? 'text-success' : 'text-danger') . "'>" . number_format($data['item_profit'], 2) . " جنيه</td>";
                            echo "<td class='" . ($item_profit_margin >= 0 ? 'text-success' : 'text-danger') . "'>" . number_format($item_profit_margin, 2) . "%</td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-active summary-row">
                            <th colspan="3">الإجمالي</th>
                            <th><?= number_format($total_material_cost, 2) ?> جنيه</th>
                            <th><?= number_format($total_labor_cost, 2) ?> جنيه</th>
                            <th><?= number_format($total_machine_cost, 2) ?> جنيه</th>
                            <th><?= number_format($total_electricity_cost, 2) ?> جنيه</th>
                            <th><?= number_format($total_maintenance_cost, 2) ?> جنيه</th>
                            <th><?= number_format($total_cost, 2) ?> جنيه</th>
                            <th><?= number_format($total_final_price, 2) ?> جنيه</th>
                            <th class="<?= $total_profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($total_profit, 2) ?> جنيه</th>
                            <th class="<?= $profit_margin_percent >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($profit_margin_percent, 2) ?>%</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- الإجراءات -->
    <div class="card detail-card">
        <div class="card-header bg-dark text-white">الإجراءات</div>
        <div class="card-body text-center">
            <?php if ($order['status'] == 'started'): ?>
                <div class="btn-group" role="group">
                    <a href="./work_orders_out.php?complete_order=<?= $order_id ?>&csrf_token=<?= htmlspecialchars($csrf_token) ?>" class="btn btn-success" onclick="return confirm('هل أنت متأكد من إكتمال هذا الأمر؟')">
                        <i class="bi bi-check-circle"></i> إكتمال الأمر
                    </a>
                </div>
            <?php elseif ($order['status'] == 'draft'): ?>
                <div class="btn-group" role="group">
                    <a href="./work_order.php?id=<?= $order_id ?>" class="btn btn-primary">
                        <i class="bi bi-pencil"></i> تعديل الأمر
                    </a>
                    <a href="./work_orders_out.php?start_order=<?= $order_id ?>&csrf_token=<?= htmlspecialchars($csrf_token) ?>" class="btn btn-success" onclick="return confirm('هل أنت متأكد من بدء تنفيذ هذا الأمر؟')">
                        <i class="bi bi-play-circle"></i> بدء التنفيذ
                    </a>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    لا يمكن تنفيذ إجراءات على هذا الأمر حالياً
                </div>
            <?php endif; ?>

            <?php if ($total_profit < 0): ?>
                <div class="mt-3">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" name="recalculate_prices" class="btn btn-warning" onclick="return confirm('هل أنت متأكد من إعادة حساب أسعار البيع بناءً على التكاليف الفعلية وهامش الربح المحدد في الإعدادات؟')">
                            <i class="bi bi-calculator"></i> إعادة حساب أسعار البيع
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<!-- إضافة مكتبة Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<?php include "includes/footer.php"; ?>