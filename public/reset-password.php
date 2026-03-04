<?php
$NO_REDIRECT = $NO_PRELOAD = 1;
// error_reporting(E_ALL);
// ini_set('display_errors', 0);
include "includes/common_front.php";

$email = $_GET['email'] ?? '';
$token = $_GET['token'] ?? '';

if (empty($email) || empty($token)) {
    die("Invalid reset link.");
}

$email_escaped = db_input($email);
$token_escaped = db_input($token);

/* ===============================
   VERIFY TOKEN
================================= */
$query = sql_query("
    SELECT pr.iUserID, pr.dExpiresAt 
    FROM password_resets pr
    INNER JOIN user u ON u.iUserID = pr.iUserID
    WHERE u.vEmail = '$email_escaped'
    AND pr.vToken = '$token_escaped'
    AND pr.cStatus = 'A'
    LIMIT 1
");

if (sql_num_rows($query) == 0) {
    die("Invalid or expired reset link.");
}

$data = sql_fetch_assoc($query);
$NOW = NOW;
if ($data['dExpiresAt'] < $NOW) {
    die("Reset link has expired.");
}

$user_id = $data['iUserID'];

/* ===============================
   HANDLE PASSWORD UPDATE
================================= */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if (empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {

        // Base64 + MD5 hashing (as requested)
        $hashed_password = base64_encode(md5($new_password, true));

        sql_query("UPDATE user 
                   SET vPassword = '$hashed_password' 
                   WHERE iUserID = '$user_id'");

        // Invalidate token after use
        sql_query("UPDATE password_resets 
                   SET cStatus = 'U' 
                   WHERE vToken = '$token_escaped'");

        echo "<h3>Password updated successfully.</h3>";
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reset Password</title>
</head>
<body style="font-family:Arial;background:#f4f4f4;padding:30px;">
<div style="max-width:500px;margin:auto;background:#fff;padding:30px;border-radius:8px;">
    <h2>Reset Password</h2>

    <?php if (!empty($error)) { ?>
        <p style="color:red;"><?php echo $error; ?></p>
    <?php } ?>

    <form method="POST">
        <label>New Password</label><br>
        <input type="password" name="new_password" required style="width:100%;padding:10px;margin-bottom:15px;"><br>

        <label>Confirm Password</label><br>
        <input type="password" name="confirm_password" required style="width:100%;padding:10px;margin-bottom:20px;"><br>

        <button type="submit" style="background:#4CAF50;color:#fff;padding:10px 20px;border:none;border-radius:5px;">
            Reset Password
        </button>
    </form>
</div>
</body>
</html>