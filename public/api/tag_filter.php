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
$tag = $_REQUEST['tag'] ?? '';

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

if (!$tag) {
    http_response_code(400);
    echo json_encode(["statusCode" => 400, "error" => ["message" => "Missing tag"]]);
    exit;
}

$userid  = (int)DecodeParam($token);
$fieldid = (int)$fieldid;

try {

    $tag     = db_input(trim($tag));
    $fieldid = (int)$fieldid;

    $videoQuery = "
        SELECT 
            v.*,
            COALESCE(l.like_count, 0)    AS like_count,
            COALESCE(c.comment_count, 0) AS comment_count,
            CASE WHEN ul.iUserID IS NOT NULL THEN 1 ELSE 0 END AS isLiked
        FROM videos v

        LEFT JOIN (
            SELECT iVideoID, COUNT(*) AS like_count
            FROM user_liked_video
            WHERE cStatus = 'A'
            GROUP BY iVideoID
        ) l ON v.iVideoID = l.iVideoID

        LEFT JOIN (
            SELECT iVideoID, COUNT(*) AS comment_count
            FROM comment
            WHERE cStatus = 'A'
            GROUP BY iVideoID
        ) c ON v.iVideoID = c.iVideoID

        LEFT JOIN user_liked_video ul
            ON v.iVideoID = ul.iVideoID
            AND ul.iUserID = $userid
            AND ul.cStatus = 'A'

        WHERE v.iFieldID = $fieldid
        AND v.cStatus = 'A'
        AND v.vTags LIKE '%$tag%'

        GROUP BY v.iVideoID

        ORDER BY v.iVideoID DESC
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
            "field_id" => $fieldid,
            "tag"      => $tag,
            "videos"   => $videos,
            "total"    => count($videos)
        ]
    ]);

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