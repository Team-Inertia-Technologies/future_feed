<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";
header('Content-Type: application/json');
$postdata = file_get_contents("php://input");

$request = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);

$phone    = db_input($request['phone'] ?? '');
$apple_id = db_input($request['apple_id'] ?? '');

if (empty($phone) || empty($apple_id)) {
    http_response_code(400);
    echo json_encode([
        "statusCode" => 400,
        "error" => ["message" => "Phone and Apple ID are required"]
    ]);
    exit;
}

$checkApple = sql_query("SELECT iUserID FROM user WHERE vAppleID = '$apple_id' LIMIT 1", 'LINK.APPLE.CHECK');

if (sql_num_rows($checkApple)) {
    http_response_code(409);
    echo json_encode([
        "statusCode" => 409,
        "error" => ["message" => "This Apple ID is already linked to another account"]
    ]);
    exit;
}

$q = "SELECT iUserID FROM user WHERE vMobile = '$phone' AND cStatus = 'A' LIMIT 1";
$r = sql_query($q, 'LINK.APPLE.1');

if (sql_num_rows($r)) {
    list($u_id) = sql_fetch_row($r);
    sql_query("UPDATE user SET vAppleID = '$apple_id' WHERE iUserID = $u_id", 'LINK.APPLE.2');

    http_response_code(200);
    echo json_encode([
        "statusCode" => 200,
        "data" => [
            "message" => "Apple ID linked successfully"
        ]
    ]);
    exit;

} else {
    http_response_code(404);
    echo json_encode([
        "statusCode" => 404,
        "error" => [
            "message" => "No account found. Please register first."
        ]
    ]);
    exit;
}