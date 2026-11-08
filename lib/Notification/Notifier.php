<?php

declare(strict_types=1);

namespace OCA\Impagina\Notification;

use OCA\Impagina\AppInfo\Application;
use OCA\Impagina\Service\Notifiche;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Traduce le notifiche dell'app nella lingua di chi le legge.
 *
 * Il job salva solo codici e parametri: qui diventano frasi, nella lingua del
 * destinatario e non in quella di chi ha avviato la conversione (che e' la
 * stessa persona, ma la regola vale comunque).
 */
final readonly class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function getID(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Impagina');
	}

	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$parametri = $notification->getSubjectParameters();
		$sorgente = Spiegazione::testo($parametri, 'sorgente');

		$notification->setIcon($this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'),
		));

		return match ($notification->getSubject()) {
			Notifiche::SOGGETTO_RIUSCITA => $this->riuscita($notification, $l, $parametri, $sorgente),
			Notifiche::SOGGETTO_FALLITA => $this->fallita($notification, $l, $parametri, $sorgente),
			default => throw new UnknownNotificationException(),
		};
	}

	/**
	 * @param array<array-key, mixed> $parametri
	 */
	private function riuscita(
		INotification $notification,
		\OCP\IL10N $l,
		array $parametri,
		string $sorgente,
	): INotification {
		$nome = Spiegazione::testo($parametri, 'nome');
		$percorso = Spiegazione::testo($parametri, 'percorso');
		$fileId = $notification->getObjectId();
		$collegamento = $this->urlGenerator->linkToRouteAbsolute('files.View.showFile', ['fileid' => $fileId]);

		$notification->setParsedSubject($l->t('%s is ready', [$nome]));
		$notification->setRichSubject(
			$l->t('{file} is ready'),
			[
				'file' => [
					'type' => 'file',
					'id' => $fileId,
					'name' => $nome,
					'path' => $percorso,
					'link' => $collegamento,
				],
			],
		);
		$notification->setLink($collegamento);

		$avvisi = Spiegazione::elenco($parametri, 'avvisi');
		$messaggio = $l->t('Converted from %s.', [$sorgente]);
		if ($avvisi !== []) {
			$messaggio .= ' ' . $l->n(
				'One warning during conversion: %s',
				'%n warnings during conversion: %s',
				count($avvisi),
				[implode(' · ', $avvisi)],
			);
		}
		$notification->setParsedMessage($messaggio);

		return $notification;
	}

	/**
	 * @param array<array-key, mixed> $parametri
	 */
	private function fallita(
		INotification $notification,
		\OCP\IL10N $l,
		array $parametri,
		string $sorgente,
	): INotification {
		$notification->setParsedSubject($l->t('%s was not converted', [$sorgente]));
		$notification->setRichSubject($l->t('{file} was not converted'), [
			'file' => [
				'type' => 'highlight',
				'id' => $notification->getObjectId(),
				'name' => $sorgente,
			],
		]);
		$notification->setParsedMessage(Spiegazione::motivo($l, $parametri));

		return $notification;
	}
}
