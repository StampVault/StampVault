/*******************************************************************/
/*******************************************************************/
// all event wiring: controls, navigation, keyboard, delegation, uploads.

/*******************************************************************/
// delegated stamp handlers
// one listener for all dynamically created stamp entries

const stampsContent = document.getElementById("stampsContent");

stampsContent.addEventListener("mouseover", ev => {
    const el = ev.target.closest("[data-action='preview']");
    if(el) previewStamp(el.dataset.stampid);
});

stampsContent.addEventListener("click", ev => {
    const el = ev.target.closest("[data-action]");
    if(!el) return;
    const id = el.dataset.stampid;
    switch(el.dataset.action) {
        case "preview":  modalPreviewStamp(); break;
        case "actions":  if(!UI_readonly) showActions(id); break;
        case "state":    if(!UI_readonly) toggleStatus(parseInt(el.dataset.state), id); break;
        case "notesOk":  handleNotesKey(id, 13); break;
    }
});

// notes: save on every keystroke (debounced), close on Enter/Escape
stampsContent.addEventListener("input", ev => {
    if(ev.target.matches("input[id^='notes_']"))
        toggleStatus(4, ev.target.dataset.stampid);
});
stampsContent.addEventListener("keydown", ev => {
    if(ev.target.matches("input[id^='notes_']"))
        handleNotesKey(ev.target.dataset.stampid, ev.keyCode);
});

/*******************************************************************/
// continent selector

document.getElementById("continentSelector").addEventListener("change", function() {
    // save pending work first
    flushPendingChanges();
    CURRENT_continent = this.value;
    CURRENT_country = defaultCountries[this.value];
    loadCountryList();
    loadInitialFilter();
    setStampsCookie("all");
    updateUrlState();
    getStamps();
});

/*******************************************************************/
// country search

document.getElementById("searchCountry").addEventListener("input", function() {
    const txt = this.value.toLowerCase();
    let haveMain = false;
    for(const entry of document.getElementById("countryList").children) {
        if(entry.dataset.search === undefined) continue;
        const show = entry.dataset.search.toLowerCase().includes(txt);
        entry.classList.toggle("hidden", !show);
        if(show && entry.dataset.cat == "main") haveMain = true;
    }
    // hide the main/sub divider when no main entry matches
    document.getElementById("mainSubDivider")?.classList.toggle("hidden", !haveMain);
    document.getElementById("countryList").scrollTo(0, 0);
});

/*******************************************************************/
// category

eleFilterCategory.addEventListener("change", function() {
    // changes belong to the OLD category
    flushPendingChanges();
    CURRENT_denomination = "";
    CURRENT_currency = "";
    CURRENT_searchtxt = "";
    setFilterDecades();
    setFilterYears();
    updateStamps();
    setFilterDenominations(this.value);
    setFilterCurrencies(this.value);
    setStampsCookie("all");
    updateUrlState();
});

/*******************************************************************/
// decade/year navigation
// both lists run newest->oldest with a placeholder at index 0

function applyYearFilter() {
    setStampsCookie("years");
    updateUrlState();
    updateStamps();
}

// shift the decade index by delta (older = +1, newer = -1), wrapping between the first and last real decade (index 0 is the "all years" placeholder)
// returns false if there is nothing to switch
function shiftDecadeIndex(delta) {
    const sel = eleFilterDecades;
    const n = sel.options.length;
    if(n < 2) return false;
    let i = sel.selectedIndex + delta;
    sel.selectedIndex = i < 1 ? n - 1 : (i > n - 1 ? 1 : i);
    setFilterYears();
    return true;
}

