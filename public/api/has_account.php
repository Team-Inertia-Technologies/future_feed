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
	$query = "SELECT iUserID FROM user WHERE cStatus='A' AND  iUserID = " . intval($userid);
	$result = sql_query($query);
	$row = sql_fetch_assoc($result);

	if (!$row) {
		http_response_code(200);
		echo json_encode([
			"statusCode" => 200,
			"data" => [
				"hasAccount" => false
			]
		]);
		exit;
	}

	http_response_code(200);
	echo json_encode([
		"statusCode" => 200,
		"data" => [
			"hasAccount" => true
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
