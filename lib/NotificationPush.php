<?php

declare(strict_types=1);

namespace OCA\UpPushNotifications;

use OCA\UnifiedPushProvider\Device\Device;
use OCA\UnifiedPushProvider\Device\Event;
use OCA\UnifiedPushProvider\Device\EventType;
use OCA\UnifiedPushProvider\Device\Urgency;
use OCA\Notifications\Handler as NotificationsHandler;
use OCA\Notifications\FakeUser;
use OCP\Notification\IApp as INotificationApp;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\DB\IResult;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IncompleteParsedNotificationException;
use OCP\Notification\INotification;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\UserStatus\IManager as IUserStatusManager;
use OCP\UserStatus\IUserStatus;
use OCP\Server;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;


class NotificationPush implements INotificationApp
{
	public const WELL_KNOWN_NEXTCLOUD_CLIENT_APP_NAME = 'com.nextcloud.client';
	public const WELL_KNOWN_NEXTCLOUD_TALK_APP_NAME = 'com.nextcloud.talk2';

	/**
	 * @psalm-var array<string, ?IUserStatus>
	 */
	protected array $userStatuses = [];

	public function __construct(
		protected IDBConnection $db,
		protected IFactory $l10nFactory,
		protected INotificationManager $notificationManager,
		protected IUserStatusManager $userStatusManager,
		protected ContainerInterface $context,
	)
	{
	}

	public static function isNotificationsAppAvailable(): bool {
		try {
			$appManager = Server::get(IAppManager::class);
			return (($appManager !== null) &&
				($appManager->isInstalled('notifications') === true) &&
				(class_exists(NotificationsHandler::class) === true));
		} catch (\Exception|NotFoundExceptionInterface $e) {
		}

		return false;		// assume false in case of problems
	}

	public static function isUpPushAppAvailable(): bool {
		try {
			$appManager = Server::get(IAppManager::class);
			return (($appManager !== null) &&
				($appManager->isInstalled('uppush') === true));
		} catch (\Exception|NotFoundExceptionInterface $e) {
		}

		return false;		// assume false in case of problems
	}

	// use 'main' nextcloud app to get possibly mutliple notifications from 
	// a single notification object
	protected function unpackNotification(INotification $notification): array {
		// this app has to follow the lead of the 'main' nextcloud notification 
		// app which can have multiple notifications 'packed' into a single 
		// notification object. urgh.
		// use it's 'multi-notification' unpack logic here
		$notificationsHandler = null;
		try {
			$notificationsHandler = Server::get(NotificationsHandler::class);
		} catch (\Exception | 
						 NotFoundExceptionInterface | 
						 ContainerExceptionInterface $e
		) { /* nothing */ }

		if ($notificationsHandler === null) {
			return [];
		}

		return $notificationsHandler->get($notification, PHP_INT_MAX);
	}

	public function notify(INotification $notification): void {
		// get fake user record
		$user = new FakeUser($notification->getUser());

		// unpack possible 'multi-notification' to a list of notifications
		$notifications = $this->unpackNotification($notification);

		// for each notification
		foreach ($notifications as $notificationId => $notification) {
			// if user isset to do not disturb and notification is not priority, do not send
			if (!array_key_exists($notification->getUser(), $this->userStatuses)) {
				$userStatus = $this->userStatusManager->getUserStatuses([
					$notification->getUser(),
				]);

				$this->userStatuses[$notification->getUser()] = $userStatus[$notification->getUser()] ?? null;
			}

			if (isset($this->userStatuses[$notification->getUser()])) {
				$userStatus = $this->userStatuses[$notification->getUser()];
				if ($userStatus instanceof IUserStatus
					&& $userStatus->getStatus() === IUserStatus::DND
					&& !$notification->isPriorityNotification()) {
					continue;
				}
			}

			// prepare the notification message if not yet prepared
			if (!$notification->isValidParsed()) {
				$language = $this->l10nFactory->getUserLanguage($user);

				try {
					$this->notificationManager->setPreparingPushNotification(true);
					$notification = $this->notificationManager->prepare(
						$notification, 
						$language
					);
					$this->notificationManager->setPreparingPushNotification(false);
				} catch (AlreadyProcessedException | 
								 IncompleteParsedNotificationException | 
								 \InvalidArgumentException
				) {
					// FIXME remove \InvalidArgumentException in Nextcloud 39
					return;
				}
			}

			$data = [
				'nid' => $notificationId,
				'app' => $notification->getApp(),
				'subject' => $notification->getParsedSubject(),
				'type' => $notification->getObjectType(),
				'id' => $notification->getObjectId(),
			];

			$this->pushArrayToUser(
				$notification->getUser(), 
				$data, 
				$notification->getApp()
			);
		}
	}

	public function markProcessed(INotification $notification): void {
		// unpack possible 'multi-notification' to a list of notifications
		$notifications = $this->unpackNotification($notification);

		// for each notification
		foreach ($notifications as $notificationId => $notification) {
			// push delete notification
			$this->pushArrayToUser(
				$notification->getUser(), 
				[ 'nid' => $notificationId, 'delete' => true ], 
				$notification->getApp()
			);
		}
	}

	public function getCount(INotification $notification): int {
		return 0;		// return 0 because notifications are not held on to
	}

	public function pushArrayToUser(
		string $user, 
		array $dataArray, 
		string $notificationApp
	): void {
		$result = null;

		// if this is a talk notification, only send to talk devices, if any, for user
		$isTalkNotification = \in_array(
			$notificationApp, 
			['spreed', 'talk', 'admin_notification_talk'], 
			true
		);
		if ($isTalkNotification) {
			$result = $this->getDevicesAndTokensForUserAndApp(
				$user, 
				NotificationPush::WELL_KNOWN_NEXTCLOUD_TALK_APP_NAME
			);
		}

		// not a talk app notification OR no talk devices found for user, 
		// try and find registered nc client devices for user
		if (!$result?->rowCount()) {
			$result = $this->getDevicesAndTokensForUserAndApp(
				$user, 
				NotificationPush::WELL_KNOWN_NEXTCLOUD_CLIENT_APP_NAME
			);
		}

		// no registered device for app that notification relates to, exit now
		if ($result === null) {
			return;
		}

		// loop through returned device/token pairs
		while ($row = $result->fetch()) {
			try {
				$message = new Event(
					EventType::Message,
					$row['token'],
					base64_encode(json_encode($dataArray))
				);

				// send notification with high urgency level (no delay)
				Device::withDevice(
					$this->context,
					$row['device_id'],
					fn($device): mixed => $device->push($message, (24 * 60 * 60) /* ttl 1 day */, Urgency::High, null),
				);
			} catch (\Exception $e) {
			}
		}

		$result->closeCursor();
	}

	protected function getDevicesAndTokensForUserAndApp(
		string $user, 
		string $appName
	): ?IResult {
		try {
			// get device_ids and tokens from db for user and app name
			$query = $this->db->getQueryBuilder();
			$query->select('ua.token', 'ua.device_id')
				->from('uppush_applications', 'ua')
				->innerJoin('ua', 'uppush_devices', 'ud', $query->expr()->eq('ua.device_id', 'ud.device_id'))
				->where($query->expr()->eq('ud.user_id', $query->createNamedParameter($user)))
				->andWhere($query->expr()->eq('ua.app_name', $query->createNamedParameter($appName)));
				return $query->executeQuery();
		} catch (\Exception $e) {
		}

		return null;
	}
}
