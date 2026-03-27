<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";
require_once "../vendor/autoload.php";
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
header('Content-Type: application/json');

$postdata = file_get_contents("php://input");
$request  = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);

function respond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

// ============================================
// GOOGLE SSO PRE-FILL CHECK (existing)
// ============================================
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

    if ($gdata['aud'] !== '595789305816-7mv597ph6jmmt80p4737h05tkjf9cldo.apps.googleusercontent.com') {
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

// ============================================
// APPLE SSO PRE-FILL CHECK (token verification)
// ============================================
$apple_token = isset($_REQUEST['apple_token']) ? trim($_REQUEST['apple_token']) : '';
if ($apple_token !== '') {

    try {
        // 1. Fetch Apple's public keys
        $appleKeysJson = file_get_contents('https://appleid.apple.com/auth/keys');
        if (!$appleKeysJson) {
            respond(500, ["statusCode" => 500, "error" => ["message" => "Could not reach Apple to verify token."]]);
        }
        $appleKeys = json_decode($appleKeysJson, true);

        // 2. Decode token header to find the key
        $tokenParts = explode('.', $apple_token);
        if (count($tokenParts) !== 3) {
            respond(401, ["statusCode" => 401, "error" => ["message" => "Invalid Apple token format"]]);
        }

        $headerJson = base64_decode(
            str_pad(strtr($tokenParts[0], '-_', '+/'), strlen($tokenParts[0]) % 4, '=', STR_PAD_RIGHT)
        );
        $header = json_decode($headerJson, true);
        $kid    = $header['kid'] ?? null;

        if (!$kid) {
            respond(401, ["statusCode" => 401, "error" => ["message" => "Invalid Apple token"]]);
        }

        // 3. Find matching public key
        $matchingKey = null;
        foreach ($appleKeys['keys'] as $key) {
            if ($key['kid'] === $kid) {
                $matchingKey = $key;
                break;
            }
        }
        if (!$matchingKey) {
            respond(401, ["statusCode" => 401, "error" => ["message" => "Apple key not found"]]);
        }

        // 4. Verify the token
        $publicKey = JWK::parseKey($matchingKey, 'RS256');
        $payload   = JWT::decode($apple_token, $publicKey);

        // 5. Validate claims
        if ($payload->iss !== 'https://appleid.apple.com') {
            respond(401, ["statusCode" => 401, "error" => ["message" => "Invalid token issuer"]]);
        }
        if ($payload->aud !== 'com.futurefeed') {
            respond(401, ["statusCode" => 401, "error" => ["message" => "Token audience mismatch"]]);
        }

        // 6. Extract apple_id
        $apple_id   = $payload->sub;
        $appleEmail = $payload->email ?? null;
        $appleName  = isset($_REQUEST['apple_name']) ? trim($_REQUEST['apple_name']) : '';

        // 7. Check if already registered
        $existing_id = GetXFromYID(
            "SELECT iUserID FROM user WHERE vAppleID = '" . db_input($apple_id) . "' LIMIT 1"
        );
        if (!empty($existing_id)) {
            respond(409, [
                "statusCode" => 409,
                "error" => ["message" => "An account with this Apple ID already exists. Please log in."]
            ]);
        }

        // 8. Return apple_id + whatever info we have
        // Frontend will show a form for user to fill name, email, mobile, DOB
        respond(200, [
            "statusCode" => 200,
            "data" => [
                "apple_id"  => $apple_id,
                "isNew"     => true,
                "isApple"   => true,
                "message"   => "Please complete your profile to finish registration.",
            ]
        ]);

    } catch (Exception $e) {
        respond(401, ["statusCode" => 401, "error" => ["message" => "Apple verification failed: " . $e->getMessage()]]);
    }
}

// ============================================
// ACTUAL REGISTRATION (Google, Apple, or Email)
// ============================================
$name      = isset($_REQUEST['name'])      ? trim($_REQUEST['name'])      : '';
$email     = isset($_REQUEST['email'])     ? trim($_REQUEST['email'])     : '';
$password  = htmlspecialchars_decode(isset($_REQUEST['password'])  ? trim($_REQUEST['password'])  : '');
$mobile    = isset($_REQUEST['mobile'])    ? trim($_REQUEST['mobile'])    : '';
$DOB       = date('Y-m-d', strtotime(isset($_REQUEST['DOB'])       ? trim($_REQUEST['DOB'])       : ''));
$google_id = isset($_REQUEST['google_id']) ? trim($_REQUEST['google_id']) : '';
$apple_id  = isset($_REQUEST['apple_id'])  ? trim($_REQUEST['apple_id'])  : '';
$picture   = isset($_REQUEST['pic'])       ? trim($_REQUEST['pic'])       : '';

$is_apple = !empty($apple_id) && empty($google_id);

/* ===============================
   VALIDATION
================================= */
// Mobile + DOB required for everyone
if ($mobile === '') {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Mobile number is required"]]);
}
if (empty($_REQUEST['DOB'])) {
    respond(400, ["statusCode" => 400, "error" => ["message" => "Date of birth is required"]]);
}

if ($is_apple) {
    // Apple — skip name, email, password checks
    // Email may or may not be provided (Apple can hide it)
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(400, ["statusCode" => 400, "error" => ["message" => "Invalid email address."]]);
    }
    if ($email !== '') {
        $existing_id = GetXFromYID("SELECT iUserID FROM user WHERE vEmail = '" . db_input($email) . "' LIMIT 1");
        if (!empty($existing_id)) {
            respond(409, ["statusCode" => 409, "error" => ["message" => "An account with this email already exists. Please log in."]]);
        }
    }
} else {
    // Google / Email registration — full validation
    if ($name === '') {
        respond(400, ["statusCode" => 400, "error" => ["message" => "Name is required"]]);
    }
    if ($password === '') {
        respond(400, ["statusCode" => 400, "error" => ["message" => "Password is required"]]);
    }
    if ($email === '') {
        respond(400, ["statusCode" => 400, "error" => ["message" => "Email is required"]]);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(400, ["statusCode" => 400, "error" => ["message" => "Invalid email address."]]);
    }
    $existing_id = GetXFromYID("SELECT iUserID FROM user WHERE vEmail = '" . db_input($email) . "' LIMIT 1");
    if (!empty($existing_id)) {
        respond(409, ["statusCode" => 409, "error" => ["message" => "An account with this email already exists. Please log in."]]);
    }
}

