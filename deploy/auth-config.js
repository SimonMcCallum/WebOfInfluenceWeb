/* Temporary auth token + fetch wrapper for demo usage.
   This is served by Vite/Dist from public/ and loads before the app.
   TODO: Replace with a secure token mechanism (e.g., server-issued) before production.
*/
(function () {
  // Store the demo token on the window for easy inspection; replace later.
  window.__AUTH_TOKEN__ = window.__AUTH_TOKEN__ || 'changeme-strong-secret';

  var API_BASE = (window.__APP_CONFIG__ && window.__APP_CONFIG__.API_BASE) || '/php-api/index.php';

  var originalFetch = window.fetch;
  window.fetch = function(resource, init) {
    try {
      var url = typeof resource === 'string' ? resource : (resource && resource.url) || '';
      var shouldAuth =
        (url && (url.indexOf(API_BASE) === 0 || url.indexOf('/php-api') === 0 || url.indexOf('/api') === 0)) ||
        (typeof resource !== 'string' && resource instanceof Request && resource.url && (resource.url.indexOf(API_BASE) === 0));

      if (shouldAuth) {
        init = init || {};
        init.headers = init.headers || {};
        // Normalize headers into a Headers object then back to plain object so we don't lose existing headers.
        var hdrs = new Headers(init.headers);
        if (!hdrs.has('Authorization')) {
          hdrs.set('Authorization', 'Bearer ' + window.__AUTH_TOKEN__);
        }
        // Copy back into a plain object to avoid issues with some environments
        var out = {};
        hdrs.forEach(function(v, k) { out[k] = v; });
        init.headers = out;
      }
    } catch (e) {
      // Non-fatal: fall back to original fetch without header
    }
    return originalFetch.call(this, resource, init);
  };
})();
