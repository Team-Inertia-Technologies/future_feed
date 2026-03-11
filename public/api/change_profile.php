<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";
header('Content-Type: application/json');

// Use $_POST since we're receiving multipart/form-data for file upload
$token = $_POST['token'] ?? $_REQUEST['token'] ?? '';
$name  = $_POST['name']  ?? $_REQUEST['name']  ?? '';
$dob   = $_POST['dob']   ?? $_REQUEST['dob']   ?? '';
$DOB   = !empty($dob) ? date('Y-m-d', strtotime(trim($dob))) : '';

if (!$token) {
    http_response_code(400);
    echo json_encode([
        "statusCode" => 400,
        "error" => ["message" => "Missing token"]
    ]);
    exit;
}

$userID = DecodeParam($token);
if (!$userID) {
    http_response_code(401);
    echo json_encode([
        "statusCode" => 401,
        "error" => ["message" => "Invalid token"]
    ]);
    exit;
}

try {
    $photoFilename = null;

    // ── Handle profile picture upload ─────────────────────────────────
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {

        $file     = $_FILES['profile_pic'];
        $allowed  = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $mimeType = mime_content_type($file['tmp_name']);

        // Validate file type
        if (!in_array($mimeType, $allowed)) {
            http_response_code(400);
            echo json_encode([
                "statusCode" => 400,
                "error" => ["message" => "Invalid file type. Only JPG, PNG and WEBP are allowed."]
            ]);
            exit;
        }

        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode([
                "statusCode" => 400,
                "error" => ["message" => "File size exceeds 5MB limit."]
            ]);
            exit;
        }

        // Build upload path
        $uploadDir = "../uploads/profile_pics/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext           = pathinfo($file['name'], PATHINFO_EXTENSION);
        $photoFilename = "user_" . $userID . "_" . time() . "." . $ext;
        $uploadPath    = $uploadDir . $photoFilename;

        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            http_response_code(500);
            echo json_encode([
                "statusCode" => 500,
                "error" => ["message" => "Failed to upload profile picture."]
            ]);
            exit;
        }
    }

    // ── Build update query ────────────────────────────────────────────
    $setParts = [];

    if (!empty($name)) {
        $setParts[] = "vName = '" . db_input($name) . "'";
    }
    if (!empty($DOB)) {
        $setParts[] = "dDOB = '$DOB'";
    }
    if (!empty($photoFilename)) {
        $setParts[] = "vPic = '../uploads/profile_pics/" . db_input($photoFilename) . "'";
    }

    if (empty($setParts)) {
        http_response_code(400);
        echo json_encode([
            "statusCode" => 400,
            "error" => ["message" => "No data provided to update."]
        ]);
        exit;
    }

    $setClause = implode(", ", $setParts);
    $query     = "UPDATE user SET $setClause WHERE iUserID = $userID";
    echo $query;
    exit;
    sql_query($query);

    // ── Return updated pic URL if uploaded ────────────────────────────
    $responseData = ["message" => "User updated successfully"];
    if (!empty($photoFilename)) {
        $responseData["profile_pic"] = "uploads/profile_pics/" . $photoFilename;
    }

    http_response_code(200);
    echo json_encode([
        "statusCode" => 200,
        "data"       => $responseData
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "statusCode" => 500,
        "error" => ["message" => $e->getMessage()]
    ]);
    exit;
}