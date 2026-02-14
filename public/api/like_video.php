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
$videoid = $_REQUEST['iVideoID'] ?? '';

if (!$token) {
    http_response_code(400);
    header('Content-Type: application/json');
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
    $id = NextID('iAssocID', 'user_liked_video');
    $now = NOW;
    $query = "INSERT INTO user_liked_video (iAssocID, iUserID, iVideoID, dtAdded) VALUES ($id, $userid, $videoid, '$now')";
    sql_query($query);

    $response = array(
        "statusCode" => 200,
        "data" => array(
            "message" => "Video Liked successfully"
        )
    );
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
} catch (Exception $e) {
    $response = array(
        "error" => array(
            "message" => $e->getMessage()
        ),
        "statusCode" => 500,
    );
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
