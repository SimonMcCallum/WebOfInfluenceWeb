export const API_BASE =
  (window.__APP_CONFIG__ && window.__APP_CONFIG__.API_BASE) ||
  (window.location.hostname === "localhost"
    ? "http://localhost:5050"
    : "https://ludogogy.ac.nz/webofinfluence/api");
