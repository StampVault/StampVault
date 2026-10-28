// stamp data loading, filtering, rendering and ownership changes

var CACHE_stamps = [];
var CACHE_states = [];

// request token against stale responses
let stampsRequest = 0;

async function getStamps() {

    const myRequest = ++stampsRequest;

    // skeleton placeholder cards give immediate feedback while the data loads
    document.getElementById("stampsContent").innerHTML = skeletonCards();
    document.body.style.overflow = "hidden";
    previewStamp("");

    try {
        const formData = new FormData();
        formData.append("country", CURRENT_country);
        const resp = await apiPost("assets/get_stamps.php", formData);

        if(myRequest != stampsRequest)
            return;

        if(resp.success) {
            CACHE_stamps = resp.stamps;
            CACHE_states = resp.states;
            updateStamps();
        } else {
            CACHE_stamps = [];
            CACHE_states = [];
            showCountryNotSupported();
        }
        setTimeout(adjustStampWidth, 100);

    } catch(err) {
        showToast("Could not load stamps: " + err.message);
        // never silently keep the previously loaded country's stamps
        showCountryNotSupported("Could not load the stamps of this country: " + err.message);
    } finally {
        if(myRequest == stampsRequest)
            document.body.style.overflow = "auto";
    }
}

// placeholder cards shown in the grid while getStamps() waits for the server
function skeletonCards() {
    let txt = "";
    for(let i = 0; i < 10; ++i)
        txt += `<div class="stamps-entry skeleton">
            <div class="skeleton-block skeleton-image"></div>
            <div class="skeleton-block skeleton-line"></div>
            <div class="skeleton-block skeleton-line skeleton-short"></div>
        </div>`;
    return txt;
}

function showCountryNotSupported(msg) {
    stampsVisible = 0;
    stampsTotal = 0;
    document.getElementById("resultCounterText").textContent = "";
    // without msg: data for the country is missing; with msg: load error
    document.getElementById("stampsContent").innerHTML = `
        <div class='stamps-nothing-message'>
            ${msg ? escapeHtml(msg)
                  : "The data for this country is not available... you might have to retrieve the data first.<br><br>Check the project page for details on how to do this."}
        </div>`;
}

/*******************************************************************/
// filtering

var current_stamps_data = [];
function updateStamps() {

    current_stamps_data = [];

    const sel = eleFilterDecades.selectedOptions[0];
    let cur_year_from = sel ? sel.getAttribute("year-from") : -1;
    let cur_year_to   = sel ? sel.getAttribute("year-to")   : -1;
    // a specific year is selected
    if(eleFilterYears.selectedIndex > 0) {
        cur_year_from = eleFilterYears.value;
        cur_year_to = eleFilterYears.value;
    }

    const category = currentCategory();
    const data = CACHE_stamps[category] || {};

    for(const id of Object.keys(data)) {

        const item = data[id];

        // year range (skip the check entirely when "all years" is selected)
        if(cur_year_from != -1 && (item.year < cur_year_from || item.year > cur_year_to))
            continue;

        // denomination / currency (case-insensitive exact match)
        if(CURRENT_denomination != "" && item.denomination.toLowerCase() != CURRENT_denomination.toLowerCase())
            continue;
        if(CURRENT_currency != "" && item.currency.toLowerCase() != CURRENT_currency.toLowerCase())
            continue;

        // free text in title, description or keywords
        const txt = CURRENT_searchtxt.toLowerCase();
        if(CURRENT_searchtxt != "" && !item.title.toLowerCase().includes(txt)
            && !item.description.toLowerCase().includes(txt)
            && !item.keywords.join("\n").toLowerCase().includes(txt))
            continue;

        // ownership filter (have / have not)
        if(!(category in CACHE_states)) CACHE_states[category] = {};
        if(!(id in CACHE_states[category])) CACHE_states[category][id] = [0,0,0,0,""];
        if(document.getElementById("stampHave").checked || document.getElementById("stampHaveNot").checked) {
            const sum = CACHE_states[category][id].slice(0,4).reduce((a, b) => a + b, 0);
            if((document.getElementById("stampHaveNot").checked && sum > 0) ||
               (document.getElementById("stampHave").checked && sum == 0))
                continue;
        }

        current_stamps_data.push(id);
    }

    stampsVisible = 0;
    stampsTotal = current_stamps_data.length;
    document.getElementById("stampsContent").innerHTML = "";

    // result counter above the grid: matching vs. total stamps of the category
    const totalInCategory = Object.keys(CACHE_stamps[currentCategory()] || {}).length;
    document.getElementById("resultCounterText").textContent =
        current_stamps_data.length + " of " + totalInCategory + " stamps";

    showStamps();
    window.scrollTo(0, 0);
}

/*******************************************************************/
// rendering

