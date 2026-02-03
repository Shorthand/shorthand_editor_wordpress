<?php

namespace Utils\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Type\VerbosityLevel;
use PhpParser\Node\Stmt\ClassMethod;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class StripPrimitiveArgumentTypes extends AbstractRector {
	/**
	 * @return array<class-string<Node>>
	 */
	public function getNodeTypes(): array {
		// what node types are we looking for?
		// pick from
		// https://github.com/rectorphp/php-parser-nodes-docs/
		// return [ ClassMethod::class, Function_::class];
		return [ Param::class];
	}

	/**
	 * @param Function_ $node
	 */
	public function refactor( Node $node ): ?Node {
		$type = $this->getType( $node );
		if ( $type->isString()->yes() || $type->isInteger()->yes() ) {
			$node->type = NULL;
		}
		return $node;
	}

	/**
	 * This method helps other to understand the rule
	 * and to generate documentation.
	 */
	public function getRuleDefinition(): RuleDefinition {
		return new RuleDefinition(
			'Strip primitive argument types from functions.', [ 
				new CodeSample(
					// code before
					'function my_function(string $param) { return \'hello\'; }',
					// code after
					'function my_function($param) { return \'hello\'; }'
				),
			]
		);
	}
}
