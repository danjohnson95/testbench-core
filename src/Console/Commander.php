<?php

namespace Orchestra\Testbench\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Support\Arr;
use function Orchestra\Testbench\default_environment_variables;
use Orchestra\Testbench\Foundation\Application;
use Orchestra\Testbench\Foundation\Bootstrap\LoadEnvironmentVariablesFromArray;
use Orchestra\Testbench\Foundation\Bootstrap\LoadMigrationsFromArray;
use Orchestra\Testbench\Foundation\TestbenchServiceProvider;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * @internal
 *
 * @phpstan-type TConfig array{laravel: string|null, env: array|null, providers: array|null, dont-discover: array|null, migrations: array|bool|null}
 */
class Commander
{
    /**
     * Application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * List of configurations.
     *
     * @var TConfig
     */
    protected $config = [
        'laravel' => null,
        'env' => [],
        'providers' => [],
        'dont-discover' => [],
        'migrations' => [],
    ];

    /**
     * Working path.
     *
     * @var string
     */
    protected $workingPath;

    /**
     * The environment file name.
     *
     * @var string
     */
    protected $environmentFile = '.env';

    /**
     * Construct a new Commander.
     *
     * @param  TConfig  $config
     * @param  string  $workingPath
     */
    public function __construct(array $config, string $workingPath)
    {
        $this->config = $config;
        $this->workingPath = $workingPath;
    }

    /**
     * Handle the command.
     *
     * @return void
     */
    public function handle()
    {
        $input = new ArgvInput();
        $output = new ConsoleOutput();

        try {
            $laravel = $this->laravel();
            $kernel = $laravel->make(ConsoleKernel::class);

            $status = $kernel->handle($input, $output);

            $kernel->terminate($input, $status);
        } catch (Throwable $error) {
            $status = $this->handleException($output, $error);
        }

        exit($status);
    }

    /**
     * Create Laravel application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function laravel()
    {
        if (! $this->app instanceof LaravelApplication) {
            $laravelBasePath = $this->getBasePath();

            Application::createVendorSymlink($laravelBasePath, $this->workingPath.'/vendor');

            $hasEnvironmentFile = file_exists("{$laravelBasePath}/.env");

            $options = array_filter([
                'load_environment_variables' => $hasEnvironmentFile,
                'extra' => [
                    'providers' => Arr::get($this->config, 'providers', []),
                    'dont-discover' => Arr::get($this->config, 'dont-discover', []),
                ],
            ]);

            $this->app = Application::create(
                $this->getBasePath(),
                function ($app) use ($hasEnvironmentFile) {
                    if ($hasEnvironmentFile === false) {
                        (new LoadEnvironmentVariablesFromArray(
                            ! empty($this->config['env']) ? $this->config['env'] : default_environment_variables()
                        ))->bootstrap($app);
                    }

                    \call_user_func($this->resolveApplicationCallback(), $app);
                },
                $options
            );
        }

        return $this->app;
    }

    /**
     * Resolve application implementation.
     *
     * @return \Closure
     */
    protected function resolveApplicationCallback()
    {
        return function ($app) {
            $app->register(TestbenchServiceProvider::class);

            $migrations = \is_array($this->config['migrations']) ? $this->config['migrations'] : [];

            if ($migrations !== false || \is_array($migrations)) {
                (new LoadMigrationsFromArray($migrations))->bootstrap($app);
            }
        };
    }

    /**
     * Get base path.
     *
     * @return string
     */
    protected function getBasePath()
    {
        $laravelBasePath = $this->config['laravel'] ?? null;

        if (! \is_null($laravelBasePath)) {
            return tap(str_replace('./', $this->workingPath.'/', $laravelBasePath), static function ($path) {
                $_ENV['APP_BASE_PATH'] = $path;
            });
        }

        return static::applicationBasePath();
    }

    /**
     * Get Application base path.
     *
     * @return string
     */
    public static function applicationBasePath()
    {
        return Application::applicationBasePath();
    }

    /**
     * Render an exception to the console.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Throwable  $error
     * @return int
     */
    protected function handleException(OutputInterface $output, Throwable $error)
    {
        $laravel = $this->laravel();

        tap($laravel->make(ExceptionHandler::class), static function ($handler) use ($error, $output) {
            $handler->report($error);
            $handler->renderForConsole($output, $error);
        });

        return 1;
    }
}
