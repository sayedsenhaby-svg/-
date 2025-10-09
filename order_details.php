<?php
// order_details.php - نسخة كاملة مصححة ومنقحة
session_start();

require_once "includes/db.php";
include "includes/navbar.php";

// ---------------------- CSRF ----------------------
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

$csrf_token = generateCSRFToken();

// ------------------ مساعدات للتأكد من أعمدة الجداول ------------------
function addColumnIfMissing(mysqli $conn, string $table, string $column, string $definition): void {
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if (!$res || $res->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

// التأكد من الأعمدة الأساسية المطلوبة
addColumnIfMissing($conn, 'work_orders', 'project_name', 'VARCHAR(255) DEFAULT NULL');
addColumnIfMissing($conn, 'work_orders', 'serial_number', 'VARCHAR(50) DEFAULT NULL');
addColumnIfMissing($conn, 'work_orders', 'month', 'INT DEFAULT NULL');
addColumnIfMissing($conn, 'work_orders', 'year', 'INT DEFAULT NULL');
addColumnIfMissing($conn, 'products', 'price', 'DECIMAL(10,2) DEFAULT 0');

// ------------------ دوال حساب مُنقحة ------------------

// دالة حساب تكلفة العمالة (تعيد تكلفة الوحدة و التكلفة الكلية للكمية)
function calculateLaborCost(mysqli $conn, int $product_id, int $quantity): array {
    $result = [
        'labor_cost_per_unit' => 0.0,
        'total_labor_cost' => 0.0,
        'details' => []
    ];

    // جلب إعدادات النظام
    $settings_row = $conn->query("SELECT * FROM settings LIMIT 1");
    $settings = $settings_row ? $settings_row->fetch_assoc() : [];

    // قيم افتراضية آمنة
    $labor_cost_per_hour = floatval($settings['labor_cost_per_hour'] ?? 50);
    $labor_cost_shift1 = floatval($settings['labor_cost_shift1'] ?? 0);
    $labor_cost_shift2 = floatval($settings['labor_cost_shift2'] ?? 0);

    // جلب بيانات المنتج
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc() ?: [];

    // إذا المنتج يحتوي على حقل labor_cost محدد (تكلفة لكل وحدة)
    $product_defined_labor = floatval($product['labor_cost'] ?? 0);
    $production_hours = floatval($product['production_hours'] ?? 0);

    if ($product_defined_labor > 0) {
        $labor_cost_per_unit = $product_defined_labor;
    } else {
        // تكلفة العمالة تعتمد على ساعات الإنتاج * تكلفة الساعة
        $labor_cost_per_unit = $production_hours * $labor_cost_per_hour;
    }

    $total_labor_cost = $labor_cost_per_unit * max(0, $quantity);

    $result['labor_cost_per_unit'] = round($labor_cost_per_unit, 4);
    $result['total_labor_cost'] = round($total_labor_cost, 4);
    $result['details'][] = [
        'method' => 'general',
        'production_hours' => $production_hours,
        'rate_per_hour' => $labor_cost_per_hour,
        'labor_cost_per_unit' => $labor_cost_per_unit,
        'total_labor_cost' => $total_labor_cost
    ];

    return $result;
}

// دالة حساب تكلفة الماكينات (تعيد تكلفة الوحدة و التكلفة الكلية للكمية)
function calculateMachineCost(mysqli $conn, int $product_id, int $quantity): array {
    $result = [
        'machine_cost_per_unit' => 0.0,
        'total_machine_cost' => 0.0,
        'details' => []
    ];

    // جلب إعدادات
    $settings_row = $conn->query("SELECT * FROM settings LIMIT 1");
    $settings = $settings_row ? $settings_row->fetch_assoc() : [];

    $electricity_cost_per_hour = floatval($settings['electricity_cost_per_hour'] ?? 5);
    $monthly_maintenance_cost = floatval($settings['monthly_maintenance_cost'] ?? 1000);
    $working_days_per_month = intval($settings['working_days_per_month'] ?? 24);
    $working_hours_per_day = intval($settings['working_hours_per_day'] ?? 8);

    // جلب بيانات المنتج
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc() ?: [];

    $production_hours = floatval($product['production_hours'] ?? 0);

    // حساب تكلفة الكهرباء والإهلاك/صيانة موزعة على ساعات العمل الشهرية
    $total_monthly_hours = max(1, $working_hours_per_day * $working_days_per_month);
    $maintenance_cost_per_hour = $monthly_maintenance_cost / $total_monthly_hours;

    $hourly_machine_cost = $electricity_cost_per_hour + $maintenance_cost_per_hour;
    $machine_cost_per_unit = $production_hours * $hourly_machine_cost;
    $total_machine_cost = $machine_cost_per_unit * max(0, $quantity);

    $result['machine_cost_per_unit'] = round($machine_cost_per_unit, 4);
    $result['total_machine_cost'] = round($total_machine_cost, 4);
    $result['details'][] = [
        'production_hours' => $production_hours,
        'electricity_per_hour' => $electricity_cost_per_hour,
        'maintenance_per_hour' => $maintenance_cost_per_hour,
        'hourly_machine_cost' => $hourly_machine_cost,
        'machine_cost_per_unit' => $machine_cost_per_unit,
        'total_machine_cost' => $total_machine_cost
    ];

    return $result;
}

// ------------------ بداية المعالجة ------------------

// التحقق من وجود معرف الأمر
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: ./work_orders_out.php");
    exit;
}
$order_id = intval($_GET['id']);

// جلب إعدادات النظام (مرة واحدة)
$settings_row = $conn->query("SELECT * FROM settings LIMIT 1");
$settings = $settings_row ? $settings_row->fetch_assoc() : [];

// مُعينات افتراضية آمنة
$profit_margin = floatval($settings['profit_margin'] ?? 15.0);
$indirect_percent = floatval($settings['indirect_percent'] ?? $settings['indirect_cost_percentage'] ?? 0.0);
$risk_factor = floatval($settings['risk_factor'] ?? 1.0);
$shift2_multiplier = floatval($settings['shift2_multiplier'] ?? $settings['second_shift_multiplier'] ?? 1.0);
$maintenance_cost_percentage = floatval($settings['maintenance_cost_percentage'] ?? 0.0);
$default_material_cost = floatval($settings['default_material_cost'] ?? 0.0);
$working_hours_per_day = intval($settings['working_hours_per_day'] ?? 8);
$working_days_per_month = intval($settings['working_days_per_month'] ?? 24);
$monthly_fixed_costs = floatval($settings['monthly_rent_cost'] ?? 0) + floatval($settings['monthly_other_costs'] ?? 0);
$total_monthly_hours = max(1, $working_hours_per_day * $working_days_per_month);
$cost_per_hour = $monthly_fixed_costs / $total_monthly_hours;

// ------------------ عملية إعادة حساب الأسعار ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalculate_prices'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        die("طلب غير صالح (CSRF).");
    }

    // جلب جميع سجلات الإنتاج للأمر
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

    while ($row = $production_result->fetch_assoc()) {
        $prod_id = intval($row['product_id']);
        $quantity = max(0, intval($row['quantity']));

        // --- حساب تكلفة المواد للوحدة ---
        $industrial_wood_cost = floatval($row['industrial_wood_cost'] ?? 0);
        $industrial_waste_pct = floatval($row['industrial_waste_pct'] ?? 0);
        $natural_wood_cost = floatval($row['natural_wood_cost'] ?? 0);
        $natural_waste_pct = floatval($row['natural_waste_pct'] ?? 0);
        $accessories_cost = floatval($row['accessories_cost'] ?? 0);

        $industrial_wood_with_waste = $industrial_wood_cost * (1 + $industrial_waste_pct / 100);
        $natural_wood_with_waste = $natural_wood_cost * (1 + $natural_waste_pct / 100);
        $material_cost_per_unit = $industrial_wood_with_waste + $natural_wood_with_waste + $accessories_cost;

        if ($material_cost_per_unit <= 0) {
            $material_cost_per_unit = $default_material_cost;
        }

        // إذا كان هناك قيمة material_cost مخزنة (إجمالي أو وحدة) فنعتمدها
        if (isset($row['material_cost']) && floatval($row['material_cost']) > 0) {
            // نتحقق إن الحقل مخزن للإجمالي أم للوحدة:
            // افتراض: material_cost مخزن كـ إجمالي (total) — إذا كانت كمية > 0 نحوله للوحدة
            $maybe_total_material_cost = floatval($row['material_cost']);
            if ($quantity > 0 && $maybe_total_material_cost > ($material_cost_per_unit * $quantity * 0.5)) {
                // يبدو أنه إجمالي -> نقسمه على الكمية
                $material_cost_per_unit = $maybe_total_material_cost / $quantity;
            } else {
                // يبدو أنه تكلفة للوحدة
                $material_cost_per_unit = $maybe_total_material_cost;
            }
        }

        $item_material_cost_total = $material_cost_per_unit * $quantity;

        // --- تكلفة العمالة ---
        $laborCalc = calculateLaborCost($conn, $prod_id, $quantity);
        $item_labor_cost_total = floatval($laborCalc['total_labor_cost']);

        // تطبيق معامل الوردية الثانية إذا مفعل في الأمر
        $order_shift2_row = $conn->query("SELECT shift2 FROM work_orders WHERE id = $order_id")->fetch_assoc();
        $order_shift2 = intval($order_shift2_row['shift2'] ?? 0);
        if ($order_shift2 === 1) {
            // shift2_multiplier مخزن كقيمة مضاعفة مباشرة (1.5 => ×1.5)
            $item_labor_cost_total *= $shift2_multiplier;
        }

        // --- تكلفة الماكينات ---
        $machineCalc = calculateMachineCost($conn, $prod_id, $quantity);
        $item_machine_cost_total = floatval($machineCalc['total_machine_cost']);

        // --- تكاليف غير مباشرة (نطبق مرة واحدة على المجموع الأساسي) ---
        $base_cost_total = $item_material_cost_total + $item_labor_cost_total + $item_machine_cost_total;
        $item_indirect_cost_total = $base_cost_total * ($indirect_percent / 100);

        // --- التكاليف الثابتة (حصة من الإيجار/تكاليف ثابتة حسب ساعات الإنتاج) ---
        $production_hours = floatval($row['production_hours'] ?? 0);
        $item_fixed_cost_total = $cost_per_hour * ($production_hours * $quantity);

        // --- صيانة إضافية كنسبة من تكلفة العمالة ---
        $item_additional_maintenance_cost_total = $item_labor_cost_total * ($maintenance_cost_percentage / 100);

        // --- إجمالي التكلفة بعد تطبيق عامل المخاطر (TOTAL لكل الكمية) ---
        $item_total_cost = ($base_cost_total + $item_indirect_cost_total + $item_fixed_cost_total + $item_additional_maintenance_cost_total) * $risk_factor;

        // --- حساب سعر البيع لكل وحدة (نخزن final_price كوحدة) ---
        $unit_total_cost = $quantity > 0 ? ($item_total_cost / $quantity) : $item_total_cost;
        $new_final_price_per_unit = $unit_total_cost * (1 + ($profit_margin / 100));

        // تحديث سجل الإنتاج: final_price كسعر للواحدة
        $update_stmt = $conn->prepare("UPDATE production_log SET final_price = ?, material_cost = ?, labor_cost = ?, machine_cost = ?, indirect_cost = ?, maintenance_cost = ?, electricity_cost = ? WHERE id = ?");
        // ملاحظة: بعض الحقول قد لا توجد في جدولك؛ إذا لم تكن موجودة ستحتاج لحذفها من الاستعلام أو إضافتها في القاعدة
        $material_cost_for_db = round($item_material_cost_total, 4); // إجمالي المواد
        $labor_cost_for_db = round($item_labor_cost_total, 4);
        $machine_cost_for_db = round($item_machine_cost_total, 4);
        $indirect_cost_for_db = round($item_indirect_cost_total, 4);
        $maintenance_cost_for_db = round($item_additional_maintenance_cost_total, 4);
        $electricity_cost_for_db = floatval($row['electricity_cost'] ?? 0);

        // تأكد من أن الأعمدة موجودة في production_log قبل تنفيذ هذا الاستعلام
        // إذا لم تكن موجودة، استبدل الاستعلام بـ: UPDATE production_log SET final_price = ? WHERE id = ?
        $colsRes = $conn->query("SHOW COLUMNS FROM production_log LIKE 'material_cost'");
        if ($colsRes && $colsRes->num_rows > 0) {
            $update_stmt->bind_param("ddddddii",
                $new_final_price_per_unit,
                $material_cost_for_db,
                $labor_cost_for_db,
                $machine_cost_for_db,
                $indirect_cost_for_db,
                $maintenance_cost_for_db,
                $electricity_cost_for_db,
                $row['id']
            );
        } else {
            // أعمدة التكلفة التفصيلية غير موجودة -> تحديث final_price فقط
            $update_stmt = $conn->prepare("UPDATE production_log SET final_price = ? WHERE id = ?");
            $update_stmt->bind_param("di", $new_final_price_per_unit, $row['id']);
        }
        $update_stmt->execute();
    }

    // إعادة توجيه لعرض النتائج بعد التحديث
    header("Location: order_details.php?id=$order_id&recalculated=1");
    exit;
}

