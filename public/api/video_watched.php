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
$duration = $_REQUEST['duration'] ?? '';

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
    // 🔹 Check if already watched
    $checkQuery = "SELECT iAssocID FROM user_watched_video WHERE iUserID = $userid AND iVideoID = $videoid AND cStatus = 'A'";
    $checkResult = sql_query($checkQuery);

    if (sql_num_rows($checkResult) > 0) {
        // 🔹 Already exists - just update the duration
        $updateQuery = "UPDATE user_watched_video SET vDuration = '$duration', dtAdded = '".NOW."' WHERE iUserID = $userid AND iVideoID = $videoid AND cStatus = 'A'";
        sql_query($updateQuery);

        echo json_encode([
            "statusCode" => 200,
            "data" => ["message" => "Watch duration updated"]
        ]);
        exit;
    }

    // 🔹 Fresh insert
    $id  = NextID('iAssocID', 'user_watched_video');
    $now = NOW;
    $query = "INSERT INTO user_watched_video (iAssocID, iUserID, iVideoID, vDuration, dtAdded, cStatus) VALUES ($id, $userid, $videoid, '$duration', '$now', 'A')";
    sql_query($query);

    echo json_encode([
        "statusCode" => 200,
        "data" => ["message" => "Video watched successfully"]
    ]);
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
