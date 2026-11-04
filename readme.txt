=== Apparel Quote & Mockup (SanMar) ===
Contributors: insationtech
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later

Customers pick a SanMar product, colour and size quantities, upload their logo, see a live mockup on the real product photo, and send a quote request. No prices are shown.

== Setup ==

1. Upload and activate. The SanMar catalog bundled in data/catalog.json.gz (SanMar Data Library, 3 Nov 2026, discontinued items removed) is imported on activation.
2. Add [apparel_quote] to a page (Elementor: Shortcode widget).
   Optional: [apparel_quote category="Caps"] or [apparel_quote style="PC54"].
3. Quote Requests > Settings: set the email address requests go to, and pick which categories to show.

== How it works ==

* Product data and image paths come from the SanMar Data Library. Product photos are loaded from SanMar's image server through a small same-origin cache (wp-content/uploads/iaq-cache) so the mockup can be saved as an image.
* The mockup is drawn in the customer's browser: their actual logo file placed in the print area on SanMar's flat product photo. Customers can drag and resize it. White backgrounds are removed automatically when detected.
* On submit, the shop gets an email with the order details, the logo file and one mockup image per side used (Reply-To is the customer). The customer gets a copy with their mockups.
* Requests are saved under Quote Requests in WP Admin with a status (New / Quote sent / Ordered / Closed). Uploaded files are stored in wp-content/uploads/iaq-quotes, which is closed to the public, and can be opened from the request screen.

== Updating the catalog ==

Quote Requests > Settings > SanMar catalog: upload a new SanMar_SDL_*.zip from sanmar.com (Resources > SanMar Data Library), or give the path of a file already on the server.

== Updates ==

The plugin checks GitHub releases on insation/apparel-quote and offers updates on the Plugins screen like any other plugin ("Check for updates" link next to the plugin). To ship an update: bump the Version header, then publish a release tagged v<version>.
Private repo: define( 'IAQ_GITHUB_TOKEN', '...' ); in wp-config.php (read-only token).
Other repo: define( 'IAQ_GITHUB_REPO', 'owner/repo' );

The SanMar catalog itself is not in the repository. It lives in the site's database and is loaded from the SanMar Data Library file in Settings.

== Spam protection ==

Hidden honeypot field, minimum fill time, form token, and a limit of 6 requests per hour per IP.
