<?php
/*
 * This file is part of Rocketeer
 *
 * (c) Maxime Fabre <ehtnam6@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Rocketeer\Traits\BashModules;

/**
 * Handles finding and calling binaries
 *
 * @author Maxime Fabre <ehtnam6@gmail.com>
 */
trait Binaries
{
	////////////////////////////////////////////////////////////////////
	/////////////////////////////// BINARIES ///////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Prefix a command with the right path to PHP
	 *
	 * @param string|null $command
	 *
	 * @return string
	 */
	public function php($command = null)
	{
		$php = $this->which('php');

		return trim($php.' '.$command);
	}

	// Artisan
	////////////////////////////////////////////////////////////////////

	/**
	 * Prefix a command with the right path to Artisan
	 *
	 * @param string|null $command
	 * @param array       $flags
	 *
	 * @return string
	 */
	public function artisan($command = null, $flags = array())
	{
		$artisan = $this->which('artisan', $this->releasesManager->getCurrentReleasePath().'/artisan') ?: 'artisan';
		foreach ($flags as $name => $value) {
			$command .= ' --'.$name;
			$command .= $value ? '="'.$value.'"' : '';
		}

		return $this->php($artisan.' '.$command);
	}

	/**
	 * Run an artisan command
	 *
	 * @param string|null $command
	 * @param array       $flags
	 *
	 * @return string
	 */
	public function runArtisan($command = null, $flags = array())
	{
		// Check if the seeds/migration need to be forced
		$forced = array('migrate', 'db:seed');
		if (in_array($command, $forced) && $this->versionCheck('4.2.0')) {
			$flags['force'] = '';
		}

		// Create full command
		$command = $this->artisan($command, $flags);

		return $this->runForCurrentRelease($command);
	}

	/**
	 * Run any outstanding migrations
	 *
	 * @param boolean $seed Whether the database should also be seeded
	 *
	 * @return string
	 */
	public function runMigrations($seed = false)
	{
		$this->command->comment('Running outstanding migrations');
		$flags = $seed ? array('seed' => '') : array();

		return $this->runArtisan('migrate', $flags);
	}

	/**
	 * Seed the database
	 *
	 * @param string|null $class A class to seed
	 *
	 * @return string
	 */
	public function runSeed($class = null)
	{
		$this->command->comment('Seeding database');
		$flags = $class ? array('class' => $class) : array();

		return $this->runArtisan('db:seed', $flags);
	}

	// PHPUnit
	////////////////////////////////////////////////////////////////////

	/**
	 * Run the application's tests
	 *
	 * @param string|null $arguments Additional arguments to pass to PHPUnit
	 *
	 * @return boolean
	 */
	public function runTests($arguments = null)
	{
		// Look for PHPUnit
		$phpunit = $this->which('phpunit', $this->releasesManager->getCurrentReleasePath().'/vendor/bin/phpunit');
		if (!$phpunit) {
			return true;
		}

		// Run PHPUnit
		$this->command->info('Running tests...');
		$output = $this->runForCurrentRelease(array(
			$phpunit.' --stop-on-failure '.$arguments,
		));

		return $this->checkStatus('Tests failed', $output, 'Tests passed successfully');
	}

	// Composer
	////////////////////////////////////////////////////////////////////

	/**
	 * Prefix a command with the right path to Composer
	 *
	 * @param string|null $command
	 *
	 * @return string
	 */
	public function composer($command = null)
	{
		$composer = $this->which('composer', $this->releasesManager->getCurrentReleasePath().'/composer.phar');

		// Prepend PHP command
		if (strpos($composer, 'composer.phar') !== false) {
			$composer = $this->php($composer);
		}

		return trim($composer.' '.$command);
	}

	/**
	 * Run Composer on the folder
	 *
	 * @param boolean $force
	 *
	 * @return string
	 */
	public function runComposer($force = false)
	{
		if (!$this->localStorage->usesComposer() and !$force) {
			return true;
		}

		// Find Composer
		$composer = $this->composer();
		if (!$composer) {
			return true;
		}

		// Get the Composer commands to run
		$tasks = $this->rocketeer->getOption('remote.composer');
		if (!is_callable($tasks)) {
			return true;
		}

		// Cancel if no tasks to execute
		$tasks = (array) $tasks($this);
		if (empty($tasks)) {
			return true;
		}

		// Run commands
		$this->command->info('Installing Composer dependencies');
		$this->runForCurrentRelease($tasks);

		return $this->checkStatus('Composer could not install dependencies');
	}

	////////////////////////////////////////////////////////////////////
	/////////////////////////////// HELPERS ////////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Get a binary
	 *
	 * @param  string      $binary   The name of the binary
	 * @param  string|null $fallback A fallback location
	 *
	 * @return string
	 */
	public function which($binary, $fallback = null)
	{
		$location  = false;
		$locations = array(
			array($this->localStorage, 'get', 'paths.'.$binary),
			array($this->rocketeer, 'getPath', $binary),
			array($this, 'runSilently', 'which '.$binary),
		);

		// Add fallback if provided
		if ($fallback) {
			$locations[] = array($this, 'runSilently', 'which '.$fallback);
		}

		// Add command prompt if possible
		if ($this->hasCommand()) {
			$prompt      = $binary.' could not be found, please enter the path to it';
			$locations[] = array($this->command, 'ask', $prompt);
		}

		// Look in all the locations
		$tryout = 0;
		while (!$location and array_key_exists($tryout, $locations)) {
			list($object, $method, $argument) = $locations[$tryout];

			$location = $object->$method($argument);
			$tryout++;
		}

		// Store found location
		$this->localStorage->set('paths.'.$binary, $location);

		return $location ?: false;
	}

	/**
	 * Check the Laravel version
	 *
	 * @param  string $version  The version to check against
	 * @param  string $operator The operator (default: '>=')
	 *
	 * @return bool
	 */
	protected function versionCheck($version, $operator = '>=')
	{
		$app = $this->app;
		if (is_a($app, 'Illuminate\Foundation\Application')) {
			return version_compare($app::VERSION, $version, $operator);
		}

		return false;
	}
}
