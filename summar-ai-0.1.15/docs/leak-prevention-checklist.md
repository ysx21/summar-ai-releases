# Leak Prevention Checklist

Use the steps below to confirm frontend behavior and error handling remain safe.

## Quick checks

1) Verify no API key is rendered in HTML or JS.
   - View the page source of a post with the shortcode.
   - Search the source for `sk-` and confirm no matches.

2) Verify the frontend calls the WordPress REST endpoint.
   - Open DevTools → Network.
   - Trigger a search and confirm requests go to `/wp-json/summarai/v1/generate`.
   - Confirm there are no requests to `https://api.openai.com/*`.

3) Verify error responses are safe.
   - Temporarily set an invalid API key.
   - Trigger a search and confirm the response contains a generic error without headers or keys.