// ------------------ جلب بيانات الأمر والمنتجات وسجلات الإنتاج لعرضها ------------------

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

// جلب منتجات الأمر (work_order_items)
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

// جلب سجلات الإنتاج
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

// ------------------ حسابات ملخصية لعرض المؤشرات ------------------
$profit_margin_display = $profit_margin;
$indirect_percent_display = $indirect_percent;

$total_material_cost = 0.0;
$total_labor_cost = 0.0;
$total_machine_cost = 0.0;
$total_indirect_cost = 0.0;
$total_fixed_cost = 0.0;
$total_additional_maintenance_cost = 0.0;
$total_cost = 0.0;
$total_final_price = 0.0;
$total_profit = 0.0;
$total_quantity = 0;
$total_unit_price = 0.0;

// إعادة مؤشر النتيجة
$production_result->data_seek(0);

while ($row = $production_result->fetch_assoc()) {
    $quantity = max(0, intval($row['quantity']));

    // تكلفة المواد (الوحدة)
    $industrial_wood_cost = floatval($row['industrial_wood_cost'] ?? 0);
    $industrial_waste_pct = floatval($row['industrial_waste_pct'] ?? 0);
    $natural_wood_cost = floatval($row['natural_wood_cost'] ?? 0);
    $natural_waste_pct = floatval($row['natural_waste_pct'] ?? 0);
    $accessories_cost = floatval($row['accessories_cost'] ?? 0);

    $industrial_wood_with_waste = $industrial_wood_cost * (1 + $industrial_waste_pct / 100);
    $natural_wood_with_waste = $natural_wood_cost * (1 + $natural_waste_pct / 100);
    $material_cost_per_unit = $industrial_wood_with_waste + $natural_wood_with_waste + $accessories_cost;

    if ($material_cost_per_unit == 0) {
        $material_cost_per_unit = $default_material_cost;
    }

    if (isset($row['material_cost']) && floatval($row['material_cost']) > 0) {
        $maybe_total_material_cost = floatval($row['material_cost']);
        if ($quantity > 0 && $maybe_total_material_cost > ($material_cost_per_unit * $quantity * 0.5)) {
            $material_cost_per_unit = $maybe_total_material_cost / $quantity;
        } else {
            $material_cost_per_unit = $maybe_total_material_cost;
        }
    }

    $item_material_cost = $material_cost_per_unit * $quantity;

    // العمالة
    $laborCalc = calculateLaborCost($conn, intval($row['product_id']), $quantity);
    $item_labor_cost = floatval($laborCalc['total_labor_cost']);

    if (intval($order['shift2']) === 1) {
        $item_labor_cost *= $shift2_multiplier;
    }

    // ماكينات
    $machineCalc = calculateMachineCost($conn, intval($row['product_id']), $quantity);
    $item_machine_cost = floatval($machineCalc['total_machine_cost']);

    // كهرباء وصيانة من السجل إن وجدت
    $item_electricity_cost = floatval($row['electricity_cost'] ?? 0);
    $item_maintenance_cost = floatval($row['maintenance_cost'] ?? 0);

    $base_cost_total = $item_material_cost + $item_labor_cost + $item_machine_cost;
    $item_indirect_cost = $base_cost_total * ($indirect_percent / 100);

    $production_hours = floatval($row['production_hours'] ?? 0);
    $item_fixed_cost = $cost_per_hour * ($production_hours * $quantity);

    $item_additional_maintenance_cost = $item_labor_cost * ($maintenance_cost_percentage / 100);

    $item_total_cost = ($base_cost_total + $item_indirect_cost + $item_fixed_cost + $item_additional_maintenance_cost) * $risk_factor;

    // final_price في السجل مخزن كسعر للوحدة (إن وُجد)
    $item_final_price_per_unit = floatval($row['final_price'] ?? 0);
    if ($item_final_price_per_unit == 0) {
        $item_final_price_per_unit = ($quantity > 0 ? ($item_total_cost / $quantity) : $item_total_cost) * (1 + ($profit_margin / 100));
    }
    $item_final_price_total = $item_final_price_per_unit * $quantity;

    $item_profit = $item_final_price_total - $item_total_cost;

    // تجميع
    $total_material_cost += $item_material_cost;
    $total_labor_cost += $item_labor_cost;
    $total_machine_cost += $item_machine_cost;
    $total_indirect_cost += $item_indirect_cost;
    $total_fixed_cost += $item_fixed_cost;
    $total_additional_maintenance_cost += $item_additional_maintenance_cost;
    $total_cost += $item_total_cost;
    $total_final_price += $item_final_price_total;
    $total_profit += $item_profit;
    $total_quantity += $quantity;
    $total_unit_price += floatval($row['unit_price'] ?? 0) * $quantity;
}

