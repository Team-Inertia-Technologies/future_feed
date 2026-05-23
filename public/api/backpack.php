<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";
header('Content-Type: application/json');

$postdata = file_get_contents("php://input");
$request  = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);

$token = $_REQUEST['token'] ?? '';
if (!$token) {
    http_response_code(400);
    echo json_encode(["statusCode" => 400, "error" => ["message" => "Missing token"]]);
    exit;
}

try {
    $userId = (int) DecodeParam($token);
    if (!$userId) {
        http_response_code(400);
        echo json_encode(["statusCode" => 400, "error" => ["message" => "Invalid token"]]);
        exit;
    }

    // 1. Get user's field IDs
    $fieldResult = sql_query("SELECT iFieldID FROM user_field_assoc WHERE iUserID = $userId AND cStatus = 'A'");
    $fieldIds = [];
    while ($row = sql_fetch_assoc($fieldResult)) {
        $fieldIds[] = (int) $row['iFieldID'];
    }

    if (empty($fieldIds)) {
        echo json_encode(["statusCode" => 200, "data" => ["videos" => [], "message" => "No field interests found for user"]]);
        exit;
    }

    $fieldIdList = implode(',', $fieldIds);

    // 2. Fetch a pool of 80 candidate IDs cheaply — no joins, just index seeks
    //    NOT EXISTS on (iUserID, iVideoID, cStatus) index = microseconds per row
    $idResult = sql_query("
        SELECT v.iVideoID
        FROM videos v
        WHERE v.iFieldID IN ($fieldIdList)
          AND v.cStatus = 'A'
          AND NOT EXISTS (
              SELECT 1 FROM user_watched_video uwv
              WHERE uwv.iVideoID = v.iVideoID
                AND uwv.iUserID  = $userId
                AND uwv.cStatus  = 'A'
          )
        LIMIT 80
    ");

    $candidateIds = [];
    while ($row = sql_fetch_assoc($idResult)) {
        $candidateIds[] = (int) $row['iVideoID'];
    }

    if (empty($candidateIds)) {
        echo json_encode(["statusCode" => 200, "data" => ["videos" => [], "total" => 0]]);
        exit;
    }

    // 3. Shuffle in PHP (free) and pick 8 random IDs from across the pool
    shuffle($candidateIds);
    $picked     = array_slice($candidateIds, 0, 8);
    $pickedList = implode(',', $picked);

    // 4. Fetch full video data for only those 8 rows (8 PK lookups = instant)
    //    Counts via correlated subqueries — only run on 8 rows, no join explosion
    $videoResult = sql_query("
        SELECT
            v.*,
            (SELECT COUNT(*) FROM user_liked_video vl
             WHERE vl.iVideoID = v.iVideoID AND vl.cStatus = 'A') AS like_count,
            (SELECT COUNT(*) FROM comment vc
             WHERE vc.iVideoID = v.iVideoID AND vc.cStatus = 'A') AS comment_count,
            EXISTS (
                SELECT 1 FROM user_liked_video ul
                WHERE ul.iVideoID = v.iVideoID
                  AND ul.iUserID  = $userId
                  AND ul.cStatus  = 'A'
            ) AS isLiked
        FROM videos v
        WHERE v.iVideoID IN ($pickedList)
    ");

    $videos = [];
    while ($row = sql_fetch_assoc($videoResult)) {
        $row['like_count']    = (int)  $row['like_count'];
        $row['comment_count'] = (int)  $row['comment_count'];
        $row['isLiked']       = (bool) $row['isLiked'];
        $videos[] = $row;
    }

    // 5. Re-shuffle so IN() clause order doesn't cluster results
    shuffle($videos);

    echo json_encode(["statusCode" => 200, "data" => ["videos" => $videos, "total" => count($videos)]]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["statusCode" => 500, "error" => ["message" => $e->getMessage()]]);
    exit;
}