// stamps already on screen
var stampsVisible = 0;
// stamps matching the current filter
var stampsTotal = 0;
// how many to add per scroll step
var stampsPerStep = 100;
// re-entrancy guard for the scroll handler
var stampsLoading = false;

function showStamps() {

    if(stampsLoading || stampsVisible >= stampsTotal)
        return;
    stampsLoading = true;

    const category = currentCategory();
    const data = CACHE_stamps[category];
    if(!data) { stampsLoading = false; return; }

    let html = "";
    let currentGroup = "";
    let currentData = [];
    let found = 0;

    // continue from where the last step stopped
    // a set (group) is never split across steps: we only stop at group boundaries
    for(let i = stampsVisible; i < current_stamps_data.length; ++i) {

        const id = current_stamps_data[i];
        const item = data[id];
        if(!item) continue;

        if(currentGroup != item.group) {
            if(currentData.length) {
                html += composeStampsEntry(currentData);
                currentData = [];
                // stop at a group boundary
                if(found >= stampsPerStep)
                    break;
            }
            currentGroup = item.group;
        }
        currentData.push(id);
        found++;
    }
    if(currentData.length)
        html += composeStampsEntry(currentData);

    stampsVisible += found;

    if(!stampsTotal)
        html += `<div class='stamps-nothing-message'>No stamps found for the currently selected filter...</div>`;

    document.getElementById("stampsContent").insertAdjacentHTML("beforeend", html);
    stampsLoading = false;
}

// one "set" of stamps: several entries sharing a group
// all dynamic values run through escapeHtml()
// behaviour is attached via // data-action attributes and delegated listeners
function composeStampsEntry(ids) {

    const category = currentCategory();
    let txt = "";

    ids.forEach((id, index) => {

        const item = CACHE_stamps[category][id];
        if(!item) return;

        // image: custom upload > stock image > placeholder
        // "+ vignette" entries reuse the previous stamp's image
        let imgpath = item.customimgpath || item.imgpath;
        if(!imgpath && index > 0) {
            const prev = CACHE_stamps[category][ids[index-1]];
            if(item.denomination == prev.denomination && item.group == prev.group
               && item.description == "+ vignette")
                imgpath = prev.customimgpath || prev.imgpath;
        }
        if(!imgpath) imgpath = "assets/no-stamp-image.png";

        // ownership state + notes
        const status = (CACHE_states[category] || {})[id] || [0,0,0,0,""];
        const have = status.slice(0,4).some(v => v);
        const notes = status[4] || "";

        // info line: description · perforation · size
        const extra = [item.description,
                       item.perforation ? "perf.: " + item.perforation : "",
                       item.size]
                      .filter(Boolean).map(escapeHtml).join(" &bull; ");
        const denom = escapeHtml([item.denomination, item.currency].filter(Boolean).join(" "));

        txt += `<div id='stampEntry_${id}' class='stamps-entry ${have ? "have-stamp" : "dont-have-stamp"} ${index < ids.length-1 ? "inside-group" : "end-group"}'>
            <div class='stamps-entry-image' data-action='preview' data-stampid='${id}'>
                <img id="stampImg_${id}" src='${imgpath}' alt='${escapeHtml(item.title)}' loading='lazy'>
                <div class='stamp-entry-edit-notes${UI_readonly ? " readonly" : ""}' data-action='actions' data-stampid='${id}'>
                    <img src='assets/actions.png' alt='edit'>
                </div>
                <div class='stamp-entry-take-notes hidden' id='takeNotes_${id}'>
                    <input type="text" id="notes_${id}" data-stampid='${id}' aria-label='note for this stamp'>
                    <button data-action='notesOk' data-stampid='${id}' aria-label='save note'><img src='assets/checkmark.png' alt='save'></button>
                </div>
                <div id="showNotes_${id}" class='stamp-entry-show-notes${notes === "" ? " hidden" : ""}'>${escapeHtml(notes)}</div>
            </div>
            <div class='stamps-entry-info'>
                <div class='stamp-entry-info-title'><b>${escapeHtml(item.date)}</b> &nbsp;&bull;&nbsp; <b>${denom}</b><br>${escapeHtml(item.title)}</div>
                <div class='stamp-entry-info-description'>${extra}</div>
            </div>
            <div class='state-toggle-row'>
                <button id='stateBtn0_${id}' class='state-toggle${status[0] ? " owned" : ""}${UI_readonly ? " readonly" : ""}' data-action='state' data-state='0' data-stampid='${id}' aria-label='toggle mint' title='mint'><img src='assets/state_mint.png' alt='mint'></button>
                <button id='stateBtn1_${id}' class='state-toggle${status[1] ? " owned" : ""}${UI_readonly ? " readonly" : ""}' data-action='state' data-state='1' data-stampid='${id}' aria-label='toggle unused' title='unused'><img src='assets/state_unused.png' alt='unused'></button>
                <button id='stateBtn2_${id}' class='state-toggle${status[2] ? " owned" : ""}${UI_readonly ? " readonly" : ""}' data-action='state' data-state='2' data-stampid='${id}' aria-label='toggle used' title='used'><img src='assets/state_used.png' alt='used'></button>
                <button id='stateBtn3_${id}' class='state-toggle${status[3] ? " owned" : ""}${UI_readonly ? " readonly" : ""}' data-action='state' data-state='3' data-stampid='${id}' aria-label='toggle first day cover' title='first day cover'><img src='assets/state_fdc.png' alt='first day cover'></button>
            </div>
        </div>`;
    });

    return txt;
}

