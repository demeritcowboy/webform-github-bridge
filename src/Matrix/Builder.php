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
    $carrotjson = file_get_contents("{$repourl}/-/raw/{$this->branch}/tests/civicarrot.json");
    $matrix = json_decode($carrotjson, TRUE);
    $matrix = $this->fillMatrix($matrix['singlePR'] ?? []);
    return $this->replaceCarrotVars(json_encode($matrix));
  }

  /**
   * If some parameters are missing put in some defaults.
   * @param array $matrix
   * @return array
   */
  private function fillMatrix(array $matrix): array {
    if (empty($matrix['php-versions'])) {
      $matrix['php-versions'] = ['CIVICARROT_PHP_SENSIBLE'];
    }
    if (empty($matrix['drupal'])) {
      $matrix['drupal'] = ['CIVICARROT_DRUPAL_LATEST'];
    }
    if (empty($matrix['civicrm'])) {
      $matrix['civicrm'] = ['CIVICARROT_CIVI_RELEASECANDIDATE'];
    }
    return $matrix;
  }

  /**
   * Replace some placeholder vars with actual strings.
   * @param string $s
   * @return string
   */
  private function replaceCarrotVars(string $s): string {
    $s = str_replace('CIVICARROT_DRUPAL_LATEST', $this->getDrupalVersion(), $s);
    $s = str_replace('CIVICARROT_CIVI_DEV', $this->getCiviVersion(self::CIVICARROT_CIVI_DEV), $s);
    $s = str_replace('CIVICARROT_CIVI_RELEASECANDIDATE', $this->getCiviVersion(self::CIVICARROT_CIVI_RELEASECANDIDATE), $s);
    $s = str_replace('CIVICARROT_CIVI_LATEST', $this->getCiviVersion(self::CIVICARROT_CIVI_LATEST), $s);
    // This should be last since we compute it from the packagist data we
    // already retrieved for the other packages.
    $s = str_replace('CIVICARROT_PHP_SENSIBLE', $this->getPhpVersion(), $s);
    return $s;
  }

  private function getDrupalVersion(): string {
    $version = $this->getLatestFromPackagist('drupal/core');
    return empty($version) ? '^9' : "~{$version}";
  }

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
      $version = "{$version[0]}." . ($version[1] - 1) . '.x-dev';
    }
    // otherwise if self::CIVICARROT_CIVI_LATEST or something else then just leave as-is
    return $version;
  }

  private function getLatestFromPackagist(string $package): string {
    if (empty(self::$packagist[$package])) {
      $json = file_get_contents('https://repo.packagist.org/p2/{$package}.json');
      $info = json_decode($json, TRUE);
      self::$packagist[$package] = $info['packages'][$package][0] ?? [];
    }
    return self::$packagist[$package]['version'] ?? '';
  }

  private function getPhpVersion(): string {
    // TODO: Just scrap all this and use the version this site is running?
    $highest_version = '7.0';
    foreach (self::$packagist as $package) {
      $php = $package['require']['php'] ?? NULL;
      if (empty($php)) {
        continue;
      }
      // Take just the first one separated by either space or |
      $php = explode('|', $php);
      $php = explode(' ', $php[0]);
      // Remove other junk
      $php = preg_replace('[^\d\.]', $php[0]);
      // Just care about the major+minor version
      $php = explode('.', $php);
      $php = $php[0] . (isset($php[1]) ? ".{$php[1]}" : '.0');
      if (version_compare($php, $highest_version, '>')) {
        $highest_version = $php;
      }
    }
    return $highest_version;
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
