<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";

header('Content-Type: application/json');

$postdata = file_get_contents("php://input");
$request  = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);

// ─────────────────────────────────────────────────────────────
// Helper: send JSON response and exit
// ─────────────────────────────────────────────────────────────
function respond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

// ─────────────────────────────────────────────────────────────
// Detect which flow: Google SSO  vs  normal registration
// Frontend must send { "google_token": "<ID_TOKEN>" } for SSO
// ─────────────────────────────────────────────────────────────
$google_token = isset($_REQUEST['google_token']) ? trim($_REQUEST['google_token']) : '';

// ═════════════════════════════════════════════════════════════
// GOOGLE SSO FLOW
// ═════════════════════════════════════════════════════════════
if ($google_token !== '') {

    // ----------------------------------------------------------
    // 1. Verify the ID token with Google (no SDK needed)
    // ----------------------------------------------------------
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

    // Google returns error_description on bad tokens
    if (!empty($gdata['error_description']) || empty($gdata['email'])) {
        respond(401, ["statusCode" => 401, "error" => ["message" => "Invalid Google token: " . ($gdata['error_description'] ?? 'no email returned')]]);
    }

    // ----------------------------------------------------------
    // 2. (Recommended) Validate the token was issued for YOUR app
    //    Set your Google OAuth Client ID in common_api.php:
    //    define('GOOGLE_CLIENT_ID', 'XXXXXXXX.apps.googleusercontent.com');
    // ----------------------------------------------------------
    if (defined('GOOGLE_CLIENT_ID') && $gdata['aud'] !== '856248388214-8obo2cg3s0i59cc1e1btsqi4vfhnrppg.apps.googleusercontent.com') {
        respond(401, ["statusCode" => 401, "error" => ["message" => "Token audience mismatch"]]);
    }

    // ----------------------------------------------------------
    // 3. Extract verified profile fields
    // ----------------------------------------------------------
    $email     = db_input($gdata['email']);
    $name      = db_input($gdata['name']    ?? $gdata['email']);
    $google_id = db_input($gdata['sub']);       // Google's permanent unique user ID
    $picture   = db_input($gdata['picture'] ?? '');

    // ----------------------------------------------------------
    // 4. Upsert: returning user → return token; new user → insert
    // ----------------------------------------------------------
    $existing_id = GetXFromYID("SELECT iUserID FROM user WHERE vEmail = '$email' LIMIT 1");

    if (!empty($existing_id)) {
        // Update google_id/picture in case they signed up via email before
        sql_query("UPDATE user SET vGoogleID = '$google_id', vPic = '$picture' WHERE iUserID = $existing_id");

        $user_name = GetXFromYID("SELECT vName FROM user WHERE iUserID = $existing_id");
        respond(200, [
            "statusCode" => 200,
            "data" => [
                "token"     => EncodeParam($existing_id),
                "userName"  => $user_name,
                "hasFields" => false,
                "isNew"     => false,
            ]
        ]);
    }

    // New Google user — insert row
    // vPassword left empty; cAuthType = 'GOOGLE' distinguishes from email accounts
    // mobile/DOB unknown at this point — collect them later via profile screen (hasFields: true)
    try {
        $id = NextID('iUserID', 'user');
        $q  = "INSERT INTO user
                   (iUserID, vName, vEmail, vPassword, vMobile, dDOB, vGoogleID, vPicture, cStatus)
               VALUES
                   ($id, '$name', '$email', '', '', '0000-00-00', '$google_id', '$picture', 'A')";

        if (sql_query($q)) {
            respond(200, [
                "statusCode" => 200,
                "data" => [
                    "token"     => EncodeParam($id),
                    "userName"  => $gdata['name'] ?? $gdata['email'],
                    "hasFields" => true,    // tell the app to collect mobile/DOB
                    "isNew"     => true,
                ]
            ]);
        } else {
            respond(500, ["statusCode" => 500, "error" => ["message" => "Failed to create account via Google SSO"]]);
        }
    } catch (Exception $e) {
        respond(500, ["statusCode" => 500, "error" => ["message" => $e->getMessage()]]);
    }
}

// ═════════════════════════════════════════════════════════════
// NORMAL EMAIL / PASSWORD REGISTRATION FLOW
// ═════════════════════════════════════════════════════════════
$name     = isset($_REQUEST['name'])     ? trim($_REQUEST['name'])     : '';
$email    = isset($_REQUEST['email'])    ? trim($_REQUEST['email'])    : '';
$password = htmlspecialchars_decode(isset($_REQUEST['password']) ? trim($_REQUEST['password']) : '');
$mobile   = isset($_REQUEST['mobile'])  ? trim($_REQUEST['mobile'])   : '';
$DOB      = date('Y-m-d', strtotime(isset($_REQUEST['DOB']) ? trim($_REQUEST['DOB']) : ''));

// Validate all fields present
if ($name === '' || $email === '' || $password === '' || $mobile === '' || $DOB === '') {
    respond(400, ["statusCode" => 400, "error" => ["message" => "All fields are required"]]);
}

// Duplicate email check
$existing_id = GetXFromYID("SELECT iUserID FROM user WHERE vEmail = '$email' LIMIT 1");
if (!empty($existing_id)) {
    respond(409, ["statusCode" => 409, "error" => ["message" => "An account with this email already exists. Please log in."]]);
}

try {
    $id = NextID('iUserID', 'user');
    $q  = "INSERT INTO user
               (iUserID, vName, vEmail, vPassword, vMobile, dDOB, cAuthType, cStatus)
           VALUES
               ($id, '$name', '$email', '$password', '$mobile', '$DOB', 'EMAIL', 'A')";
    $result = sql_query($q);

    if ($result) {
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