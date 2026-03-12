<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";
header('Content-Type: application/json');
$postdata = file_get_contents("php://input");

$request = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);

$token   = $_REQUEST['token']   ?? '';
$fieldid = $_REQUEST['fieldID'] ?? '';

if (!$token) {
    http_response_code(400);
    echo json_encode(["statusCode" => 400, "error" => ["message" => "Missing token"]]);
    exit;
}

if (!$fieldid) {
    http_response_code(400);
    echo json_encode(["statusCode" => 400, "error" => ["message" => "Missing FieldID"]]);
    exit;
}

$userid  = (int)DecodeParam($token);
$fieldid = (int)$fieldid;

try {

    $videoQuery = "
        SELECT v.*,
            COUNT(DISTINCT vl.iAssocID) AS like_count,
            COUNT(DISTINCT vc.iCommentID) AS comment_count,
            CASE WHEN ul.iUserID IS NOT NULL THEN 1 ELSE 0 END AS isLiked
        FROM videos v
        LEFT JOIN user_liked_video vl
            ON v.iVideoID = vl.iVideoID
            AND vl.cStatus = 'A'
        LEFT JOIN comment vc
            ON v.iVideoID = vc.iVideoID
            AND vc.cStatus = 'A'
        LEFT JOIN user_liked_video ul
            ON v.iVideoID = ul.iVideoID
            AND ul.iUserID = $userid
            AND ul.cStatus = 'A'
        WHERE v.iFieldID = $fieldid
        AND v.cStatus = 'A'
        GROUP BY v.iVideoID
        ORDER BY v.iVideoID DESC
    ";
    $videoResult = sql_query($videoQuery);

    $videos  = [];
    $allTags = [];

    while ($row = sql_fetch_assoc($videoResult)) {
        $row['like_count']    = (int)$row['like_count'];
        $row['comment_count'] = (int)$row['comment_count'];
        $row['isLiked']       = (bool)$row['isLiked'];
        $videos[] = $row;

        if (!empty($row['vTags'])) {
            $tags = explode(',', $row['vTags']);
            foreach ($tags as $tag) {
                $clean = trim(str_replace(['"', "'"], '', $tag));
                if ($clean !== '') {
                    $allTags[] = $clean;
                }
            }
        }
    }

    $uniqueTags = array_values(array_unique($allTags));

    echo json_encode([
        "statusCode" => 200,
        "data" => [
            "videos"      => $videos,
            "total"       => count($videos),
            "tags" => $uniqueTags
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["statusCode" => 500, "error" => ["message" => $e->getMessage()]]);
    exit;
}