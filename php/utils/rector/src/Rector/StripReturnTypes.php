<?php

namespace Utils\Rector\Rector;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class StripReturnTypes extends AbstractRector {
	/**
	 * @return array<class-string<Node>>
	 */
	public function getNodeTypes(): array {
		// what node types are we looking for?
		// pick from
		// https://github.com/rectorphp/php-parser-nodes-docs/
		return [ FunctionLike::class];
	}

	/**
	 * @param FunctionLike $node
	 */
	public function refactor( Node $node ): ?Node {
		$returnType = $node->getReturnType();
		if ( $returnType === NULL ) {
			return $node;
		}
		$node->returnType = NULL;
		return $node;
	}

	/**
	 * This method helps other to understand the rule
	 * and to generate documentation.
	 */
	public function getRuleDefinition(): RuleDefinition {
		return new RuleDefinition(
			'Strip return types from functions.', [ 
				new CodeSample(
					// code before
					'function my_function(): string { return \'hello\'; }',
					// code after
					'function my_function() { return \'hello\'; }'
				),
			]
		);
	}
}
