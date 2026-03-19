<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";
require_once "../vendor/autoload.php";

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == "POST") {

    $request = json_decode(file_get_contents("php://input"), true);

    if (!$request) {
        $request = $_POST;
    }

    $loginType = $request['login_type'] ?? 'email';

    // ============================================
    // GOOGLE SSO LOGIN
    // ============================================
    if ($loginType === 'google') {

        if (empty($request['google_token'])) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                "statusCode" => 400,
                "error" => ["message" => "Google token is required"]
            ]);
            exit;
        }

        // Your Google Client ID
        // $googleClientId = '856248388214-8obo2cg3s0i59cc1e1btsqi4vfhnrppg.apps.googleusercontent.com';
        $googleClientId = '595789305816-7mv597ph6jmmt80p4737h05tkjf9cldo.apps.googleusercontent.com';

        $client = new Google_Client(['client_id' => $googleClientId]);

        try {
            // Verify the token sent from frontend
            $payload = $client->verifyIdToken($request['google_token']);

            if (!$payload) {
                throw new Exception('Invalid Google token');
            }

            // Extract user info from Google token
            $googleEmail = $payload['email'];
            $googleName = $payload['name'];
            $googleId = $payload['sub'];
            $googlePicture = $payload['picture'] ?? '';
            $emailVerified = $payload['email_verified'] ?? false;

            // Security: Only allow verified emails
            if (!$emailVerified) {
                throw new Exception('Email not verified by Google');
            }

            // Check if user exists
            $q = "SELECT iUserID, vName, vEmail, vGoogleID, dDOB, vPic FROM user WHERE vEmail='" . db_input($googleEmail) . "' AND cStatus='A'";
            $r = sql_query($q, 'AUTH.GOOGLE.1');

            if (sql_num_rows($r)) {
                // Existing user
                list($u_id, $u_name, $u_email, $existing_google_id, $dob, $pic) = sql_fetch_row($r);

                // Update Google ID if not set
                if (empty($existing_google_id)) {
                    $update_q = "UPDATE user SET vGoogleID='" . db_input($googleId) . "', vPic='" . db_input($googlePicture) . "' WHERE iUserID=$u_id";
                    sql_query($update_q, 'AUTH.GOOGLE.2');
                }
            } else {
                http_response_code(201);
                header('Content-Type: application/json');
                echo json_encode([
                    "statusCode" => 201,
                    "error" => [
                        "message" => "No account found. Please register first.",
                        "data" => [
                            "name"      => $googleName,
                            "email"     => $googleEmail,
                            "google_id" => $googleId,
                            "pic"       => $googlePicture,
                        ]
                    ]
                ]);
                exit;
            }

            // Create session (same as email login)
            session_destroy();
            session_start();
            session_regenerate_id();

            $randomtoken = base64_encode(uniqid(rand(), true));

            $_SESSION[PROJ_SESSION_ID] = new userdat;
            $_SESSION[PROJ_SESSION_ID]->log_time = NOW2;
            $_SESSION[PROJ_SESSION_ID]->log_stat = "A";
            $_SESSION[PROJ_SESSION_ID]->user_id = $u_id;
            $_SESSION[PROJ_SESSION_ID]->user_name = $u_name;
            $_SESSION[PROJ_SESSION_ID]->sess = session_id();
            $_SESSION[PROJ_SESSION_ID]->rmadr = $_SERVER['REMOTE_ADDR'];
            $_SESSION[PROJ_SESSION_ID]->lhs_menu = true;
            $_SESSION[PROJ_SESSION_ID]->sess_token = $randomtoken;
            $_SESSION[PROJ_SESSION_ID]->sess_active = 'Y';
            $_SESSION[PROJ_SESSION_ID]->allow_vessel_close = 'N';

            LogAttempt($googleEmail, 'S', 'Logged via Google');

            // Update last login
            $update_login_q = "UPDATE user SET cActive='Y', dtLastLogin='" . NOW . "', vLastLoginIP='" . $_SERVER['REMOTE_ADDR'] . "' WHERE iUserID=$u_id";
            sql_query($update_login_q, 'AUTH.GOOGLE.4');

            $token = EncodeParam($u_id);
            $exists = GetXFromYID("SELECT iUserID FROM user_field_assoc WHERE iUserID = $u_id LIMIT 1", "USER.FIELD.CHECK");
            $hasFields = ($exists) ? true : false;

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                "statusCode" => 200,
                "data" => [
                    "token" => $token,
                    "userName" => $u_name,
                    "email" => $googleEmail,
                    "DOB" => $dob,
                    "profilePic" => $googlePicture,
                    "loginType" => "google",
                    "hasFields" => $hasFields,
                    "profilePic" => !empty($pic) ? "https://futurefeed.top/uploads/profile_pics/" . $pic : null

                ]
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                "statusCode" => 401,
                "error" => ["message" => "Google authentication failed: " . $e->getMessage()]
            ]);
            exit;
        }
    }

    // ============================================
    // APPLE SSO LOGIN
    // ============================================
    elseif ($loginType === 'apple') {

        
        if (empty($request['apple_token'])) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                "statusCode" => 400,
                "error" => ["message" => "Apple identity token is required"]
            ]);
            exit;
        }

        $appleClientId = 'com.futurefeed';

        try {
            // 1. Fetch Apple's public keys (JWKS)
            $appleKeysJson = file_get_contents('https://appleid.apple.com/auth/keys');
            if (!$appleKeysJson) {
                throw new Exception('Failed to fetch Apple public keys');
            }
            $appleKeys = json_decode($appleKeysJson, true);

            // 2. Decode token header to find which key Apple used to sign it
            $identityToken = $request['apple_token'];
            $tokenParts    = explode('.', $identityToken);

            if (count($tokenParts) !== 3) {
                throw new Exception('Invalid Apple identity token format');
            }

            $headerJson = base64_decode(
                str_pad(strtr($tokenParts[0], '-_', '+/'), strlen($tokenParts[0]) % 4, '=', STR_PAD_RIGHT)
            );
            $header = json_decode($headerJson, true);
            $kid    = $header['kid'] ?? null;

            if (!$kid) {
                throw new Exception('No key ID in Apple token header');
            }

            // 3. Find the matching key from Apple's public JWKS
            $matchingKey = null;
            foreach ($appleKeys['keys'] as $key) {
                if ($key['kid'] === $kid) {
                    $matchingKey = $key;
                    break;
                }
            }

            if (!$matchingKey) {
                throw new Exception('No matching Apple public key found');
            }

            // 4. Parse the JWK and verify the JWT
            $publicKey = JWK::parseKey($matchingKey, 'RS256');
            $payload   = JWT::decode($identityToken, $publicKey);

            // 5. Validate standard claims
            if ($payload->iss !== 'https://appleid.apple.com') {
                throw new Exception('Invalid token issuer');
            }
            if ($payload->aud !== $appleClientId) {
                throw new Exception('Token audience mismatch');
            }
            if ($payload->exp < time()) {
                throw new Exception('Apple token has expired');
            }

            // 6. Extract user data
            // 'sub' is the stable unique Apple User ID — never changes
            $appleId   = $payload->sub;
            $appleEmail = $payload->email ?? null;
            $appleName  = $request['name'] ?? '';

            // 7. Look up user — search by Apple ID first (handles hidden email case)
            if (!empty($appleEmail)) {
                $q = "SELECT iUserID, vName, vEmail, dDOB, vPic 
                    FROM user 
                    WHERE (vEmail='" . db_input($appleEmail) . "' OR vAppleID='" . db_input($appleId) . "')
                    AND cStatus='A' LIMIT 1";
            } else {
                $q = "SELECT iUserID, vName, vEmail, dDOB, vPic 
                    FROM user 
                    WHERE vAppleID='" . db_input($appleId) . "' AND cStatus='A' LIMIT 1";
            }

            $r = sql_query($q, 'AUTH.APPLE.1');

            if (sql_num_rows($r)) {
                // Existing user — make sure vAppleID is stored
                list($u_id, $u_name, $u_email, $dob, $pic) = sql_fetch_row($r);
                sql_query(
                    "UPDATE user SET vAppleID='" . db_input($appleId) . "' WHERE iUserID=$u_id",
                    'AUTH.APPLE.2'
                );

            } else {
                // No matching account — send back to frontend for registration
                http_response_code(201);
                header('Content-Type: application/json');
                echo json_encode([
                    "statusCode" => 201,
                    "error" => [
                        "message" => "No account found. Please register first.",
                        "data" => [
                            "name"     => $appleName,
                            "email"    => $appleEmail,
                            "apple_id" => $appleId,
                        ]
                    ]
                ]);
                exit;
            }

            // 8. Create session — identical to your Google/email flow
            session_destroy();
            session_start();
            session_regenerate_id();

            $randomtoken = base64_encode(uniqid(rand(), true));

            $_SESSION[PROJ_SESSION_ID] = new userdat;
            $_SESSION[PROJ_SESSION_ID]->log_time          = NOW2;
            $_SESSION[PROJ_SESSION_ID]->log_stat          = "A";
            $_SESSION[PROJ_SESSION_ID]->user_id           = $u_id;
            $_SESSION[PROJ_SESSION_ID]->user_name         = $u_name;
            $_SESSION[PROJ_SESSION_ID]->sess              = session_id();
            $_SESSION[PROJ_SESSION_ID]->rmadr             = $_SERVER['REMOTE_ADDR'];
            $_SESSION[PROJ_SESSION_ID]->lhs_menu          = true;
            $_SESSION[PROJ_SESSION_ID]->sess_token        = $randomtoken;
            $_SESSION[PROJ_SESSION_ID]->sess_active       = 'Y';
            $_SESSION[PROJ_SESSION_ID]->allow_vessel_close = 'N';

            LogAttempt($appleEmail ?? $appleId, 'S', 'Logged via Apple');

            sql_query(
                "UPDATE user SET cActive='Y', dtLastLogin='" . NOW . "', vLastLoginIP='" . $_SERVER['REMOTE_ADDR'] . "' WHERE iUserID=$u_id",
                'AUTH.APPLE.3'
            );

            $token     = EncodeParam($u_id);
            $exists    = GetXFromYID("SELECT iUserID FROM user_field_assoc WHERE iUserID = $u_id LIMIT 1", "USER.FIELD.CHECK");
            $hasFields = ($exists) ? true : false;

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                "statusCode" => 200,
                "data" => [
                    "token"      => $token,
                    "userName"   => $u_name,
                    "email"      => $u_email,
                    "DOB"        => $dob,
                    "loginType"  => "apple",
                    "hasFields"  => $hasFields,
                    "profilePic" => !empty($pic) ? "https://futurefeed.top/uploads/profile_pics/" . $pic : null
                ]
            ]);
            exit;

        } catch (Exception $e) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                "statusCode" => 401,
                "error" => ["message" => "Apple authentication failed: " . $e->getMessage()]
            ]);
            exit;
        }
    }

    // ============================================
    // REGULAR EMAIL/PASSWORD LOGIN
    // ============================================
    elseif ($loginType === 'email') {

        $username = db_input($request["txtemail"] ?? '');
        $txtpassword = htmlspecialchars_decode(db_input2($request["txtpassword"] ?? ''));

        if (empty($txtpassword)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                "statusCode" => 400,
                "error" => ["message" => "Password cannot be blank"]
            ]);
            exit;
        }

        if (empty($username)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                "statusCode" => 400,
                "error" => ["message" => "Email cannot be blank"]
            ]);
            exit;
        }

        $q = "SELECT iUserID, vName, vPassword, dDOB, vPic FROM user WHERE vEmail='" . $username . "' AND cStatus='A'";
        $r = sql_query($q, 'AUTH.61');

        if (sql_num_rows($r)) {
            list($u_id, $u_name, $u_pass, $dob, $pic) = sql_fetch_row($r);
            $u_pass = htmlspecialchars_decode($u_pass);
            $ret = ($u_pass == $txtpassword) ? 1 : -1;
        } else {
            $ret = -2;
        }

        if ($ret == -1 || $ret == -2) {
            LogAttempt($username, 'F', 'Wrong credentials');
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                "statusCode" => 400,
                "error" => ["message" => "Invalid email or password"]
            ]);
            exit;
        }

        if ($ret == 1) {
            session_destroy();
            session_start();
            session_regenerate_id();

            $randomtoken = base64_encode(uniqid(rand(), true));

            $_SESSION[PROJ_SESSION_ID] = new userdat;
            $_SESSION[PROJ_SESSION_ID]->log_time = NOW2;
            $_SESSION[PROJ_SESSION_ID]->log_stat = "A";
            $_SESSION[PROJ_SESSION_ID]->user_id = $u_id;
            $_SESSION[PROJ_SESSION_ID]->user_name = $u_name;
            $_SESSION[PROJ_SESSION_ID]->sess = session_id();
            $_SESSION[PROJ_SESSION_ID]->rmadr = $_SERVER['REMOTE_ADDR'];
            $_SESSION[PROJ_SESSION_ID]->lhs_menu = true;
            $_SESSION[PROJ_SESSION_ID]->sess_token = $randomtoken;
            $_SESSION[PROJ_SESSION_ID]->sess_active = 'Y';
            $_SESSION[PROJ_SESSION_ID]->allow_vessel_close = 'N';

            LogAttempt($username, 'S', 'Logged');

            $q = "UPDATE user SET cActive='Y', dtLastLogin='" . NOW . "', vLastLoginIP='" . $_SERVER['REMOTE_ADDR'] . "' WHERE iUserID=$u_id";
            sql_query($q, 'AUTH.78');

            $token = EncodeParam($u_id);
            $exists = GetXFromYID("SELECT iUserID FROM user_field_assoc WHERE iUserID = $u_id LIMIT 1", "USER.FIELD.CHECK");
            $hasFields = ($exists) ? true : false;

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                "statusCode" => 200,
                "data" => [
                    "token" => $token,
                    "userName" => $u_name,
                    "DOB" => $dob,
                    "loginType" => "email",
                    "hasFields" => $hasFields,
                    "profilePic" => !empty($pic) ? "https://futurefeed.top/uploads/profile_pics/" . $pic : null
                ]
            ]);
            exit;
        }
    }

    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        "statusCode" => 400,
        "error" => ["message" => "Invalid login request"]
    ]);
    exit;
} else {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'statusCode' => 403,
        'message' => "Forbidden"
    ]);
    exit;
}
