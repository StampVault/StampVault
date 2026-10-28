<?php
// statistics: owned-stamp counts per continent and per country

include "settings.php";
include "assets/common.php";
include "assets/authheader.php";

// display name -> owned-stamp count
$number_detailed = [];
$total_count = ["World" => 0];
// continent -> display names (for the buttons)
$list_countries = [];

foreach(countries_by_continent() as $continent => $entries) {

    $names = [];
    $continent_count = 0;

    foreach($entries as $cols) {
        // main/sub divider
        if($cols[0] === "---") continue;

        // display name
        $realcountry = $cols[0];
        // storage name
        $usecountry  = normalize_country(end($cols));
        $names[] = $realcountry;

        $fn = "stamps/collection/$usecountry.db";
        // no collection DB -> 0
        if(!is_file($fn)) continue;
        $db = new SQLite3($fn);

        // sum of all four ownership flags (rows with all flags 0 add 0 anyway)
        $num = (int)($db->querySingle("SELECT IFNULL(SUM(mint),0) + IFNULL(SUM(unused),0) + IFNULL(SUM(used),0) + IFNULL(SUM(fdc),0 FROM collection") ?? 0);
        $number_detailed[$realcountry] = $num;
        $continent_count += $num;
    }

    // "World" is the grand total (a virtual group), not a section of its own
    if($continent != "World") {
        $total_count[$continent] = $continent_count;
        $list_countries[$continent] = $names;
    }
    $total_count["World"] += $continent_count;

}

arsort($total_count);
$region_order = array_keys($total_count);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statistics - StampVault</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script>
    // apply the saved theme BEFORE the first paint => no light-mode flash
    if(localStorage.getItem("theme") == "dark")
        document.documentElement.classList.add("dark");
    </script>

    <link rel="stylesheet" href="assets/general.css">
    <link rel="stylesheet" href="assets/stats.css">
    <script src="assets/stats.js"></script>
    <script>
    const REGION_COUNTRIES = <?php echo json_encode($list_countries); ?>;
    const DETAILED_DATA = <?php echo json_encode($number_detailed); ?>;
    </script>
</head>
<body>

<button class="return-button" id="returnButton">&lt;</button>
<button id="themeToggle" class="theme-toggle" aria-label="toggle dark mode" title="Toggle dark mode">&#9789;</button>

<h1>Some Statistics</h1>
<hr>

<div class="stats-total">
    <span class='stats-total-number'><?php echo (int)$total_count["World"]; ?></span><br>
    stamps in total
</div>
<hr>

<?php foreach($region_order as $region) { ?>
<button class="stats-region" data-region="<?php echo htmlspecialchars($region); ?>">
    <?php echo htmlspecialchars($region); ?>: <span class='stats-total-region'><?php echo (int)$total_count[$region]; ?></span>
</button><br>
<?php if($region == "World") echo "<hr>\n"; } ?>

<?php
// continent bar chart
$chart_max = 0;
foreach($total_count as $region => $n)
    if($region != "World") $chart_max = max($chart_max, $n);
if($chart_max > 0) {
?>
<hr>

<h2>Stamps per continent</h2>

<div class="stats-chart">
<?php foreach($region_order as $region) {
    if($region == "World") continue;
    $w = (int)round(100 * $total_count[$region] / $chart_max); ?>
    <div class="stats-chart-row">
        <span class="stats-chart-label"><?php echo htmlspecialchars($region); ?></span>
        <div class="stats-chart-bar-wrap"><div class="stats-chart-bar" style="width: <?php echo $w; ?>%"></div></div>
        <span class="stats-chart-count"><?php echo (int)$total_count[$region]; ?></span>
    </div>
<?php } ?>
</div>
<?php } ?>

<hr>

<div id="statsDetailedList" class="stats-detailed-list"></div>

<script>
showDetailedStats(<?php echo json_encode($region_order[0]); ?>);
</script>

</body>
</html>