/*******************************************************************/
// previews

function previewStamp(id) {

    if(id == "") {
        document.getElementById("previewImage").style.display = "none";
        document.getElementById("previewTitle").innerHTML = "";
        document.getElementById("previewTags").innerHTML = "";
        document.getElementById("previewDescription").innerHTML = "";
        return;
    }

    const item = CACHE_stamps[currentCategory()][id];
    if(!item) return;

    let imgpath = item.customimgpath || item.imgpath || "assets/no-stamp-image.png";

    document.getElementById("previewImage").style.display = "block";
    document.getElementById("previewImage").src = imgpath;
    document.getElementById("modalPreviewImage").src = imgpath;

    const ttle = `<b>${escapeHtml(item.date)}</b> - ${escapeHtml(item.title)}`;
    document.getElementById("previewTitle").innerHTML = ttle;
    document.getElementById("modalPreviewInfoTitle").innerHTML = ttle;

    // short info if there is no real description
    let desc;
    if(item.description == "" && item.keywords.join().length < 20) {
        desc = `<b>${escapeHtml(item.denomination)} ${escapeHtml(item.currency)}</b> - <small>${escapeHtml(item.keywords.join(", "))}</small>`;
        document.getElementById("previewTags").innerHTML = "";
        document.getElementById("modalPreviewInfoTags").innerHTML = "";
    } else {
        desc = `<b>${escapeHtml(item.denomination)} ${escapeHtml(item.currency)}</b>`
             + (item.description == "" ? "" : ` <small> - ${escapeHtml(item.description)}</small>`);
        document.getElementById("previewTags").innerHTML = escapeHtml(item.keywords.join(", "));
        document.getElementById("modalPreviewInfoTags").innerHTML = escapeHtml(item.keywords.join(", "));
    }
    if(item.perforation != "")
        desc += `<small> - <b>perforation:</b> ${escapeHtml(item.perforation)}</small>`;
    document.getElementById("previewDescription").innerHTML = desc;
    document.getElementById("modalPreviewInfoDetails").innerHTML = desc;
}

function modalPreviewStamp() {
    document.getElementById("modalPreview").style.display = "block";
    document.body.style.overflow = "hidden";
}

function hideModalPreviewStamp() {
    document.getElementById("modalPreview").style.display = "none";
    document.body.style.overflow = "auto";
}

/*******************************************************************/
// actions menu

function showActions(id) {

    const eleMenu = document.getElementById("stampActionsMenu");
    const eleOver = document.getElementById("stampActionsOverlay");

    // label depends on whether a custom image already exists
    document.getElementById("customImg").innerHTML =
        (CACHE_stamps[currentCategory()][id]?.customimgpath != "") ? "Remove custom image" : "Upload custom image";

    eleMenu.dataset.stampid = id;

    const rect = document.getElementById("stampEntry_" + id).getBoundingClientRect();
    eleMenu.style.left = `${rect.left}px`;
    eleMenu.style.top = `${rect.top + 5 + window.scrollY}px`;
    eleMenu.style.display = "inline-block";
    eleOver.style.display = "block";
}

function hideActions() {
    document.getElementById("stampActionsMenu").style.display = "none";
    document.getElementById("stampActionsOverlay").style.display = "none";
}

function showTakeNotes() {

    hideActions();

    const category = currentCategory();
    const id = document.getElementById("stampActionsMenu").dataset.stampid;
    const notes = document.getElementById(`notes_${id}`);

    notes.value = ((CACHE_states[category] || {})[id] || [0,0,0,0,""])[4];
    notes.disabled = false;
    document.getElementById(`showNotes_${id}`).classList.add("hidden");
    document.getElementById(`takeNotes_${id}`).classList.remove("hidden");
    notes.focus();
}

