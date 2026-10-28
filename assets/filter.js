/*******************************************************************/
/*******************************************************************/
// country list rendering + initial filter data loading

function loadCountry(country) {
    // flush pending ownership changes FIRST: they belong to the previous country and must not be written into the new one's database
    flushPendingChanges();

    document.querySelector(".country-current")?.classList.remove("country-current");
    document.getElementById(country)?.classList.add("country-current");

    CURRENT_country = country;
    document.title = CURRENT_country + " | " + PROJECT_NAME + TITLE_SUFFIX;
    loadInitialFilter();
    setStampsCookie("country");
    setStampsCookie("years");
    updateUrlState();
    getStamps();
}

// request token: only the newest response may touch the DOM
// clicking through countries faster than the server responds can no longer render an outdated list over the current one.
let countryListRequest = 0;

async function loadCountryList() {

    const myRequest = ++countryListRequest;

    try {
        const formData = new FormData();
        formData.append("load", "countrylist");
        formData.append("continent", CURRENT_continent);
        const resp = await apiPost("assets/get_data.php", formData);

        if(!resp.success || myRequest != countryListRequest)
            return;
        renderCountryList(resp.countries);

    } catch(err) {
        showToast("Could not load country list: " + err.message);
        document.getElementById("countryList").innerHTML =
            '<div class="loading-message">Could not load the country list.</div>';
    }
}

// build the list with DOM methods
function renderCountryList(countries) {

    const list = document.getElementById("countryList");
    list.replaceChildren();
    let isMain = true;

    for(const cur of countries) {

        // main/sub divider
        if(cur[0] === "---") {
            isMain = false;
            const hr = document.createElement("hr");
            hr.id = "mainSubDivider";
            list.appendChild(hr);
            continue;
        }

        // first column = display name
        const name = cur[0];
        const entry = document.createElement("div");
        entry.id = name;
        // all columns: display name + alias + sanitized
        entry.dataset.search = cur.join("");
        entry.dataset.cat = isMain ? "main" : "sub";
        entry.textContent = name;
        if(name == CURRENT_country)
            entry.classList.add("country-current");
        entry.addEventListener("click", () => loadCountry(name));
        list.appendChild(entry);
    }

    document.getElementById(CURRENT_country)?.scrollIntoView({ block: "center", behavior: "smooth" });
}

let initialFilterRequest = 0;

async function loadInitialFilter() {

    const myRequest = ++initialFilterRequest;

    try {
        const formData = new FormData();
        formData.append("load", "initialfilter");
        formData.append("country", CURRENT_country);
        let resp = await apiPost("assets/get_data.php", formData);

        if(myRequest != initialFilterRequest)
            return;
        if(!resp.success) {
            showToast(resp.error || "Could not load filter data");
            resp = { categories: [], years: {}, denominations: {}, currencies: {} };
        }

        // categories + decades/years
        CACHE_categories = resp.categories;
        setFilterCategories();
        CACHE_years = resp.years;
        setFilterDecades();
        setFilterYears();

        // denomination/currency dropdowns for the current category
        CACHE_denominations = resp.denominations;
        CACHE_currencies = resp.currencies;
        CURRENT_denomination = "";
        CURRENT_currency = "";
        setFilterDenominations(currentCategory());
        setFilterCurrencies(currentCategory());

        // reset the remaining filters
        CURRENT_searchtxt = "";
        document.getElementById("filterText").value = "";
        document.getElementById("stampHave").checked = false;
        document.getElementById("stampHaveNot").checked = false;

    } catch(err) {
        showToast("Could not load filter data: " + err.message);
    }

    // a search text from a shared link is applied after the reset above
    if(ONLOAD_searchtxt !== null) {
        CURRENT_searchtxt = ONLOAD_searchtxt;
        document.getElementById("filterText").value = ONLOAD_searchtxt;
        ONLOAD_searchtxt = null;
    }

    // the onload selection has been applied - stop honouring it from now on
    ONLOAD_year_from = -1;
    ONLOAD_year_to = -1;
    ONLOAD_category = "Postage stamps";
}

// move to the next (+1) / previous (-1) country in the current list (keyboard shortcuts "n" / "p")
// skips entries hidden by the country search box and wraps around at the ends of the list
function stepCountry(delta) {
    const entries = [...document.querySelectorAll("#countryList [data-cat]")]
        .filter(e => !e.classList.contains("hidden"));
    if(!entries.length) return;
    let idx = entries.findIndex(e => e.classList.contains("country-current"));
    // current country not in this list
    if(idx == -1)
        idx = delta > 0 ? -1 : 0;
    const next = entries[(idx + delta + entries.length) % entries.length];
    if(next) loadCountry(next.textContent);
}
