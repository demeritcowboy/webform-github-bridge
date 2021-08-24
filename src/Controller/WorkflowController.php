<?php

namespace Drupal\webformgithubbridge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\webformgithubbridge\Matrix\MatrixBuilder;

/**
 * Controller for when webhooks come in from gitlab.
 */
class WorkflowController extends ControllerBase {

  protected $config;

  /**
   * The system logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The system mailer
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailer;

  /**
   * WorkflowController constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config
   *   An instance of ConfigFactory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Mail\MailManagerInterface $mailer
   *   The system mailer.
   */
  public function __construct(ConfigFactory $config, LoggerChannelFactoryInterface $logger_factory, MailManagerInterface $mailer) {
    $this->config = $config->get('webformgithubbridge.settings');
    $this->logger = $logger_factory->get('webformgithubbridge');
    $this->mailer = $mailer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('plugin.manager.mail'),
    );
  }

  /**
   * Process the request body from a merge request webhook on webform
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   It's always "status":"Ok"
   */
  public function run(Request $request): JsonResponse {
    $request_body = json_decode(file_get_contents('php://input'), TRUE);

    $email = !empty($request_body['user']['email']) ? $request_body['user']['email'] : NULL;

    if (empty($request_body)) {
      $this->logger->warn('Empty request body?');
      // fall through to end
    }
    elseif ($request_body['object_kind'] === 'merge_request'
      && $request_body['event_type'] === 'merge_request'
      && $request_body['object_attributes']['state'] !== 'opened') {
      // I don't think it makes sense to do anything here - the MR was likely
      // merged or closed. Notifying about this in case they thought it should
      // do something would annoy anyone else every time they closed/merged one.

      // fall through to end
    }
    elseif ($request_body['object_kind'] !== 'merge_request'
      || $request_body['event_type'] !== 'merge_request') {
      $this->logger->info('Only open merge_request events are allowed.');
      if (!empty($email)) {
        $this->mailer->mail('webformgithubbridge', 'webformgithubbridge_merge_objects_only', $email, 'en', []);
      }
      // fall through to end
    }
    else {
      $json = json_encode([
        'ref' => 'main',
        'inputs' => [
          'matrix' => $this->assembleMatrix($request_body['project']['git_http_url'], $request_body['object_attributes']['source_branch']),
          'prurl' => $request_body['object_attributes']['url'],
//          'repourl' => $request_body['project']['git_http_url'],
//          'notifyemail' => $email,
        ],
      ]);

      $curl = curl_init();
      $cookie_file_path = tempnam(sys_get_temp_dir(), 'coo');
      $curl_params = [
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_HEADER => FALSE,
        CURLOPT_URL => 'https://api.github.com/repos/semperit/CiviCARROT/actions/workflows/webform_civicrm.yml/dispatches',
        CURLOPT_HTTPHEADER => ['Content-type: application/json', 'Accept: application/vnd.github.v3+json'],
        CURLOPT_USERPWD => $this->config->get('webformgithubbridge.username') . ":" . $this->config->get('webformgithubbridge.password'),
        CURLOPT_POST => TRUE,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)',
        CURLOPT_SSL_VERIFYPEER => $this->config->get('webformgithubbridge.verifyssl'),
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_COOKIEFILE => $cookie_file_path,
        CURLOPT_COOKIEJAR => $cookie_file_path,
      ];

      $response_str = '';

      curl_setopt_array($curl, $curl_params);
      $exec_result = curl_exec($curl);
      if ($exec_result === FALSE) {
        $this->logger->debug("curlerr: " . curl_error($curl));
        $this->logger->debug(print_r(curl_getinfo($curl), TRUE));
      }
      else {
        $response_str .= $exec_result;
      }

      curl_close($curl);

      if (!empty($response_str)) {
        $this->logger->error($response_str);
        if (!empty($email)) {
          $this->mailer->mail('webformgithubbridge', 'webformgithubbridge_trigger_failure', $email, 'en', ['result' => $response_str]);
        }
      }
    }

    $response = new JsonResponse(['status' => 'Ok']);
    return $response;
  }

  /**
   * Checks access to /webformgithubbridge path.
   */
  public function checkAccess() {
    $secret = $_SERVER['HTTP_X_GITLAB_TOKEN'] ?? NULL;
    if (!empty($secret) && ($secret === $this->config->get('webformgithubbridge.gitlabtoken'))) {
      return AccessResult::allowed();
    }
    $this->logger->warning('Invalid token: @token', ['@token' => $secret]);
    return AccessResult::forbidden();
  }

  /**
   * Assemble the testing matrix.
   * @param string $repourl
   * @param string $branch The git branch for the PR
   * @return string A JSON string suitable for github actions matrix
   */
  private function assembleMatrix(string $repourl, string $branch): string {
    return (new MatrixBuilder($repourl, $branch))->build();
  }

}
