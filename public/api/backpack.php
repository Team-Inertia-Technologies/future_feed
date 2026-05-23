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

    // 2. COUNT eligible videos — fast index scan, no row data fetched
    $countRow      = sql_fetch_assoc(sql_query("
        SELECT COUNT(*) AS total
        FROM videos v
        WHERE v.iFieldID IN ($fieldIdList)
          AND v.cStatus = 'A'
          AND NOT EXISTS (
              SELECT 1 FROM user_watched_video uwv
              WHERE uwv.iVideoID = v.iVideoID
                AND uwv.iUserID  = $userId
                AND uwv.cStatus  = 'A'
          )
    "));
    $total = (int)($countRow['total'] ?? 0);

    if ($total === 0) {
        echo json_encode(["statusCode" => 200, "data" => ["videos" => [], "total" => 0]]);
        exit;
    }

    // 3. Pick 4 random OFFSETs spread across the full eligible range
    //    Each jump fetches 2 rows  →  4 × 2 = 8 candidates from different spots
    //    LIMIT 2 OFFSET N on an indexed query = instant, MySQL skips to N via index
    $jumps      = 4;
    $perJump    = 2;
    $offsets    = [];
    $attempts   = 0;
    $maxOffset  = max(0, $total - $perJump);

    while (count($offsets) < $jumps && $attempts < 30) {
        $o = rand(0, $maxOffset);
        // Keep offsets at least 10 apart so we don't land on the same creator twice
        $tooClose = false;
        foreach ($offsets as $existing) {
            if (abs($o - $existing) < 10) {
                $tooClose = true;
                break;
            }
        }
        if (!$tooClose) $offsets[] = $o;
        $attempts++;
    }
    // Safety: fill remaining slots without the spacing constraint if needed
    while (count($offsets) < $jumps) {
        $offsets[] = rand(0, $maxOffset);
    }

    // 4. Fetch IDs at each random offset — IDs only, no heavy joins
    $candidateIds = [];
    foreach ($offsets as $offset) {
        $res = sql_query("
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
            LIMIT $perJump OFFSET $offset
        ");
        while ($row = sql_fetch_assoc($res)) {
            $candidateIds[] = (int) $row['iVideoID'];
        }
    }

    $candidateIds = array_values(array_unique($candidateIds));

    if (empty($candidateIds)) {
        echo json_encode(["statusCode" => 200, "data" => ["videos" => [], "total" => 0]]);
        exit;
    }

    // 5. Final shuffle + pick 8
    shuffle($candidateIds);
    $picked     = array_slice($candidateIds, 0, 8);
    $pickedList = implode(',', $picked);

    // 6. Fetch full video data for exactly those rows — PK lookups, instant
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

    shuffle($videos);

    echo json_encode(["statusCode" => 200, "data" => ["videos" => $videos, "total" => count($videos)]]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["statusCode" => 500, "error" => ["message" => $e->getMessage()]]);
    exit;
}
