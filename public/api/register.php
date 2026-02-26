<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";

header('Content-Type: application/json');

$postdata = file_get_contents("php://input");
$request  = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);

function respond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

$google_token = isset($_REQUEST['google_token']) ? trim($_REQUEST['google_token']) : '';
if ($google_token !== '') {
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($google_token));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw      = curl_exec($ch);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err || !$raw) {
        respond(500, ["statusCode" => 500, "error" => ["message" => "Could not reach Google to verify token. Try again."]]);
    }

    $gdata = json_decode($raw, true);

    if (!empty($gdata['error_description']) || empty($gdata['email'])) {
        respond(401, ["statusCode" => 401, "error" => ["message" => "Invalid Google token: " . ($gdata['error_description'] ?? 'no email returned')]]);
    }

    if ($gdata['aud'] !== '856248388214-8obo2cg3s0i59cc1e1btsqi4vfhnrppg.apps.googleusercontent.com') {
        respond(401, ["statusCode" => 401, "error" => ["message" => "Token audience mismatch"]]);
    }

    $existing_id = GetXFromYID("SELECT iUserID FROM user WHERE vEmail = '" . db_input($gdata['email']) . "' LIMIT 1");
    if (!empty($existing_id)) {
        respond(409, ["statusCode" => 409, "error" => ["message" => "An account with this email already exists. Please log in."]]);
    }

    respond(200, [
        "statusCode" => 200,
        "data" => [
            "name"      => $gdata['name']    ?? '',
            "email"     => $gdata['email']   ?? '',
            "pic"       => $gdata['picture'] ?? '',
            "google_id" => $gdata['sub'],
            "isNew"     => true,
        ]
    ]);
}

$name      = isset($_REQUEST['name'])      ? trim($_REQUEST['name'])      : '';
$email     = isset($_REQUEST['email'])     ? trim($_REQUEST['email'])     : '';
$password  = htmlspecialchars_decode(isset($_REQUEST['password']) ? trim($_REQUEST['password']) : '');
$mobile    = isset($_REQUEST['mobile'])   ? trim($_REQUEST['mobile'])    : '';
$DOB       = date('Y-m-d', strtotime(isset($_REQUEST['DOB']) ? trim($_REQUEST['DOB']) : ''));
$google_id = isset($_REQUEST['google_id']) ? trim($_REQUEST['google_id']) : ''; 
$picture   = isset($_REQUEST['pic'])       ? trim($_REQUEST['pic'])       : '';

if ($name === '' || $email === '' || $password === '' || $mobile === '' || $DOB === '') {
    respond(400, ["statusCode" => 400, "error" => ["message" => "All fields are required"]]);
}

// Duplicate email check
$existing_id = GetXFromYID("SELECT iUserID FROM user WHERE vEmail = '$email' LIMIT 1");
if (!empty($existing_id)) {
    respond(409, ["statusCode" => 409, "error" => ["message" => "An account with this email already exists. Please log in."]]);
}

try {
    $id        = NextID('iUserID', 'user');
    $auth_type = ($google_id !== '') ? 'GOOGLE' : 'EMAIL';

    $q = "INSERT INTO user
              (iUserID, vName, vEmail, vPassword, vMobile, dDOB, vGoogleID, vPic, cStatus)
          VALUES
              ($id, '$name', '$email', '$password', '$mobile', '$DOB', '$google_id', '$picture', 'A')";

    if (sql_query($q)) {
        respond(200, [
            "statusCode" => 200,
            "data" => [
                "token"     => EncodeParam($id),
                "userName"  => $name,
                "hasFields" => false,
            ]
        ]);
    } else {
        respond(500, ["statusCode" => 500, "error" => ["message" => "Failed to register user"]]);
    }
} catch (Exception $e) {
    respond(500, ["statusCode" => 500, "error" => ["message" => $e->getMessage()]]);
}