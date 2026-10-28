<?php
// auth gate + session bootstrap. Included by every page and endpoint
// endpoints that define API_REQUEST before including get a 401 JSON reply
// browsers are redirected to login.php instead.

bootstrap_session();

// one CSRF token per session
if(empty($_SESSION["csrf"]))
    $_SESSION["csrf"] = bin2hex(random_bytes(32));

// first run: force everyone into the setup wizard
if(empty($setup_completed)) {
    if(defined("API_REQUEST")) json_fail("Setup has not been completed yet", 503);
    header("Location: setup.php");
    die;
}

// read-only mode is remembered server-side too, so the write endpoints can
// reject changes. Set via ?read=... or toggled via assets/set_readonly.php
if(isset($_GET["read"]))
    $_SESSION["readonly"] = ($_GET["read"] == "only");

// evaluate the login state for the configured auth method
// a session created with a different method (simple vs openid) is rejected,
// e.g. when the setting is switched.
$uid   = $_SESSION["user_id"] ?? "";
$other = ($type_of_auth == "simple") ? "openid" : "simple";

if($type_of_auth == "none") {
    $_SESSION["user_id"] = "none";
} elseif($uid == "" || $uid == "none" || $uid == $other) {
    if(defined("API_REQUEST")) {
        json_fail("Not authenticated", 401);
    } else {
        header("Location: login.php");
        die;
    }
}
