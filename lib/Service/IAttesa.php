<?php

declare(strict_types=1);

namespace OCA\Impagina\Service;

/**
 * Attesa fra due tentativi.
 *
 * E' un'interfaccia solo perche' i test non devono davvero dormire: in
 * esercizio l'unica implementazione e' {@see Attesa}.
 */
interface IAttesa {
	public function dormi(int $secondi): void;
}
