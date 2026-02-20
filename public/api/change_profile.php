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
$name = $_REQUEST['name'] ?? '';
$DOB = $_REQUEST['dob'] ?? '';
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

$userID = DecodeParam($token);

try{

	$query = "UPDATE user SET vName = '$name', dDOB = '$DOB' WHERE iUserID = $userID";
	sql_query($query);

	$response = array(
		"statusCode" => 200,
		"data" => array(
			"message" => "User Updated successfully"
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