<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";
header('Content-Type: application/json');
$postdata = file_get_contents("php://input");

$request = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);

$token = $_REQUEST['token'] ?? '';
if (!$token) {
    http_response_code(400);
    echo json_encode([
        "statusCode" => 400,
        "error" => [
            "message" => "Missing token"
        ]
    ]);
    exit;
}
$userid = DecodeParam($token);

try {
    $query = "SELECT vName, vEmail, vPic FROM user WHERE iUserID = " . intval($userid);
    $result = sql_query($query);
    $row = sql_fetch_assoc($result);

    if (!$row) {
        http_response_code(404);
        echo json_encode([
            "statusCode" => 404,
            "error" => [
                "message" => "User not found"
            ]
        ]);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        "statusCode" => 200,
        "data" => [
            "name"  => $row['vName'],
            "email" => $row['vEmail'],
            "pic"   => 'https://futurefeed.top/public/uploads/' . $row['vPic'],
			"watched" => (int) GetXFromYID("SELECT COUNT(*) FROM user_watched_video WHERE iUserID = $userid"),
			"liked" => (int) GetXFromYID("SELECT COUNT(*) FROM user_liked_video WHERE iUserID = $userid"),
        ]
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "statusCode" => 500,
        "error" => [
            "message" => $e->getMessage()
        ]
    ]);
    exit;
}