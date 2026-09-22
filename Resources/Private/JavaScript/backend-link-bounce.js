const link = document.getElementById('aisuite-backend-link');
if (link instanceof HTMLAnchorElement) {
    window.location.replace(link.href);
}
