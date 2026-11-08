<?php

declare(strict_types=1);

/**
 * Stub per analisi statica e test: fino a Nextcloud 34 `OCP\Files\IRootFolder`
 * estende questa interfaccia interna del server, che non fa parte degli stub
 * `nextcloud/ocp`. Dalla 35 il legame non c'e' piu'. A runtime l'interfaccia
 * vera c'e' sempre.
 *
 * Lo leggono Psalm (vedi psalm.xml) e l'autoloader di tests/bootstrap.php.
 */

namespace OC\Hooks;

interface Emitter {
	/**
	 * @param string $scope
	 * @param string $method
	 */
	public function listen($scope, $method, callable $callback);

	/**
	 * @param string|null $scope
	 * @param string|null $method
	 */
	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
