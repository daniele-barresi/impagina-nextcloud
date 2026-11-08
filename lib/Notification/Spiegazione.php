<?php

declare(strict_types=1);

namespace OCA\Impagina\Notification;

use OCA\Impagina\Exception\ConversioneFallitaException as Motivo;
use OCP\IL10N;

/**
 * Le frasi che spiegano all'utente perche' una conversione non e' riuscita.
 *
 * Servono in due posti: nella notifica, tradotta nella lingua di chi la
 * legge, e nel messaggio mostrato subito dall'app File quando la conversione
 * avviene durante la richiesta.
 */
final class Spiegazione {
	/**
	 * Il messaggio dipende dal motivo. I dettagli del servizio (`messaggio` e
	 * `suggerimento`) si mostrano solo quando parlano del documento
	 * dell'utente: negli altri casi restano nel log, dove li legge
	 * l'amministratore.
	 *
	 * @param array<array-key, mixed> $parametri
	 */
	public static function motivo(IL10N $l, array $parametri): string {
		$motivo = self::testo($parametri, 'motivo');
		$dettaglio = self::testo($parametri, 'dettaglio');
		$elenco = self::elenco($parametri, 'elenco');
		$elencoTesto = implode("\n", array_map(static fn (string $voce): string => '• ' . $voce, $elenco));

		return match ($motivo) {
			Motivo::MOTIVO_FILE_NON_TROVATO => $l->t('The file no longer exists or is not readable.'),
			Motivo::MOTIVO_PERMESSI => $l->t('You cannot create the PDF in this folder.'),
			Motivo::MOTIVO_CONFIGURAZIONE => $l->t(
				'The conversion service is not configured correctly. Ask an administrator to check it.',
			),
			Motivo::MOTIVO_RIFERIMENTI_NON_ALLEGABILI => $l->t(
				'The document refers to files that cannot be sent to the service:',
			) . "\n" . $elencoTesto,
			Motivo::MOTIVO_TYPST_GREZZO => $l->t(
				'The document contains raw Typst code, which the service refuses for security reasons.',
			),
			Motivo::MOTIVO_FILE_MANCANTI => $l->t('These referenced files were not found next to the document:')
				. "\n" . $elencoTesto,
			Motivo::MOTIVO_TROPPO_GRANDE => $dettaglio === ''
				? $l->t('The document and its images exceed the allowed size.')
				: $l->t('The document and its images exceed the allowed size: %s', [$dettaglio]),
			Motivo::MOTIVO_DOCUMENTO => $dettaglio === ''
				? $l->t('The service could not convert the document.')
				: $dettaglio,
			Motivo::MOTIVO_OCCUPATO => $l->t('The conversion service is busy. Try again in a few minutes.'),
			Motivo::MOTIVO_RETE => $l->t('The Impagina service is unreachable. Ask an administrator to check it.'),
			Motivo::MOTIVO_TEMPO_SCADUTO => $l->t('The conversion took longer than the allowed time.'),
			Motivo::MOTIVO_CODA_TROPPO_LUNGA => $l->t(
				'The job waited in the queue longer than the allowed time. Try again later.',
			),
			Motivo::MOTIVO_RISULTATO_NON_PDF => $l->t('The service returned something that is not a PDF.'),
			Motivo::MOTIVO_SALVATAGGIO => $l->t('The PDF could not be saved in the folder of the document.'),
			Motivo::MOTIVO_SERVIZIO => $l->t('Conversion service error. Ask an administrator to check the logs.'),
			default => $l->t('Unexpected error during the conversion. Ask an administrator to check the logs.'),
		};
	}

	/**
	 * @param array<array-key, mixed> $parametri
	 */
	public static function testo(array $parametri, string $chiave): string {
		return isset($parametri[$chiave]) && is_string($parametri[$chiave]) ? $parametri[$chiave] : '';
	}

	/**
	 * @param array<array-key, mixed> $parametri
	 * @return list<string>
	 */
	public static function elenco(array $parametri, string $chiave): array {
		if (!isset($parametri[$chiave]) || !is_array($parametri[$chiave])) {
			return [];
		}

		$elenco = [];
		/** @psalm-suppress MixedAssignment il tipo di ogni voce e' verificato qui sotto */
		foreach ($parametri[$chiave] as $voce) {
			if (is_string($voce)) {
				$elenco[] = $voce;
			}
		}

		return $elenco;
	}
}
