<?php

declare(strict_types=1);

require_once './vendor-bin/cs-fixer/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config
	->getFinder()
	->notPath('build')
	->notPath('docs')
	->notPath('l10n')
	->notPath('node_modules')
	->notPath('src')
	->notPath('vendor')
	->notPath('vendor-bin')
	->in(__DIR__);

return $config;