// upload one image file for one stamp - used by the file picker AND by drag & drop
// the server converts it to JPEG (GD) and returns the new file's URL (sanitized name, pre-encoded)
async function uploadCustomImage(id, file) {
    document.getElementById("busyIndicator").classList.remove("hidden");
    try {
        const formData = new FormData();
        formData.append("country", CURRENT_country);
        formData.append("id", id);
        formData.append("action", "addImage");
        formData.append("image", file);
        const resp = await apiPost("assets/handle_image.php", formData);
        if(!resp.success)
            throw new Error(resp.error);

        const item = CACHE_stamps[currentCategory()][id];
        if(item) item.customimgpath = resp.path;
        const img = document.getElementById(`stampImg_${id}`);
        if(img) img.src = resp.path + "?nocache=" + Math.random();

    } catch(err) {
        showToast("Could not upload image: " + err.message);
    } finally {
        document.getElementById("busyIndicator").classList.add("hidden");
    }
}

async function handleCustomImage() {

    hideActions();

    const id = document.getElementById("stampActionsMenu").dataset.stampid;
    const item = CACHE_stamps[currentCategory()][id];

    if(item.customimgpath == "") {
        // no custom image yet -> open the file picker
        document.getElementById("customImageUpload").click();
        return;
    }

    // custom image exists -> remove it
    try {
        const formData = new FormData();
        formData.append("country", CURRENT_country);
        formData.append("id", id);
        formData.append("action", "removeImage");
        const resp = await apiPost("assets/handle_image.php", formData);
        if(!resp.success)
            throw new Error(resp.error);

        item.customimgpath = "";
        document.getElementById(`stampImg_${id}`).src =
            (item.imgpath != "" ? item.imgpath : "assets/no-stamp-image.png") + "?nocache=" + Math.random();

    } catch(err) {
        showToast("Could not remove image: " + err.message);
    }
}

// close the note editor on Enter (save) / Escape (cancel)
// saving happens automatically via toggleStatus(4, ...) on every input
function handleNotesKey(id, keyCode) {
    if(keyCode == 27 || keyCode == 13) {
        document.getElementById(`notes_${id}`).disabled = true;
        const cur_notes = (CACHE_states[currentCategory()] || {})[id]?.[4] || "";
        if(cur_notes != "") {
            const shown = document.getElementById(`showNotes_${id}`);
            shown.textContent = cur_notes;
            shown.classList.remove("hidden");
        }
        document.getElementById(`takeNotes_${id}`).classList.add("hidden");
    }
}

/*******************************************************************/
// status changes

var changesToSend = {};
var changesToSendTimer;

// cat 0-3: mint/unused/used/fdc toggle; cat 4: notes update.
function toggleStatus(cat, id) {

    const category = currentCategory();
    document.getElementById("busyIndicator").classList.remove("hidden");

    if(!(category in CACHE_states)) CACHE_states[category] = {};
    if(!(id in CACHE_states[category])) CACHE_states[category][id] = [0,0,0,0,""];

    if(cat < 4) {

        // flip the value and update the icon
        CACHE_states[category][id][cat] = CACHE_states[category][id][cat] ? 0 : 1;
        document.getElementById(`stateBtn${cat}_${id}`).classList.toggle("owned", CACHE_states[category][id][cat]);

        // repaint the entry: "have" if ANY of the four states is on
        const have = CACHE_states[category][id].slice(0,4).some(v => v);
        document.getElementById(`stampEntry_${id}`).classList.toggle("have-stamp", have);
        document.getElementById(`stampEntry_${id}`).classList.toggle("dont-have-stamp", !have);

    } else {
        CACHE_states[category][id][4] = document.getElementById(`notes_${id}`).value;
    }

    changesToSend[id] = CACHE_states[category][id];

    // debounce: many quick clicks become one request
    clearTimeout(changesToSendTimer);
    changesToSendTimer = setTimeout(sendStatusChanges, 500);
}

// Send pending changes NOW
// called before any country/category switch so changes always land in the database they belong to.
function flushPendingChanges() {
    if(Object.keys(changesToSend).length) {
        clearTimeout(changesToSendTimer);
        sendStatusChanges();
    }
}

async function sendStatusChanges() {

    const changes = changesToSend;
    // captured NOW - this request
    const country = CURRENT_country;
    // belongs to this country/category
    const category = currentCategory();
    // buffer new changes while sending
    changesToSend = {};

    try {
        const formData = new FormData();
        formData.append("country", country);
        formData.append("category", category);
        formData.append("changes", JSON.stringify(changes));
        const resp = await apiPost("assets/update_status.php", formData);
        if(!resp.success)
            throw new Error(resp.error);
    } catch(err) {
        showToast("Could not save changes: " + err.message);
        // re-queue only while still on the same country/category, otherwise the changes would be written into the wrong country's database
        if(country == CURRENT_country && category == currentCategory())
            Object.assign(changesToSend, changes);
    } finally {
        document.getElementById("busyIndicator").classList.add("hidden");
    }
}
