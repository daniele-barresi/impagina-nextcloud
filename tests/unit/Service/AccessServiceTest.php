<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Service;

use OCA\Impagina\Model\ImpostazioniAdmin;
use OCA\Impagina\Service\AccessService;
use OCA\Impagina\Service\ConfigService;
use OCA\Impagina\Tests\Finzione\ConfigurazioneFinta;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

final class AccessServiceTest extends TestCase {
	use ConfigurazioneFinta;

	private ConfigService $config;

	#[\Override]
	protected function setUp(): void {
		$this->config = new ConfigService($this->appConfigFinto());
	}

	/**
	 * @param list<string> $gruppiUtente
	 */
	private function accesso(array $gruppiUtente, bool $amministratore = false): AccessService {
		$gruppi = $this->createStub(IGroupManager::class);
		$gruppi->method('isAdmin')->willReturn($amministratore);
		$gruppi->method('isInGroup')->willReturnCallback(
			static fn (string $utente, string $gruppo): bool => in_array($gruppo, $gruppiUtente, true),
		);

		return new AccessService($this->config, $gruppi);
	}

	public function testSenzaGruppiScelticPossonoTutti(): void {
		self::assertTrue($this->accesso([])->abilitato('anna'));
	}

	public function testConUnElencoDiGruppiPassaSoloChiNeFaParte(): void {
		$this->config->salva(new ImpostazioniAdmin(gruppi: ['redazione', 'ufficio-stampa']));

		self::assertTrue($this->accesso(['redazione'])->abilitato('anna'));
		self::assertFalse($this->accesso(['contabilita'])->abilitato('bruno'));
	}

	public function testGliAmministratoriSonoSempreAbilitati(): void {
		$this->config->salva(new ImpostazioniAdmin(gruppi: ['redazione']));

		self::assertTrue($this->accesso([], amministratore: true)->abilitato('admin'));
	}
}
