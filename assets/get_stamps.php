<?php
// API endpoint: full stamp list + ownership states of one country

define("API_REQUEST", true);
include dirname(__DIR__) . "/settings.php";
include __DIR__ . "/common.php";
include __DIR__ . "/authheader.php";

$info = resolve_country_info($_POST["country"] ?? "");
if($info === null)
    json_fail("Unknown country");
$country   = $info["storage"];
$continent = normalize_continent($info["continent"]);
$folder = continent_folder($continent, $country);

$fn = stamps_db_path($continent, $country);
if(!is_file($fn))
    json_fail("Database file not found: " . basename($fn));
$db = new SQLite3($fn);
// natural sort
$db->createCollation("NORMAL", fn($a, $b) => strnatcmp($a, $b));

// all stamps, natural-sorted by sortid
// EVERY nullable column gets an IFNULL: a NULL would either crash the JS later, or make PHP print a deprecation warning BEFORE the JSON reply
// that would make the response unparseable, so the client keeps showing the previously loaded country
$query = $db->prepare("SELECT `id`, IFNULL(`group`,'') `group`, IFNULL(`year`,0) `year`,
        IFNULL(`date`,'') `date`, IFNULL(`title`,'') `title`,
        IFNULL(`description`,'') `description`,
        IFNULL(denomination,'') `denomination`, IFNULL(currency,'') `currency`,
        IFNULL(perforation,'') `perforation`, IFNULL(`tags`,'') `tags`,
        `category`, IFNULL(size,'') `size`
        FROM `stamps` ORDER BY `year`, `sortid` COLLATE NORMAL");
$data = $query->execute();

$stamps = []; $categories = [];
while($row = $data->fetchArray(SQLITE3_ASSOC)) {

    $category = $row["category"];
    $categories[$category] = true;

    // stock image and user-uploaded image (empty string when absent)
    // existence is checked on the plain filesystem path, the returned URL is rawurlencoded per segment
    $imgpath = "";
    if(is_file(dirname(__DIR__) . "/stamps/data/$folder/$country/images/" . image_shard($row["id"]) . $row["id"] . ".jpg"))
        $imgpath = "stamps/data/" . rawurlencode($folder) . "/" . rawurlencode($country) . "/images/" . image_shard($row["id"]) . $row["id"] . ".jpg";
    $customimgpath = "";
    if(is_file(dirname(__DIR__) . "/stamps/imageuploads/$country/" . image_shard($row["id"]) . $row["id"] . ".jpg"))
        $customimgpath = "stamps/imageuploads/" . rawurlencode($country) . "/" . image_shard($row["id"]) . $row["id"] . ".jpg";

    $stamps[$category][$row["id"]] = [
        "id" => $row["id"], "imgpath" => $imgpath, "customimgpath" => $customimgpath,
        "group" => $row["group"], "year" => $row["year"], "date" => $row["date"],
        "title" => $row["title"], "description" => $row["description"],
        "denomination" => $row["denomination"], "currency" => $row["currency"],
        "perforation" => $row["perforation"], "size" => $row["size"],
        "keywords" => explode("\n", $row["tags"]),
    ];
}

// ownership states from the collection DB (created on demand by update_status.php)
$states = [];
$fn = collection_db_path($country);
if(is_file($fn)) {
    $db = new SQLite3($fn);
    foreach(array_keys($categories) as $c) {
        $query = $db->prepare("SELECT `id`,`mint`,`unused`,`used`,`fdc`,`notes`
                               FROM collection WHERE category = :cat");
        $query->bindValue(":cat", $c);
        $data = $query->execute();
        while($row = $data->fetchArray(SQLITE3_ASSOC))
            $states[$c][$row["id"]] = [$row["mint"], $row["unused"], $row["used"],
                                       $row["fdc"], $row["notes"]];
    }
}

json_out(["success" => true, "stamps" => $stamps, "states" => $states]);
