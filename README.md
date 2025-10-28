# Colby Rave Alert Sync Plugin

---

## Overview

The **Colby Rave Alert Sync** plugin automatically monitors the Colby College Rave RSS feed and synchronizes alerts with the WordPress site. It updates two areas:

1. The **ACF-based alert banner** (via ACF option fields).
2. A specific **WordPress page** (the Colby College Updates page) with the latest alert information.

It uses a WordPress cron job that checks the feed every 5 minutes and updates ACF fields and page content based on the latest alert state.

---

## Configuration

### Constants

| Constant                      | Description                                                                                 |
| ----------------------------- | ------------------------------------------------------------------------------------------- |
| `COLBY_ALERT_PAGE_ID`         | The ID of the "Colby College Updates" page to update with alert content.                    |
| `COLBY_RAVE_FEED_URL`         | The URL of the Rave RSS feed to monitor (`https://content.getrave.com/rss/colby/channel1`). |
| `COLBY_RAVE_CRON_HOOK`        | The name of the custom cron job hook (`colby_check_rave_feed`).                             |
| `COLBY_RAVE_LAST_DATE_OPTION` | WordPress option key that stores the last processed feed item's `pubDate`.                  |
| `COLBY_RAVE_LAST_DESC_OPTION` | WordPress option key that stores the last processed feed item's description.                |

---

## Dependencies

-   **Advanced Custom Fields Pro (ACF Pro)** is required.
    -   The plugin checks for the existence of the ACF class and shows an admin notice if not installed.
    -   ACF fields are updated in the `options` page context (e.g. `update_field('alert_active', true, 'options')`).

---

## Cron Job

### Custom Interval

A new cron interval called **"Every Five Minutes"** (`five_minutes`) is registered. It runs every 300 seconds.

### Activation & Deactivation

-   **Activation Hook:** `register_activation_hook()` schedules the cron job if not already set.
-   **Deactivation Hook:** Clears the scheduled cron job to prevent orphaned tasks.

---

## Main Logic: `colby_do_rave_feed_check()`

### Purpose

This function runs automatically every 5 minutes and:

-   Fetches the latest item from the Rave RSS feed.
-   Compares it against the previously stored alert data.
-   Updates ACF fields and page content accordingly.

### Steps

1. **Check for ACF availability:**

    - Stops execution if ACF functions aren’t found (logs an error).

2. **Fetch the Rave RSS Feed:**

    - Uses WordPress's `fetch_feed()` function.
    - Logs an error if the feed cannot be retrieved.

3. **Parse Feed Item:**

    - Extracts the description and publication date (`pubDate`).
    - Compares with the previously saved `pubDate` and description.

4. **Determine State:**

    - If `pubDate` hasn’t changed, no action is taken.
    - If the feed contains a new alert, it updates ACF and the alert page.
    - If the feed says **"CLEAR FEED"**, it deactivates the alert banner and drafts the alert page.

5. **ACF Field Updates (for Active Alerts):**

    - `alert_active` → `true`
    - `alert_heading` → empty
    - `alert_paragraph` → set from RSS description (if syncing enabled)
    - `alert_buttons` → adds an “Updates” button linking to the Updates page
    - `alert_type` → `emergency`

6. **Page Content Update:**

    - Builds formatted HTML with timestamp and alert text.
    - If the previous state was clear, it **replaces** content.
    - Otherwise, it **prepends** the new alert to the existing content.
    - Updates the page content and ensures the page is published.

7. **ACF & Page Updates (for Clear Feed):**

    - `alert_active` → `false`
    - `alert_from_rave` → `true`
    - The alert page is set to `draft` (retains content for future reference).

8. **State Persistence:**
    - Saves the latest `pubDate` and description to options for comparison in the next cycle.

---

## Error Handling

-   Logs to PHP error log if:
    -   ACF functions are unavailable.
    -   RSS feed cannot be fetched.
    -   Feed is empty or malformed.

---

## Example: How the System Responds

| Rave Feed State                | System Behavior                                                        |
| ------------------------------ | ---------------------------------------------------------------------- |
| **New alert posted**           | Activates ACF banner, updates content, and publishes the Updates page. |
| **Same alert persists**        | No change (feed not processed again).                                  |
| **Feed changes to CLEAR FEED** | Deactivates ACF alert banner and unpublishes the Updates page.         |

---

## Hooks and Filters

| Type           | Hook                         | Purpose                        |
| -------------- | ---------------------------- | ------------------------------ |
| `action`       | `admin_notices`              | Display missing ACF warning.   |
| `filter`       | `cron_schedules`             | Add 5-minute interval.         |
| `activation`   | `register_activation_hook`   | Schedule cron event.           |
| `deactivation` | `register_deactivation_hook` | Clear cron event.              |
| `action`       | `COLBY_RAVE_CRON_HOOK`       | Run the main feed check logic. |

---

## Notes

-   Designed for **Colby College** infrastructure; requires the page ID `42334` to exist and be published initially.
-   RSS feed monitored: [`https://content.getrave.com/rss/colby/channel1`](https://content.getrave.com/rss/colby/channel1)
-   Ideal for automated, hands-free synchronization of campus emergency alerts.

---

## Future Enhancements

-   Add admin settings to configure:
    -   Feed URL
    -   Page ID
    -   Update frequency
-   Email or Slack notifications on new alerts.
-   Support for multiple Rave channels.

---

## License

This project is licensed under the [GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html).
