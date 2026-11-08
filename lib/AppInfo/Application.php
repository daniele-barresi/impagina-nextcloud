<?php

declare(strict_types=1);

namespace OCA\Impagina\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Impagina\Listener\CaricaScriptFile;
use OCA\Impagina\Notification\Notifier;
use OCA\Impagina\Service\Attesa;
use OCA\Impagina\Service\IAttesa;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap {
	public const string APP_ID = 'impagina';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// L'unica dipendenza non autowirabile del client: l'attesa fra due
		// tentativi, che i test sostituiscono con una che non dorme.
		$context->registerServiceAlias(IAttesa::class, Attesa::class);

		// `IManager::registerNotifier` e' stato rimosso: il notifier si
		// registra come servizio, che il container istanzia quando serve.
		$context->registerNotifierService(Notifier::class);

		// L'azione nel menu contestuale dell'app File.
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, CaricaScriptFile::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
