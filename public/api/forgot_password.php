<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
$NO_REDIRECT = $NO_PRELOAD = 1;
include "../includes/common_api.php";
header('Content-Type: application/json');

$postdata = file_get_contents("php://input");
$request = json_decode($postdata, true);
$_REQUEST = array_merge($_REQUEST, $request ?? []);

$email = trim($_REQUEST['email'] ?? '');

// Validate email
if (empty($email)) {
    echo json_encode(['statusCode' => 400, 'message' => 'Email is required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['statusCode' => 400, 'message' => 'Invalid email address.']);
    exit;
}

$email_escaped = db_input($email);
$user_query = sql_query("SELECT iUserID, vName FROM user WHERE vEmail = '$email_escaped' AND cStatus = 'A' LIMIT 1");

if (sql_num_rows($user_query) === 0) {
    echo json_encode(['statusCode' => 400, 'message' => 'No account found with this email address.']);
    exit;
}

$user = sql_fetch_assoc($user_query);
$user_id   = $user['iUserID'];
$user_name = $user['vName'];

$token      = bin2hex(random_bytes(32));
$expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

sql_query("INSERT INTO password_resets (iUserID, vToken, dExpiresAt, cStatus) 
VALUES ('$user_id', '$token', '$expires_at', 'A')");

$reset_link  = "https://futurefeed.top/reset-password.php?token=$token&email=" . urlencode($email);
$site_title  = "Future Feed";

$contents = "
<!DOCTYPE html>
<html lang='en'>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1.0'>
  <title>Reset Your Password</title>
</head>
<body style='margin:0; padding:0; background-color:#1a1025; font-family: Arial, sans-serif;'>

  <table width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#1a1025; padding: 40px 20px;'>
    <tr>
      <td align='center'>

        <!-- Card -->
        <table width='100%' cellpadding='0' cellspacing='0' border='0'
               style='max-width:520px;
                      background: linear-gradient(145deg, #1e1030, #221240);
                      border-radius: 20px;
                      border: 1px solid rgba(255,255,255,0.08);
                      overflow: hidden;'>

          <!-- Top accent bar -->
          <tr>
            <td style='height:4px;
                       background: linear-gradient(90deg, #c44dff 0%, #a020f0 100%);'></td>
          </tr>

          <!-- Body -->
          <tr>
            <td style='padding: 44px 44px 40px;'>

              <!-- Icon -->
              <table cellpadding='0' cellspacing='0' border='0' style='margin-bottom:28px;'>
                <tr>
                  <td style='width:62px; height:62px;
                             background: linear-gradient(135deg, #c44dff 0%, #8e2de2 100%);
                             border-radius:16px; text-align:center; vertical-align:middle;'>
                    <img src='https://img.icons8.com/ios-filled/50/ffffff/lock-2.png'
                         width='28' height='28' alt='lock'
                         style='display:block; margin:auto; filter:brightness(10);'>
                  </td>
                </tr>
              </table>

              <!-- Heading -->
              <p style='margin:0 0 10px; font-size:24px; font-weight:700;
                        color:#ffffff; letter-spacing:-0.3px;'>
                Password Reset Request
              </p>

              <!-- Subtitle -->
              <p style='margin:0 0 28px; font-size:13.5px; color:#9b8db0; line-height:1.6;'>
                We received a request to reset your $site_title account password.
              </p>

              <!-- Divider -->
              <table width='100%' cellpadding='0' cellspacing='0' border='0'
                     style='margin-bottom:28px;'>
                <tr>
                  <td style='height:1px; background:rgba(255,255,255,0.07);'></td>
                </tr>
              </table>

              <!-- Greeting -->
              <p style='margin:0 0 14px; font-size:14px; color:#cbbfe0; line-height:1.7;'>
                Hi <strong style='color:#ffffff;'>$user_name</strong>,
              </p>
              <p style='margin:0 0 32px; font-size:14px; color:#9b8db0; line-height:1.7;'>
                Click the button below to reset your password. This link will expire in
                <strong style='color:#d0c4e0;'>1 hour</strong>.
              </p>

              <!-- CTA Button -->
              <table cellpadding='0' cellspacing='0' border='0' width='100%'
                     style='margin-bottom:32px;'>
                <tr>
                  <td align='center'>
                    <a href='$reset_link'
                       style='display:inline-block;
                              padding: 15px 48px;
                              background: linear-gradient(90deg, #d44eff 0%, #a020f0 100%);
                              color:#ffffff;
                              text-decoration:none;
                              font-size:15px;
                              font-weight:700;
                              border-radius:50px;
                              letter-spacing:0.3px;
                              box-shadow: 0 4px 24px rgba(180,40,255,0.4);'>
                      Reset Password &nbsp;&#10003;
                    </a>
                  </td>
                </tr>
              </table>

              <!-- Divider -->
              <table width='100%' cellpadding='0' cellspacing='0' border='0'
                     style='margin-bottom:24px;'>
                <tr>
                  <td style='height:1px; background:rgba(255,255,255,0.07);'></td>
                </tr>
              </table>

              <!-- Fallback link -->
              <p style='margin:0 0 10px; font-size:12px; color:#6b5d80; line-height:1.6;'>
                If the button doesn&#39;t work, copy and paste this link into your browser:
              </p>
              <p style='margin:0 0 24px; font-size:12px; word-break:break-all;'>
                <a href='$reset_link'
                   style='color:#a855f7; text-decoration:none;'>$reset_link</a>
              </p>

              <!-- Ignore notice -->
              <p style='margin:0; font-size:12px; color:#5e5270; line-height:1.6;'>
                If you did not request a password reset, you can safely ignore this email.
                Your password will remain unchanged.
              </p>

            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style='padding:20px 44px;
                       background:rgba(0,0,0,0.2);
                       border-top:1px solid rgba(255,255,255,0.06);
                       text-align:center;'>
              <p style='margin:0; font-size:12px; color:#4a3d60;'>
                &copy; " . date('Y') . " $site_title &mdash; All rights reserved.
              </p>
            </td>
          </tr>

        </table>
        <!-- /Card -->

      </td>
    </tr>
  </table>

</body>
</html>
";

$subject = "Reset Your Password - $site_title";

// Send email via Brevo
$result      = send_brevo($subject, $email, $contents, '', '', $site_title);
$result_data = json_decode($result, true);

if (!empty($result_data['messageId'])) {
    echo json_encode([
        'statusCode' => 200,
        'message' => 'Password reset link has been sent to your email.'
    ]);
} else {
    echo json_encode([
        'statusCode' => 400,
        'message' => $result_data['message'] ?? 'Failed to send email.'
    ]);
}