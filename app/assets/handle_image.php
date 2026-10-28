<?php
// API endpoint: upload / remove the user's own image for one stamp

// uploads in any common format (PNG, GIF, WebP, ...) are converted to JPEG with GD, real JPEGs are stored as-is
// anything that is not a valid image, or that GD cannot convert, returns an error

define("API_REQUEST", true);
include dirname(__DIR__) . "/settings.php";
include __DIR__ . "/common.php";
include __DIR__ . "/authheader.php";

if(!csrf_ok())      json_fail("Bad or missing CSRF token", 403);
if(!empty($_SESSION["readonly"])) json_fail("Read-only mode is active", 403);

$country = resolve_country($_POST["country"] ?? "");
if($country === null) json_fail("Unknown country");

// the id becomes a filename -> strip anything path-like
$id = basename($_POST["id"] ?? "");
if($id === "") json_fail("missing id");

$action = $_POST["action"] ?? "";

if($action == "addImage") {

    if(!isset($_FILES["image"])) json_fail("no file provided");
    $file = $_FILES["image"];
    if($file["error"] !== UPLOAD_ERR_OK)
        json_fail("upload failed (code " . $file["error"] . ")");
    if($file["size"] > 8 * 1024 * 1024)
        json_fail("image too large (max 8 MB)");

    // check by CONTENT, not by extension
    // getimagesize() only succeeds on real images, which blocks renamed scripts or text files
    $info = @getimagesize($file["tmp_name"]);
    if($info === false)
        json_fail("The uploaded file is not a valid image.");

    $dir = dirname(__DIR__) . "/stamps/imageuploads/$country";
    if(!is_dir($dir) && !mkdir($dir, 0775, true))
        json_fail("target directory cannot be created");
    $target = $dir . "/" . image_shard($id);
    if(!is_dir($target) && !mkdir($target, 0775, true))
        json_fail("target subdirectory cannot be created");
    $target .= "$id.jpg";

    if($info[2] === IMAGETYPE_JPEG) {
        // already a JPEG -> store as-is (move_uploaded_file also refuses files that did not come from this upload)
        if(!move_uploaded_file($file["tmp_name"], $target))
            json_fail("unable to save uploaded image", 500);

    } else {
        // anything else -> convert to JPEG via GD
        if(!function_exists("imagecreatefromstring"))
            json_fail("The PHP GD extension is required to convert this image type.");
        $img = @imagecreatefromstring(file_get_contents($file["tmp_name"]));
        if($img === false)
            json_fail("Unsupported image type - conversion to JPEG failed.");
        // quality 90 is plenty quality
        if(!imagejpeg($img, $target, 90)) {
            imagedestroy($img);
            json_fail("Conversion to JPEG failed.", 500);
        }
        imagedestroy($img);
        // bonus: re-encoding strips any potential payload hidden in the original file
    }

    // return the new file's URL
    // the client cannot build it itself (the sanitized storage name and the URL encoding live on the server)
    json_out(["success" => true,
              "path" => "stamps/imageuploads/" . rawurlencode($country) . "/" . image_shard($id) . "$id.jpg"]);
}

if($action == "removeImage") {
    $file = dirname(__DIR__) . "/stamps/imageuploads/$country/" . image_shard($id) . "$id.jpg";
    // nothing to remove = success
    if(!is_file($file))
        json_out(["success" => true]);
    if(unlink($file))
        json_out(["success" => true]);
    json_fail("failed to remove custom image", 500);
}

json_fail("unknown action");
