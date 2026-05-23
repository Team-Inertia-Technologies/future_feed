<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";
header('Content-Type: application/json');

$postdata = file_get_contents("php://input");
$request  = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);
$token    = $_REQUEST['token'] ?? '';

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

    // ── 1. Field IDs: cache per user for 5 min ───────────────────────────────
    $cacheKey = "user_fields_{$userId}";
    $fieldIds = false;
    if (function_exists('apcu_fetch')) {
        $fieldIds = apcu_fetch($cacheKey);
    }
    if ($fieldIds === false) {
        $fieldResult = sql_query(
            "SELECT iFieldID FROM user_field_assoc
             WHERE iUserID = $userId AND cStatus = 'A'"
        );
        $fieldIds = [];
        while ($row = sql_fetch_assoc($fieldResult)) {
            $fieldIds[] = (int) $row['iFieldID'];
        }
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $fieldIds, 300);
        }
    }

    if (empty($fieldIds)) {
        echo json_encode([
            "statusCode" => 200,
            "data"       => ["videos" => [], "message" => "No field interests found for user"]
        ]);
        exit;
    }

    $fieldIdList = implode(',', $fieldIds);

    // ── 2. Fetch a pool of ~60 candidate IDs only (no heavy joins yet) ───────
    //    This is a lightweight scan: only touches the videos + user_watched_video
    //    indexes, returns just iVideoID integers. Very fast even at scale.
    //    We fetch 60 so PHP has enough to shuffle and pick 8 diverse results.
    $candidateQuery = "
        SELECT v.iVideoID
        FROM videos v
        WHERE v.iFieldID IN ($fieldIdList)
          AND v.cStatus = 'A'
          AND NOT EXISTS (
              SELECT 1
              FROM user_watched_video uwv
              WHERE uwv.iVideoID = v.iVideoID
                AND uwv.iUserID  = $userId
                AND uwv.cStatus  = 'A'
          )
        LIMIT 60
    ";

    $candidateResult = sql_query($candidateQuery);
    $candidateIds = [];
    while ($row = sql_fetch_assoc($candidateResult)) {
        $candidateIds[] = (int) $row['iVideoID'];
    }

    if (empty($candidateIds)) {
        echo json_encode([
            "statusCode" => 200,
            "data"       => ["videos" => [], "total" => 0]
        ]);
        exit;
    }

    // ── 3. True shuffle in PHP, pick 8 ───────────────────────────────────────
    //    PHP shuffle is O(n) on a tiny array (max 60 ints). Zero DB cost.
    shuffle($candidateIds);
    $pickedIds = array_slice($candidateIds, 0, 8);
    $pickedIdList = implode(',', $pickedIds);

    // ── 4. Fetch full data for exactly those 8 IDs (PK lookups — instant) ────
    $videoQuery = "
        SELECT
            v.*,
            COALESCE((
                SELECT COUNT(*)
                FROM user_liked_video vl
                WHERE vl.iVideoID = v.iVideoID AND vl.cStatus = 'A'
            ), 0) AS like_count,
            COALESCE((
                SELECT COUNT(*)
                FROM comment vc
                WHERE vc.iVideoID = v.iVideoID AND vc.cStatus = 'A'
            ), 0) AS comment_count,
            EXISTS (
                SELECT 1
                FROM user_liked_video ul
                WHERE ul.iVideoID = v.iVideoID
                  AND ul.iUserID  = $userId
                  AND ul.cStatus  = 'A'
            ) AS isLiked
        FROM videos v
        WHERE v.iVideoID IN ($pickedIdList)
    ";

    $videoResult = sql_query($videoQuery);
    $videos = [];
    while ($row = sql_fetch_assoc($videoResult)) {
        $row['like_count']    = (int)  $row['like_count'];
        $row['comment_count'] = (int)  $row['comment_count'];
        $row['isLiked']       = (bool) $row['isLiked'];
        $videos[] = $row;
    }

    // ── 5. Re-shuffle final list to match the PHP-randomised order ───────────
    //    MySQL IN() doesn't guarantee order, so shuffle again for good measure.
    shuffle($videos);

    echo json_encode([
        "statusCode" => 200,
        "data"       => ["videos" => $videos, "total" => count($videos)]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["statusCode" => 500, "error" => ["message" => $e->getMessage()]]);
    exit;
}
