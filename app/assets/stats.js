// detailed per-country breakdown for the region buttons of stats.php

// one listener per region button (data-region attribute, no inline onClick)
document.querySelectorAll("[data-region]").forEach(btn =>
    btn.addEventListener("click", () => showDetailedStats(btn.dataset.region)));

function showDetailedStats(region) {

    const list = document.getElementById("statsDetailedList");
    list.replaceChildren();

    // which countries belong to the region? "World" => all with data
    const names = (region == "World")
        ? Object.keys(DETAILED_DATA)
        : (REGION_COUNTRIES[region] ?? []);

    // sort by count, biggest first; skip countries without stamps
    const entries = names
        .map(c => [c, DETAILED_DATA[c] ?? 0])
        .filter(([, n]) => n > 0)
        .sort((a, b) => b[1] - a[1]);

    // biggest count => full bar
    const max = entries.length ? entries[0][1] : 0;

    // DOM-built entries
    for(const [country, num] of entries) {
        const row = document.createElement("div");
        row.className = "stats-detailed-entry";
        const c = document.createElement("span");
        c.className = "stats-detailed-entry-country";
        c.textContent = country + ": ";
        const barWrap = document.createElement("div");
        barWrap.className = "stats-detailed-bar-wrap";
        const bar = document.createElement("div");
        bar.className = "stats-detailed-bar";
        bar.style.width = (max ? Math.round(100 * num / max) : 0) + "%";
        barWrap.appendChild(bar);
        const n = document.createElement("span");
        n.className = "stats-detailed-entry-number";
        n.textContent = num;
        row.append(c, barWrap, n);
        list.appendChild(row);
    }
}

// return button
document.getElementById("returnButton").addEventListener("click", () => {
    window.location = "index.php";
});

// dark-mode toggle
const themeBtn = document.getElementById("themeToggle");
function syncThemeButton() {
    themeBtn.innerHTML = document.documentElement.classList.contains("dark") ? "&#9728;" : "&#9789;"; // sun / moon
}
themeBtn.addEventListener("click", () => {
    const dark = !document.documentElement.classList.contains("dark");
    document.documentElement.classList.toggle("dark", dark);
    document.body.classList.toggle("dark", dark);
    localStorage.setItem("theme", dark ? "dark" : "light");
    syncThemeButton();
});
syncThemeButton();
