<?php

declare(strict_types=1);

namespace OCA\Impagina\Controller;

use OCA\Impagina\BackgroundJob\ConvertJob;
use OCA\Impagina\Model\EsitoConversione as Esito;
use OCA\Impagina\Notification\Spiegazione;
use OCA\Impagina\Service\AccessService;
use OCA\Impagina\Service\Blocco;
use OCA\Impagina\Service\ConfigService;
use OCA\Impagina\Service\Conversione;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\BackgroundJob\IJobList;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * L'endpoint chiamato dall'azione "Converti in PDF con Impagina" dell'app File.
 *
 * Tutti i controlli si rifanno qui lato server, non solo nell'interfaccia:
 * gruppo abilitato, esistenza e lettura del file, estensione, permesso di
 * creare nella cartella e dimensione.
 *
 * La conversione si prova **subito**, durante la richiesta: il servizio
 * impiega meno di un secondo, e cosi' l'utente non aspetta il cron. Solo se il
 * servizio e' occupato, irraggiungibile o lento la conversione passa a un
 * lavoro in background, e la risposta lo dice.
 */
final class ConvertController extends OCSController {
	/** @var list<string> */
	private const array ESTENSIONI = ['md', 'markdown'];

	/**
	 * Quanto si aspetta il risultato durante la richiesta: abbastanza per un
	 * documento normale, poco per tenere l'utente davanti a un messaggio di
	 * attesa. Oltre, la conversione prosegue in background.
	 */
	public const int ATTESA_IMMEDIATA = 20;

	/** Durata del blocco contro i doppi clic: copre anche i timeout HTTP. */
	private const int DURATA_BLOCCO = 300;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $sessione,
		private readonly IRootFolder $rootFolder,
		private readonly AccessService $accesso,
		private readonly ConfigService $config,
		private readonly IJobList $lavori,
		private readonly Conversione $conversione,
		private readonly Blocco $blocco,
		private readonly IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Converte il file e risponde con l'esito, oppure accoda la conversione
	 * se il servizio non puo' farla adesso.
	 *
	 * Stati della risposta: `completata` (con `fileId` e `nome` del PDF),
	 * `in_coda`, `gia_in_coda`, `gia_in_corso` e `fallita` (con `messaggio`).
	 *
	 * @throws OCSException se l'app non e' configurata
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/convert')]
	public function convert(int $fileId): DataResponse {
		$utente = $this->sessione->getUser();
		if ($utente === null) {
			throw new OCSForbiddenException($this->l10n->t('You are not logged in.'));
		}

		$userId = $utente->getUID();
		if (!$this->accesso->abilitato($userId)) {
			throw new OCSForbiddenException($this->l10n->t('You are not allowed to use Impagina.'));
		}

		if (!$this->config->configurato()) {
			throw new OCSException(
				$this->l10n->t('Impagina is not configured yet. Ask an administrator to set it up.'),
				Http::STATUS_PRECONDITION_FAILED,
			);
		}

		$file = $this->file($userId, $fileId);

		if (!in_array(strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)), self::ESTENSIONI, true)) {
			throw new OCSBadRequestException($this->l10n->t('Only Markdown files can be converted.'));
		}

		$cartella = $file->getParent();
		if (($cartella->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
			throw new OCSForbiddenException(
				$this->l10n->t('You cannot create the PDF in the folder of this document.'),
			);
		}

		if ($file->getSize() > Conversione::MASSIMO_MARKDOWN) {
			throw new OCSException(
				$this->l10n->t('This Markdown file is too large to be converted.'),
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
			);
		}

		$argomento = ['userId' => $userId, 'fileId' => $fileId];

		// Due clic ravvicinati non devono produrre due PDF: se il lavoro c'e'
		// gia', o se la stessa conversione e' in corso, va bene cosi'.
		if ($this->lavori->has(ConvertJob::class, $argomento)) {
			return new DataResponse(['stato' => 'gia_in_coda'], Http::STATUS_ACCEPTED);
		}
		if (!$this->blocco->prendi($userId, $fileId, self::DURATA_BLOCCO)) {
			return new DataResponse(['stato' => 'gia_in_corso'], Http::STATUS_ACCEPTED);
		}

		try {
			$esito = $this->conversione->subito($userId, $fileId, self::ATTESA_IMMEDIATA);

			// Il lavoro si accoda prima di rilasciare il blocco: in mezzo, un
			// altro clic non deve trovare ne' l'uno ne' l'altro.
			if ($esito->stato === Esito::RIMANDATA) {
				$this->lavori->add(ConvertJob::class, $argomento);
			}
		} finally {
			$this->blocco->rilascia($userId, $fileId);
		}

		return match ($esito->stato) {
			Esito::COMPLETATA => new DataResponse([
				'stato' => 'completata',
				'fileId' => $esito->fileIdPdf,
				'nome' => $esito->nomePdf,
			], Http::STATUS_CREATED),
			Esito::RIMANDATA => new DataResponse(['stato' => 'in_coda'], Http::STATUS_ACCEPTED),
			default => new DataResponse([
				'stato' => 'fallita',
				'messaggio' => Spiegazione::motivo(
					$this->l10n,
					array_merge($esito->parametri, ['motivo' => $esito->motivo]),
				),
			]),
		};
	}

	private function file(string $userId, int $fileId): File {
		$nodo = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);

		if (!$nodo instanceof File) {
			throw new OCSNotFoundException($this->l10n->t('File not found.'));
		}
		if (!$nodo->isReadable()) {
			throw new OCSForbiddenException($this->l10n->t('You cannot read this file.'));
		}

		return $nodo;
	}
}
