<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المشاريع</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <h1>أداة إدارة المشاريع</h1>

        <div class="project-selector">
            <label for="project-select">اختر مشروع:</label>
            <select id="project-select"></select>
        </div>

        <div id="gantt"></div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/frappe-gantt/dist/frappe-gantt.min.js"></script>
    <script src="gantt.js"></script>
</body>
</html>