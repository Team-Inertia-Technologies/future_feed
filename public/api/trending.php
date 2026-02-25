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

$userid = (int)DecodeParam($token);

try {

    $query = "
        SELECT 
            v.*,
            COALESCE(w.watch_count, 0) AS watch_count,
            COALESCE(l.like_count, 0)  AS like_count,
            (COALESCE(w.watch_count, 0) + COALESCE(l.like_count, 0)) AS popularity_score
        FROM videos v

        LEFT JOIN (
            SELECT iVideoID, COUNT(*) AS watch_count
            FROM user_watched_video
            WHERE cStatus = 'A'
            GROUP BY iVideoID
        ) w ON v.iVideoID = w.iVideoID

        LEFT JOIN (
            SELECT iVideoID, COUNT(*) AS like_count
            FROM user_liked_video
            WHERE cStatus = 'A'
            GROUP BY iVideoID
        ) l ON v.iVideoID = l.iVideoID

        WHERE v.cStatus = 'A'

        ORDER BY popularity_score DESC, watch_count DESC, like_count DESC

        LIMIT 10
    ";

    $result = sql_query($query);

    $videos = [];
    while ($row = sql_fetch_assoc($result)) {
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