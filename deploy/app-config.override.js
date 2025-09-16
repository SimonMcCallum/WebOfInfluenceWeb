/* Runtime config for production without .htaccess rewrites.
   Use index.php front controller with ?route=... to reach endpoints.
*/
window.__APP_CONFIG__ = {
  // Use the php-api under /webofinfluence in production
  API_BASE: '/webofinfluence/php-api/index.php'
};
