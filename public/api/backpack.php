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

    // ── 1. Field IDs: cache per user for 5 min to avoid repeated lookup ──────
    $cacheKey  = "user_fields_{$userId}";
    $fieldIds  = false;

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
            apcu_store($cacheKey, $fieldIds, 300); // 5-minute TTL
        }
    }

    if (empty($fieldIds)) {
        echo json_encode([
            "statusCode" => 200,
            "data"       => ["videos" => [], "message" => "No field interests found for user"]
        ]);
        exit;
    }

    $fieldIdList = implode(',', $fieldIds); // already cast to int above

    // ── 2. Fast random offset instead of ORDER BY RAND() ─────────────────────
    //    Get the min/max eligible video IDs first (cheap index scan), then pick
    //    a random starting point within that range.
    $rangeRow = sql_fetch_assoc(sql_query(
        "SELECT MIN(v.iVideoID) AS min_id, MAX(v.iVideoID) AS max_id
         FROM videos v
         WHERE v.iFieldID IN ($fieldIdList) AND v.cStatus = 'A'"
    ));

    $minId = (int)($rangeRow['min_id'] ?? 0);
    $maxId = (int)($rangeRow['max_id'] ?? 0);

    // Random offset anchor — fall back to 0 if range is tiny
    $randOffset = ($maxId > $minId) ? rand($minId, $maxId) : $minId;

    // ── 3. Main query — pre-aggregate in subqueries, no row multiplication ────
    //    Strategy:
    //      • Use iVideoID >= $randOffset with LIMIT 8 for the fast random page,
    //        then UNION with the wraparound block to always return 8 rows.
    //      • Counts are subquery scalars — avoids cross-join row explosion.
    //      • Watched exclusion uses NOT EXISTS (index-friendly).
    //      • Like status uses a single EXISTS check.
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
        WHERE v.iFieldID IN ($fieldIdList)
          AND v.cStatus  = 'A'
          AND v.iVideoID >= $randOffset
          AND NOT EXISTS (
                SELECT 1
                FROM user_watched_video uwv
                WHERE uwv.iVideoID = v.iVideoID
                  AND uwv.iUserID  = $userId
                  AND uwv.cStatus  = 'A'
          )
        LIMIT 8
    ";

    $videos = [];
    $videoResult = sql_query($videoQuery);
    while ($row = sql_fetch_assoc($videoResult)) {
        $row['like_count']    = (int)  $row['like_count'];
        $row['comment_count'] = (int)  $row['comment_count'];
        $row['isLiked']       = (bool) $row['isLiked'];
        $videos[] = $row;
    }

    // ── 4. Wraparound: if we got fewer than 8, fetch from the start ───────────
    if (count($videos) < 8) {
        $existingIds = empty($videos)
            ? '0'
            : implode(',', array_map(fn($v) => (int)$v['iVideoID'], $videos));
        $need = 8 - count($videos);

        $wrapQuery = "
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
            WHERE v.iFieldID IN ($fieldIdList)
              AND v.cStatus  = 'A'
              AND v.iVideoID < $randOffset
              AND v.iVideoID NOT IN ($existingIds)
              AND NOT EXISTS (
                    SELECT 1
                    FROM user_watched_video uwv
                    WHERE uwv.iVideoID = v.iVideoID
                      AND uwv.iUserID  = $userId
                      AND uwv.cStatus  = 'A'
            )
            LIMIT $need
        ";

        $wrapResult = sql_query($wrapQuery);
        while ($row = sql_fetch_assoc($wrapResult)) {
            $row['like_count']    = (int)  $row['like_count'];
            $row['comment_count'] = (int)  $row['comment_count'];
            $row['isLiked']       = (bool) $row['isLiked'];
            $videos[] = $row;
        }
    }

    echo json_encode([
        "statusCode" => 200,
        "data"       => ["videos" => $videos, "total" => count($videos)]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["statusCode" => 500, "error" => ["message" => $e->getMessage()]]);
    exit;
}
