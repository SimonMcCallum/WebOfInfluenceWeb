/* Runtime-configurable settings injected before the app loads.
   This file is served as-is to the browser (Vite copies public/* to dist/).
   You can overwrite this file during deploy to point the frontend at the correct API.
*/
window.__APP_CONFIG__ = {
  // Point to PHP API instead of the old Flask API
  API_BASE: '/php-api'
};
