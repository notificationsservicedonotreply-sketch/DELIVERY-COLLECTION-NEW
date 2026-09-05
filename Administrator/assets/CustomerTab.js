/*
function openTab(evt, tabId)
{
    let tabs = document.getElementsByClassName("tab-content");

    for (let i = 0; i < tabs.length; i++) {
        tabs[i].style.display = "none";
    }

    let btns = document.getElementsByClassName("tab-btn");

    for (let i = 0; i < btns.length; i++) {
        btns[i].classList.remove("active");
    }

    document.getElementById(tabId).style.display = "block";
    evt.currentTarget.classList.add("active");
}

document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".tab-content").forEach(el => el.style.display = "none");
    document.getElementById("tab_profile").style.display = "block";
});

*/

// Open Tab
function openTab(evt, tabId) {

    // Hide all tab contents
    const tabs = document.querySelectorAll(".tab-content");

    tabs.forEach(tab => {
        tab.style.display = "none";
    });

    // Remove active class from all buttons
    const buttons = document.querySelectorAll(".tab-btn");

    buttons.forEach(btn => {
        btn.classList.remove("active");
    });

    // Show selected tab if it exists
    const selectedTab = document.getElementById(tabId);

    if (selectedTab) {
        selectedTab.style.display = "block";
    } else {
        console.warn(`Tab '${tabId}' not found.`);
    }

    // Add active class safely
    if (evt && evt.currentTarget) {
        evt.currentTarget.classList.add("active");
    }
}

// Initialize
document.addEventListener("DOMContentLoaded", function () {

    // Check if there are any tabs on this page
    const tabs = document.querySelectorAll(".tab-content");

    if (tabs.length === 0) {
        return;
    }

    // Hide all tabs
    tabs.forEach(tab => {
        tab.style.display = "none";
    });

    // Show default tab if it exists
    const defaultTab = document.getElementById("tab_profile");

    if (defaultTab) {
        defaultTab.style.display = "block";
    } else {
        // If no tab_profile exists, show the first tab
        tabs[0].style.display = "block";
    }

    // Activate the first button
    const firstButton = document.querySelector(".tab-btn");

    if (firstButton) {
        firstButton.classList.add("active");
    }

});