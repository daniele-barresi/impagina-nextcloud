<?php

declare(strict_types=1);

namespace OCA\Impagina\Service;

use OCA\Impagina\AppInfo\Application;
use OCA\Impagina\Model\Connessione;
use OCA\Impagina\Model\ImpostazioniAdmin;
use OCA\Impagina\Model\OpzioniConversione;
use OCP\IAppConfig;

/**
 * Accesso tipizzato alla configurazione dell'app.
 *
 * Il token e' l'unico valore trattato a parte: si salva con il flag
 * `sensitive` di `IAppConfig` (cosi' `occ config:list` lo oscura) e non viene
 * mai restituito all'interfaccia, che ne conosce solo l'esistenza.
 */
final readonly class ConfigService {
	public const string CHIAVE_SCHEMA = 'schema';
	public const string CHIAVE_HOST = 'host';
	public const string CHIAVE_PORTA = 'porta';
	public const string CHIAVE_PERCORSO_BASE = 'percorso_base';
	public const string CHIAVE_TOKEN = 'token';
	public const string CHIAVE_VERIFICA_TLS = 'verifica_tls';
	public const string CHIAVE_TIMEOUT_CONNESSIONE = 'timeout_connessione';
	public const string CHIAVE_TIMEOUT_RICHIESTA = 'timeout_richiesta';
	public const string CHIAVE_TEMPLATE = 'template';
	public const string CHIAVE_TOC = 'toc';
	public const string CHIAVE_COVER = 'cover';
	public const string CHIAVE_WATERMARK = 'watermark';
	public const string CHIAVE_DIMENSIONE_MASSIMA = 'dimensione_massima_mb';
	public const string CHIAVE_ATTESA_MASSIMA = 'attesa_massima';
	public const string CHIAVE_GRUPPI = 'gruppi';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function impostazioni(): ImpostazioniAdmin {
		$porta = $this->appConfig->getValueInt(Application::APP_ID, self::CHIAVE_PORTA, 0);

		return new ImpostazioniAdmin(
			$this->appConfig->getValueString(Application::APP_ID, self::CHIAVE_SCHEMA, 'https'),
			$this->appConfig->getValueString(Application::APP_ID, self::CHIAVE_HOST),
			$porta === 0 ? null : $porta,
			$this->appConfig->getValueString(Application::APP_ID, self::CHIAVE_PERCORSO_BASE),
			$this->appConfig->getValueBool(Application::APP_ID, self::CHIAVE_VERIFICA_TLS, true),
			$this->appConfig->getValueInt(Application::APP_ID, self::CHIAVE_TIMEOUT_CONNESSIONE, 10),
			$this->appConfig->getValueInt(Application::APP_ID, self::CHIAVE_TIMEOUT_RICHIESTA, 60),
			$this->appConfig->getValueString(Application::APP_ID, self::CHIAVE_TEMPLATE, 'company'),
			$this->appConfig->getValueBool(Application::APP_ID, self::CHIAVE_TOC, false),
			$this->appConfig->getValueString(
				Application::APP_ID,
				self::CHIAVE_COVER,
				ImpostazioniAdmin::COVER_TEMPLATE,
			),
			$this->appConfig->getValueString(Application::APP_ID, self::CHIAVE_WATERMARK),
			$this->appConfig->getValueInt(
				Application::APP_ID,
				self::CHIAVE_DIMENSIONE_MASSIMA,
				ImpostazioniAdmin::DIMENSIONE_MASSIMA_MB_PREDEFINITA,
			),
			$this->appConfig->getValueInt(
				Application::APP_ID,
				self::CHIAVE_ATTESA_MASSIMA,
				ImpostazioniAdmin::ATTESA_MASSIMA_PREDEFINITA,
			),
			$this->gruppi(),
		);
	}

	public function salva(ImpostazioniAdmin $impostazioni): void {
		$this->appConfig->setValueString(Application::APP_ID, self::CHIAVE_SCHEMA, $impostazioni->schema);
		$this->appConfig->setValueString(Application::APP_ID, self::CHIAVE_HOST, $impostazioni->host);
		$this->appConfig->setValueInt(Application::APP_ID, self::CHIAVE_PORTA, $impostazioni->porta ?? 0);
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CHIAVE_PERCORSO_BASE,
			$impostazioni->percorsoBase,
		);
		$this->appConfig->setValueBool(Application::APP_ID, self::CHIAVE_VERIFICA_TLS, $impostazioni->verificaTls);
		$this->appConfig->setValueInt(
			Application::APP_ID,
			self::CHIAVE_TIMEOUT_CONNESSIONE,
			$impostazioni->timeoutConnessione,
		);
		$this->appConfig->setValueInt(
			Application::APP_ID,
			self::CHIAVE_TIMEOUT_RICHIESTA,
			$impostazioni->timeoutRichiesta,
		);
		$this->appConfig->setValueString(Application::APP_ID, self::CHIAVE_TEMPLATE, $impostazioni->template);
		$this->appConfig->setValueBool(Application::APP_ID, self::CHIAVE_TOC, $impostazioni->toc);
		$this->appConfig->setValueString(Application::APP_ID, self::CHIAVE_COVER, $impostazioni->cover);
		$this->appConfig->setValueString(Application::APP_ID, self::CHIAVE_WATERMARK, $impostazioni->watermark);
		$this->appConfig->setValueInt(
			Application::APP_ID,
			self::CHIAVE_DIMENSIONE_MASSIMA,
			$impostazioni->dimensioneMassimaMb,
		);
		$this->appConfig->setValueInt(Application::APP_ID, self::CHIAVE_ATTESA_MASSIMA, $impostazioni->attesaMassima);
		$this->appConfig->setValueArray(Application::APP_ID, self::CHIAVE_GRUPPI, $impostazioni->gruppi);
	}

	/**
	 * Salva il token come valore sensibile. `IAppConfig::setValueString`
	 * prende `sensitive` come quinto parametro: qui si passa per nome, perche'
	 * scambiarlo con `lazy` significherebbe scrivere il token in chiaro nelle
	 * liste di configurazione.
	 */
	public function salvaToken(string $token): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CHIAVE_TOKEN,
			$token,
			sensitive: true,
		);
	}

	public function cancellaToken(): void {
		$this->appConfig->deleteKey(Application::APP_ID, self::CHIAVE_TOKEN);
	}

	public function token(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::CHIAVE_TOKEN);
	}

	public function haToken(): bool {
		return $this->token() !== '';
	}

	/**
	 * Vero quando ci sono host e token: senza, l'app non puo' convertire nulla.
	 */
	public function configurato(): bool {
		return $this->impostazioni()->host !== '' && $this->haToken();
	}

	public function connessione(): Connessione {
		return $this->impostazioni()->connessione($this->token());
	}

	public function opzioni(): OpzioniConversione {
		return $this->impostazioni()->opzioni();
	}

	/**
	 * @return list<string>
	 */
	private function gruppi(): array {
		$gruppi = [];
		/** @psalm-suppress MixedAssignment il tipo di ogni voce e' verificato qui sotto */
		foreach ($this->appConfig->getValueArray(Application::APP_ID, self::CHIAVE_GRUPPI) as $gruppo) {
			if (is_string($gruppo) && $gruppo !== '') {
				$gruppi[] = $gruppo;
			}
		}

		return $gruppi;
	}
}
