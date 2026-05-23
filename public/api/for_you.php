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

    // ── 1. Field IDs (APCu cached 5 min) ─────────────────────────────────────
    $cacheKey = "user_fields_{$userId}";
    $fieldIds = false;
    if (function_exists('apcu_fetch')) $fieldIds = apcu_fetch($cacheKey);
    if ($fieldIds === false) {
        $res = sql_query("SELECT iFieldID FROM user_field_assoc WHERE iUserID = $userId AND cStatus = 'A'");
        $fieldIds = [];
        while ($row = sql_fetch_assoc($res)) $fieldIds[] = (int) $row['iFieldID'];
        if (function_exists('apcu_store')) apcu_store($cacheKey, $fieldIds, 300);
    }

    if (empty($fieldIds)) {
        echo json_encode(["statusCode" => 200, "data" => ["videos" => [], "message" => "No field interests found for user"]]);
        exit;
    }

    $fieldIdList = implode(',', $fieldIds);

    // ── 2. Collect unique tags (APCu cached 10 min) ───────────────────────────
    $tagCacheKey = "user_tags_{$userId}";
    $allTags = false;
    if (function_exists('apcu_fetch')) $allTags = apcu_fetch($tagCacheKey);
    if ($allTags === false) {
        $tagResult = sql_query("SELECT vTags FROM videos WHERE iFieldID IN ($fieldIdList) AND cStatus = 'A' AND vTags IS NOT NULL AND vTags != ''");
        $allTags = [];
        while ($row = sql_fetch_assoc($tagResult)) {
            foreach (explode(',', $row['vTags']) as $tag) {
                $clean = trim(str_replace(['"', "'"], '', $tag));
                if ($clean !== '') $allTags[] = db_input($clean);
            }
        }
        $allTags = array_values(array_unique($allTags));
        if (function_exists('apcu_store')) apcu_store($tagCacheKey, $allTags, 600);
    }

    // ── 3. Get total count of eligible videos so we can random-offset ─────────
    //    This is a fast COUNT on the index — no row data transferred.
    $countRow = sql_fetch_assoc(sql_query("
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
    $totalEligible = (int)($countRow['total'] ?? 0);

    // ── 4. Field-match candidates via random OFFSETs ──────────────────────────
    //    Instead of LIMIT 80 from the top (same creator every time), we jump to
    //    several random positions in the result set and take a few rows each.
    //    5 jumps × 16 rows = 80 candidates spread across the whole table.
    $fieldCandidates = [];

    if ($totalEligible > 0) {
        $jumps    = 5;
        $perJump  = 16;
        $offsets  = [];

        // Generate unique random offsets spread across the eligible range
        $attempts = 0;
        while (count($offsets) < $jumps && $attempts < 20) {
            $o = rand(0, max(0, $totalEligible - $perJump));
            if (!in_array($o, $offsets)) $offsets[] = $o;
            $attempts++;
        }

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
                $fieldCandidates[] = (int) $row['iVideoID'];
            }
        }
    }

    // ── 5. Tag-match candidates — same random-offset approach ─────────────────
    $tagCandidates = [];
    if (!empty($allTags)) {
        shuffle($allTags);
        $activeTags = array_slice($allTags, 0, 20);
        $tagLikes   = array_map(fn($t) => "v.vTags LIKE '%$t%'", $activeTags);
        $tagWhere   = implode(' OR ', $tagLikes);

        // Count tag-eligible rows for offset range
        $tagCountRow = sql_fetch_assoc(sql_query("
            SELECT COUNT(*) AS total
            FROM videos v
            WHERE v.cStatus = 'A'
              AND ($tagWhere)
              AND NOT EXISTS (
                  SELECT 1 FROM user_watched_video uwv
                  WHERE uwv.iVideoID = v.iVideoID
                    AND uwv.iUserID  = $userId
                    AND uwv.cStatus  = 'A'
              )
        "));
        $totalTagEligible = (int)($tagCountRow['total'] ?? 0);

        if ($totalTagEligible > 0) {
            $tagJumps   = 3;
            $tagPerJump = 14;
            $tagOffsets = [];
            $attempts   = 0;
            while (count($tagOffsets) < $tagJumps && $attempts < 15) {
                $o = rand(0, max(0, $totalTagEligible - $tagPerJump));
                if (!in_array($o, $tagOffsets)) $tagOffsets[] = $o;
                $attempts++;
            }

            foreach ($tagOffsets as $offset) {
                $res = sql_query("
                    SELECT v.iVideoID
                    FROM videos v
                    WHERE v.cStatus = 'A'
                      AND ($tagWhere)
                      AND NOT EXISTS (
                          SELECT 1 FROM user_watched_video uwv
                          WHERE uwv.iVideoID = v.iVideoID
                            AND uwv.iUserID  = $userId
                            AND uwv.cStatus  = 'A'
                      )
                    LIMIT $tagPerJump OFFSET $offset
                ");
                while ($row = sql_fetch_assoc($res)) {
                    $tagCandidates[] = (int) $row['iVideoID'];
                }
            }
        }
    }

    // ── 6. Merge, deduplicate, shuffle in PHP ─────────────────────────────────
    $allCandidates = array_values(array_unique(array_merge($fieldCandidates, $tagCandidates)));

    if (empty($allCandidates)) {
        echo json_encode(["statusCode" => 200, "data" => ["videos" => [], "total" => 0]]);
        exit;
    }

    shuffle($allCandidates);
    $picked     = array_slice($allCandidates, 0, 10);
    $pickedList = implode(',', $picked);

    // ── 7. Fetch full data for exactly those 10 rows (PK lookups — instant) ───
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
