<?php

ini_set('memory_limit', '2G');

define('CIVICRM_TEST', 1);
define('CIVICRM_UF', 'UnitTests');

// Load settings only, as core's tests/phpunit/CiviTest/bootstrap.php does: a
// full boot needs CiviCRM's tables, but on a fresh CI database they don't
// exist yet. CiviUnitTestCase::setUpBeforeClass() installs them, then boots.
// phpcs:disable
eval(cv('php:boot --level=settings', 'phpcode'));
// phpcs:enable
// Allow autoloading of PHPUnit helper classes in this extension.
$loader = new \Composer\Autoload\ClassLoader();
$loader->add('CRM_', __DIR__);
// ...and the extension's own classes. PHPUnit reads them while collecting
// tests (e.g. constants in data providers) - on a fresh database that is
// before CiviCRM has installed the extension and registered its classloader.
$loader->add('CRM_', dirname(__DIR__, 2));
$loader->add('Civi\\', __DIR__);
$loader->add('api_', __DIR__);
$loader->add('api\\', __DIR__);
$loader->register();

/**
 * Call the "cv" command.
 *
 * @param string $cmd
 *   The rest of the command to send.
 * @param string $decode
 *   Ex: 'json' or 'phpcode'.
 * @return string
 *   Response output (if the command executed normally).
 * @throws \RuntimeException
 *   If the command terminates abnormally.
 */
function cv($cmd, $decode = 'json') {
  $cmd = 'cv ' . $cmd;
  $descriptorSpec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => STDERR];
  $oldOutput = getenv('CV_OUTPUT');
  putenv("CV_OUTPUT=json");

  // Execute `cv` in the original folder. This is a work-around for
  // phpunit/codeception, which seem to manipulate PWD.
  $cmd = sprintf('cd %s; %s', escapeshellarg(getenv('PWD')), $cmd);

  $process = proc_open($cmd, $descriptorSpec, $pipes, __DIR__);
  putenv("CV_OUTPUT=$oldOutput");
  fclose($pipes[0]);
  $result = stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  if (proc_close($process) !== 0) {
    throw new RuntimeException("Command failed ($cmd):\n$result");
  }
  switch ($decode) {
    case 'raw':
      return $result;

    case 'phpcode':
      // If the last output is /*PHPCODE*/, then we managed to complete execution.
      if (substr(trim($result), 0, 12) !== "/*BEGINPHP*/" || substr(trim($result), -10) !== "/*ENDPHP*/") {
        throw new \RuntimeException("Command failed ($cmd):\n$result");
      }
      return $result;

    case 'json':
      return json_decode($result, 1);

    default:
      throw new RuntimeException("Bad decoder format ($decode)");
  }
}
