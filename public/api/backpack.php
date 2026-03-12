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
    $userId = DecodeParam($token);

    if (!$userId) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            "statusCode" => 400,
            "error" => [
                "message" => "Invalid token"
            ]
        ]);
        exit;
    }

    $fieldQuery = "SELECT iFieldID FROM user_field_assoc WHERE iUserID = $userId AND cStatus = 'A'";
    $fieldResult = sql_query($fieldQuery);


    $fieldIds = [];
    while ($row = sql_fetch_assoc($fieldResult)) {
        $fieldIds[] = $row['iFieldID'];
    }
    if (empty($fieldIds)) {
        $response = [
            "statusCode" => 200,
            "data" => [
                "videos" => [],
                "message" => "No field interests found for user"
            ]
        ];
        echo json_encode($response);
        exit;
    }

    // Get videos that match user's fields but exclude watched ones
    $fieldIds = array_map('intval', $fieldIds);
    $userId   = (int)$userId;

    // Convert array to comma separated string
    $fieldIdList = implode(',', $fieldIds);

    $videoQuery = "
        SELECT 
            v.*,
            COUNT(DISTINCT vl.iAssocID) AS like_count,
            COUNT(DISTINCT vc.iCommentID) AS comment_count,
            CASE WHEN ul.iUserID IS NOT NULL THEN 1 ELSE 0 END AS isLiked
        FROM videos v
        LEFT JOIN user_watched_video uwv 
            ON v.iVideoID = uwv.iVideoID 
            AND uwv.iUserID = $userId 
            AND uwv.cStatus = 'A'
        LEFT JOIN user_liked_video vl 
            ON v.iVideoID = vl.iVideoID 
            AND vl.cStatus = 'A'
        LEFT JOIN comment vc 
            ON v.iVideoID = vc.iVideoID 
            AND vc.cStatus = 'A'
        LEFT JOIN user_liked_video ul
            ON v.iVideoID = ul.iVideoID
            AND ul.iUserID = $userId
            AND ul.cStatus = 'A'
        WHERE v.iFieldID IN ($fieldIdList)
        AND v.cStatus = 'A'
        AND uwv.iVideoID IS NULL
        GROUP BY v.iVideoID
        ORDER BY RAND()
        LIMIT 8";

    $videoResult = sql_query($videoQuery);

    $videos = [];
    while ($row = sql_fetch_assoc($videoResult)) {
        $row['like_count']    = (int)$row['like_count'];
        $row['comment_count'] = (int)$row['comment_count'];
        $row['isLiked']       = (bool)$row['isLiked'];
        $videos[] = $row;
    }
    $response = [
        "statusCode" => 200,
        "data" => [
            "videos" => $videos,
            "total" => count($videos)
        ]
    ];

    echo json_encode($response);
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
