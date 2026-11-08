<?php

declare(strict_types=1);

use Nextcloud\Rector\Set\NextcloudSets;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
	->withPaths([
		__DIR__ . '/lib',
		__DIR__ . '/tests',
	])
	->withPhpSets(php83: true)
	->withTypeCoverageLevel(0)
	->withSkip([
		// `AlberoFinto` nomina `OCP\Files\IUserFolder` come stringa di
		// proposito: l'interfaccia esiste solo da Nextcloud 36, e scriverla
		// come `::class` la renderebbe irrisolvibile fino alla 35.
		StringClassNameToClassConstantRector::class => [
			__DIR__ . '/tests/Finzione/AlberoFinto.php',
		],
	])
	->withSets([
		// La versione minima supportata: le regole della 34 e della 35 sono le stesse.
		NextcloudSets::NEXTCLOUD_33,
	]);
