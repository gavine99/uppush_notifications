# UnifiedPush Notifications - Server App

Send Nextcloud Notifications via UnifiedPush provider app.

## Dependencies

* Nextcloud (https://github.com/nextcloud/server)
* The 'main' Nextcloud Notifications app (https://github.com/nextcloud/notifications)
* The UnifiedPush app (https://apps.nextcloud.com/apps/uppush)

To have the best reliability for this app you need pull request https://github.com/nextcloud/server/pull/51800 which has been merged into nextcloud server mainline and will flow trough to release in time. Until then you can patch it in yourself quite easily.

## Installation

Install into your Nextcloud apps directory and, as Admin, enable the app. There are no settings.

## Development

Clone the repository to __nextcloud/apps/uppush_notifications__.
