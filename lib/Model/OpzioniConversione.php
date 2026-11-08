<?php

declare(strict_types=1);

namespace OCA\Impagina\Model;

/**
 * Le opzioni di conversione decise dall'amministratore, uguali per tutti gli
 * utenti.
 *
 * `cover` ha tre stati: `true` (sempre), `false` (mai) e `null`, che significa
 * "decide il template" e si traduce nell'**omissione** del campo. `watermark`
 * vuota viene a sua volta omessa. Un'opzione sconosciuta farebbe fallire la
 * richiesta con `400 opzione_non_valida`, quindi qui si genera solo cio' che la
 * specifica prevede.
 *
 * Il front-matter del documento ha comunque la precedenza su questi valori.
 */
final readonly class OpzioniConversione {
	public const int WATERMARK_MAX = 100;

	public function __construct(
		private string $template = 'company',
		private bool $toc = false,
		private ?bool $cover = null,
		private ?string $watermark = null,
	) {
	}

	public function template(): string {
		return $this->template;
	}

	public function toc(): bool {
		return $this->toc;
	}

	public function cover(): ?bool {
		return $this->cover;
	}

	public function watermark(): ?string {
		return $this->watermark;
	}

	/**
	 * I campi da mettere nel form di `POST /lavori`, gia' nella forma testuale
	 * accettata dal servizio.
	 *
	 * @return array<string, string>
	 */
	public function campi(): array {
		$campi = [
			'template' => $this->template,
			'toc' => $this->toc ? 'true' : 'false',
		];

		if ($this->cover !== null) {
			$campi['cover'] = $this->cover ? 'true' : 'false';
		}

		$watermark = $this->watermark === null ? '' : trim($this->watermark);
		if ($watermark !== '') {
			$campi['watermark'] = mb_substr($watermark, 0, self::WATERMARK_MAX);
		}

		return $campi;
	}
}
