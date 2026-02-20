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
$userID = DecodeParam($token);
try {

    $myFieldsQuery = "
        SELECT 
            f.iFieldID,
            f.vName,
            f.vFile,
            ufa.cStatus
        FROM user_field_assoc ufa
        INNER JOIN fields f ON ufa.iFieldID = f.iFieldID
        WHERE ufa.iUserID = $userID
        AND ufa.cStatus = 'A'
        ORDER BY f.vName
    ";
    $myFieldsResult = sql_query($myFieldsQuery);
    $myFields = array();
    while ($row = sql_fetch_assoc($myFieldsResult)) {
        $myFields[] = array(
            "fieldID"   => $row['iFieldID'],
            "name"      => $row['vName'],
            "icon"      => "https://ti-stage-projects-future-feed.krjqe5.easypanel.host/uploads/" . rawurlencode($row['vFile']),
        );
    }

    $exploreQuery = "
        SELECT 
            f.iFieldID,
            f.vName,
            f.vFile
        FROM fields f
        WHERE f.cStatus = 'A'
        AND f.iFieldID NOT IN (
            SELECT iFieldID 
            FROM user_field_assoc 
            WHERE iUserID = $userID 
            AND cStatus = 'A'
        )
        ORDER BY f.vName
    ";
    $exploreResult = sql_query($exploreQuery);
    $exploreFields = array();
    while ($row = sql_fetch_assoc($exploreResult)) {
        $exploreFields[] = array(
            "fieldID"   => $row['iFieldID'],
            "name"      => $row['vName'],
            "icon"      => "https://ti-stage-projects-future-feed.krjqe5.easypanel.host/uploads/" . rawurlencode($row['vFile']),
        );
    }

    $response = array(
        "statusCode" => 200,
        "data" => array(
            "myFields"     => $myFields,
            "exploreMore"  => $exploreFields
        )
    );

    http_response_code(200);
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