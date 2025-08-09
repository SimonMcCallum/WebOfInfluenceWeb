/* Runtime-configurable settings injected before the app loads.
   This file is served as-is to the browser (Vite copies public/* to dist/).
   You can overwrite this file during deploy to point the frontend at the correct API.
*/
window.__APP_CONFIG__ = {
  // Compute API base from the first URL segment so it works under subpaths like /webofinfluence
  API_BASE: (function() {
    try {
      var segments = (window.location.pathname || '/').split('/').filter(Boolean);
      var base = segments.length > 0 ? '/' + segments[0] + '/' : '/';
      var baseNoSlash = base.replace(/\/$/, '');
      return baseNoSlash + '/api';
    } catch (e) {
      return '/api';
    }
  })()
};
