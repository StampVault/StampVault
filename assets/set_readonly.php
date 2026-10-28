<?php
// API endpoint: toggle server-side read-only mode
// while active, update_status.php and handle_image.php reject all writes

define("API_REQUEST", true);
include dirname(__DIR__) . "/settings.php";
include __DIR__ . "/common.php";
include __DIR__ . "/authheader.php";

if(!csrf_ok()) json_fail("Bad or missing CSRF token", 403);

$_SESSION["readonly"] = empty($_SESSION["readonly"]);
json_out(["success" => true, "readonly" => !empty($_SESSION["readonly"])]);
