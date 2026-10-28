<?php
// API endpoint: store ownership changes (mint/unused/used/fdc/notes)

define("API_REQUEST", true);
include dirname(__DIR__) . "/settings.php";
include __DIR__ . "/common.php";
include __DIR__ . "/authheader.php";

if(!csrf_ok())      json_fail("Bad or missing CSRF token", 403);
if(!empty($_SESSION["readonly"])) json_fail("Read-only mode is active", 403);

$country = resolve_country($_POST["country"] ?? "");
if($country === null) json_fail("Unknown country");

$category = $_POST["category"] ?? "";
$changes  = json_decode($_POST["changes"] ?? "null", true) ?? [];

// create the country's collection DB from the template on first write
$fn = collection_db_path($country);
if(!is_file($fn) && !copy(dirname(__DIR__) . "/stamps/collection/COLLECTION_TEMPLATE.db", $fn))
    json_fail("Unable to create database for collection");

$db = new SQLite3($fn);
// wait on locks instead of failing
$db->busyTimeout(5000);
// readers never block the writer
$db->exec("PRAGMA journal_mode=WAL");

$logtxt = [];
$errcode = 0;

// all changes go into ONE transaction - either everything is stored or nothing
$db->exec("BEGIN");
foreach($changes as $id => $status) {

    // calidate the shape: five values (mint, unused, used, fdc, notes)
    if(!is_array($status) || count($status) != 5) { $errcode++; continue; }
    $id = (string)$id;

    // entry exists? -> UPDATE, otherwise INSERT
    $query = $db->prepare("SELECT COUNT(`id`) FROM collection WHERE category=:cat AND `id`=:id");
    $query->bindValue(":cat", $category);
    $query->bindValue(":id", $id);
    $exists = $query->execute()->fetchArray()[0];

    if($exists)
        $query = $db->prepare("UPDATE `collection` SET `mint`=:s0,`unused`=:s1,`used`=:s2,`fdc`=:s3,`notes`=:s4
                                WHERE category=:cat AND `id`=:id");
    else
        $query = $db->prepare("INSERT INTO `collection` (`category`,`id`,`mint`,`unused`,`used`,`fdc`,`notes`)
                                VALUES (:cat,:id,:s0,:s1,:s2,:s3,:s4)");
    $query->bindValue(":cat", $category);
    $query->bindValue(":id", $id);
    for($i = 0; $i < 4; ++$i)
        $query->bindValue(":s$i", (int)$status[$i]);
    $query->bindValue(":s4", (string)$status[4]);
    if(!$query->execute()) $errcode++;

    // audit log; htmlspecialchars keeps injected markup out of the log file
    $logtxt[] = date("Y-m-d H:i:s") . ": '$id' (" . htmlspecialchars($country) . "/" . htmlspecialchars($category) . ") -> [" . implode(",", array_map("htmlspecialchars", $status)) . "]";

}
$db->exec("COMMIT");

// one log file per day
@mkdir(dirname(__DIR__) . "/stamps/logs", 0775, true);
file_put_contents(dirname(__DIR__) . "/stamps/logs/logfile-" . date("Y-m-d") . ".txt",
                  implode("\n", $logtxt) . PHP_EOL, FILE_APPEND | LOCK_EX);

if($errcode == 0) json_out(["success" => true]);
json_out(["success" => false, "error" => "Something went wrong storing changes..."], 500);
