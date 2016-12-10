<?php
/**
 * Framework Class (Singleton)
 * 
 * @package 	Modern Wordpress Framework
 * @author	Kevin Carwile
 * @since	Nov 20, 2016
 */

namespace Modern\Wordpress;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

use \Doctrine\Common\Annotations\AnnotationReader;
use \Doctrine\Common\Annotations\FileCacheReader;

/**
 * Provides access to core framework methods and features. 
 */
class Framework extends Plugin
{
	/**
	 * Instance Cache - Required for all singleton subclasses
	 *
	 * @var	self
	 */
	protected static $_instance;
	
	/** 
	 * @var Annotations Reader
	 */
	protected $reader;
	
	/**
	 * Constructor
	 */
	protected function __construct()
	{
		/* Load Annotation Reader */
		$this->reader = new FileCacheReader( new AnnotationReader(), __DIR__ . "/../annotations/cache", defined( 'MODERN_WORDPRESS_DEV' ) and MODERN_WORDPRESS_DEV );
		
		/* Register WP CLI */
		if ( defined( '\WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'mwp', 'Modern\Wordpress\CLI' );
		}
		
		/* Init Parent */
		parent::__construct();		
	}
	
	/**
	 * Attach instances to wordpress
	 *
	 * @api
	 *
	 * @param	object		$instance		An object instance to attach to wordpress 
	 * @return	this
	 */
	public function attach( $instance )
	{
		$reflClass = new \ReflectionClass( get_class( $instance ) );
		$vars = array();
		
		/**
		 * Class Annotations
		 */
		foreach( $this->reader->getClassAnnotations( $reflClass ) as $annotation )
		{
			if ( $annotation instanceof \Modern\Wordpress\Annotation )
			{
				$result = $annotation->applyToObject( $instance, $vars );
				if ( ! empty( $result ) )
				{
					$vars = array_merge( $vars, $result );
				}
			}
		}
		
		/**
		 * Property Annotations
		 */
		foreach ( $reflClass->getProperties() as $property ) 
		{
			foreach ( $this->reader->getPropertyAnnotations( $property ) as $annotation ) 
			{
				if ( $annotation instanceof \Modern\Wordpress\Annotation )
				{
					$result = $annotation->applyToProperty( $instance, $property, $vars );
					if ( ! empty( $result ) )
					{
						$vars = array_merge( $vars, $result );
					}
				}
			}
		}		
		
		/**
		 * Method Annotations
		 */
		foreach ( $reflClass->getMethods() as $method ) 
		{
			foreach ( $this->reader->getMethodAnnotations( $method ) as $annotation ) 
			{
				if ( $annotation instanceof \Modern\Wordpress\Annotation )
				{
					$result = $annotation->applyToMethod( $instance, $method, $vars );
					if ( ! empty( $result ) )
					{
						$vars = array_merge( $vars, $result );
					}
				}
			}
		}
		
		return $this;
	}
	
	/**
	 * Get all modern wordpress plugins
	 *
	 * @api
	 *
	 * @param	bool		$recache		Force recaching of plugins
	 * @return	array
	 */
	public function getPlugins( $recache=FALSE )
	{
		static $plugins;
		
		if ( ! isset( $plugins ) or $recache )
		{
			$plugins = apply_filters( 'modern_wordpress_find_plugins', array() );
		}
		
		return $plugins;
	}
	
	/**
	 * Add a one minute time period to the wordpress cron schedule
	 *
	 * @Wordpress\Filter( for="cron_schedules" )
	 *
	 * @param	array		$schedules		Array of schedule frequencies
	 * @return	array
	 */
	public function cronSchedules( $schedules )
	{
		$schedules['minutely'] = array(
			'interval' => 60,
			'display' => __( 'Once Per Minute' )
		);
		
		return $schedules;
	}
	
	/**
	 * Setup the queue schedule on framework activation
	 *
	 * @Wordpress\Plugin( on="activation", file="framework.php" )
	 *
	 * @return	void
	 */
	public function frameworkActivated()
	{
		wp_clear_scheduled_hook( 'modern_wordpress_queue_run' );
		wp_schedule_event( time(), 'minutely', 'modern_wordpress_queue_run' );
	}
	
	/**
	 * Clear the queue schedule on framework deactivation
	 *
	 * @Wordpress\Plugin( on="deactivation", file="framework.php" )
	 *
	 * @return	void
	 */
	public function frameworkDeactivated()
	{
		wp_clear_scheduled_hook( 'modern_wordpress_queue_run' );
	}
	
	/**
	 * Run any queued tasks (future use)
	 *
	 * @Wordpress\Action( for="modern_wordpress_queue_run" )
	 */
	public function runQueue()
	{
		
	}
	
	/**
	 * Generate a new plugin from the boilerplate
	 *
	 * @api
	 *
	 * @param	array		$data		New plugin data
	 * @return	this
	 * @throws	\InvalidArgumentException	Throws exception when invalid plugin data is provided
	 * @throws	\ErrorException			Throws an error when the plugin data conflicts with another plugin
	 */
	public function createPlugin( $data )
	{
		$plugin_dir = $data[ 'slug' ];
		$plugin_name = $data[ 'name' ];
		$plugin_vendor = $data[ 'vendor' ];
		$plugin_namespace = $data[ 'namespace' ];
		
		if ( ! $data[ 'slug' ] )      { throw new \InvalidArgumentException( 'Invalid plugin slug.' ); }
		if ( ! $data[ 'name' ] )      { throw new \InvalidArgumentException( 'No plugin name provided.' );  }
		if ( ! $data[ 'vendor' ] )    { throw new \InvalidArgumentException( 'No vendor name provided.' );  }
		if ( ! $data[ 'namespace' ] ) { throw new \InvalidArgumentException( 'No namespace provided.' );    }
		
		if ( ! is_dir( $this->getPath() . '/boilerplate' ) )
		{
			throw new \ErrorException( "Boilerplate plugin not present. Can't create a new one.", 1 );
		}
		
		if ( is_dir( WP_PLUGIN_DIR . '/' . $plugin_dir ) )
		{
			throw new \ErrorException( 'Plugin directory is already being used.', 2 );
		}
		
		$this->copyPlugin( $this->getPath() . '/boilerplate', WP_PLUGIN_DIR . '/' . $plugin_dir, $data );
		
		/* Create an alias file for the test suite, etc... */
		$fh = fopen( WP_PLUGIN_DIR . '/' . $plugin_dir . '/' . $data[ 'slug' ] . '.php', 'w+' );
		fwrite( $fh, "<?php\n\nrequire_once 'plugin.php';" );
		fclose( $fh );
		
		/* Include autoloader so we can instantiate the plugin */
		include_once WP_PLUGIN_DIR . '/' . $plugin_dir . '/vendor/autoload.php';
		
		$pluginClass = $plugin_namespace . '\Plugin';
		$plugin = $pluginClass::instance();
		$plugin->setPath( WP_PLUGIN_DIR . '/' . $plugin_dir );
		$plugin->setData( 'plugin-meta', $data );
		
		return $this;
	}
	
	/**
	 * Copy boilerplate plugin and customize the metadata
	 *
	 * @param       string   $source    Source path
	 * @param       string   $dest      Destination path
	 * @param	array    $data      Plugin metadata
	 * @return      bool     Returns TRUE on success, FALSE on failure
	 */
	protected function copyPlugin( $source, $dest, $data )
	{
		// Simple copy for a file
		if ( is_file( $source ) ) 
		{
			if ( ! in_array( basename( $source ), array( 'README.md', '.gitignore' ) ) )
			{
				copy( $source, $dest );
				
				$pathinfo = pathinfo( $dest );
				if ( in_array( $pathinfo[ 'extension' ], array( 'php', 'js', 'json', 'css' ) ) )
				{
					file_put_contents( $dest, $this->replaceMetaContents( file_get_contents( $dest ), $data ) );
				}
				
				return true;
			}
			
			return false;
		}

		// Make destination directory
		if ( ! is_dir( $dest ) ) 
		{
			mkdir( $dest );
		}

		// Loop through the folder
		$dir = dir( $source );
		while ( false !== $entry = $dir->read() ) 
		{
			// Skip pointers & special dirs
			if ( in_array( $entry, array( '.', '..', '.git' ) ) )
			{
				continue;
			}

			// Deep copy directories
			if ( $dest !== "$source/$entry" ) 
			{
				$this->copyPlugin( "$source/$entry", "$dest/$entry", $data );
			}
		}

		// Clean up
		$dir->close();
		return true;
	}
	
	/**
	 * Create new javascript module
	 *
	 * @param	
	 * @return	void
	 * @throws	\ErrorException
	 */
	public function createJavascript( $slug, $name )
	{
		if ( ! file_exists( WP_PLUGIN_DIR . '/modern-wordpress/boilerplate/assets/js/module.js' ) )
		{
			throw new \ErrorException( "The boilerplate plugin is not present.\nTry using: $ wp mwp update-boilerplate https://github.com/Miller-Media/wp-plugin-boilerplate/archive/master.zip" );
		}
		
		if ( ! is_dir( WP_PLUGIN_DIR . '/' . $slug . '/assets/js' ) )
		{
			throw new \ErrorException( 'Javascript directory is not valid: ' . $slug . '/assets/js' );
		}
		
		if ( substr( $name, -3 ) === '.js' )
		{
			$name = substr( $name, 0, strlen( $name ) - 3 );
		}
		
		$javascript_file = WP_PLUGIN_DIR . '/' . $slug . '/assets/js/' . $name . '.js';
		
		if ( file_exists( $javascript_file ) )
		{
			throw new \ErrorException( "The javascript file already exists: " . $slug . '/assets/js/' . $name . '.js' );
		}
		
		if ( ! copy( WP_PLUGIN_DIR . '/modern-wordpress/boilerplate/assets/js/module.js', $javascript_file ) )
		{
			throw new \ErrorException( 'Error copying file to destination: ' . $slug . '/assets/js/' . $name . '.js' );
		}
		
		$plugin_data_file = WP_PLUGIN_DIR . '/' . $slug . '/data/plugin-meta.php';
		
		if ( file_exists( $plugin_data_file ) )
		{
			$plugin_data = json_decode( include $plugin_data_file, TRUE );
			file_put_contents( $javascript_file, $this->replaceMetaContents( file_get_contents( $javascript_file ), $plugin_data ) );
		}	
	}
	
	/**
	 * Create new stylesheet
	 *
	 * @param	
	 * @return	void
	 * @throws	\ErrorException
	 */
	public function createStylesheet( $slug, $name )
	{
		if ( ! file_exists( WP_PLUGIN_DIR . '/modern-wordpress/boilerplate/assets/css/style.css' ) )
		{
			throw new \ErrorException( "The boilerplate plugin is not present.\nTry using: $ wp mwp update-boilerplate https://github.com/Miller-Media/wp-plugin-boilerplate/archive/master.zip" );
		}
		
		if ( ! is_dir( WP_PLUGIN_DIR . '/' . $slug . '/assets/css' ) )
		{
			throw new \ErrorException( 'Stylesheet directory is not valid: ' . $slug . '/assets/css' );
		}
		
		if ( substr( $name, -4 ) === '.css' )
		{
			$name = substr( $name, 0, strlen( $name ) - 4 );
		}
		
		$stylesheet_file = WP_PLUGIN_DIR . '/' . $slug . '/assets/css/' . $name . '.css';
		
		if ( file_exists( $stylesheet_file ) )
		{
			throw new \ErrorException( "The stylesheet file already exists: " . $slug . '/assets/css/' . $name . '.css' );
		}
		
		if ( ! copy( WP_PLUGIN_DIR . '/modern-wordpress/boilerplate/assets/css/style.css', $stylesheet_file ) )
		{
			throw new \ErrorException( 'Error copying file to destination: ' . $slug . '/assets/css/' . $name . '.css' );
		}
		
		$plugin_data_file = WP_PLUGIN_DIR . '/' . $slug . '/data/plugin-meta.php';
		
		if ( file_exists( $plugin_data_file ) )
		{
			$plugin_data = json_decode( include $plugin_data_file, TRUE );
			file_put_contents( $stylesheet_file, $this->replaceMetaContents( file_get_contents( $stylesheet_file ), $plugin_data ) );
		}	
	}

	/**
	 * Create new php class
	 *
	 * @param	
	 * @return	void
	 * @throws	\ErrorException
	 */
	public function createClass( $slug, $name )
	{
		$plugin_data_file = WP_PLUGIN_DIR . '/' . $slug . '/data/plugin-meta.php';
		
		if ( ! file_exists( $plugin_data_file ) )
		{
			throw new \ErrorException( "No metadata available for this plugin. Namespace unknown." );
		}
		
		$plugin_data = json_decode( include $plugin_data_file, TRUE );

		if ( ! isset( $plugin_data[ 'namespace' ] ) )
		{
			throw new \ErrorException( "Namespace not defined in the plugin metadata." );
		}
		
		$namespace = $plugin_data[ 'namespace' ];
		$name = trim( str_replace( $namespace, '', $name ), '\\' );
		$parts = explode( '\\', $name );
		$classname = array_pop( $parts );
		
		if ( ! is_dir( WP_PLUGIN_DIR . '/' . $slug . '/classes' ) )
		{
			throw new \ErrorException( 'Class directory is not valid: ' . 'plugins/' . $slug . '/classes' );
		}
		
		$basedir = WP_PLUGIN_DIR . '/' . $slug . '/classes';
		foreach( $parts as $dir )
		{
			$basedir .= '/' . $dir;
			if ( ! is_dir( $basedir ) )
			{
				mkdir( $basedir );
			}
			$namespace .= '\\' . $dir;
		}
		
		$class_file = $basedir . '/' . $classname . '.php';
		
		if ( file_exists( $class_file ) )
		{
			throw new \ErrorException( "The class file already exists: " . str_replace( WP_PLUGIN_DIR, '', $class_file ) );
		}
		
		$class_contents = <<<CLASS
<?php
/**
 * Plugin Class File
 *
 * @vendor:  {vendor_name}
 * @package: {plugin_name}
 * @author:  {plugin_author}
 * @link:    {plugin_author_url}
 * @since:   {date_time}
 */
namespace $namespace;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Access denied.' );
}

/**
 * $classname Class
 */
class $classname
{
	/**
	 * @var 	\MillerMedia\Boilerplate\Plugin		Provides access to the plugin instance
	 */
	protected $plugin;
	 
	/**
	 * Constructor
	 *
	 * @return	void
	 */
	public function __construct()
	{
		$this->plugin = \MillerMedia\Boilerplate\Plugin::instance();
	}
}

CLASS;
		file_put_contents( $class_file, $this->replaceMetaContents( $class_contents, $plugin_data ) );
	
	}

