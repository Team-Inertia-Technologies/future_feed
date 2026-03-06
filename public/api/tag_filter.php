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
        SELECT * FROM videos
        WHERE iFieldID = $fieldid
        AND cStatus = 'A'
        AND vTags LIKE '%$tag%'
        ORDER BY iVideoID DESC
    ";

    $videoResult = sql_query($videoQuery);

    $videos = [];
    while ($row = sql_fetch_assoc($videoResult)) {
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