try {
    $id = NextID('iUserID', 'user');

    $name_esc     = db_input($name);
    $email_esc    = db_input($email);
    $password_esc = db_input($password);
    $mobile_esc   = db_input($mobile);
    $google_esc   = db_input($google_id);
    $apple_esc    = db_input($apple_id);
    $picture_esc  = db_input($picture);

    $q = "INSERT INTO user
              (iUserID, vName, vEmail, vPassword, vMobile, dDOB, vGoogleID, vAppleID, vPic, cStatus, cEmailVerified)
          VALUES
              ($id, '$name_esc', '$email_esc', '$password_esc', '$mobile_esc', '$DOB', '$google_esc', '$apple_esc', '$picture_esc', 'A', 'N')";

    if (!sql_query($q)) {
        respond(500, ["statusCode" => 500, "error" => ["message" => "Failed to register user"]]);
    }

    // Apple — skip OTP and email entirely
    if ($is_apple) {
        respond(200, [
            "statusCode" => 200,
            "data" => [
                "token"         => EncodeParam($id),
                "userName"      => $name,
                "hasFields"     => false,
                "emailVerified" => true,
                "emailSent"     => false,
            ]
        ]);
    }

    /* ===============================
       OTP + VERIFICATION EMAIL (Google / Email only)
    ================================= */
    $otp        = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    $otp_hash   = password_hash($otp, PASSWORD_BCRYPT);
    $expires_at = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    sql_query("UPDATE email_otps SET cStatus = 'U' WHERE iUserID = '$id'");
    sql_query("INSERT INTO email_otps (iUserID, vOTP, dExpiresAt, cStatus)
               VALUES ('$id', '$otp_hash', '$expires_at', 'A')");

    $site_title = "Future Feed";
    $year       = date('Y');

    $email_body = "
<!DOCTYPE html>
<html lang='en'>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1.0'>
  <title>Verify Your Email</title>
</head>
<body style='margin:0; padding:0; background-color:#1a1025; font-family: Arial, sans-serif;'>
  <table width='100%' cellpadding='0' cellspacing='0' border='0'
         style='background-color:#1a1025; padding:40px 20px;'>
    <tr>
      <td align='center'>
        <table width='100%' cellpadding='0' cellspacing='0' border='0'
               style='max-width:520px;
                      background: linear-gradient(145deg, #1e1030, #221240);
                      border-radius:20px;
                      border:1px solid rgba(255,255,255,0.08);
                      overflow:hidden;'>
          <tr>
            <td style='height:4px;
                       background:linear-gradient(90deg,#c44dff 0%,#a020f0 100%);'></td>
          </tr>
          <tr>
            <td style='padding:44px 44px 40px;'>
              <table cellpadding='0' cellspacing='0' border='0' style='margin-bottom:28px;'>
                <tr>
                  <td style='width:62px; height:62px;
                             background:linear-gradient(135deg,#c44dff 0%,#8e2de2 100%);
                             border-radius:16px; text-align:center; vertical-align:middle;'>
                    <img src='https://img.icons8.com/ios-filled/50/ffffff/email-open.png'
                         width='28' height='28' alt='email'
                         style='display:block; margin:auto;'>
                  </td>
                </tr>
              </table>
              <p style='margin:0 0 10px; font-size:24px; font-weight:700;
                        color:#ffffff; letter-spacing:-0.3px;'>Verify Your Email</p>
              <p style='margin:0 0 28px; font-size:13.5px; color:#9b8db0; line-height:1.6;'>
                Thanks for signing up with $site_title! Use the OTP below to verify your email address.
              </p>
              <table width='100%' cellpadding='0' cellspacing='0' border='0' style='margin-bottom:28px;'>
                <tr><td style='height:1px; background:rgba(255,255,255,0.07);'></td></tr>
              </table>
              <p style='margin:0 0 22px; font-size:14px; color:#cbbfe0; line-height:1.7;'>
                Hi <strong style='color:#ffffff;'>$name</strong>,
              </p>
              <table cellpadding='0' cellspacing='0' border='0' width='100%' style='margin-bottom:28px;'>
                <tr>
                  <td align='center'>
                    <table cellpadding='0' cellspacing='0' border='0'
                           style='background:rgba(180,40,255,0.12);
                                  border:1px solid rgba(180,40,255,0.35);
                                  border-radius:14px; padding:22px 40px;'>
                      <tr>
                        <td align='center'>
                          <p style='margin:0 0 6px; font-size:12px; color:#9b8db0;
                                    letter-spacing:2px; text-transform:uppercase;'>Your OTP Code</p>
                          <p style='margin:0; font-size:42px; font-weight:700;
                                    color:#ffffff; letter-spacing:10px;
                                    font-family: \"Courier New\", monospace;'>$otp</p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
              <p style='margin:0 0 28px; font-size:13.5px; color:#9b8db0;
                        line-height:1.7; text-align:center;'>
                This code will expire in <strong style='color:#d0c4e0;'>10 minutes</strong>.
                Do not share it with anyone.
              </p>
              <table width='100%' cellpadding='0' cellspacing='0' border='0' style='margin-bottom:24px;'>
                <tr><td style='height:1px; background:rgba(255,255,255,0.07);'></td></tr>
              </table>
              <p style='margin:0; font-size:12px; color:#5e5270; line-height:1.6;'>
                If you did not create an account with $site_title, you can safely ignore this email.
              </p>
            </td>
          </tr>
          <tr>
            <td style='padding:20px 44px;
                       background:rgba(0,0,0,0.2);
                       border-top:1px solid rgba(255,255,255,0.06);
                       text-align:center;'>
              <p style='margin:0; font-size:12px; color:#4a3d60;'>
                &copy; $year $site_title &mdash; All rights reserved.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>";

    $subject     = "Verify Your Email - $site_title";
    $result      = send_brevo($subject, $email, $email_body, '', '', $site_title);
    $result_data = json_decode($result, true);

    if (empty($result_data['messageId'])) {
        respond(200, [
            "statusCode" => 200,
            "data" => [
                "token"         => EncodeParam($id),
                "userName"      => $name,
                "hasFields"     => false,
                "emailVerified" => false,
                "emailSent"     => false,
            ]
        ]);
    }

    respond(200, [
        "statusCode" => 200,
        "data" => [
            "token"         => EncodeParam($id),
            "userName"      => $name,
            "hasFields"     => false,
            "emailVerified" => false,
            "emailSent"     => true,
            "email"         => $email,
        ]
    ]);

} catch (Exception $e) {
    respond(500, ["statusCode" => 500, "error" => ["message" => $e->getMessage()]]);
}