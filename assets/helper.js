// shared DOM references, small utilities and the decade/year select builders

const eleFilterCategory = document.getElementById("categorySelector");
const eleFilterDecades = document.getElementById("filterDecades");
const eleFilterYears = document.getElementById("filterYears");

// escape any text before it goes into innerHTML (blocks XSS from stamp data, country names or user notes)
// also covers attribute contexts (&quot;)
function escapeHtml(s) {
    return String(s ?? "").replace(/[&<>"']/g, c => ({
        "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    })[c]);
}

// current category with the original "Postage stamps" fallback
function currentCategory() {
    return eleFilterCategory.value || "Postage stamps";
}

// POST formData to an endpoint, return the parsed JSON reply
// sends the CSRF token with every request, throws on HTTP/JSON errors
async function apiPost(url, formData) {
    formData.append("csrf", CSRF_TOKEN);
    const response = await fetch(url, { method: "POST", body: formData });
    if(!response.ok) {
        // include the server's error message when there is one
        let detail = `HTTP ${response.status}`;
        try { const r = await response.json(); if(r && r.error) detail += ": " + r.error; } catch(e) {}
        throw new Error(detail);
    }
    return await response.json();
}

// small non-blocking toast instead of alert()
function showToast(message) {
    let box = document.querySelector(".toast");
    if(!box) {
        box = document.createElement("div");
        box.className = "toast";
        document.body.appendChild(box);
    }
    box.textContent = message;
    box.classList.add("show");
    clearTimeout(box._timer);
    box._timer = setTimeout(() => box.classList.remove("show"), 4000);
}

/*******************************************************************/
// cookies
// persist the current selection in cookies (30 days, written by JS because they change on every interaction; PHP reads them on the next page load)
function setStampsCookie(whichone) {
    const d = new Date(Date.now() + 30*24*60*60*1000);
    const dstr = d.toUTCString();
    const in_order = ["continent", "country", "categories", "years"];
    in_order.forEach(item => {
        if(item != whichone && whichone != "all")
            return;
        if(item == "continent")
            document.cookie = "continent=" + CURRENT_continent + ";expires=" + dstr + ";path=/";
        if(item == "country")
            document.cookie = "country=" + CURRENT_country + ";expires=" + dstr + ";path=/";
        if(item == "categories")
            document.cookie = "category=" + currentCategory() + ";expires=" + dstr + ";path=/";
        if(item == "years") {
            if(eleFilterYears.selectedIndex > 0) {
                document.cookie = "year_from=" + eleFilterYears.value + ";expires=" + dstr + ";path=/";
                document.cookie = "year_to=" + eleFilterYears.value + ";expires=" + dstr + ";path=/";
            } else {
                // guard: the decades list can be empty (country without data)
                const opt = eleFilterDecades.options[eleFilterDecades.selectedIndex];
                if(opt) {
                    document.cookie = "year_from=" + opt.getAttribute("year-from") + ";expires=" + dstr + ";path=/";
                    document.cookie = "year_to=" + opt.getAttribute("year-to") + ";expires=" + dstr + ";path=/";
                }
            }
        }
    });
}

/*******************************************************************/
// theme
// the saved theme is applied in <head> before the first paint (see index.php)
// these functions only switch it and keep the button in sync
function setTheme(dark) {
    document.documentElement.classList.toggle("dark", dark);
    document.body.classList.toggle("dark", dark);
    localStorage.setItem("theme", dark ? "dark" : "light");
    const btn = document.getElementById("themeToggle");
    // sun / moon
    if(btn) btn.innerHTML = dark ? "&#9728;" : "&#9789;";
}
function toggleTheme() {
    setTheme(!document.documentElement.classList.contains("dark"));
}

/*******************************************************************/
// shareable URLs
// the current view is reflected in the address bar (?continent=&country=&category=&year_from=&year_to=&q=) so it can be bookmarked and shared
// URL parameters override the stored cookies when such a link is opened

// pending search text from a shared link
let ONLOAD_searchtxt = null;

// called once on startup, BEFORE any data is loaded
function applyUrlParams() {
    const params = new URLSearchParams(window.location.search);
    const continent = params.get("continent");
    if(continent) {
        CURRENT_continent = continent;
        CURRENT_country = defaultCountries[continent] || CURRENT_country;
        document.getElementById("continentSelector").value = continent;
    }
    if(params.get("country")) CURRENT_country = params.get("country");
    if(params.get("category")) ONLOAD_category = params.get("category");
    const yf = parseInt(params.get("year_from")), yt = parseInt(params.get("year_to"));
    if(yf > 0 && yt >= yf) { ONLOAD_year_from = yf; ONLOAD_year_to = yt; }
    if(params.get("q")) ONLOAD_searchtxt = params.get("q");
}

// rewrite the address bar without reloading on every view change
function updateUrlState() {
    const params = new URLSearchParams();
    params.set("continent", CURRENT_continent);
    params.set("country", CURRENT_country);
    if(currentCategory() && currentCategory() != "Postage stamps")
        params.set("category", currentCategory());
    // null when empty (no data)
    const sel = eleFilterDecades.selectedOptions[0];
    if(sel) {
        if(eleFilterYears.selectedIndex > 0) {
            params.set("year_from", eleFilterYears.value);
            params.set("year_to", eleFilterYears.value);
        } else {
            const yf = parseInt(sel.getAttribute("year-from"));
            // "all years" stays default
            if(yf > 0) {
                params.set("year_from", yf);
                params.set("year_to", parseInt(sel.getAttribute("year-to")));
            }
        }
    }
    if(CURRENT_searchtxt) params.set("q", CURRENT_searchtxt);
    const read = new URLSearchParams(window.location.search).get("read");
    // keep the read-only flag
    if(read) params.set("read", read);
    const qs = params.toString();
    window.history.replaceState(null, null, qs ? "?" + qs : window.location.pathname);
}

/*******************************************************************/
// filter option lists

function setFilterCategories() {
    let txt = "";
    for(const c of CACHE_categories)
        txt += `<option${c == ONLOAD_category ? " selected" : ""}>${escapeHtml(c)}</option>\n`;
    eleFilterCategory.innerHTML = txt;
}

// rebuild the denomination/currency dropdown for one category
function setFilterDenominations(category) {
    let txt = "<option value=''>---</option>\n";
    for(const d of CACHE_denominations[category] || [])
        txt += `<option>${escapeHtml(d)}</option>\n`;
    document.getElementById("denominationSelector").innerHTML = txt;
}
function setFilterCurrencies(category) {
    let txt = "<option value=''>---</option>\n";
    for(const c of CACHE_currencies[category] || [])
        txt += `<option>${escapeHtml(c)}</option>\n`;
    document.getElementById("currencySelector").innerHTML = txt;
}

// decades: newest decade first
// a decade is only listed if at least one stamp exists within it
// the first (partial) decade starts at the newest year
function setFilterDecades() {

    const cur_years = CACHE_years[eleFilterCategory.value] || [];
    if(!cur_years.length) { eleFilterDecades.innerHTML = ""; return; }

    var base_decade = 10 * Math.floor(cur_years[cur_years.length-1] / 10);
    var start_decade = cur_years[cur_years.length-1];
    var stepsize = 10 - (start_decade - base_decade);
    var txtDec = [];

    while(cur_years[0] - start_decade > 9) {

        var end_decade = start_decade + stepsize - 1;

        // decade without any stamps? skip it entirely
        var has_stamps = false;
        for(var i = start_decade; i <= end_decade; ++i)
            if(cur_years.includes(i)) { has_stamps = true; break; }
        if(!has_stamps) {
            start_decade += stepsize;
            stepsize = 10;
            continue;
        }

        // year-from/year-to attributes carry the range of the decade
        var cur = `<option year-from='${start_decade}' year-to='${end_decade}'`;
        if(ONLOAD_year_from >= start_decade && ONLOAD_year_to <= end_decade)
            cur += " selected";
        cur += `>${start_decade}-${start_decade+stepsize-1}</option>`;
        txtDec.unshift(cur);
        start_decade += stepsize;
        stepsize = 10;
    }
    // the oldest decade (up to the oldest year)
    var final = `<option year-from='${start_decade}' year-to='${cur_years[0]}'`;
    if(ONLOAD_year_from >= start_decade && ONLOAD_year_to <= cur_years[0])
        final += " selected";
    final += `>${start_decade}-${cur_years[0]}</option>`;
    txtDec.unshift(final);
    txtDec.unshift("<option year-from='-1' year-to='-1'>all years</option>\n");
    eleFilterDecades.innerHTML = txtDec.join("\n");
}

// years of the selected decade (or all years), newest first
function setFilterYears() {

    const sel = eleFilterDecades.selectedOptions[0];
    // decades list empty (country without data)
    if(!sel) return;
    const startDecade = parseInt(sel.getAttribute("year-from"));
    const endDecade = parseInt(sel.getAttribute("year-to"));
    const category = currentCategory();
    const years = CACHE_years[category] || [];

    var txtYears = "<option value='-1'>---</option>\n";
    if(startDecade == -1) {
        for(const y of years)
            txtYears += `<option>${y}</option>\n`;
    } else {
        for(let y = endDecade; y >= startDecade; --y)
            if(years.includes(y))
                txtYears += `<option${(ONLOAD_year_from == ONLOAD_year_to && ONLOAD_year_from == y) ? " selected" : ""}>${y}</option>\n`;
    }
    eleFilterYears.innerHTML = txtYears;
}
