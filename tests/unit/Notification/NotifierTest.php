<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Unit\Notification;

use OCA\Impagina\Exception\ConversioneFallitaException as Motivo;
use OCA\Impagina\Notification\Notifier;
use OCA\Impagina\Service\Notifiche;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase {
	private Notifier $notifier;
	private string $soggettoTradotto = '';
	private string $messaggioTradotto = '';

	#[\Override]
	protected function setUp(): void {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $testo, array $parametri = []): string {
				if ($parametri === []) {
					return $testo;
				}

				return vsprintf($testo, array_map(
					static fn (mixed $parametro): string => is_scalar($parametro) ? (string)$parametro : '',
					$parametri,
				));
			},
		);
		$l10n->method('n')->willReturnCallback(
			static function (string $singolare, string $plurale, int $quanti, array $parametri = []): string {
				$testo = str_replace('%n', (string)$quanti, $quanti === 1 ? $singolare : $plurale);

				return $parametri === [] ? $testo : vsprintf($testo, array_map(
					static fn (mixed $parametro): string => is_scalar($parametro) ? (string)$parametro : '',
					$parametri,
				));
			},
		);

		$fabbrica = $this->createStub(IFactory::class);
		$fabbrica->method('get')->willReturn($l10n);

		$url = $this->createStub(IURLGenerator::class);
		$url->method('imagePath')->willReturn('/apps/impagina/img/app-dark.svg');
		$url->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $percorso): string => 'https://nextcloud.example' . $percorso,
		);
		$url->method('linkToRouteAbsolute')->willReturn('https://nextcloud.example/f/99');

		$this->notifier = new Notifier($fabbrica, $url);
	}

	/**
	 * @param array<string, mixed> $parametri
	 */
	private function notifica(string $soggetto, array $parametri, string $app = 'impagina'): INotification {
		$notifica = $this->createStub(INotification::class);
		$notifica->method('getApp')->willReturn($app);
		$notifica->method('getSubject')->willReturn($soggetto);
		$notifica->method('getSubjectParameters')->willReturn($parametri);
		$notifica->method('getObjectId')->willReturn('99');
		$notifica->method('setIcon')->willReturn($notifica);
		$notifica->method('setLink')->willReturn($notifica);
		$notifica->method('setRichSubject')->willReturn($notifica);
		$notifica->method('setParsedSubject')->willReturnCallback(
			function (string $testo) use ($notifica): INotification {
				$this->soggettoTradotto = $testo;

				return $notifica;
			},
		);
		$notifica->method('setParsedMessage')->willReturnCallback(
			function (string $testo) use ($notifica): INotification {
				$this->messaggioTradotto = $testo;

				return $notifica;
			},
		);

		return $notifica;
	}

	public function testNotificaDiSuccesso(): void {
		$this->notifier->prepare($this->notifica(Notifiche::SOGGETTO_RIUSCITA, [
			'nome' => 'relazione.pdf',
			'percorso' => '/anna/files/Documenti/relazione.pdf',
			'sorgente' => 'relazione.md',
			'avvisi' => [],
		]), 'it');

		self::assertStringContainsString('relazione.pdf', $this->soggettoTradotto);
		self::assertStringContainsString('relazione.md', $this->messaggioTradotto);
	}

	public function testGliAvvisiDiTypstFinisconoNelMessaggio(): void {
		$this->notifier->prepare($this->notifica(Notifiche::SOGGETTO_RIUSCITA, [
			'nome' => 'relazione.pdf',
			'percorso' => '/anna/files/relazione.pdf',
			'sorgente' => 'relazione.md',
			'avvisi' => ['font sostituito', 'immagine ridimensionata'],
		]), 'it');

		self::assertStringContainsString('font sostituito', $this->messaggioTradotto);
		self::assertStringContainsString('immagine ridimensionata', $this->messaggioTradotto);
		self::assertStringContainsString('2', $this->messaggioTradotto);
	}

	public function testNotificaDiErroreConElencoDiRiferimenti(): void {
		$this->notifier->prepare($this->notifica(Notifiche::SOGGETTO_FALLITA, [
			'sorgente' => 'relazione.md',
			'motivo' => Motivo::MOTIVO_FILE_MANCANTI,
			'elenco' => ['immagini/manca.png', 'logo.svg'],
		]), 'it');

		self::assertStringContainsString('relazione.md', $this->soggettoTradotto);
		self::assertStringContainsString('immagini/manca.png', $this->messaggioTradotto);
		self::assertStringContainsString('logo.svg', $this->messaggioTradotto);
	}

	public function testIlMessaggioDelServizioArrivaSoloPerIProblemiDelDocumento(): void {
		$this->notifier->prepare($this->notifica(Notifiche::SOGGETTO_FALLITA, [
			'sorgente' => 'relazione.md',
			'motivo' => Motivo::MOTIVO_DOCUMENTO,
			'dettaglio' => 'typst ha segnalato un errore. Controlla la tabella a pagina 2.',
		]), 'it');
		self::assertStringContainsString('Controlla la tabella a pagina 2.', $this->messaggioTradotto);

		$this->notifier->prepare($this->notifica(Notifiche::SOGGETTO_FALLITA, [
			'sorgente' => 'relazione.md',
			'motivo' => Motivo::MOTIVO_SERVIZIO,
			'dettaglio' => 'dettaglio tecnico da non mostrare',
		]), 'it');
		self::assertStringNotContainsString('dettaglio tecnico', $this->messaggioTradotto);
	}

	public function testOgniMotivoHaUnMessaggio(): void {
		$motivi = [
			Motivo::MOTIVO_FILE_NON_TROVATO,
			Motivo::MOTIVO_PERMESSI,
			Motivo::MOTIVO_CONFIGURAZIONE,
			Motivo::MOTIVO_RIFERIMENTI_NON_ALLEGABILI,
			Motivo::MOTIVO_TYPST_GREZZO,
			Motivo::MOTIVO_FILE_MANCANTI,
			Motivo::MOTIVO_TROPPO_GRANDE,
			Motivo::MOTIVO_DOCUMENTO,
			Motivo::MOTIVO_SERVIZIO,
			Motivo::MOTIVO_OCCUPATO,
			Motivo::MOTIVO_RETE,
			Motivo::MOTIVO_TEMPO_SCADUTO,
			Motivo::MOTIVO_CODA_TROPPO_LUNGA,
			Motivo::MOTIVO_RISULTATO_NON_PDF,
			Motivo::MOTIVO_SALVATAGGIO,
			Motivo::MOTIVO_INTERNO,
		];

		foreach ($motivi as $motivo) {
			$this->messaggioTradotto = '';
			$this->notifier->prepare($this->notifica(Notifiche::SOGGETTO_FALLITA, [
				'sorgente' => 'relazione.md',
				'motivo' => $motivo,
			]), 'it');

			self::assertNotSame('', $this->messaggioTradotto, "motivo senza messaggio: $motivo");
		}
	}

	public function testRifiutaLeNotificheDiAltreApp(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($this->notifica(Notifiche::SOGGETTO_RIUSCITA, [], 'altra-app'), 'it');
	}

	public function testRifiutaUnSoggettoSconosciuto(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($this->notifica('qualcosa_di_nuovo', []), 'it');
	}
}
