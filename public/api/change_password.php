<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";

header('Content-Type: application/json');

$postdata = file_get_contents("php://input");
$request = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);

$token            = $_REQUEST['token'] ?? '';
$currentPassword  = trim($_REQUEST['currentPassword'] ?? '');
$newPassword      = trim($_REQUEST['newPassword'] ?? '');
$confirmPassword  = trim($_REQUEST['confirmPassword'] ?? '');

if (!$token) {
    http_response_code(400);
    echo json_encode([
        "statusCode" => 400,
        "error" => ["message" => "Missing token"]
    ]);
    exit;
}

$userid = DecodeParam($token);

if (!$userid) {
    http_response_code(401);
    echo json_encode([
        "statusCode" => 401,
        "error" => ["message" => "Invalid token"]
    ]);
    exit;
}

if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
    http_response_code(400);
    echo json_encode([
        "statusCode" => 400,
        "error" => ["message" => "All password fields are required"]
    ]);
    exit;
}

if ($newPassword !== $confirmPassword) {
    http_response_code(400);
    echo json_encode([
        "statusCode" => 400,
        "error" => ["message" => "New password and confirm password do not match"]
    ]);
    exit;
}

$userQuery = "
    SELECT iUserID 
    FROM user 
    WHERE vPassword = '" . db_input($currentPassword) . "'
    LIMIT 1
";

$userResult = sql_query($userQuery, 'API.CHANGE.PASS.1');

if (!sql_num_rows($userResult)) {
    http_response_code(404);
    echo json_encode([
        "statusCode" => 404,
        "error" => ["message" => "Current password is incorrect"]
    ]);
    exit;
}

$updateQuery = "
    UPDATE user 
    SET vPassword = '" . db_input($newPassword) . "' 
    WHERE iUserID = '" . intval($userid) . "'
";

$updateResult = sql_query($updateQuery, 'API.CHANGE.PASS.2');

if (!$updateResult) {
    http_response_code(500);
    echo json_encode([
        "statusCode" => 500,
        "error" => ["message" => "Failed to update password"]
    ]);
    exit;
}

http_response_code(200);
echo json_encode([
    "statusCode" => 200,
    "message" => "Password updated successfully"
]);
exit;
?>