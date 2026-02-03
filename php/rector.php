<?php

use Rector\Config\RectorConfig;


return RectorConfig::configure()
	->withBootstrapFiles([__DIR__ . '/rector-bootstrap.php'])
	->withFileExtensions(['php'])
	->withDowngradeSets(php72: true)
	?>