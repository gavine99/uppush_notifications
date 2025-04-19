<?php

declare(strict_types=1);

namespace OCA\UpPushNotifications\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;
use OCP\Notification\IManager as INotificationManager;
use OCA\UpPushNotifications\UpPushNotifications;

class Application extends App implements IBootstrap {
    public const APP_ID = 'uppush_notifications';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

	/**
	 * @param IRegistrationContext $context
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(UpPushNotifications::class);
	}

	/**
	 * @param IBootContext $context
	 */
	public function boot(IBootContext $context): void {
		// do nothing if UnifiedPush app is not available
		if (UpPushNotifications::isUpPushAppAvailable() === false)
			return;

		// if notifications app is installed and enabled, and it's handler class is available to use
		// then register to handle notification messages
		if (UpPushNotifications::isNotificationsAppAvailable() === true) {
			$context->injectFn($this->registerAppAndNotifier(...));
		}
	}

	public function registerAppAndNotifier(INotificationManager $notificationManager): void {
		$notificationManager->registerApp(UpPushNotifications::class);
	}
}
