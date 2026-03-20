<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";
header('Content-Type: application/json');
$postdata = file_get_contents("php://input");

$request = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);

$phone    = $request['phone'];
$apple_id = $request['apple_id'];
$q = "SELECT iUserID FROM user 
      WHERE vMobile = '" . db_input($phone) . "' 
      AND cStatus = 'A' LIMIT 1";
$r = sql_query($q, 'LINK.APPLE.1');

if (sql_num_rows($r)) {
    list($u_id) = sql_fetch_row($r);
    sql_query(
        "UPDATE user SET vAppleID = '" . db_input($apple_id) . "' 
         WHERE iUserID = $u_id",
        'LINK.APPLE.2'
    );

	echo json_encode([
		"statusCode" => 200,
		"data" => [
			"message" => "Apple ID linked successfully"
		]
	]);
	exit;
} else {
	http_response_code(201);
	header('Content-Type: application/json');
	echo json_encode([
		"statusCode" => 201,
		"error" => [
			"message" => "No account found. Please register first."
		]
	]);
	exit;
}