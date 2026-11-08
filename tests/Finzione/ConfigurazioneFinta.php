<?php

declare(strict_types=1);

namespace OCA\Impagina\Tests\Finzione;

use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\Stub;

/**
 * Una `IAppConfig` che tiene i valori in memoria.
 *
 * Il vero `IAppConfig` ha una trentina di metodi: qui si simulano solo quelli
 * che l'app usa, e si ricorda quali chiavi sono state scritte come sensibili,
 * perche' il token non finisca in chiaro.
 *
 * @psalm-require-extends \PHPUnit\Framework\TestCase
 */
trait ConfigurazioneFinta {
	/** @var array<string, string|int|bool|list<string>> */
	private array $valoriConfig = [];

	/** @var list<string> */
	private array $chiaviSensibili = [];

	/**
	 * @return IAppConfig&Stub
	 */
	private function appConfigFinto(): IAppConfig {
		// Uno stub, non un mock: qui nessun test verifica le chiamate, guarda
		// il contenuto della configurazione.
		$appConfig = $this->createStub(IAppConfig::class);

		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $chiave, string $predefinito = ''): string {
				$valore = $this->valoriConfig[$chiave] ?? null;

				return is_string($valore) ? $valore : $predefinito;
			},
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			function (string $app, string $chiave, int $predefinito = 0): int {
				$valore = $this->valoriConfig[$chiave] ?? null;

				return is_int($valore) ? $valore : $predefinito;
			},
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			function (string $app, string $chiave, bool $predefinito = false): bool {
				$valore = $this->valoriConfig[$chiave] ?? null;

				return is_bool($valore) ? $valore : $predefinito;
			},
		);
		$appConfig->method('getValueArray')->willReturnCallback(
			function (string $app, string $chiave, array $predefinito = []): array {
				$valore = $this->valoriConfig[$chiave] ?? null;

				return is_array($valore) ? $valore : $predefinito;
			},
		);

		$appConfig->method('setValueString')->willReturnCallback(
			function (
				string $app,
				string $chiave,
				string $valore,
				bool $lazy = false,
				bool $sensibile = false,
			): bool {
				$this->valoriConfig[$chiave] = $valore;
				if ($sensibile && !in_array($chiave, $this->chiaviSensibili, true)) {
					$this->chiaviSensibili[] = $chiave;
				}

				return true;
			},
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $chiave, int $valore): bool {
				$this->valoriConfig[$chiave] = $valore;

				return true;
			},
		);
		$appConfig->method('setValueBool')->willReturnCallback(
			function (string $app, string $chiave, bool $valore): bool {
				$this->valoriConfig[$chiave] = $valore;

				return true;
			},
		);
		$appConfig->method('setValueArray')->willReturnCallback(
			function (string $app, string $chiave, array $valore): bool {
				$elenco = [];
				/** @psalm-suppress MixedAssignment il tipo di ogni voce e' verificato qui sotto */
				foreach ($valore as $voce) {
					if (is_string($voce)) {
						$elenco[] = $voce;
					}
				}
				$this->valoriConfig[$chiave] = $elenco;

				return true;
			},
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $chiave): void {
				unset($this->valoriConfig[$chiave]);
			},
		);

		return $appConfig;
	}

	private function configurazioneSensibile(string $chiave): bool {
		return in_array($chiave, $this->chiaviSensibili, true);
	}
}