// step one year (delta +1 = newer, -1 = older), crossing decade boundaries and wrapping at the ends
// the "---" placeholder jumps to the far end
function stepYears(delta) {

    const sel = eleFilterYears;

    // no individual years -> move decades
    if(sel.options.length < 2) {
        if(shiftDecadeIndex(delta > 0 ? -1 : 1)) applyYearFilter();
        return;
    }
    // "---" -> jump to the far end
    if(sel.selectedIndex == 0) {
        sel.selectedIndex = (delta > 0) ? sel.options.length - 1 : 1;
        applyYearFilter();
        return;
    }

    // newest year has the lowest index
    const i = sel.selectedIndex - delta;
    if(i >= 1 && i <= sel.options.length - 1) {
        sel.selectedIndex = i;
    } else if(delta > 0) {
        // past the newest year of the decade -> next decade, its oldest year
        if(!shiftDecadeIndex(-1)) return;
        sel.selectedIndex = eleFilterYears.options.length - 1;
    } else {
        // past the oldest year of the decade -> previous decade, its newest year
        if(!shiftDecadeIndex(1)) return;
        sel.selectedIndex = 1;
    }

    applyYearFilter();

}

document.getElementById("decadePrev").addEventListener("click", () => { if(shiftDecadeIndex(1))  applyYearFilter(); });  // older
document.getElementById("decadeNext").addEventListener("click", () => { if(shiftDecadeIndex(-1)) applyYearFilter(); }); // newer
document.getElementById("yearPrev").addEventListener("click", () => stepYears(-1));  // one year older
document.getElementById("yearNext").addEventListener("click", () => stepYears(1));   // one year newer

// direct selection changes
eleFilterDecades.addEventListener("change", () => { setFilterYears(); applyYearFilter(); });
eleFilterYears.addEventListener("change", applyYearFilter);

/*******************************************************************/
// denomination / currency / text / ownership

document.getElementById("denominationSelector").addEventListener("change", function() {
    CURRENT_denomination = this.value;
    updateStamps();
});

document.getElementById("currencySelector").addEventListener("change", function() {
    CURRENT_currency = this.value;
    updateStamps();
});

document.getElementById("filterText").addEventListener("input", function() {
    CURRENT_searchtxt = this.value;
    updateUrlState();
    updateStamps();
});

document.getElementById("stampHave").addEventListener("click", updateStamps);
document.getElementById("stampHaveNot").addEventListener("click", updateStamps);

/*******************************************************************/
// filter reset buttons
// setting selectedIndex and dispatching the matching event reuses the normal change handlers - no duplicated reset logic.

document.getElementById("filterClearDecade").addEventListener("click", () => {
    eleFilterDecades.selectedIndex = 0;
    eleFilterDecades.dispatchEvent(new Event("change"));
});
document.getElementById("filterClearYear").addEventListener("click", () => {
    eleFilterYears.selectedIndex = 0;
    eleFilterYears.dispatchEvent(new Event("change"));
});
document.getElementById("filterClearDenomination").addEventListener("click", () => {
    document.getElementById("denominationSelector").selectedIndex = 0;
    document.getElementById("denominationSelector").dispatchEvent(new Event("change"));
});
document.getElementById("filterClearCurrency").addEventListener("click", () => {
    document.getElementById("currencySelector").selectedIndex = 0;
    document.getElementById("currencySelector").dispatchEvent(new Event("change"));
});
document.getElementById("filterClearText").addEventListener("click", () => {
    const el = document.getElementById("filterText");
    el.value = "";
    el.dispatchEvent(new Event("input"));
});

/*******************************************************************/
// sidebar / modal / menus

document.getElementById("menuButton").addEventListener("click", () => {
    document.getElementById("sidebarContent").classList.toggle("small-hidden");
});

/*******************************************************************/
// theme / back-to-top

// the saved theme class is already on <html> (head script); this only syncs the button label
// clicking toggles and persists the choice
setTheme(document.documentElement.classList.contains("dark"));
document.getElementById("themeToggle").addEventListener("click", toggleTheme);

const backToTop = document.getElementById("backToTop");
backToTop.addEventListener("click", () => window.scrollTo({ top: 0, behavior: "smooth" }));

