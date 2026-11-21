# PARALLEL — Minimalist Daily Connection Game

A calm, privacy-focused, Halal-friendly 2-player daily puzzle built with PHP + SQLite + HTML5.

## Features
- **Private Invite Rooms:** Strictly 2 players per room via 6-character code (no public stranger browsing or open text chat).
- **Asymmetric 5×5 Daily Grid:** Both partners hold hidden stone blockers and collaborate to connect Start (A) to End (B).
- **Wholesome Daily Spark:** Thoughtful daily reflection unlocked after solving the grid.
- **24-Hour Streak System:** Snapchat-style daily streak that resets to `0` if either player misses a day, with an optional weekly Rewarded Ad restore hook.
- **Zero Database Config:** Automatically initializes `data/parallel.db` (SQLite) on Hostinger.

## Configuration
Open `index.php` and update lines 10–12 when ready:
- `ADSENSE_PUB_ID`: Your Google AdSense `ca-pub-XXXXXXXXXXXXXXXX` ID
- `CONTACT_EMAIL`: Your support email for the Privacy Policy page
- `APP_TIMEZONE`: Default `Asia/Karachi`

## Hosting on Hostinger
1. Upload `index.php` to `public_html/` (or `public_html/play/` if using WordPress on your main domain).
2. Visit the URL once—the `data/` folder and database are created automatically.
