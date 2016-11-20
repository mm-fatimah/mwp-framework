<?php

namespace Wordpress;

/**
 * @Annotation 
 * @Target( "METHOD" )
 */
class Filter extends \Modern\Wordpress\Annotation
{
	/**
	 * @var string
	 */
	public $for;
	
	/**
	 * @var integer
	 */
	public $priority;
	
	/**
	 * @var integer
	 */
	public $args;
	
	/**
	 * Apply to Method
	 *
	 * @param	object					$instance		The object that the method belongs to
	 * @param	ReflectionMethod		$method			The reflection method of the object instance
	 * @param	array					$vars			Persisted variables returned by previous annotations
	 * @return	array|NULL
	 */
	public function applyToMethod( $instance, $method, $vars )
	{
		add_filter( $this->for, array( $instance, $method->name ), $this->priority, $this->args );
	}	
	
}