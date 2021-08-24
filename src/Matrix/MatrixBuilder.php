<?php

namespace Drupal\webformgithubbridge\Matrix;

class MatrixBuilder {

  const CIVICARROT_CIVI_DEV = 1;
  const CIVICARROT_CIVI_RELEASECANDIDATE = 2;
  const CIVICARROT_CIVI_LATEST = 3;
  const CIVICARROT_DRUPAL_LATEST = 4;
  const CIVICARROT_PHP_SENSIBLE = 5;

  /**
   * @var array
   * Cache of packagist data.
   */
  private static $packagist = [];

  /**
   * @var string
   * The url of the gitlab repo
   */
  private $repourl;

  /**
   * @var string
   * The git branch for the PR
  */
  private $branch;

  /**
   * constructor
   * @param string $repourl
   * @param string $branch
   */
  public function __construct(string $repourl, string $branch) {
    $this->repourl = $repourl;
    $this->branch = $branch;
  }

  /**
   * Determine the desired testing matrix based on the values in civicarrot.json
   * @return string A JSON string suitable for github actions matrix
   */
  public function build(): string {
    $repourl = $this->removeDotGit($this->repourl);
    //$carrotjson = file_get_contents("{$repourl}/-/raw/{$this->branch}/tests/civicarrot.json");
    $carrotjson = '{"singlePR":{"include":[{"php-versions":"7.3","drupal":"~9.1.1","civicrm":"5.40.x-dev"},{"php-versions":"7.4","drupal":"CIVICARROT_DRUPAL_LATEST","civicrm":"dev-master"}]}}';
    $matrix = json_decode($carrotjson, TRUE);
    $matrix = $this->fillMatrix($matrix['singlePR'] ?? []);
    return $this->replaceCarrotVars(json_encode($matrix));
  }

  /**
   * If some parameters are missing put in some defaults.
   * It's a little trickier if they're using "include", so just assume they
   * are specifying everything in that case.
   * @param array $matrix
   * @return array
   */
  private function fillMatrix(array $matrix): array {
    if (!isset($matrix['include'])) {
      if (empty($matrix['php-versions'])) {
        $matrix['php-versions'] = ['CIVICARROT_PHP_SENSIBLE'];
      }
      if (empty($matrix['drupal'])) {
        $matrix['drupal'] = ['CIVICARROT_DRUPAL_LATEST'];
      }
      if (empty($matrix['civicrm'])) {
        $matrix['civicrm'] = ['CIVICARROT_CIVI_RELEASECANDIDATE'];
      }
    }
    return $matrix;
  }

  /**
   * Replace some placeholder vars with actual strings.
   * @param string $s
   * @return string
   */
  private function replaceCarrotVars(string $s): string {
    // Note we try to avoid network calls if there's no replacement needed.
    if (strpos($s, 'CIVICARROT_DRUPAL_LATEST') !== FALSE) {
      $s = str_replace('CIVICARROT_DRUPAL_LATEST', $this->getDrupalVersion(), $s);
    }
    if (strpos($s, 'CIVICARROT_CIVI_DEV') !== FALSE) {
      $s = str_replace('CIVICARROT_CIVI_DEV', $this->getCiviVersion(self::CIVICARROT_CIVI_DEV), $s);
    }
    if (strpos($s, 'CIVICARROT_CIVI_RELEASECANDIDATE') !== FALSE) {
      $s = str_replace('CIVICARROT_CIVI_RELEASECANDIDATE', $this->getCiviVersion(self::CIVICARROT_CIVI_RELEASECANDIDATE), $s);
    }
    if (strpos($s, 'CIVICARROT_CIVI_LATEST') !== FALSE) {
      $s = str_replace('CIVICARROT_CIVI_LATEST', $this->getCiviVersion(self::CIVICARROT_CIVI_LATEST), $s);
    }
    if (strpos($s, 'CIVICARROT_PHP_SENSIBLE') !== FALSE) {
      $s = str_replace('CIVICARROT_PHP_SENSIBLE', $this->getPhpVersion(), $s);
    }
    return $s;
  }

  /**
   * Get a drupal version.
   * At the moment only CIVICARROT_DRUPAL_LATEST is supported.
   * @return string
   */
  private function getDrupalVersion(): string {
    echo "\nhi\n";
    $version = $this->getLatestFromPackagist('drupal/core');
    return empty($version) ? '^9' : "~{$version}";
  }

  /**
   * Get a civi version.
   * @param int $stage The enum corresponding to how cutting-edge a version
   *   you want.
   * @return string
   */
  private function getCiviVersion(int $stage): string {
    if ($stage === self::CIVICARROT_CIVI_DEV) {
      // don't even need to make network call
      return 'dev-master';
    }
    $version = $this->getLatestFromPackagist('civicrm/civicrm-core');
    if (empty($version)) {
      $version = 'dev-master';
    }
    elseif ($stage === self::CIVICARROT_CIVI_RELEASECANDIDATE) {
      $version = explode('.', $version);
      $version = "{$version[0]}." . ($version[1] + 1) . '.x-dev';
    }
    // otherwise if self::CIVICARROT_CIVI_LATEST or something else then just leave as-is
    return $version;
  }

  /**
   * Get metadata about a package from packagist.org
   * Cache it since we might call it for the same package a few times.
   * @param string $package e.g. drupal/core
   * @return string
   */
  private function getLatestFromPackagist(string $package): string {
    if (empty(self::$packagist[$package])) {
      $json = file_get_contents("https://repo.packagist.org/p2/{$package}.json");
      $info = json_decode($json, TRUE);
      self::$packagist[$package] = $info['packages'][$package][0] ?? [];
    }
    return self::$packagist[$package]['version'] ?? '';
  }

  /**
   * Get a php version.
   * At the moment only CIVICARROT_PHP_SENSIBLE is supported.
   * @return string
   */
  private function getPhpVersion(): string {
    // Just use the version this site is running, since it runs drupal+civi
    // and so is likely to be a reasonable choice.
    return phpversion();
  }

  /**
   * Removes .git on the end if present
   * @param string $s
   * @return string
   */
  private function removeDotGit(string $s): string {
    if (substr($s, -4, 4) === '.git') {
      return substr($s, 0, -4);
    }
    return $s;
  }

}
