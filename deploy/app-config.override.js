/* Runtime config for production without .htaccess rewrites.
   Use index.php front controller with ?route=... to reach endpoints.
*/

/*
Old:
window.__APP_CONFIG__ = {
  // Example full URL: https://ludogogy.co.nz/php-api/index.php
  API_BASE: '/php-api/index.php'
};*/

window.__APP_CONFIG__ = {
  API_BASE: '/webofinfluence/api/index.php'
};