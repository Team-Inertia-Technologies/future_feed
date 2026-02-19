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

try {

    $query = "SELECT iFieldID, vName, vFile FROM fields WHERE cStatus = 'A' ORDER BY iRank ASC";
    $result = sql_query($query);
    $fields = array();
    while ($row = sql_fetch_assoc($result)) {
        $fields[] = array(
			"FieldID" => (int)$row['iFieldID'],
			"Name" => $row['vName'],
            "icon" => $row['vFile']
        );
    }

    $response = array(
        "statusCode" => 200,
        "data" => array(
            "fields" => $fields
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
