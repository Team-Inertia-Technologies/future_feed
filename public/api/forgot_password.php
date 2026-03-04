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

$reset_link  = "https://futurefeed.top/api/reset-password.php?token=$token&email=" . urlencode($email);
$site_title  = "Future Feed";

$contents = "
<!DOCTYPE html>
<html>
<body style='font-family: Arial, sans-serif; background:#f4f4f4; padding:30px;'>
  <div style='max-width:600px; margin:auto; background:#fff; border-radius:8px; padding:30px;'>
    <h2 style='color:#333;'>Password Reset Request</h2>
    <p>Hi <strong>$user_name</strong>,</p>
    <p>We received a request to reset your password. Click the button below to reset it:</p>
    <p style='text-align:center; margin:30px 0;'>
      <a href='$reset_link'
         style='background:#4CAF50; color:#fff; padding:12px 24px;
                border-radius:5px; text-decoration:none; font-size:16px;'>
        Reset Password
      </a>
    </p>
    <p>This link will expire in <strong>1 hour</strong>.</p>
    <p>If you did not request a password reset, please ignore this email.</p>
    <hr style='border:none; border-top:1px solid #eee; margin:20px 0;'>
    <p style='color:#999; font-size:12px;'>
      If the button doesn't work, copy and paste this link into your browser:<br>
      <a href='$reset_link'>$reset_link</a>
    </p>
  </div>
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