<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Finzione;

use OCA\Impagina\Service\IAttesa;

/**
 * Attesa che non dorme: registra soltanto i secondi richiesti.
 */
final class AttesaFinta implements IAttesa {
	/** @var list<int> */
	private array $attese = [];

	#[\Override]
	public function dormi(int $secondi): void {
		$this->attese[] = $secondi;
	}

	/**
	 * @return list<int>
	 */
	public function attese(): array {
		return $this->attese;
	}
}
