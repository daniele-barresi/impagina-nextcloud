<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Finzione;

use OCA\Impagina\Service\Notifiche;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Log\NullLogger;

/**
 * Raccoglie le notifiche che l'app invierebbe, invece di mostrarle.
 *
 * Si usa il servizio `Notifiche` vero con un gestore simulato: cosi' i test
 * controllano anche che soggetto e parametri siano quelli attesi.
 *
 * @psalm-require-extends \PHPUnit\Framework\TestCase
 */
trait NotificheFinte {
	/** @var list<array{utente: string, oggetto: string, soggetto: string, parametri: array<string, mixed>}> */
	private array $notificheInviate = [];

	private function notificheFinte(): Notifiche {
		$gestore = $this->createStub(IManager::class);
		$gestore->method('createNotification')->willReturnCallback(
			fn (): INotification => $this->notificaFinta(),
		);
		$gestore->method('notify')->willReturnCallback(
			function (INotification $notifica): void {
				$this->notificheInviate[] = [
					'utente' => $this->utenteInCorso,
					'oggetto' => $this->oggettoInCorso,
					'soggetto' => $this->soggettoInCorso,
					'parametri' => $this->parametriInCorso,
				];
			},
		);

		$tempo = $this->createStub(ITimeFactory::class);
		$tempo->method('getDateTime')->willReturn(new \DateTime('2026-09-19 12:00:00'));

		return new Notifiche($gestore, $tempo, new NullLogger());
	}

	private string $utenteInCorso = '';
	private string $oggettoInCorso = '';
	private string $soggettoInCorso = '';

	/** @var array<string, mixed> */
	private array $parametriInCorso = [];

	private function notificaFinta(): INotification {
		$notifica = $this->createStub(INotification::class);
		$notifica->method('setApp')->willReturn($notifica);
		$notifica->method('setDateTime')->willReturn($notifica);
		$notifica->method('setUser')->willReturnCallback(
			function (string $utente) use ($notifica): INotification {
				$this->utenteInCorso = $utente;

				return $notifica;
			},
		);
		$notifica->method('setObject')->willReturnCallback(
			function (string $tipo, string $id) use ($notifica): INotification {
				$this->oggettoInCorso = $id;

				return $notifica;
			},
		);
		$notifica->method('getObjectId')->willReturnCallback(fn (): string => $this->oggettoInCorso);
		$notifica->method('setSubject')->willReturnCallback(
			function (string $nome, array $parametri = []) use ($notifica): INotification {
				$this->soggettoInCorso = $nome;
				/** @var array<string, mixed> $parametri */
				$this->parametriInCorso = $parametri;

				return $notifica;
			},
		);
		$notifica->method('getSubject')->willReturnCallback(fn (): string => $this->soggettoInCorso);

		return $notifica;
	}

	/**
	 * @return array{utente: string, oggetto: string, soggetto: string, parametri: array<string, mixed>}
	 */
	private function ultimaNotifica(): array {
		if ($this->notificheInviate === []) {
			self::fail('nessuna notifica inviata');
		}

		return $this->notificheInviate[count($this->notificheInviate) - 1];
	}

	private function motivoNotificato(): string {
		$parametri = $this->ultimaNotifica()['parametri'];

		return isset($parametri['motivo']) && is_string($parametri['motivo']) ? $parametri['motivo'] : '';
	}

	/**
	 * @return list<string>
	 */
	private function elencoNotificato(): array {
		$parametri = $this->ultimaNotifica()['parametri'];
		if (!isset($parametri['elenco']) || !is_array($parametri['elenco'])) {
			return [];
		}

		$elenco = [];
		/** @psalm-suppress MixedAssignment il tipo di ogni voce e' verificato qui sotto */
		foreach ($parametri['elenco'] as $voce) {
			if (is_string($voce)) {
				$elenco[] = $voce;
			}
		}

		return $elenco;
	}
}
