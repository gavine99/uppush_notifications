<?php

declare(strict_types=1);

namespace OCA\UpPushNotifications;

use OCA\UnifiedPushProvider\Device\Device;
use OCA\UnifiedPushProvider\Device\Event;
use OCA\UnifiedPushProvider\Device\EventType;
use OCA\UnifiedPushProvider\Device\Urgency;
use \OCA\UnifiedPushProvider\Request\RequestTtl;
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
	 * @var ?IUserStatus[]
	 * @psalm-var array<string, ?IUserStatus>
	 */
	protected ?array $userStatuses = [];

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

	protected function getUniqueNotificationId($notification): ?int {
		$notificationsHandler = null;
		try {
			$notificationsHandler = Server::get(NotificationsHandler::class);
		} catch (\Exception|NotFoundExceptionInterface|ContainerExceptionInterface $e) {
		}
		if ($notificationsHandler === null) {
			return null;
		}

		// the unique id will be the key of the first notification that matches the notification being processed
		$dbNotifications = $notificationsHandler->get($notification, 1);
		if (count($dbNotifications) === 0) {
			return null;
		}

		return array_keys($dbNotifications)[0];
	}

	public function notify(INotification $notification): void {
		// get unique notification id early so we don't do a lot of other work and then find the unique id
		// isn't available
		$notificationUniqueId = $this->getUniqueNotificationId($notification);
		if ($notificationUniqueId === null) {
			return;
		}

		// get fake user record
		$user = new FakeUser($notification->getUser());

		// cache user status in case of multiple notifications being sent in a higher level loop
		if (array_key_exists($notification->getUser(), $this->userStatuses) === false) {
			$userStatus = $this->userStatusManager->getUserStatuses([$notification->getUser()]);
			$this->userStatuses[$notification->getUser()] = $userStatus[$notification->getUser()] ?? null;
		}

		// if user status is set to do not disturb, return without sending
		if (isset($this->userStatuses[$notification->getUser()])) {
			$userStatus = $this->userStatuses[$notification->getUser()];
			if ($userStatus instanceof IUserStatus
				&& $userStatus->getStatus() === IUserStatus::DND
				&& empty($this->allowedDNDPushList[$notification->getApp()])) {
				return;
			}
		}

		// prepare the notification message
		if (!$notification->isValidParsed()) {
			$language = $this->l10nFactory->getUserLanguage($user);

			try {
				$this->notificationManager->setPreparingPushNotification(true);
				$notification = $this->notificationManager->prepare($notification, $language);
				$this->notificationManager->setPreparingPushNotification(false);
			} catch (AlreadyProcessedException|IncompleteParsedNotificationException|\InvalidArgumentException) {
				// FIXME remove \InvalidArgumentException in Nextcloud 39
				return;
			}
		}

		$data = [
			'nid' => $notificationUniqueId,
			'app' => $notification->getApp(),
			'subject' => $notification->getParsedSubject(),
			'type' => $notification->getObjectType(),
			'id' => $notification->getObjectId(),
		];

		$this->pushArrayToUser($notification->getUser(), $data, $notification->getApp());
	}

	public function notifyDelete(string $user, ?int $id, ?INotification $notification): void
	{
		// if we don't have the unique id for the notification we can't accomplish anything, return now
		if ($id === null) {
			return;
		}

		$data = [
			'nid' => $id,
			'delete' => true
		];

		$this->pushArrayToUser($user, $data, $notification->getApp());
	}

	public function markProcessed(INotification $notification): void {
		// get unique notification id if it's available in the Nextcloud Notification app
		$notificationUniqueId = $this->getUniqueNotificationId($notification);
		if ($notificationUniqueId === null)
			return;

		$this->notifyDelete($notification->getUser(), $notificationUniqueId, $notification);
	}

	public function getCount(INotification $notification): int {
		return 0;		// return 0 because notifications are not held on to
	}

	public function pushArrayToUser(string $user, array $dataArray, string $notificationApp): void {
		$result = null;

		// if this is a talk notification, only send to talk devices, if any, for user
		$isTalkNotification = \in_array($notificationApp, ['spreed', 'talk', 'admin_notification_talk'], true);
		if ($isTalkNotification) {
			$result = $this->getDevicesAndTokensForUserAndApp($user, NotificationPush::WELL_KNOWN_NEXTCLOUD_TALK_APP_NAME);
		}

		// not a talk app notification OR no talk devices found for user, try and find registered nc client devices for user
		if (!$result?->rowCount()) {
			$result = $this->getDevicesAndTokensForUserAndApp($user, NotificationPush::WELL_KNOWN_NEXTCLOUD_CLIENT_APP_NAME);
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
					function(Device $device) use ($message) {
						return $device->push($message, (24 * 60 * 60) /* ttl 1 day */, Urgency::High, null);
					},
				);
			} catch (\Exception $e) {
			}
		}
		$result->closeCursor();
	}

	protected function getDevicesAndTokensForUserAndApp(string $user, string $appName): ?IResult {
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
