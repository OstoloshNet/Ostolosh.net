#!/usr/bin/env python3
"""Copies the latest posts of a Facebook Page into news.json for the site.

Run it from cron every 30 minutes, for example:
    */30 * * * * FB_PAGE_ID=123 FB_TOKEN=xxx /usr/bin/python3 /var/www/ostolosh/news-sync.py

Environment variables:
    FB_PAGE_ID   numeric id of your Facebook Page
    FB_TOKEN     long-lived Page access token (Meta developer app, Page Public Content / pages_read_engagement)
    OUT_FILE     where to write the result (default: news.json next to this script)
    GRAPH_VERSION  optional, e.g. v23.0. If empty, Meta's default version is used.
"""
import json
import os
import sys
import tempfile
import urllib.parse
import urllib.request

page_id = os.environ.get("FB_PAGE_ID")
token = os.environ.get("FB_TOKEN")
if not page_id or not token:
    sys.exit("Set FB_PAGE_ID and FB_TOKEN")

page_id = page_id.strip()
token = token.strip()
if not page_id.isdigit():
    sys.exit("FB_PAGE_ID must contain digits only (no letters, spaces or slashes). Check what you typed.")
try:
    token.encode("ascii")
except UnicodeEncodeError:
    sys.exit("FB_TOKEN contains a non-English character (for example a Cyrillic letter). Paste the token again.")

here = os.path.dirname(os.path.abspath(__file__))
out_file = os.environ.get("OUT_FILE", os.path.join(here, "news.json"))
version = os.environ.get("GRAPH_VERSION", "").strip("/")
base = "https://graph.facebook.com" + ("/" + version if version else "")

query = urllib.parse.urlencode({
    "fields": "id,message,story,created_time,permalink_url,attachments{title,description}",
    "limit": 12,
    "access_token": token,
})
url = f"{base}/{page_id}/posts?{query}"

import urllib.error

try:
    with urllib.request.urlopen(url, timeout=20) as resp:
        data = json.load(resp)
except urllib.error.HTTPError as e:
    try:
        err = json.load(e).get("error", {})
        sys.exit(
            f"Facebook error {e.code}: {err.get('message')} "
            f"(code {err.get('code')}, subcode {err.get('error_subcode')})"
        )
    except ValueError:
        sys.exit(f"Facebook error {e.code}")

raw = data.get("data", [])
print(f"Facebook returned {len(raw)} posts")
if not raw:
    print("No posts returned. Check that the token is a Page token with pages_read_engagement "
          "and that the Page ID is correct (try ID/posts in Graph API Explorer).")

items = []
skipped = 0
for post in raw:
    text = (post.get("message") or "").strip()
    if not text:
        for att in (post.get("attachments") or {}).get("data", []):
            text = (att.get("description") or att.get("title") or "").strip()
            if text:
                break
    if not text:
        text = (post.get("story") or "").strip()
    if not text:
        skipped += 1
        continue  # nothing readable (e.g. photo without caption)
    items.append({
        "id": post["id"],
        "date": post["created_time"],
        "text": text,
        "url": post.get("permalink_url", ""),
    })
print(f"Kept {len(items)} posts, skipped {skipped} without text")

# write atomically so the site never reads a half-written file
fd, tmp = tempfile.mkstemp(dir=os.path.dirname(out_file), suffix=".tmp")
with os.fdopen(fd, "w", encoding="utf-8") as f:
    json.dump({"items": items}, f, ensure_ascii=False, indent=2)
os.replace(tmp, out_file)
os.chmod(out_file, 0o644)
print(f"Saved {len(items)} posts to {out_file}")
