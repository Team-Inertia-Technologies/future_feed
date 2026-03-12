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
    echo json_encode([
        "statusCode" => 400,
        "error" => ["message" => "Missing token"]
    ]);
    exit;
}

$userId = DecodeParam($token);

try {

	$likedquery = "
    SELECT 
        ula.iVideoID, v.vName, v.vDesc, v.vUrl, v.vCreator, v.iFieldID,
        COUNT(DISTINCT vl.iAssocID) AS like_count,
        COUNT(DISTINCT vc.iCommentID) AS comment_count
    FROM user_liked_video ula
    JOIN videos v ON ula.iVideoID = v.iVideoID
    LEFT JOIN user_liked_video vl
        ON v.iVideoID = vl.iVideoID
        AND vl.cStatus = 'A'
    LEFT JOIN comment vc
        ON v.iVideoID = vc.iVideoID
        AND vc.cStatus = 'A'
    WHERE ula.iUserID = $userId
    AND ula.cStatus = 'A'
    GROUP BY ula.iVideoID, v.vName, v.vDesc, v.vUrl, v.vCreator, v.iFieldID
";
	$likedResult = sql_query($likedquery);
	$likedVideos = [];
	while ($row = sql_fetch_assoc($likedResult)) {
		$likedVideos[] = [
			"VideoID"      => (int)$row['iVideoID'],
			"FieldID"      => (int)$row['iFieldID'],
			"Title"        => $row['vName'],
			"Description"  => $row['vDesc'],
			"Url"          => $row['vUrl'],
			"ChannelName"  => $row['vCreator'],
			"isLiked"      => true,
			"like_count"   => (int)$row['like_count'],
			"comment_count"=> (int)$row['comment_count'],
		];
	}

	$filedquery = "SELECT iFieldID, vName FROM fields WHERE cStatus = 'A'";
	$filedResult = sql_query($filedquery);
	$fields = [];
	$fields[] = [
		'id' => 0,
		'Name' => 'All'
	];
	while ($row = sql_fetch_assoc($filedResult)) {
		$fields[] = [
			"id" => (int)$row['iFieldID'],
			"Name" => $row['vName']
		];
	}

	$response = [
		"statusCode" => 200,
		"data" => [
			"videos" => $likedVideos,
			"fields" => $fields
		]
	];

	http_response_code(200);
	echo json_encode($response);
	exit;

} catch (Exception $e) {
	http_response_code(500);
	echo json_encode([
		"statusCode" => 500,
		"error" => ["message" => $e->getMessage()]
	]);
	exit;
}