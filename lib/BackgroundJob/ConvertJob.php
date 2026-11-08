<?php

declare(strict_types=1);

namespace OCA\Impagina\BackgroundJob;

use OCA\Impagina\Service\Blocco;
use OCA\Impagina\Service\Conversione;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Il lavoro accodato da `ConvertController` quando la conversione immediata
 * non e' riuscita perche' il servizio era occupato, irraggiungibile o lento:
 * un file, un utente.
 *
 * Qui dentro c'e' solo la lettura degli argomenti; il flusso sta in
 * {@see Conversione}, che si prova senza il meccanismo dei lavori.
 *
 * Le `QueuedJob` partono con il cron di Nextcloud (in AIO ogni pochi
 * minuti): e' il motivo per cui si prova prima a convertire subito.
 * `occ background-job:worker 'OCA\Impagina\BackgroundJob\ConvertJob'`
 * le fa partire senza aspettare il cron.
 */
final class ConvertJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly Conversione $conversione,
		private readonly Blocco $blocco,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param mixed $argument `['userId' => string, 'fileId' => int]`
	 */
	#[\Override]
	protected function run($argument): void {
		if (!is_array($argument)
			|| !isset($argument['userId'], $argument['fileId'])
			|| !is_string($argument['userId'])
			|| !is_int($argument['fileId'])) {
			$this->logger->error('impagina: lavoro con argomenti non validi, ignorato');

			return;
		}

		$userId = $argument['userId'];
		$fileId = $argument['fileId'];

		// Il blocco ferma un nuovo clic mentre questo lavoro converte. Se non
		// si riesce a prenderlo si converte lo stesso: il lavoro e' gia' fuori
		// dalla coda, e saltarlo perderebbe la conversione.
		$preso = $this->blocco->prendi($userId, $fileId, Conversione::DURATA_BLOCCO_BACKGROUND);

		try {
			$this->conversione->esegui($userId, $fileId);
		} finally {
			if ($preso) {
				$this->blocco->rilascia($userId, $fileId);
			}
		}
	}
}
