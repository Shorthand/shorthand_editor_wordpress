<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;


return array(
	'exclude-namespaces' => array(
		'Psr\\',
	),
	'finders'            => array(
		Finder::create()
		->files()
		->in( 'vendor/firebase/php-jwt' )
		->name( array( '*.php', 'LICENSE', 'composer.json' ) ),
	),

	'patchers'           => array(),
);
