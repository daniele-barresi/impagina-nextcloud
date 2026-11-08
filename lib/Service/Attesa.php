<?php

declare(strict_types=1);

namespace OCA\Impagina\Service;

final class Attesa implements IAttesa {
	#[\Override]
	public function dormi(int $secondi): void {
		if ($secondi > 0) {
			sleep($secondi);
		}
	}
}
