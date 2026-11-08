<?php

declare(strict_types=1);

/**
 * Bootstrap per i test di unità.
 *
 * I test girano senza un server Nextcloud: le classi `OCP\` e `NCU\` arrivano
 * dagli stub di `nextcloud/ocp`, che è una libreria senza sezione `autoload`,
 * quindi va registrato qui a mano.
 */

require_once __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
	// Fino alla 34 `IRootFolder` estende questa interfaccia interna del server.
	if ($class === 'OC\\Hooks\\Emitter') {
		require_once __DIR__ . '/../stubs/oc-hooks.php';

		return;
	}

	foreach (['OCP\\' => 'OCP/', 'NCU\\' => 'NCU/'] as $prefix => $dir) {
		if (!str_starts_with($class, $prefix)) {
			continue;
		}

		$relative = substr($class, strlen($prefix));
		$file = __DIR__ . '/../vendor/nextcloud/ocp/' . $dir . str_replace('\\', '/', $relative) . '.php';
		if (is_file($file)) {
			require_once $file;
		}

		return;
	}
});
