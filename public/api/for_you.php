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
    echo json_encode(["statusCode" => 400, "error" => ["message" => "Missing token"]]);
    exit;
}

try {

    $userId = DecodeParam($token);

    if (!$userId) {
        http_response_code(400);
        echo json_encode(["statusCode" => 400, "error" => ["message" => "Invalid token"]]);
        exit;
    }

    $userId = (int)$userId;

    $fieldQuery = "SELECT iFieldID FROM user_field_assoc WHERE iUserID = $userId AND cStatus = 'A'";
    $fieldResult = sql_query($fieldQuery);

    $fieldIds = [];
    while ($row = sql_fetch_assoc($fieldResult)) {
        $fieldIds[] = (int)$row['iFieldID'];
    }

    if (empty($fieldIds)) {
        echo json_encode([
            "statusCode" => 200,
            "data" => ["videos" => [], "message" => "No field interests found for user"]
        ]);
        exit;
    }

    $fieldIdList = implode(',', $fieldIds);
    $tagQuery = "
        SELECT DISTINCT vTags 
        FROM videos 
        WHERE iFieldID IN ($fieldIdList) 
        AND cStatus = 'A' 
        AND vTags IS NOT NULL 
        AND vTags != ''
    ";
    $tagResult = sql_query($tagQuery);

    $allTags = [];
    while ($row = sql_fetch_assoc($tagResult)) {
        $tags = explode(',', $row['vTags']);
        foreach ($tags as $tag) {
            $clean = trim(str_replace(['"', "'"], '', $tag));
            if ($clean !== '') {
                $allTags[] = db_input($clean);
            }
        }
    }
    $allTags = array_unique($allTags);

    $tagConditions = '';
    if (!empty($allTags)) {
        $tagLikes = array_map(fn($tag) => "v.vTags LIKE '%$tag%'", $allTags);
        $tagConditions = " OR (" . implode(' OR ', $tagLikes) . ")";
    }

    $videoQuery = "
    SELECT 
        v.*,
        (
            SELECT COUNT(*)
            FROM user_liked_video ulv
            WHERE ulv.iVideoID = v.iVideoID
            AND ulv.cStatus = 'A'
        ) AS like_count,

        (
            SELECT COUNT(*)
            FROM comment c
            WHERE c.iVideoID = v.iVideoID
            AND c.cStatus = 'A'
        ) AS comment_count,

        EXISTS(
            SELECT 1
            FROM user_liked_video ul
            WHERE ul.iVideoID = v.iVideoID
            AND ul.iUserID = $userId
            AND ul.cStatus = 'A'
        ) AS isLiked

    FROM videos v

    WHERE v.cStatus = 'A'

    AND NOT EXISTS (
        SELECT 1
        FROM user_watched_video uwv
        WHERE uwv.iVideoID = v.iVideoID
        AND uwv.iUserID = $userId
        AND uwv.cStatus = 'A'
    )

    AND (
        v.iFieldID IN ($fieldIdList)
        $tagConditions
    )

    ORDER BY v.iVideoID DESC
    LIMIT 10
";

    $videoResult = sql_query($videoQuery);

    $videos = [];
    while ($row = sql_fetch_assoc($videoResult)) {
        $row['like_count']    = (int)$row['like_count'];
        $row['comment_count'] = (int)$row['comment_count'];
        $row['isLiked']       = (bool)$row['isLiked'];
        $videos[] = $row;
    }

    echo json_encode([
        "statusCode" => 200,
        "data" => [
            "videos" => $videos,
            "total"  => count($videos)
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["statusCode" => 500, "error" => ["message" => $e->getMessage()]]);
    exit;
}