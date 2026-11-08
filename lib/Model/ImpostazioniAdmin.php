<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

use OCA\Impagina\Exception\ConfigurazioneNonValidaException;

/**
 * La configurazione dell'istanza, valida per tutte le conversioni.
 *
 * La validazione sta qui e non nel controller: e' la stessa sia che i valori
 * arrivino dalla pagina di amministrazione sia che arrivino da `occ config:app:set`.
 * Il token non fa parte di questo oggetto, perche' e' un valore sensibile con
 * un ciclo di vita diverso (si scrive, non si rilegge mai).
 */
final readonly class ImpostazioniAdmin {
	public const string COVER_TEMPLATE = 'template';
	public const string COVER_SEMPRE = 'sempre';
	public const string COVER_MAI = 'mai';

	public const int DIMENSIONE_MASSIMA_MB_PREDEFINITA = 50;
	public const int ATTESA_MASSIMA_PREDEFINITA = 180;
	public const int ATTESA_MASSIMA_MINIMA = 10;
	public const int ATTESA_MASSIMA_MASSIMA = 900;
	public const int DIMENSIONE_MASSIMA_MB_LIMITE = 2048;

	/**
	 * @param list<string> $gruppi id dei gruppi abilitati; vuoto = tutti
	 */
	public function __construct(
		public string $schema = 'https',
		public string $host = '',
		public ?int $porta = null,
		public string $percorsoBase = '',
		public bool $verificaTls = true,
		public int $timeoutConnessione = 10,
		public int $timeoutRichiesta = 60,
		public string $template = 'company',
		public bool $toc = false,
		public string $cover = self::COVER_TEMPLATE,
		public string $watermark = '',
		public int $dimensioneMassimaMb = self::DIMENSIONE_MASSIMA_MB_PREDEFINITA,
		public int $attesaMassima = self::ATTESA_MASSIMA_PREDEFINITA,
		public array $gruppi = [],
	) {
	}

	/**
	 * Costruisce e valida a partire dai campi inviati dalla pagina di
	 * amministrazione.
	 *
	 * @param array<string, mixed> $dati
	 * @throws ConfigurazioneNonValidaException
	 */
	public static function daArray(array $dati): self {
		$schema = self::testo($dati, 'schema', 'https');
		if ($schema !== 'http' && $schema !== 'https') {
			throw new ConfigurazioneNonValidaException('schema', 'valore_non_ammesso');
		}

		$host = trim(self::testo($dati, 'host', ''));
		if ($host === '') {
			throw new ConfigurazioneNonValidaException('host', 'obbligatorio');
		}
		if (!self::hostValido($host)) {
			throw new ConfigurazioneNonValidaException('host', 'non_valido');
		}

		$porta = self::interoOpzionale($dati, 'porta');
		if ($porta !== null && ($porta < 1 || $porta > 65535)) {
			throw new ConfigurazioneNonValidaException('porta', 'fuori_intervallo');
		}

		$percorsoBase = trim(self::testo($dati, 'percorsoBase', ''));
		if ($percorsoBase !== '' && !self::percorsoValido($percorsoBase)) {
			throw new ConfigurazioneNonValidaException('percorsoBase', 'non_valido');
		}

		$timeoutConnessione = self::intero($dati, 'timeoutConnessione', 10);
		if ($timeoutConnessione < 1 || $timeoutConnessione > 120) {
			throw new ConfigurazioneNonValidaException('timeoutConnessione', 'fuori_intervallo');
		}

		$timeoutRichiesta = self::intero($dati, 'timeoutRichiesta', 60);
		if ($timeoutRichiesta < 1 || $timeoutRichiesta > 600) {
			throw new ConfigurazioneNonValidaException('timeoutRichiesta', 'fuori_intervallo');
		}

		$template = trim(self::testo($dati, 'template', 'company'));
		if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $template) !== 1) {
			throw new ConfigurazioneNonValidaException('template', 'non_valido');
		}

		$cover = self::testo($dati, 'cover', self::COVER_TEMPLATE);
		if (!in_array($cover, [self::COVER_TEMPLATE, self::COVER_SEMPRE, self::COVER_MAI], true)) {
			throw new ConfigurazioneNonValidaException('cover', 'valore_non_ammesso');
		}

		$watermark = trim(self::testo($dati, 'watermark', ''));
		if (mb_strlen($watermark) > OpzioniConversione::WATERMARK_MAX) {
			throw new ConfigurazioneNonValidaException('watermark', 'troppo_lunga');
		}

		$dimensione = self::intero($dati, 'dimensioneMassimaMb', self::DIMENSIONE_MASSIMA_MB_PREDEFINITA);
		if ($dimensione < 1 || $dimensione > self::DIMENSIONE_MASSIMA_MB_LIMITE) {
			throw new ConfigurazioneNonValidaException('dimensioneMassimaMb', 'fuori_intervallo');
		}

		$attesa = self::intero($dati, 'attesaMassima', self::ATTESA_MASSIMA_PREDEFINITA);
		if ($attesa < self::ATTESA_MASSIMA_MINIMA || $attesa > self::ATTESA_MASSIMA_MASSIMA) {
			throw new ConfigurazioneNonValidaException('attesaMassima', 'fuori_intervallo');
		}

		return new self(
			$schema,
			$host,
			$porta,
			trim($percorsoBase, '/'),
			self::booleano($dati, 'verificaTls', true),
			$timeoutConnessione,
			$timeoutRichiesta,
			$template,
			self::booleano($dati, 'toc', false),
			$cover,
			$watermark,
			$dimensione,
			$attesa,
			self::gruppi($dati),
		);
	}

	/**
	 * I valori da mandare alla pagina di amministrazione: tutti tranne il
	 * token, che non esce mai dal backend.
	 *
	 * @return array<string, mixed>
	 */
	public function perInterfaccia(): array {
		return [
			'schema' => $this->schema,
			'host' => $this->host,
			'porta' => $this->porta,
			'percorsoBase' => $this->percorsoBase,
			'verificaTls' => $this->verificaTls,
			'timeoutConnessione' => $this->timeoutConnessione,
			'timeoutRichiesta' => $this->timeoutRichiesta,
			'template' => $this->template,
			'toc' => $this->toc,
			'cover' => $this->cover,
			'watermark' => $this->watermark,
			'dimensioneMassimaMb' => $this->dimensioneMassimaMb,
			'attesaMassima' => $this->attesaMassima,
			'gruppi' => $this->gruppi,
		];
	}

	public function opzioni(): OpzioniConversione {
		return new OpzioniConversione(
			$this->template,
			$this->toc,
			match ($this->cover) {
				self::COVER_SEMPRE => true,
				self::COVER_MAI => false,
				default => null,
			},
			$this->watermark,
		);
	}

	public function dimensioneMassimaByte(): int {
		return $this->dimensioneMassimaMb * 1024 * 1024;
	}

	public function connessione(string $token): Connessione {
		return Connessione::da(
			$this->schema,
			$this->host,
			$this->porta,
			$this->percorsoBase,
			$token,
			$this->verificaTls,
			$this->timeoutConnessione,
			$this->timeoutRichiesta,
		);
	}

	/**
	 * Un percorso di base e' una sequenza di segmenti semplici: niente
	 * risalite `..`, niente segmenti vuoti, niente query o frammenti.
	 */
	private static function percorsoValido(string $percorso): bool {
		if (preg_match('#^/?[A-Za-z0-9._~\-]+(/[A-Za-z0-9._~\-]+)*/?$#', $percorso) !== 1) {
			return false;
		}

		foreach (explode('/', trim($percorso, '/')) as $segmento) {
			if ($segmento === '.' || $segmento === '..') {
				return false;
			}
		}

		return true;
	}

	private static function hostValido(string $host): bool {
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return true;
		}

		// Niente schema, porta, percorso o spazi: solo il nome dell'host.
		if (preg_match('#[\s/@:\\\\]#', $host) === 1) {
			return false;
		}

		return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	private static function testo(array $dati, string $chiave, string $predefinito): string {
		if (!isset($dati[$chiave])) {
			return $predefinito;
		}
		if (!is_string($dati[$chiave])) {
			throw new ConfigurazioneNonValidaException($chiave, 'non_testuale');
		}

		return $dati[$chiave];
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	private static function intero(array $dati, string $chiave, int $predefinito): int {
		if (!isset($dati[$chiave])) {
			return $predefinito;
		}
		if (is_int($dati[$chiave])) {
			return $dati[$chiave];
		}
		if (is_string($dati[$chiave]) && preg_match('/^\d+$/', trim($dati[$chiave])) === 1) {
			return (int)trim($dati[$chiave]);
		}

		throw new ConfigurazioneNonValidaException($chiave, 'non_numerico');
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	private static function interoOpzionale(array $dati, string $chiave): ?int {
		if (!isset($dati[$chiave])) {
			return null;
		}
		if (is_string($dati[$chiave]) && trim($dati[$chiave]) === '') {
			return null;
		}

		return self::intero($dati, $chiave, 0);
	}

	/**
	 * @param array<string, mixed> $dati
	 */
	private static function booleano(array $dati, string $chiave, bool $predefinito): bool {
		if (!isset($dati[$chiave])) {
			return $predefinito;
		}
		if (is_bool($dati[$chiave])) {
			return $dati[$chiave];
		}
		if (is_string($dati[$chiave])) {
			return in_array(strtolower(trim($dati[$chiave])), ['1', 'true', 'si', 'yes', 'on'], true);
		}

		throw new ConfigurazioneNonValidaException($chiave, 'non_booleano');
	}

	/**
	 * @param array<string, mixed> $dati
	 * @return list<string>
	 */
	private static function gruppi(array $dati): array {
		if (!isset($dati['gruppi'])) {
			return [];
		}
		if (!is_array($dati['gruppi'])) {
			throw new ConfigurazioneNonValidaException('gruppi', 'non_elenco');
		}

		$gruppi = [];
		/** @psalm-suppress MixedAssignment il tipo di ogni voce e' verificato qui sotto */
		foreach ($dati['gruppi'] as $gruppo) {
			if (!is_string($gruppo) || trim($gruppo) === '') {
				throw new ConfigurazioneNonValidaException('gruppi', 'non_elenco');
			}
			if (!in_array($gruppo, $gruppi, true)) {
				$gruppi[] = $gruppo;
			}
		}

		return $gruppi;
	}
}
