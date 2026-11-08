<?php

declare(strict_types=1);

namespace OCA\Impagina\Exception;

use RuntimeException;
use Throwable;

/**
 * Interrompe la conversione con un **motivo** che il `Notifier` sapra'
 * tradurre nella lingua di chi riceve la notifica.
 *
 * Nella notifica finiscono codici e parametri, non frasi gia' fatte: la
 * traduzione avviene al momento di mostrarla, non al momento di crearla.
 */
final class ConversioneFallitaException extends RuntimeException {
	public const string MOTIVO_FILE_NON_TROVATO = 'file_non_trovato';
	public const string MOTIVO_PERMESSI = 'permessi';
	public const string MOTIVO_CONFIGURAZIONE = 'configurazione';
	public const string MOTIVO_RIFERIMENTI_NON_ALLEGABILI = 'riferimenti_non_allegabili';
	public const string MOTIVO_TYPST_GREZZO = 'typst_grezzo';
	public const string MOTIVO_FILE_MANCANTI = 'file_mancanti';
	public const string MOTIVO_TROPPO_GRANDE = 'troppo_grande';
	public const string MOTIVO_DOCUMENTO = 'documento';
	public const string MOTIVO_SERVIZIO = 'servizio';
	public const string MOTIVO_OCCUPATO = 'occupato';
	public const string MOTIVO_RETE = 'rete';
	public const string MOTIVO_TEMPO_SCADUTO = 'tempo_scaduto';
	public const string MOTIVO_CODA_TROPPO_LUNGA = 'coda_troppo_lunga';
	public const string MOTIVO_RISULTATO_NON_PDF = 'risultato_non_pdf';
	public const string MOTIVO_SALVATAGGIO = 'salvataggio';
	public const string MOTIVO_INTERNO = 'interno';

	/**
	 * @param array<string, mixed> $parametri dati per il messaggio della notifica
	 */
	public function __construct(
		private readonly string $motivo,
		private readonly array $parametri = [],
		string $messaggioTecnico = '',
		?Throwable $precedente = null,
	) {
		parent::__construct($messaggioTecnico === '' ? "conversione fallita: $motivo" : $messaggioTecnico, 0, $precedente);
	}

	public function motivo(): string {
		return $this->motivo;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function parametri(): array {
		return $this->parametri;
	}
}