document.getElementById("previewImage").addEventListener("click", modalPreviewStamp);
document.getElementById("modalPreview").addEventListener("click", hideModalPreviewStamp);
// keyboard shortcuts, ignored while typing in a field and with modifiers
//   /    focus the country search
// n / p  next / previous country
//   t    scroll to the top
//   b    scroll to the bottom
//   d    toggle dark mode
//  Esc   close the preview
//   ?    show shortcuts info
document.addEventListener("keydown", ev => {
    if(ev.key == "Escape") { hideModalPreviewStamp(); hideWelcome(); return; }
    if(ev.ctrlKey || ev.metaKey || ev.altKey) return;
    if(ev.target.matches("input, select, textarea")) return;
    switch(ev.key) {
        case "/":  ev.preventDefault(); document.getElementById("searchCountry").focus(); break;
        case "n":  stepCountry(1);  break;
        case "p":  stepCountry(-1); break;
        case "t":  window.scrollTo({ top: 0, behavior: "smooth" }); break;
        case "b":  window.scrollTo({ top: document.body.scrollHeight, behavior: "smooth" }); break;
        case "d":  toggleTheme(); break;
        case "?":  showWelcome(); break;
    }
});

document.getElementById("extraActionsButton").addEventListener("click", () => {
    document.getElementById("extraActions").style.display = "block";
    document.body.style.overflow = "hidden";
});
document.getElementById("extraActions").addEventListener("click", () => {
    document.getElementById("extraActions").style.display = "none";
    document.body.style.overflow = "auto";
});

// stamp actions menu
document.getElementById("editNotes").addEventListener("click", showTakeNotes);
document.getElementById("customImg").addEventListener("click", handleCustomImage);
document.getElementById("stampActionsOverlay").addEventListener("click", hideActions);

// statistics button
document.getElementById("gotoStats").addEventListener("click", () => {
    window.location = "stats.php";
});

// "Log out": ends the session SERVER-side
document.getElementById("logoutButton").addEventListener("click", async () => {
    try {
        await apiPost("assets/logout.php", new FormData());
    } catch(err) {
        // fall through -> login.php redirects anyway
    }
    window.location = "login.php";
});

// "Copy link": copies the URL of the current view into the clipboard
document.getElementById("shareLink").addEventListener("click", async () => {
    try {
        if(navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(window.location.href);
        // fallback for plain http / older browsers
        } else {
            const tmp = document.createElement("textarea");
            tmp.value = window.location.href;
            document.body.appendChild(tmp);
            tmp.select();
            document.execCommand("copy");
            tmp.remove();
        }
        showToast("Link copied to the clipboard.");
    } catch(err) {
        showToast("Could not copy the link: " + err.message);
    }
});

/*******************************************************************/
// read-only toggle
// toggled SERVER-side now: the write endpoints reject changes while active

document.getElementById("extraReadonly").addEventListener("click", async () => {

    try {
        const resp = await apiPost("assets/set_readonly.php", new FormData());
        UI_readonly = resp.readonly;
    } catch(err) {
        showToast("Could not toggle read-only mode: " + err.message);
        return;
    }

    if(UI_readonly) {
        TITLE_SUFFIX = " (read-only)";
    } else {
        TITLE_SUFFIX = "";
    }
    // keeps the ?read= flag alongside the view parameters
    updateUrlState();
    document.title = CURRENT_country + " | " + PROJECT_NAME + TITLE_SUFFIX;

    // re-render so the readonly styling is applied everywhere
    updateStamps();
});

/*******************************************************************/
// custom image upload

const eleFileUpload = document.getElementById("customImageUpload");
eleFileUpload.addEventListener("change", async () => {

    const file = eleFileUpload.files[0];
    if(!file) return;
    // allow picking the same file again
    eleFileUpload.value = "";

    const id = document.getElementById("stampActionsMenu").dataset.stampid;
    // shared with drag & drop
    await uploadCustomImage(id, file);
});

/*******************************************************************/
// drag & drop upload
// dropping an image onto a stamp card uploads it as that stamp's custom image
// dragover MUST call preventDefault(), otherwise the browser would open the dropped file instead of allowing the drop

