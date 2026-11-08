<?php

declare(strict_types=1);

namespace OCA\Impagina\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Impagina\AppInfo\Application;
use OCA\Impagina\Service\AccessService;
use OCA\Impagina\Service\ConfigService;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Inserisce nell'app File lo script che registra l'azione
 * "Converti in PDF con Impagina".
 *
 * L'azione compare solo se l'app e' configurata e l'utente e' abilitato: il
 * controllo vero resta comunque lato server, questo serve a non mostrare voci
 * che darebbero errore.
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
final readonly class CaricaScriptFile implements IEventListener {
	public function __construct(
		private IUserSession $sessione,
		private AccessService $accesso,
		private ConfigService $config,
		private IInitialState $statoIniziale,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}

		$utente = $this->sessione->getUser();
		if ($utente === null) {
			return;
		}

		$this->statoIniziale->provideInitialState(
			'abilitato',
			$this->config->configurato() && $this->accesso->abilitato($utente->getUID()),
		);

		Util::addScript(Application::APP_ID, 'impagina-files', 'files');
		Util::addStyle(Application::APP_ID, 'impagina-files');
	}
}
