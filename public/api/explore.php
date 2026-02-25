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
        SELECT * FROM videos
        WHERE iFieldID = $fieldid
        AND cStatus = 'A'
        ORDER BY iVideoID DESC
    ";
    $videoResult = sql_query($videoQuery);

    $videos  = [];
    $allTags = [];

    while ($row = sql_fetch_assoc($videoResult)) {
        $videos[] = $row;

        // Parse tags from each video
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