<?php

namespace Acquia\Cli\Command\CodeStudio;

use Acquia\Cli\Command\CommandBase;
use Acquia\Cli\Command\WizardCommandBase;
use Acquia\Cli\Exception\AcquiaCliException;
use Acquia\Cli\Output\Checklist;
use Acquia\DrupalEnvironmentDetector\AcquiaDrupalEnvironmentDetector;
use AcquiaCloudApi\Endpoints\Account;
use AcquiaCloudApi\Response\ApplicationResponse;
use Gitlab\Client;
use Gitlab\Exception\RuntimeException;
use Gitlab\HttpClient\Builder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Yaml\Yaml;
use Webmozart\PathUtil\Path;

/**
 * Class CodeStudioPipelinesMigrateCommand.
 */
class CodeStudioPipelinesMigrateCommand extends CommandBase {

  protected static $defaultName = 'codestudio:pipelines-migrate';

  /**
   * @var string
   */
  private $appUuid;

  /**
   * @var \Gitlab\Client
   */
  private $gitLabClient;

  /**
   * @var string
   */
  private $gitlabToken;

  /**
   * @var string
   */
  private $gitlabHost;

  /**
   * {inheritdoc}.
   */
  protected function configure() {
    $this->setDescription('@todo')
      ->addOption('key', NULL, InputOption::VALUE_REQUIRED, 'The Cloud Platform API token that Code Studio will use')
      ->addOption('secret', NULL, InputOption::VALUE_REQUIRED, 'The Cloud Platform API secret that Code Studio will use')
      ->addOption('gitlab-token', NULL, InputOption::VALUE_REQUIRED, 'The GitLab personal access token that will be used to communicate with the GitLab instance')
      ->addOption('gitlab-project-id', NULL, InputOption::VALUE_REQUIRED, 'The project ID (an integer) of the GitLab project to configure.')
      ->setAliases(['cs:pipelines-migrate']);
    $this->acceptApplicationUuid();
    $this->setHidden(!AcquiaDrupalEnvironmentDetector::isAhIdeEnv());
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @return int
   * @throws \Exception
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    // $this->gitlabHost = $this->getGitLabHost();

    // $this->io->info('Hello World');
    $this->gitlabHost = $this->getGitLabHost();
    $this->env = $this->validateEnvironment();
    $this->gitlabToken = $this->getGitLabToken($this->gitlabHost);
    $this->getGitLabClient();
    try {
      $this->gitLabAccount = $this->gitLabClient->users()->me();
    }
    catch (RuntimeException $exception) {
      $this->io->error([
        "Unable to authenticate with Code Studio",
        "Did you set a valid token with the <options=bold>api</> and <options=bold>write_repository</> scopes?",
        "Try running `glab auth login` to re-authenticate.",
        "Then try again.",
      ]);
      return 1;
    }

    // Get Cloud access tokens.
    if (!$input->getOption('key') || !$input->getOption('secret')) {
      $token_url = 'https://cloud.acquia.com/a/profile/tokens';
      $this->io->writeln([
        "",
        "This will configure AutoDevOps for a Code Studio project using credentials",
        "(an API Token and SSH Key) belonging to your current Acquia Cloud user account.",
        "Before continuing, make sure that you're logged into the right Acquia Cloud Platform user account.",
        "",
        "<comment>Typically this command should only be run once per application</comment>",
        "but if your Cloud Platform account is deleted in the future, the Code Studio project will",
        "need to be re-configured using a different user account.",
        "",
        "<options=bold>To begin, visit this URL and create a new API Token for Code Studio to use:</>",
        "<href=$token_url>$token_url</>",
      ]);
    }

    $cloud_key = $this->determineApiKey($input, $output);
    $cloud_secret = $this->determineApiSecret($input, $output);
    // We may already be authenticated with Acquia Cloud Platform via a refresh token.
    // But, we specifically need an API Token key-pair of Code Studio.
    // So we reauthenticate to be sure we're using the provided credentials.
    $this->reAuthenticate($cloud_key, $cloud_secret, $this->cloudCredentials->getBaseUri());

    $this->checklist = new Checklist($output);
    $this->appUuid = $this->determineCloudApplication();
    // Get Cloud application.
    $cloud_application = $this->getCloudApplication($this->appUuid);
    $migrate_application = $cloud_application->name;
    print_r($migrate_application);

    $this->gitLabProjectDescription = "Source repository for Acquia Cloud Platform application <comment>$this->appUuid</comment>";
    $project = $this->determineGitLabProject($cloud_application);

    $this->getGitLabCiCdVariables($project, $this->appUuid, $cloud_key, $cloud_secret);

    $this->checkPipelineExists($project);
    // Get Cloud account.
    $acquia_cloud_client = $this->cloudApiClientService->getClient();
    $account_adapter = new Account($acquia_cloud_client);
    $account = $account_adapter->get();
    $this->validateRequiredCloudPermissions($acquia_cloud_client, $this->appUuid, $account);
    return 0;
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *
   * @return bool
   */
  protected function commandRequiresAuthentication(InputInterface $input): bool {
    return FALSE;
  }

  /**
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   */
  protected function validateEnvironment() {
    //$this->requireCloudIdeEnvironment();
    if (!getenv('GITLAB_HOST')) {
      throw new AcquiaCliException('The GITLAB_HOST environmental variable must be set.');
    }
  }

  /**
   * @param ApplicationResponse $cloud_application
   *
   * @return array
   */
  protected function determineGitLabProject(ApplicationResponse $cloud_application) {
    // Search for existing project that matches expected description pattern.
    $projects = $this->gitLabClient->projects()->all(['search' => $cloud_application->uuid]);
    #print_r($projects);
    if (count($projects) == 1) {
      print("");
      print("Project exists");
      return reset($projects);
    }
    else {
      throw new AcquiaCliException( "Could not find any existing Code Studio project for Acquia Cloud Platform application {$cloud_application->name}.");
    }
  }

  /**
   * @param array $project
   * @param string $cloud_application_uuid
   * @param string $cloud_key
   * @param string $cloud_secret
   * @param string $project_access_token_name
   * @param string $project_access_token
   */
  protected function getGitLabCiCdVariables(array $project, string $cloud_application_uuid, string $cloud_key, string $cloud_secret): array {
    $GLAB_TOKEN_NAME = "ACQUIA_GLAB_TOKEN_NAME";
    $gitlab_cicd_existing_variables = $this->gitLabClient->projects()->variables($project['id']);
    #print_r($gitlab_cicd_existing_variables);
    foreach ($gitlab_cicd_existing_variables as $variable) {
      if ($variable['key'] == $GLAB_TOKEN_NAME) {
        print("");
        print("Variable exists");
        return $variable;
      }
    }
    foreach ($gitlab_cicd_existing_variables as $variable) {
      if ($variable['key'] != $GLAB_TOKEN_NAME) {
        throw new AcquiaCliException("Please run the wizard command as ACQUIA_GLAB_TOKEN_NAME doesnt exists");
      }
    }
  }

  protected function checkPipelineExists(array $project): bool {

    $pipelines_filepath = Path::join($this->repoRoot, 'acquia-pipelines.yml');
    if ($this->localMachineHelper->getFilesystem()->exists($pipelines_filepath)) {
      $pipelines_config = Yaml::parseFile($pipelines_filepath);

      $this->gitLabClient->projects()
        ->update($project['id'], ['ci_config_path' => '']);

      // @todo Read $pipelines_config, add stuff to $gitlab_ci_config.

      $this->io->success([
        "Removed ci_config_path from the project",
      ]);
    }
    else {
      $this->io->error(
        ['Could not find .acquia-pipelines.yml file in ' . $this->repoRoot],
      );
    }
    return TRUE;
  }

  /**
   * @param string $gitlab_host
   *
   * @return string
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   */
  protected function getGitLabToken(string $gitlab_host): string {
    if ($this->input->getOption('gitlab-token')) {
      return $this->input->getOption('gitlab-token');
    }
    $process = $this->localMachineHelper->execute([
      'glab',
      'config',
      'get',
      'token',
      '--host=' . $gitlab_host,
    ], NULL, NULL, FALSE);
    if ($process->isSuccessful() && trim($process->getOutput())) {
      return trim($process->getOutput());
    }

    $this->io->writeln([
      "",
      "You must first authenticate with Code Studio by creating a personal access token:",
      "* Visit https://$gitlab_host/-/profile/personal_access_tokens",
      "* Create a token and grant it both <comment>api</comment> and <comment>write repository</comment> scopes",
      "* Copy the token to your clipboard",
      "* Run <comment>glab auth login --hostname=$gitlab_host</comment> and paste the token when prompted",
      "* Try this command again.",
    ]);

    throw new AcquiaCliException("Could not determine GitLab token");
  }

  /**
   * @return string
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   */
  protected function getGitLabHost(): string {
    $process = $this->localMachineHelper->execute([
      'glab',
      'config',
      'get',
      'host',
    ], NULL, NULL, FALSE);
    if (!$process->isSuccessful()) {
      throw new AcquiaCliException("Could not determine GitLab host: {error_message}", ['error_message' => $process->getErrorOutput()]);
    }
    $output = trim($process->getOutput());
    $url_parts = parse_url($output);
    if (!array_key_exists('scheme', $url_parts) && !array_key_exists('host', $url_parts)) {
      // $output looks like code.cloudservices.acquia.io.
      return $output;
    }
    // $output looks like http://code.cloudservices.acquia.io/.
    return $url_parts['host'];
  }

  /**
   * @param \AcquiaCloudApi\Connector\Client $acquia_cloud_client
   * @param string|null $cloud_application_uuid
   * @param \AcquiaCloudApi\Response\AccountResponse $account
   *
   * @throws \Acquia\Cli\Exception\AcquiaCliException
   */
  protected function validateRequiredCloudPermissions(\AcquiaCloudApi\Connector\Client $acquia_cloud_client, ?string $cloud_application_uuid, \AcquiaCloudApi\Response\AccountResponse $account): void {
    $required_permissions = [
      "deploy to non-prod",
      # Add SSH key to git repository
      "add ssh key to git",
      # Add SSH key to non-production environments
      "add ssh key to non-prod",
      # Add a CD environment
      "add an environment",
      # Delete a CD environment
      "delete an environment",
      # Manage environment variables on a non-production environment
      "administer environment variables on non-prod",
    ];

    $permissions = $acquia_cloud_client->request('get', "/applications/{$cloud_application_uuid}/permissions");
    $keyed_permissions = [];
    foreach ($permissions as $permission) {
      $keyed_permissions[$permission->name] = $permission;
    }
    foreach ($required_permissions as $name) {
      if (!array_key_exists($name, $keyed_permissions)) {
        throw new AcquiaCliException("The Acquia Cloud account {account} does not have the required '{name}' permission. Please add the permissions to this user or use an API Token belonging to a different Acquia Cloud user.", [
          'account' => $account->mail,
          'name' => $name
        ]);
      }
    }
  }

  /**
   * @return \Gitlab\Client
   */
  protected function getGitLabClient(): Client {
    if (!isset($this->gitLabClient)) {
      $gitlab_client = new Client(new Builder(new \GuzzleHttp\Client()));
      $gitlab_client->setUrl('https://' . $this->gitlabHost);
      $gitlab_client->authenticate($this->gitlabToken, Client::AUTH_OAUTH_TOKEN);
      $this->setGitLabClient($gitlab_client);
    }
    return $this->gitLabClient;
  }

  /**
   * @param Client $client
   */
  public function setGitLabClient($client) {
    $this->gitLabClient = $client;
  }

}
