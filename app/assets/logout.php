<?php
// API endpoint: end the session (logout)

define("API_REQUEST", true);
include "../settings.php";
include "common.php";
include "authheader.php";

if(!csrf_ok()) json_fail("Bad or missing CSRF token", 403);

// clear the session data, expire the cookie, destroy the store
$_SESSION = [];
if(ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), "", time() - 42000, $p["path"], $p["domain"] ?? "", $p["secure"], $p["httponly"]);
}
session_destroy();

json_out(["success" => true]);

?>
