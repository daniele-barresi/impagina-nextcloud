<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Come e' andato un tentativo di conversione immediata.
 *
 * - `completata`: il PDF e' salvato accanto al sorgente;
 * - `rimandata`: il servizio e' occupato, irraggiungibile o lento, e la
 *   conversione va ripetuta in background;
 * - `fallita`: errore definitivo (documento, file mancanti, permessi...),
 *   con il motivo e i parametri che finiscono anche nella notifica.
 */
final readonly class EsitoConversione {
	public const string COMPLETATA = 'completata';
	public const string RIMANDATA = 'rimandata';
	public const string FALLITA = 'fallita';

	/**
	 * @param array<string, mixed> $parametri
	 */
	private function __construct(
		public string $stato,
		public int $fileIdPdf = 0,
		public string $nomePdf = '',
		public string $motivo = '',
		public array $parametri = [],
	) {
	}

	public static function completata(int $fileIdPdf, string $nomePdf): self {
		return new self(self::COMPLETATA, $fileIdPdf, $nomePdf);
	}

	public static function rimandata(string $motivo): self {
		return new self(self::RIMANDATA, motivo: $motivo);
	}

	/**
	 * @param array<string, mixed> $parametri
	 */
	public static function fallita(string $motivo, array $parametri = []): self {
		return new self(self::FALLITA, motivo: $motivo, parametri: $parametri);
	}
}