stampsContent.addEventListener("dragover", ev => {
    const el = ev.target.closest(".stamps-entry");
    if(el && !UI_readonly && ev.dataTransfer?.types?.includes("Files")) {
        ev.preventDefault();
        el.classList.add("drop-target");
    }
});
stampsContent.addEventListener("dragleave", ev => {
    const el = ev.target.closest(".stamps-entry");
    if(el) el.classList.remove("drop-target");
});
stampsContent.addEventListener("drop", async ev => {

    const el = ev.target.closest(".stamps-entry");
    if(!el) return;
    ev.preventDefault();
    el.classList.remove("drop-target");
    if(UI_readonly) return;

    const file = ev.dataTransfer.files[0];
    if(!file) return;
    if(!file.type.startsWith("image/")) {
        showToast("That file is not an image.");
        return;
    }
    const id = el.id.replace(/^stampEntry_/, "");
    await uploadCustomImage(id, file);
});

// never let the browser navigate away when a file is dropped outside a card
window.addEventListener("dragover", ev => {
    if(ev.dataTransfer?.types?.includes("Files") && !ev.target.closest?.(".stamps-entry"))
        ev.preventDefault();
});
window.addEventListener("drop", ev => {
    if(ev.dataTransfer?.types?.includes("Files") && !ev.target.closest?.(".stamps-entry"))
        ev.preventDefault();
});

/*******************************************************************/
// infinite scroll

// load more stamps near the bottom of the page
// passive listener (we never preventDefault)
// showStamps() itself guards against re-entrancy
window.addEventListener("scroll", () => {
    const doc = document.documentElement;
    if(Math.abs(doc.scrollHeight - window.innerHeight - window.scrollY) < 500)
        showStamps();
    backToTop.classList.toggle("show", window.scrollY > 600);
}, { passive: true });

/*******************************************************************/
// window resize

// debounced: recomputing the grid for every intermediate size is expensive.
let resizeTimer;
window.addEventListener("resize", () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(adjustStampWidth, 100);
});

// fit the stamp grid to the available width: base width per column, then distribute the remainder evenly
// also recentres the preview modal image
function adjustStampWidth() {
    const layoutWidth = document.getElementById("stampsContent").offsetWidth;
    const baseWidth = (layoutWidth > 400 ? 200 : 150);
    const numele = Math.floor(layoutWidth / (baseWidth + 1));
    if(numele < 1) return;
    const widthSoFar = numele * (baseWidth + 1);
    const newWidth = baseWidth + Math.floor((layoutWidth - widthSoFar) / numele);
    document.documentElement.style.setProperty("--stamp-entry-width", `${newWidth}px`);
    document.getElementById("modalPreviewImage").style.maxWidth = `${layoutWidth}px`;
    document.getElementById("modalPreviewImageContainer").style.maxWidth = `${layoutWidth}px`;

    // reposition the actions menu if it is open
    if(document.getElementById("stampActionsOverlay").style.display == "block") {
        showActions(document.getElementById("stampActionsMenu").dataset.stampid);
    }
}
adjustStampWidth();

/*******************************************************************/
// welcome screen
// overview of how to use the app: shown once on the first visit (localStorage flag), reopened with the "?" toolbar button or the "?" key

function showWelcome() {
    document.getElementById("welcomeOverlay").classList.remove("hidden");
    document.body.style.overflow = "hidden";
    document.getElementById("welcomeClose").focus();
}
function hideWelcome() {
    document.getElementById("welcomeOverlay").classList.add("hidden");
    document.body.style.overflow = "auto";
    localStorage.setItem("welcomeSeen", "1");
}

document.getElementById("welcomeButton").addEventListener("click", showWelcome);
document.getElementById("welcomeOverlay").addEventListener("click", ev => {
    if(ev.target.classList.contains("welcome-overlay-bg")) hideWelcome();
});
document.getElementById("welcomeClose").addEventListener("click", hideWelcome);

// first visit -> show the overview once
if(localStorage.getItem("welcomeSeen") != "1") showWelcome();
