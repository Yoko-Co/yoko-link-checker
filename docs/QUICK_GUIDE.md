# Yoko Link Checker - Quick Guide

> A simple guide for checking broken links on WordPress sites before launch.

---

## What Is It?

Yoko Link Checker scans your WordPress site for broken links and reports any issues. Run it before launching a site to catch dead links, missing pages, and redirect chains.

---

## Where to Find It

In WordPress Admin:
1. Go to **Tools → Yoko Link Checker** in the left sidebar
2. You'll see the **Dashboard** with scan controls and stats

---

## Running a Scan

### Starting a Scan

1. Go to **Tools → Yoko Link Checker**, **Dashboard** tab
2. Click **Start Scan**
3. Watch the progress bar — the scan runs in the background
4. When complete, you'll see a summary of results

### What Gets Scanned

- All published **Pages** and **Posts**
- Links in the content (both internal and external)
- Images and media files

### How Long Does It Take?

Depends on site size:
- Small site (50 pages): ~2-3 minutes
- Medium site (200 pages): ~10-15 minutes
- Large site (500+ pages): ~30-45 minutes

You can leave the page and come back — progress is saved.

---

## Understanding Results

### The Dashboard

Shows at-a-glance stats:

| Stat | What It Means |
|------|---------------|
| **Total Links** | All links found across the site |
| **Broken** | Links that return 404 or error — **fix these** |
| **Warnings** | Links that need manual review |
| **Redirects** | Links that redirect to another URL |
| **Valid** | Links that work correctly |

### Status Types Explained

| Status | Icon | What It Means | Action Needed |
|--------|------|---------------|---------------|
| **Valid** | ✅ | Link works | None |
| **Broken** | 🔴 | Page doesn't exist (404) | Fix or remove the link |
| **Warning** | ⚠️ | Couldn't verify — may work, may not | Check manually |
| **Redirect** | ↪️ | Link redirects to another page | Consider updating to final URL |
| **Blocked** | 🚫 | Site blocked our check (login required) | Check manually |
| **Timeout** | ⏱️ | Site took too long to respond | Check manually, may be slow site |

---

## Viewing Detailed Results

1. Go to **Tools → Yoko Link Checker**, **Reports** tab
2. Use the **filter tabs** to view by status:
   - All | Pending | Valid | Redirect | Broken | Warning | Blocked | Timeout | Error | Ignored
   - Each tab shows how many link occurrences it contains; hover for the unique-URL count

### For Each Link You'll See

| Column | Description |
|--------|-------------|
| **URL** | The link being checked |
| **Status** | Current status (hover for details) |
| **HTTP Code** | Technical status code (200 = OK, 404 = Not Found) |
| **Source** | Which page contains this link (click to edit) |
| **Link Text** | The clickable text of the link |
| **Last Checked** | When it was last scanned |

---

## Fixing Broken Links

### Recommended Workflow

1. **Filter to "Broken"** — focus on definite issues first
2. **Click the Source link** — opens the page editor
3. **Find and fix the link** — update URL or remove it
4. **Update the page**
5. **Move to the next broken link**

### Common Fixes

| Problem | Solution |
|---------|----------|
| Page was deleted | Remove the link or link to replacement content |
| URL typo | Correct the URL |
| Page moved | Update to new URL |
| External site gone | Remove the link or find alternative |

---

## Exporting Results

Need to share results with a client or track in a spreadsheet?

1. Go to **Tools → Yoko Link Checker**, **Reports** tab
2. Filter to the status you want (or leave on "All")
3. Click **Export CSV (current filters)** — the file contains exactly what is on screen

### CSV Includes

- Broken URL
- Source URL (page containing the link)
- Source Title
- Source Type (page, post, etc.)
- Status
- HTTP Code
- Error Details
- Link Text

---

## Tips & Best Practices

### Before Site Launch

1. Run a full scan
2. Fix all **Broken** links (priority)
3. Review **Warnings** and **Redirects**
4. Re-scan to confirm fixes

### Ignoring Links

Some links may be intentionally blocked (member-only content, external sites that block bots). These are fine to ignore if you've verified them manually.

### When to Re-scan

- After fixing broken links
- After major content updates
- Before any site launch or migration
- Monthly for ongoing maintenance

---

## Common Questions

### Why is a working link showing as "Warning"?

Some external sites (LinkedIn, Facebook, Instagram) block automated checks. If you can visit the link in a browser, it's fine.

### Why is an internal page showing as "Broken"?

The page may be:
- In draft/pending status (not published)
- Password protected
- Deleted or trashed

### Can I scan only specific pages?

Currently scans all published content. Future versions may add selective scanning.

### Will scanning slow down the site?

No — the scanner is designed to work in small batches and won't impact site performance for visitors.

---

## Need Help?

Contact your friendly neighborhood dev team. 🚀
