<?php

namespace Acquia\Cli\Command\App;

use Acquia\Cli\Command\CommandBase;
use Acquia\Cli\Exception\AcquiaCliException;
use Acquia\Cli\Helpers\LoopHelper;
use AcquiaCloudApi\Endpoints\Notifications;
use AcquiaCloudApi\Response\NotificationResponse;
use React\EventLoop\Factory;
use React\EventLoop\Loop;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class TaskWaitCommand.
 */
class TaskWaitCommand extends CommandBase {

  protected static $defaultName = 'app:task-wait';

  /**
   * {inheritdoc}.
   */
  protected function configure() {
    $this->setDescription('Wait for a task to complete')
      ->addArgument('notification-uuid', InputArgument::REQUIRED, 'The UUID of the task notification returned by the Cloud API')
      ->setHelp('This command will accepts either a notification uuid as an argument or else a json string passed through standard input. The json string must contain the _links->notification->href property.')
      ->addUsage('acli app:task-wait "$(api:environments:domain-clear-caches [environmentId] [domain])"');
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @return int 0 if everything went fine, or an exit code
   * @throws \Exception
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $notification_uuid = $this->getNotificationUuid($input);
    $acquia_cloud_client = $this->cloudApiClientService->getClient();
    // $loop is statically cached by Loop::get(). To prevent it
    // persisting into other instances we must use Factory::create() to reset it.
    // @phpstan-ignore-next-line
    Loop::set(Factory::create());
    $loop = Loop::get();
    $spinner = LoopHelper::addSpinnerToLoop($loop, "Waiting for task $notification_uuid to complete", $this->output);
    $notifications_resource = new Notifications($acquia_cloud_client);
    $callback = function () use ($loop, $spinner, $notifications_resource, $notification_uuid) {
      $notification = $notifications_resource->get($notification_uuid);
      if ($notification->progress === 100) {
        LoopHelper::finishSpinner($spinner);
        $loop->stop();
        $this->writeCompletedMessage($notification);
      }
      else {
        $spinner->setMessage("Task of type {$notification->label} is {$notification->progress}% complete");
      }
    };
    // Run once immediately to speed up tests.
    $loop->addTimer(0.1, $callback);
    $loop->addPeriodicTimer(5, $callback);
    LoopHelper::addTimeoutToLoop($loop, 45, $spinner);

    // Start the loop.
    try {
      $loop->run();
    }
    catch (AcquiaCliException $exception) {
      $this->io->error($exception->getMessage());
      return 1;
    }

    return 0;
  }

  /**
   * @param InputInterface $input
   *
   * @return string
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   */
  protected function getNotificationUuid(InputInterface $input): string {
    $notification_uuid = $input->getArgument('notification-uuid');
    $json = json_decode($notification_uuid);
    if (json_last_error() === JSON_ERROR_NONE) {
      if (property_exists($json, '_links') && property_exists($json->_links, 'notification') && property_exists($json->_links->notification, 'href')) {
        return $this->getNotificationUuidFromResponse($json);
      }
      throw new AcquiaCliException("Input JSON must contain the _links.notification.href property.");
    }

    return $this->validateUuid($input->getArgument('notification-uuid'));
  }

  /**
   * @param \AcquiaCloudApi\Response\NotificationResponse $notification
   * @param string $notification_uuid
   */
  protected function writeCompletedMessage(NotificationResponse $notification): void {
    $duration = strtotime($notification->completed_at) - strtotime($notification->created_at);
    $completed_at = date("D M j G:i:s T Y", strtotime($notification->completed_at));
    $this->io->success([
      "The task with notification uuid {$notification->uuid} completed with status \"{$notification->status}\"" . PHP_EOL .
      "on " . $completed_at,
      "Task type: " . $notification->label . PHP_EOL .
      "Duration: $duration seconds",
    ]);
  }

}
