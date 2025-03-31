# UnifiedPush Notifications - Server App

Send Nextcloud Notifications via UnifiedPush provider app.

## Dependencies

* Nextcloud (https://github.com/nextcloud/server)
* The 'main' Nextcloud Notifications app (https://github.com/nextcloud/notifications)
* The UnifiedPush app (https://apps.nextcloud.com/apps/uppush)

NOTE: The below pull requests must be applied to the below projects if they aren't already for this app to work most correctly and reliably;

nextcloud server; https://github.com/nextcloud/server/pull/51800 so this app will be called deterministically before or after the 'main' nextcloud notification app.

nextcloud notifications app; https://github.com/nextcloud/notifications/pull/2277 so that this app will be able to react to all deletions made by the 'main' nextcloud notification app.

## Installation

Install into your Nextcloud apps directory and, as Admin, enable the app. There are no settings.

## Development

Clone the repository to __nextcloud/apps/uppush_notifications__.
