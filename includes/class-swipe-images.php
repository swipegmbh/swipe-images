<?php
/**
 * Core-Klasse: lädt Abhängigkeiten, entscheidet den Modus, registriert Hooks.
 *
 * @package Swipe_Images
 */

class Swipe_Images {

	protected Swipe_Images_Loader $loader;
	protected string $plugin_name = 'swipe-images';
	protected string $version     = SWIPE_IMAGES_VERSION;
	protected bool $blocked       = false;

	public function __construct() {
		$this->load_dependencies();
		$this->loader = new Swipe_Images_Loader();
	}

	private function load_dependencies(): void {
		require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-loader.php';
		require_once SWIPE_IMAGES_PATH . 'includes/class-swipe-images-i18n.php';
	}

	/**
	 * Registriert den Boot bei after_setup_theme, weil erst dann das Theme geladen ist.
	 */
	public function run(): void {
		add_action( 'after_setup_theme', array( $this, 'boot' ), 100 );
	}

	public function boot(): void {
		$i18n = new Swipe_Images_i18n();
		$this->loader->add_action( 'init', $i18n, 'load_plugin_textdomain' );
		$this->loader->run();
	}

	public function is_blocked(): bool {
		return $this->blocked;
	}

	public function get_plugin_name(): string {
		return $this->plugin_name;
	}

	public function get_version(): string {
		return $this->version;
	}

	public function get_loader(): Swipe_Images_Loader {
		return $this->loader;
	}
}
