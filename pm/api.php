<?php
header("Content-Type: application/json; charset=UTF-8");
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? '';
$id = $_GET['id'] ?? null;

switch ($resource) {
    case 'projects':
        handle_projects($conn, $method, $id);
        break;
    case 'tasks':
        handle_tasks($conn, $method, $id);
        break;
    default:
        http_response_code(404);
        echo json_encode(["message" => "Resource not found."]);
        break;
}

function handle_projects($conn, $method, $id) {
    switch ($method) {
        case 'GET':
            if ($id) {
                // Get a single project
                $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $project = $result->fetch_assoc();
                echo json_encode($project);
            } else {
                // Get all projects
                $result = $conn->query("SELECT * FROM projects");
                $projects = [];
                while ($row = $result->fetch_assoc()) {
                    $projects[] = $row;
                }
                echo json_encode($projects);
            }
            break;
        default:
            http_response_code(405);
            echo json_encode(["message" => "Method not allowed."]);
            break;
    }
}

function handle_tasks($conn, $method, $id) {
    switch ($method) {
        case 'GET':
            if (isset($_GET['project_id'])) {
                $project_id = intval($_GET['project_id']);
                $stmt = $conn->prepare("SELECT * FROM tasks WHERE project_id = ?");
                $stmt->bind_param("i", $project_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $tasks = [];
                while ($row = $result->fetch_assoc()) {
                    $tasks[] = $row;
                }
                echo json_encode($tasks);
            } else {
                http_response_code(400);
                echo json_encode(["message" => "Project ID is required."]);
            }
            break;
        default:
            http_response_code(405);
            echo json_encode(["message" => "Method not allowed."]);
            break;
    }
}

$conn->close();
?>