// إعادة مؤشر
$production_result->data_seek(0);

// مؤشرات
$average_unit_cost = $total_quantity > 0 ? $total_cost / $total_quantity : 0;
$average_selling_price = $total_quantity > 0 ? $total_final_price / $total_quantity : 0;
$profit_margin_percent = $total_final_price > 0 ? ($total_profit / $total_final_price) * 100 : 0;
$cost_efficiency = $total_cost > 0 ? ($total_final_price / $total_cost) * 100 : 0;

// تنسيق ورديات
$shifts = "";
if ($order['shift1']) $shifts .= "وردية أولى ";
if ($order['shift2']) $shifts .= "وردية ثانية ";

// الشهر والسنة
$month_year = "-";
if ($order['month'] && $order['year']) {
    $month_year = $order['month'] . '/' . $order['year'];
}

?>
<!-- ===================== واجهة العرض (HTML + CSS) ===================== -->
<style>
    .detail-card { margin-bottom: 20px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.06); }
    .card-header { font-weight: bold; font-size: 1.05rem; }
    .info-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #eee; }
    .info-label { font-weight:bold; color:#444; }
    .info-value { color:#222; }
    .summary-row { background-color:#f8f9fa; font-weight:bold; }
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
        <div class="alert alert-success">تمت إعادة حساب الأسعار بنجاح.</div>
    <?php endif; ?>

    <?php if ($total_profit < 0): ?>
        <div class="alert alert-warning">
            ⚠️ تحذير: هذا الأمر يسجل خسارة صافية (<?= number_format($total_profit,2) ?> جنيه).
        </div>
    <?php endif; ?>

    <div class="card detail-card">
        <div class="card-header bg-primary text-white">معلومات الأمر</div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="info-row"><span class="info-label">اسم المشروع:</span><span class="info-value"><?= htmlspecialchars($order['project_name'] ?? '-') ?></span></div>
                    <div class="info-row"><span class="info-label">الرقم التسلسلي:</span><span class="info-value"><?= htmlspecialchars($order['serial_number'] ?? '-') ?></span></div>
                    <div class="info-row"><span class="info-label">الشهر/السنة:</span><span class="info-value"><?= htmlspecialchars($month_year) ?></span></div>
                </div>
                <div class="col-md-6">
                    <div class="info-row"><span class="info-label">مدة التنفيذ:</span><span class="info-value"><?= htmlspecialchars($order['duration_days'] ?? '-') ?> يوم</span></div>
                    <div class="info-row"><span class="info-label">الورديات:</span><span class="info-value"><?= htmlspecialchars($shifts) ?></span></div>
                    <div class="info-row"><span class="info-label">تاريخ الإنشاء:</span><span class="info-value"><?= htmlspecialchars($order['created_at'] ?? '-') ?></span></div>
                </div>
            </div>
            <div class="info-row"><span class="info-label">ملاحظات:</span><span class="info-value"><?= htmlspecialchars($order['notes'] ?? '-') ?></span></div>
            <div class="info-row"><span class="info-label">الحالة:</span>
                <span class="info-value">
                    <?php
                        $status_class = 'bg-secondary'; $status_text = $order['status'] ?? '';
                        switch ($order['status']) {
                            case 'draft': $status_class='bg-secondary'; $status_text='مسودة'; break;
                            case 'started': $status_class='bg-primary'; $status_text='قيد التنفيذ'; break;
                            case 'completed': $status_class='bg-success'; $status_text='مكتمل'; break;
                            case 'expired': $status_class='bg-warning'; $status_text='منتهي الصلاحية'; break;
                        }
                    ?>
                    <span class="badge <?= $status_class ?>"><?= $status_text ?></span>
                </span>
            </div>
        </div>
    </div>

    <!-- مؤشرات مالية -->
    <div class="row mb-3">
        <div class="col-md-4">
            <div class="card detail-card p-3">
                <h6>التكلفة الإجمالية</h6>
                <div class="h4"><?= number_format($total_cost,2) ?> جنيه</div>
                <small>لـ <?= $total_quantity ?> وحدة</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card detail-card p-3">
                <h6>صافي الربح</h6>
                <div class="h4 <?= $total_profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($total_profit,2) ?> جنيه</div>
                <small>هامش ربح: <?= number_format($profit_margin_percent,2) ?>%</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card detail-card p-3">
                <h6>كفاءة التكلفة</h6>
                <div class="h4"><?= number_format($cost_efficiency,2) ?>%</div>
                <small>العائد على التكلفة</small>
            </div>
        </div>
    </div>

    <!-- منتجات الأمر -->
    <div class="card detail-card">
        <div class="card-header bg-info text-white">منتجات الأمر</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead><tr><th>#</th><th>المنتج</th><th>الكمية</th><th>سعر الوحدة (قيمة)</th><th>القيمة الإجمالية</th><th>ملاحظات</th></tr></thead>
                    <tbody>
                        <?php
                        $i=1;
                        $items_result->data_seek(0);
                        while ($item = $items_result->fetch_assoc()) {
                            $total_value = $item['quantity'] * $item['unit_price'];
                            echo "<tr>";
                            echo "<td>".$i++."</td>";
                            echo "<td>".htmlspecialchars($item['product_name'])."</td>";
                            echo "<td>".htmlspecialchars($item['quantity'])."</td>";
                            echo "<td>".number_format(floatval($item['unit_price']),2)." جنيه</td>";
                            echo "<td>".number_format($total_value,2)." جنيه</td>";
                            echo "<td>".htmlspecialchars($item['notes'] ?? '-')."</td>";
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
                            <th>#</th><th>المنتج</th><th>الكمية</th><th>تكلفة المواد</th><th>تكلفة العمالة</th>
                            <th>تكلفة الماكينات</th><th>تكلفة الكهرباء</th><th>تكلفة الصيانة</th>
                            <th>التكلفة الإجمالية</th><th>سعر البيع (الوحدة)</th><th>الربح</th><th>هامش الربح %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $i=1;
                        $production_result->data_seek(0);
                        while ($row = $production_result->fetch_assoc()) {
                            $quantity = max(0,intval($row['quantity']));

                            // إعادة حساب سريع مطابق لما استخدمناه أعلاه للعرض
                            $industrial_wood_cost = floatval($row['industrial_wood_cost'] ?? 0);
                            $industrial_waste_pct = floatval($row['industrial_waste_pct'] ?? 0);
                            $natural_wood_cost = floatval($row['natural_wood_cost'] ?? 0);
                            $natural_waste_pct = floatval($row['natural_waste_pct'] ?? 0);
                            $accessories_cost = floatval($row['accessories_cost'] ?? 0);

                            $industrial_wood_with_waste = $industrial_wood_cost * (1 + $industrial_waste_pct / 100);
                            $natural_wood_with_waste = $natural_wood_cost * (1 + $natural_waste_pct / 100);
                            $material_cost_per_unit = $industrial_wood_with_waste + $natural_wood_with_waste + $accessories_cost;

                            if ($material_cost_per_unit == 0) $material_cost_per_unit = $default_material_cost;

                            if (isset($row['material_cost']) && floatval($row['material_cost']) > 0) {
                                $maybe_total_material_cost = floatval($row['material_cost']);
                                if ($quantity > 0 && $maybe_total_material_cost > ($material_cost_per_unit * $quantity * 0.5)) {
                                    $material_cost_per_unit = $maybe_total_material_cost / $quantity;
                                } else {
                                    $material_cost_per_unit = $maybe_total_material_cost;
                                }
                            }

                            $item_material_cost = $material_cost_per_unit * $quantity;

                            $laborCalc = calculateLaborCost($conn, intval($row['product_id']), $quantity);
                            $item_labor_cost = floatval($laborCalc['total_labor_cost']);
                            if (intval($order['shift2']) === 1) $item_labor_cost *= $shift2_multiplier;

                            $machineCalc = calculateMachineCost($conn, intval($row['product_id']), $quantity);
                            $item_machine_cost = floatval($machineCalc['total_machine_cost']);

                            $item_electricity_cost = floatval($row['electricity_cost'] ?? 0);
                            $item_maintenance_cost = floatval($row['maintenance_cost'] ?? 0);

                            $base_cost_total = $item_material_cost + $item_labor_cost + $item_machine_cost;
                            $item_indirect_cost = $base_cost_total * ($indirect_percent / 100);
                            $production_hours = floatval($row['production_hours'] ?? 0);
                            $item_fixed_cost = $cost_per_hour * ($production_hours * $quantity);
                            $item_additional_maintenance_cost = $item_labor_cost * ($maintenance_cost_percentage / 100);
                            $item_total_cost = ($base_cost_total + $item_indirect_cost + $item_fixed_cost + $item_additional_maintenance_cost) * $risk_factor;

                            $item_final_price_per_unit = floatval($row['final_price'] ?? 0);
                            if ($item_final_price_per_unit == 0) {
                                $item_final_price_per_unit = ($quantity > 0 ? ($item_total_cost / $quantity) : $item_total_cost) * (1 + ($profit_margin / 100));
                            }
                            $item_final_price_total = $item_final_price_per_unit * $quantity;
                            $item_profit = $item_final_price_total - $item_total_cost;
                            $item_profit_margin = $item_final_price_total > 0 ? ($item_profit / $item_final_price_total) * 100 : 0;

                            echo "<tr>";
                            echo "<td>".$i++."</td>";
                            echo "<td>".htmlspecialchars($row['product_name'])."</td>";
                            echo "<td>".htmlspecialchars($quantity)."</td>";
                            echo "<td>".number_format($item_material_cost,2)." جنيه</td>";
                            echo "<td>".number_format($item_labor_cost,2)." جنيه</td>";
                            echo "<td>".number_format($item_machine_cost,2)." جنيه</td>";
                            echo "<td>".number_format($item_electricity_cost,2)." جنيه</td>";
                            echo "<td>".number_format($item_maintenance_cost,2)." جنيه</td>";
                            echo "<td>".number_format($item_total_cost,2)." جنيه</td>";
                            echo "<td>".number_format($item_final_price_per_unit,2)." جنيه</td>";
                            echo "<td class='".($item_profit>=0?'text-success':'text-danger')."'>".number_format($item_profit,2)." جنيه</td>";
                            echo "<td class='".($item_profit_margin>=0?'text-success':'text-danger')."'>".number_format($item_profit_margin,2)."%</td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                    <tfoot>
                        <tr class="summary-row">
                            <th colspan="3">الإجمالي</th>
                            <th><?= number_format($total_material_cost,2) ?> جنيه</th>
                            <th><?= number_format($total_labor_cost,2) ?> جنيه</th>
                            <th><?= number_format($total_machine_cost,2) ?> جنيه</th>
                            <th><?= number_format($total_electricity_cost,2) ?> جنيه</th>
                            <th><?= number_format($total_maintenance_cost,2) ?> جنيه</th>
                            <th><?= number_format($total_cost,2) ?> جنيه</th>
                            <th><?= number_format($total_final_price,2) ?> جنيه</th>
                            <th class="<?= $total_profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($total_profit,2) ?> جنيه</th>
                            <th class="<?= $profit_margin_percent >= 0 ? 'text-success' : 'text-danger' ?>"><?= number_format($profit_margin_percent,2) ?>%</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- إجراءات -->
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
                <div class="alert alert-info">لا يمكن تنفيذ إجراءات على هذا الأمر حالياً</div>
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
            <?php else: ?>
                <div class="mt-3">
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" name="recalculate_prices" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-repeat"></i> تحديث أسعار البيع (اختياري)
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<?php include "includes/footer.php"; ?>