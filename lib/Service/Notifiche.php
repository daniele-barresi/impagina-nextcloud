<?php

declare(strict_types=1);

namespace OCA\Impagina\Service;

use OCA\Impagina\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Invio delle notifiche di fine conversione.
 *
 * Nella notifica finiscono **codici e parametri**, mai frasi gia' tradotte:
 * la lingua giusta e' quella di chi la legge, e la sceglie il
 * {@see \OCA\Impagina\Notification\Notifier} quando la mostra.
 *
 * Se l'app Notifiche non e' abilitata la conversione non deve fallire per
 * questo: il PDF e' gia' stato salvato.
 */
final readonly class Notifiche {
	public const string SOGGETTO_RIUSCITA = 'conversione_riuscita';
	public const string SOGGETTO_FALLITA = 'conversione_fallita';

	public function __construct(
		private IManager $manager,
		private ITimeFactory $tempo,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param list<string> $avvisi avvisi di Typst sul documento convertito
	 */
	public function riuscita(
		string $userId,
		int $fileIdPdf,
		string $nomePdf,
		string $percorsoPdf,
		string $sorgente,
		array $avvisi = [],
	): void {
		$this->invia($userId, $fileIdPdf, self::SOGGETTO_RIUSCITA, [
			'nome' => $nomePdf,
			'percorso' => $percorsoPdf,
			'sorgente' => $sorgente,
			'avvisi' => $avvisi,
		]);
	}

	/**
	 * @param array<string, mixed> $parametri dati specifici del motivo
	 */
	public function fallita(
		string $userId,
		int $fileIdSorgente,
		string $sorgente,
		string $motivo,
		array $parametri = [],
	): void {
		$this->invia($userId, $fileIdSorgente, self::SOGGETTO_FALLITA, array_merge($parametri, [
			'sorgente' => $sorgente,
			'motivo' => $motivo,
		]));
	}

	/**
	 * @param array<string, mixed> $parametri
	 */
	private function invia(string $userId, int $fileId, string $soggetto, array $parametri): void {
		try {
			$notifica = $this->manager->createNotification();
			$notifica->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime($this->tempo->getDateTime())
				->setObject('file', (string)$fileId)
				->setSubject($soggetto, $parametri);

			$this->manager->notify($notifica);
		} catch (\Throwable $e) {
			// Tipicamente: l'app Notifiche non e' abilitata sull'istanza.
			$this->logger->warning('impagina: notifica non inviata a {utente}', [
				'utente' => $userId,
				'soggetto' => $soggetto,
				'exception' => $e,
			]);
		}
	}
}
