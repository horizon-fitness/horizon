<?php
// 1. Initialize the session
session_start();

// 2. Unset all session variables
$_SESSION = array();

// 3. If it's desired to kill the session, also delete the session cookie.
// This is more secure than just unsetting variables.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Finally, destroy the session.
session_destroy();

// 5. Redirect to the login in page
header("Location: login.php");
exit();
?>