	/**
	 * Replace meta contents
	 *
	 * @param	string		$source		The source code to replace meta contents in
	 * @param	array		$data		Plugin meta data
	 * @return	string
	 */
	public function replaceMetaContents( $source, $data )
	{
		$data = array_merge( array( 
			'name' => '',
			'description' => '',
			'namespace' => '',
			'slug' => '',
			'vendor' => '',
			'author' => '',
			'author_url' => '',
			'date' => date( 'F j, Y' ),
			), $data );
			
		return strtr( $source, array
		( 
			'b7f88d4569eea7ab0b52f6a8c0e0e90c'  => md5( $data[ 'slug' ] ),
			'MillerMedia\Boilerplate'           => $data[ 'namespace' ],
			'MillerMedia\\\Boilerplate'         => str_replace( '\\', '\\\\', $data[ 'namespace' ] ),
			'millermedia/boilerplate'           => strtolower( str_replace( '\\', '/', $data[ 'namespace' ] ) ),
			'BoilerplatePlugin'                 => str_replace( '\\', '', $data[ 'namespace'] ) . 'Plugin',
			'{vendor_name}'                     => $data[ 'vendor' ],
			'{plugin_name}'                     => $data[ 'name' ],
			'{plugin_description}'              => $data[ 'description' ],
			'{plugin_dir}'                      => $data[ 'slug' ],
			'{plugin_author}'                   => $data[ 'author' ],
			'{plugin_author_url}'               => $data[ 'author_url' ],
			'{date_time}'                       => $data[ 'date' ],						
		) );
	}
	
}