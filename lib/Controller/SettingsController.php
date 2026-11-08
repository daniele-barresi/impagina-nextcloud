<?php

declare(strict_types=1);

namespace OCA\Impagina\Controller;

use OCA\Impagina\Exception\ConfigurazioneException;
use OCA\Impagina\Exception\ConfigurazioneNonValidaException;
use OCA\Impagina\Exception\ImpaginaException;
use OCA\Impagina\Exception\ReteException;
use OCA\Impagina\Exception\RispostaNonValidaException;
use OCA\Impagina\Exception\ServizioOccupatoException;
use OCA\Impagina\Model\ImpostazioniAdmin;
use OCA\Impagina\Service\ConfigService;
use OCA\Impagina\Service\ImpaginaClient;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Endpoint della pagina di amministrazione: salvataggio della configurazione e
 * verifica della connessione al servizio.
 *
 * Senza `#[NoAdminRequired]` il framework li riserva agli amministratori
 * dell'istanza; la delega per sezione richiederebbe `IDelegatedSettings`, che
 * qui non serve. Il token entra ma non esce mai: l'interfaccia sa solo se e'
 * impostato.
 */
final class SettingsController extends OCSController {
	/** Lunghezza minima plausibile per un token `imp_...`. */
	private const int TOKEN_LUNGHEZZA_MINIMA = 8;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConfigService $config,
		private readonly ImpaginaClient $client,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Salva la configurazione dell'istanza.
	 *
	 * Il token si aggiorna solo se ne arriva uno nuovo: l'interfaccia non lo
	 * rilegge mai, quindi un campo vuoto significa "lascia quello che c'e'".
	 *
	 * @param array<string, mixed> $impostazioni
	 */
	#[ApiRoute(verb: 'POST', url: '/api/impostazioni')]
	public function salva(array $impostazioni = [], ?string $token = null, bool $cancellaToken = false): DataResponse {
		try {
			$valide = ImpostazioniAdmin::daArray($impostazioni);
		} catch (ConfigurazioneNonValidaException $e) {
			throw new OCSBadRequestException($this->messaggioValidazione($e));
		}

		$this->config->salva($valide);

		if ($cancellaToken) {
			$this->config->cancellaToken();
		} elseif ($token !== null && trim($token) !== '') {
			$token = trim($token);
			if (preg_match('/^\S{' . self::TOKEN_LUNGHEZZA_MINIMA . ',}$/', $token) !== 1) {
				throw new OCSBadRequestException($this->l10n->t('The token does not look valid.'));
			}
			$this->config->salvaToken($token);
		}

		return new DataResponse([
			'impostazioni' => $this->config->impostazioni()->perInterfaccia(),
			'tokenImpostato' => $this->config->haToken(),
		]);
	}

	/**
	 * Verifica la connessione in due passi, sui valori mostrati nella pagina
	 * (anche se non ancora salvati):
	 *
	 * 1. `GET /salute` senza autenticazione: il servizio e' raggiungibile?
	 * 2. `GET /template` con il token: il token e' valido?
	 *
	 * I due esiti restano distinti, cosi' l'amministratore sa se il problema e'
	 * l'indirizzo o la chiave.
	 *
	 * @param array<string, mixed> $impostazioni
	 */
	#[ApiRoute(verb: 'POST', url: '/api/verifica')]
	public function verifica(array $impostazioni = [], ?string $token = null): DataResponse {
		try {
			$valide = ImpostazioniAdmin::daArray($impostazioni);
		} catch (ConfigurazioneNonValidaException $e) {
			throw new OCSBadRequestException($this->messaggioValidazione($e));
		}

		$tokenDaProvare = $token !== null && trim($token) !== '' ? trim($token) : $this->config->token();
		$connessione = $valide->connessione($tokenDaProvare);

		try {
			$salute = $this->client->salute($connessione);
		} catch (ImpaginaException $e) {
			$this->logger->info('impagina: verifica della raggiungibilita\' non riuscita', ['exception' => $e]);

			return new DataResponse([
				'servizio' => ['stato' => 'errore', 'messaggio' => $this->messaggioTecnico($e)],
				'token' => ['stato' => 'non_provato', 'messaggio' => ''],
			]);
		}

		$avviso = null;
		if (!$salute->supportata()) {
			$avviso = $this->l10n->t(
				'The service reports version %s, while this app supports the 1.x and 2.x series.',
				[$salute->versione()],
			);
		}

		$servizio = [
			'stato' => 'ok',
			'messaggio' => $this->l10n->t(
				'Service reached: version %1$s, %2$d parallel conversions, %3$d queued, %4$d running.',
				[$salute->versione(), $salute->worker(), $salute->inCoda(), $salute->inCorso()],
			),
			'avviso' => $avviso,
			'versione' => $salute->versione(),
		];

		if ($tokenDaProvare === '') {
			return new DataResponse([
				'servizio' => $servizio,
				'token' => [
					'stato' => 'mancante',
					'messaggio' => $this->l10n->t('No token set: enter one to complete the check.'),
				],
			]);
		}

		try {
			$template = $this->client->template($connessione);
		} catch (ImpaginaException $e) {
			$this->logger->info('impagina: verifica del token non riuscita', ['exception' => $e]);

			return new DataResponse([
				'servizio' => $servizio,
				'token' => ['stato' => 'errore', 'messaggio' => $this->messaggioTecnico($e)],
			]);
		}

		return new DataResponse([
			'servizio' => $servizio,
			'token' => [
				'stato' => 'ok',
				'messaggio' => $this->l10n->n(
					'Token accepted, %n template available.',
					'Token accepted, %n templates available.',
					count($template),
				),
				'template' => $template,
			],
		]);
	}

