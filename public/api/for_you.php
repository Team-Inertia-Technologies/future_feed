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
    if (function_exists('apcu_fetch')) {
        $fieldIds = apcu_fetch($cacheKey);
    }
    if ($fieldIds === false) {
        $res = sql_query("SELECT iFieldID FROM user_field_assoc
                          WHERE iUserID = $userId AND cStatus = 'A'");
        $fieldIds = [];
        while ($row = sql_fetch_assoc($res)) {
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

    // ── 2. Collect unique tags (APCu cached 10 min per user) ─────────────────
    //    The tag scan only touches the videos table — still cheap — but we cache
    //    it so repeat requests don't re-scan 2,864 video rows every time.
    $tagCacheKey = "user_tags_{$userId}";
    $allTags     = false;
    if (function_exists('apcu_fetch')) {
        $allTags = apcu_fetch($tagCacheKey);
    }
    if ($allTags === false) {
        $tagResult = sql_query("
            SELECT vTags
            FROM videos
            WHERE iFieldID IN ($fieldIdList)
              AND cStatus = 'A'
              AND vTags IS NOT NULL
              AND vTags != ''
        ");
        $allTags = [];
        while ($row = sql_fetch_assoc($tagResult)) {
            foreach (explode(',', $row['vTags']) as $tag) {
                $clean = trim(str_replace(['"', "'"], '', $tag));
                if ($clean !== '') {
                    $allTags[] = db_input($clean);
                }
            }
        }
        $allTags = array_values(array_unique($allTags));
        if (function_exists('apcu_store')) {
            apcu_store($tagCacheKey, $allTags, 600);
        }
    }

    // ── 3. Candidate ID pool — IDs only, no heavy joins ──────────────────────
    //    Split into two cheap queries and merge in PHP:
    //      A) videos matching user's field IDs  (index seek on iFieldID)
    //      B) videos matching tags              (LIKE scan, unavoidable, but
    //                                            limited to unwatched rows only)
    //    Each query returns only iVideoID integers — tiny result set to transfer.
    //    We cap each at 80 rows so the LIKE scan never explodes.

    $watchedExclude = "
        NOT EXISTS (
            SELECT 1 FROM user_watched_video uwv
            WHERE uwv.iVideoID = v.iVideoID
              AND uwv.iUserID  = $userId
              AND uwv.cStatus  = 'A'
        )
    ";

    // --- 3a. Field-match candidates (fast — uses index) ----------------------
    $fieldCandidates = [];
    $res = sql_query("
        SELECT v.iVideoID
        FROM videos v
        WHERE v.iFieldID IN ($fieldIdList)
          AND v.cStatus = 'A'
          AND $watchedExclude
        LIMIT 80
    ");
    while ($row = sql_fetch_assoc($res)) {
        $fieldCandidates[] = (int) $row['iVideoID'];
    }

    // --- 3b. Tag-match candidates (LIKE scan — keep pool small) --------------
    $tagCandidates = [];
    if (!empty($allTags)) {
        // Limit to 20 tags max to keep the OR chain manageable.
        // Shuffle so different tags get a chance each request.
        shuffle($allTags);
        $activeTags  = array_slice($allTags, 0, 20);
        $tagLikes    = array_map(fn($t) => "v.vTags LIKE '%$t%'", $activeTags);
        $tagWhere    = implode(' OR ', $tagLikes);

        $res = sql_query("
            SELECT v.iVideoID
            FROM videos v
            WHERE v.cStatus = 'A'
              AND ($tagWhere)
              AND $watchedExclude
            LIMIT 80
        ");
        while ($row = sql_fetch_assoc($res)) {
            $tagCandidates[] = (int) $row['iVideoID'];
        }
    }

    // ── 4. Merge, deduplicate, shuffle in PHP — zero DB cost ─────────────────
    $allCandidates = array_values(array_unique(
        array_merge($fieldCandidates, $tagCandidates)
    ));

    if (empty($allCandidates)) {
        echo json_encode([
            "statusCode" => 200,
            "data"       => ["videos" => [], "total" => 0]
        ]);
        exit;
    }

    shuffle($allCandidates);               // true random, free in PHP
    $picked      = array_slice($allCandidates, 0, 10);
    $pickedList  = implode(',', $picked);

    // ── 5. Fetch full data for exactly those 10 rows (PK lookups — instant) ──
    $videoResult = sql_query("
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
        WHERE v.iVideoID IN ($pickedList)
    ");

    $videos = [];
    while ($row = sql_fetch_assoc($videoResult)) {
        $row['like_count']    = (int)  $row['like_count'];
        $row['comment_count'] = (int)  $row['comment_count'];
        $row['isLiked']       = (bool) $row['isLiked'];
        $videos[] = $row;
    }

    shuffle($videos); // re-shuffle since IN() doesn't guarantee order

    echo json_encode([
        "statusCode" => 200,
        "data"       => ["videos" => $videos, "total" => count($videos)]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["statusCode" => 500, "error" => ["message" => $e->getMessage()]]);
    exit;
}
