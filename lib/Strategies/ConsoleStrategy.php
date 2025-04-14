<?php

namespace PHPNomad\Integrations\WordPress\Strategies;

use PHPNomad\Console\Exceptions\ConsoleException;
use PHPNomad\Console\Interfaces\Command;
use PHPNomad\Console\Interfaces\ConsoleStrategy as CoreConsoleStrategy;
use PHPNomad\Console\Interfaces\HasMiddleware;
use PHPNomad\Console\Interfaces\HasInterceptors;
use PHPNomad\Integrations\WordPress\Console\Input;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\Utils\Helpers\Arr;
use PHPNomad\Utils\Helpers\Str;
use WP_CLI;

class ConsoleStrategy implements CoreConsoleStrategy
{
    protected LoggerStrategy $logger;

    public function __construct(LoggerStrategy $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Registers a CLI command with WP-CLI based on the parsed signature.
     *
     * @param callable(): Command $commandGetter
     */
    public function registerCommand(callable $commandGetter): void
    {
        if (!defined('WP_CLI') || !WP_CLI) return;

        add_action('plugins_loaded', function() use ($commandGetter) {

            /** @var Command $command */
            $command = $commandGetter();
            $parsed = $this->parseSignature($command->getSignature());

            WP_CLI::add_command($parsed['name'], function ($args, $assoc_args) use ($command, $parsed) {
                $params = $this->resolveInputParams($args, $assoc_args, $parsed['definitions']);
                $input = new Input($params);

                try {
                    if ($command instanceof HasMiddleware) {
                        foreach ($command->getMiddleware($input) as $middleware) {
                            $middleware->process($input);
                        }
                    }

                    $exitCode = $command->handle($input);

                    if ($command instanceof HasInterceptors) {
                        foreach ($command->getInterceptors($input) as $interceptor) {
                            $interceptor->process($input, $exitCode);
                        }
                    }

                    WP_CLI::halt($exitCode);

                } catch (ConsoleException $e) {
                    $this->logger->logException($e);
                    WP_CLI::error($e->getMessage());
                }

            }, [
                'shortdesc' => $command->getDescription(),
                'synopsis' => Arr::pluck($parsed['definitions'], 'synopsis'),
            ]);
        });
    }

    /**
     * Resolves a flat param bag from WP-CLI $args and $assoc_args using the signature definitions.
     */
    protected function resolveInputParams(array $args, array $assocArgs, array $definitions): array
    {
        $params = [];

        foreach ($definitions as $index => $definition) {
            $name = $definition['name'];

            $params[$name] = $definition['isOption']
                ? Arr::get($assocArgs, $name, $definition['default'])
                : Arr::get($args, $index, $definition['default']);
        }

        return $params;
    }

    /**
     * Parses a PHPNomad signature string into WP-CLI-compatible structure.
     */
    private function parseSignature(string $signature): array
    {
        preg_match_all('/{([^}]+)}/', $signature, $matches);
        $rawParams = $matches[1];
        $commandName = trim(preg_replace('/{[^}]+}/', '', $signature));

        $definitions = Arr::process($rawParams)
            ->map(function (string $raw) {
                $isOption = Str::startsWith($raw, '--');
                $description = '';
                $default = null;
                $required = true;

                if (Str::contains($raw, ':')) {
                    [$raw, $description] = explode(':', $raw, 2);
                }

                if ($isOption) {
                    $name = Str::trimLeading($raw, '-');

                    if (Str::contains($name, '=')) {
                        [$name, $default] = explode('=', $name, 2);
                        $required = $default === '';
                    }

                    return [
                        'name' => $name,
                        'isOption' => true,
                        'required' => $required,
                        'default' => $default,
                        'description' => $description,
                        'synopsis' => [
                            'type' => $default === null ? 'flag' : 'assoc',
                            'name' => $name,
                            'optional' => !$required,
                            'default' => $default,
                            'description' => $description,
                        ]
                    ];
                }

                $name = Str::trimTrailing($raw, '?');
                $optional = Str::endsWith($raw, '?');
                $required = !$optional;

                return [
                    'name' => $name,
                    'isOption' => false,
                    'required' => $required,
                    'default' => null,
                    'description' => $description,
                    'synopsis' => [
                        'type' => 'positional',
                        'name' => $name,
                        'optional' => !$required,
                        'description' => $description,
                    ]
                ];
            })
            ->toArray();

        return [
            'name' => $commandName,
            'definitions' => $definitions,
        ];
    }
}
