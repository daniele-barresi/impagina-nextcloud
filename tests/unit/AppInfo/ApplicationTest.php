<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\AppInfo;

use OCA\Impagina\AppInfo\Application;
use OCA\Impagina\Service\Attesa;
use OCA\Impagina\Service\IAttesa;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase {
	public function testAppIdMatchesInfoXml(): void {
		$info = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		self::assertNotFalse($info);
		self::assertSame(Application::APP_ID, (string)$info->id);
	}

	public function testRegistraLImplementazioneDellAttesa(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::once())
			->method('registerServiceAlias')
			->with(IAttesa::class, Attesa::class);

		// `new Application()` chiederebbe il container di un server Nextcloud
		// in esecuzione: qui serve solo il metodo register().
		$applicazione = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$applicazione->register($context);
	}
}
