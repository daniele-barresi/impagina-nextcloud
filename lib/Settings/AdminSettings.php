<?php

declare(strict_types=1);

namespace OCA\Impagina\Settings;

use OCA\Impagina\AppInfo\Application;
use OCA\Impagina\Service\ConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * La pagina Impostazioni -> Amministrazione -> Impagina.
 *
 * Il modulo Vue riceve lo stato iniziale da `IInitialState`: la
 * configurazione **senza il token**, il fatto che un token sia stato impostato
 * e l'elenco dei gruppi fra cui scegliere.
 */
final readonly class AdminSettings implements ISettings {
	public function __construct(
		private ConfigService $config,
		private IInitialState $statoIniziale,
		private IGroupManager $gruppi,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		$this->statoIniziale->provideInitialState('impostazioni', $this->config->impostazioni()->perInterfaccia());
		$this->statoIniziale->provideInitialState('tokenImpostato', $this->config->haToken());
		$this->statoIniziale->provideInitialState('gruppi', $this->gruppiDisponibili());

		// La build separa il CSS dal JavaScript: senza addStyle i componenti di
		// @nextcloud/vue arrivano senza stili.
		Util::addScript(Application::APP_ID, 'impagina-admin-settings');
		Util::addStyle(Application::APP_ID, 'impagina-admin-settings');

		return new TemplateResponse(Application::APP_ID, 'admin-settings');
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getPriority(): int {
		return 50;
	}

	/**
	 * @return list<array{id: string, nome: string}>
	 */
	private function gruppiDisponibili(): array {
		$gruppi = [];
		foreach ($this->gruppi->search('') as $gruppo) {
			$gruppi[] = ['id' => $gruppo->getGID(), 'nome' => $gruppo->getDisplayName()];
		}

		return $gruppi;
	}
}