	/**
	 * I template disponibili secondo la configurazione salvata, per il menu a
	 * tendina. Se il servizio non risponde la pagina ripiega sul testo libero,
	 * quindi l'errore non e' fatale.
	 */
	#[ApiRoute(verb: 'GET', url: '/api/template')]
	public function template(): DataResponse {
		if (!$this->config->configurato()) {
			return new DataResponse(['template' => [], 'messaggio' => '']);
		}

		try {
			return new DataResponse(['template' => $this->client->template($this->config->connessione())]);
		} catch (ImpaginaException $e) {
			$this->logger->info('impagina: elenco dei template non disponibile', ['exception' => $e]);

			return new DataResponse(
				['template' => [], 'messaggio' => $this->messaggioTecnico($e)],
				Http::STATUS_OK,
			);
		}
	}

	private function messaggioValidazione(ConfigurazioneNonValidaException $e): string {
		$campi = [
			'schema' => $this->l10n->t('protocol'),
			'host' => $this->l10n->t('host'),
			'porta' => $this->l10n->t('port'),
			'percorsoBase' => $this->l10n->t('base path'),
			'timeoutConnessione' => $this->l10n->t('connection timeout'),
			'timeoutRichiesta' => $this->l10n->t('request timeout'),
			'template' => $this->l10n->t('template'),
			'cover' => $this->l10n->t('cover'),
			'watermark' => $this->l10n->t('watermark'),
			'dimensioneMassimaMb' => $this->l10n->t('maximum size'),
			'attesaMassima' => $this->l10n->t('maximum wait'),
			'gruppi' => $this->l10n->t('allowed groups'),
		];
		$campo = $campi[$e->campo()] ?? $e->campo();

		return match ($e->codice()) {
			'obbligatorio' => $this->l10n->t('The field "%s" is required.', [$campo]),
			'fuori_intervallo' => $this->l10n->t('The value of "%s" is out of the allowed range.', [$campo]),
			'troppo_lunga' => $this->l10n->t('The value of "%s" is too long.', [$campo]),
			default => $this->l10n->t('The value of "%s" is not valid.', [$campo]),
		};
	}

	/**
	 * Messaggio per l'amministratore: qui, a differenza delle notifiche agli
	 * utenti, ha senso riportare il dettaglio del servizio.
	 */
	private function messaggioTecnico(ImpaginaException $e): string {
		$dettaglio = $e->dettaglio();

		return match (true) {
			$e instanceof ReteException => $this->l10n->t(
				'Service unreachable. Check address, port and certificate, and on Nextcloud AIO that connections to the local network are allowed.',
			),
			$e instanceof ConfigurazioneException => $dettaglio === null
				? $this->l10n->t('The service rejected the request.')
				: $this->l10n->t('The service rejected the request: %s', [$dettaglio->testo()]),
			$e instanceof RispostaNonValidaException => $this->l10n->t(
				'The answer does not look like it comes from the impagina service: check the address and the base path.',
			),
			$e instanceof ServizioOccupatoException => $this->l10n->t(
				'The service is busy right now: try again in a moment.',
			),
			default => $dettaglio === null
				? $this->l10n->t('Conversion service error.')
				: $this->l10n->t('Conversion service error: %s', [$dettaglio->testo()]),
		};
	}
}
