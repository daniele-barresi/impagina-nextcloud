<?php

declare(strict_types=1);

namespace OCA\Impagina\Service;

use OCP\IGroupManager;

/**
 * Chi puo' usare l'app.
 *
 * Se l'amministratore non sceglie nessun gruppo, possono tutti. Gli
 * amministratori sono **sempre** abilitati: chi configura l'app deve poterla
 * provare senza aggiungersi a un gruppo.
 */
final readonly class AccessService {
	public function __construct(
		private ConfigService $config,
		private IGroupManager $gruppi,
	) {
	}

	public function abilitato(string $userId): bool {
		if ($this->gruppi->isAdmin($userId)) {
			return true;
		}

		$abilitati = $this->config->impostazioni()->gruppi;
		if ($abilitati === []) {
			return true;
		}

		foreach ($abilitati as $gruppo) {
			if ($this->gruppi->isInGroup($userId, $gruppo)) {
				return true;
			}
		}

		return false;
	}
}
