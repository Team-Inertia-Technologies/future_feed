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
        "error" => ["message" => "Missing token"]
    ]);
    exit;
}

try {

    $userId = DecodeParam($token);

    if (!$userId) {
        http_response_code(400);
        echo json_encode([
            "statusCode" => 400,
            "error" => ["message" => "Invalid token"]
        ]);
        exit;
    }

    $userId = (int)$userId;

    // 🔹 Get user interested fields
    $fieldQuery = "SELECT iFieldID FROM user_field_assoc WHERE iUserID = $userId AND cStatus = 'A'";
    $fieldResult = sql_query($fieldQuery);

    $fieldIds = [];
    while ($row = sql_fetch_assoc($fieldResult)) {
        $fieldIds[] = (int)$row['iFieldID'];
    }

    if (empty($fieldIds)) {
        echo json_encode([
            "statusCode" => 200,
            "data" => [
                "videos" => [],
                "message" => "No field interests found for user"
            ]
        ]);
        exit;
    }

    $fieldIdList = implode(',', $fieldIds);

    // 🔹 Final Video Query
    $videoQuery = "
    SELECT DISTINCT v.*
    FROM videos v

    LEFT JOIN video_tags_assoc vta 
        ON v.iVideoID = vta.iVideoID 
        AND vta.cStatus = 'A'

    LEFT JOIN user_watched_video uwv
        ON v.iVideoID = uwv.iVideoID
        AND uwv.iUserID = $userId
        AND uwv.cStatus = 'A'

    WHERE v.iFieldID IN ($fieldIdList)
    AND v.cStatus = 'A'
    AND uwv.iVideoID IS NULL

    ORDER BY RAND()
    LIMIT 10
";

    $videoResult = sql_query($videoQuery);

    $videos = [];
    while ($row = sql_fetch_assoc($videoResult)) {
        $videos[] = $row;
    }

    echo json_encode([
        "statusCode" => 200,
        "data" => [
            "videos" => $videos,
            "total" => count($videos)
        ]
    ]);
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
