/* Runtime config served as a sibling to index.html.
   API_BASE is a same-origin path; nginx routes /php-api to the PHP container.
*/
window.__APP_CONFIG__ = {
  API_BASE: '/php-api/index.php'
};
