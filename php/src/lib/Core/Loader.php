<?php

namespace Shorthand\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Loader {

	protected $actions;
	protected $filters;

	public function __construct() {
		$this->actions = array();
		$this->filters = array();
	}

	public function add_action( $hook, object $instance, string $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions = $this->add( $this->actions, $hook, $instance, $callback, $priority, $accepted_args );
	}

	public function add_filter( $hook, object $instance, string $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters = $this->add( $this->filters, $hook, $instance, $callback, $priority, $accepted_args );
	}

	public function add( $callbacks, $hook, $instance, $callback, $priority, $accepted_args ) {
		$callbacks[] = array(
			'hook'          => $hook,
			'instance'      => $instance,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return $callbacks;
	}

	public function register() {
		foreach ( $this->actions as $action ) {
			add_action(
				$action['hook'],
				array( $action['instance'], $action['callback'] ),
				$action['priority'],
				$action['accepted_args']
			);
		}

		foreach ( $this->filters as $filter ) {
			add_filter(
				$filter['hook'],
				array( $filter['instance'], $filter['callback'] ),
				$filter['priority'],
				$filter['accepted_args']
			);
		}

		$this->actions = array();
		$this->filters = array();
	}
}
