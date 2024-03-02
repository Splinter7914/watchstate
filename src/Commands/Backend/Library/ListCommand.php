<?php

declare(strict_types=1);

namespace App\Commands\Backend\Library;

use App\Command;
use App\Libs\Attributes\Route\Cli;
use App\Libs\Config;
use App\Libs\Options;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ListCommand
 *
 * This command list the backend libraries. This help you to know which library are supported.
 */
#[Cli(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const ROUTE = 'backend:library:list';

    /**
     * Configures the command.
     */
    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Get Backend libraries list.')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Use Alternative config file.')
            ->addArgument('backend', InputArgument::REQUIRED, 'Backend name.')
            ->setHelp(
                <<<HELP

                This command list the backend libraries. This help you to know which library are supported.
                the <notice>Id</notice> column refers to backend <notice>library id</notice>.

                HELP
            );
    }

    /**
     * Executes the command.
     *
     * @param InputInterface $input The input instance.
     * @param OutputInterface $output The output instance.
     *
     * @return int The command exit code.
     */
    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $mode = $input->getOption('output');
        $backend = $input->getArgument('backend');

        // -- Use Custom servers.yaml file.
        if (($config = $input->getOption('config'))) {
            try {
                Config::save('servers', Yaml::parseFile($this->checkCustomBackendsFile($config)));
            } catch (\App\Libs\Exceptions\RuntimeException $e) {
                $output->writeln(r('<error>{message}</error>', ['message' => $e->getMessage()]));
                return self::FAILURE;
            }
        }

        if (null === ag(Config::get('servers', []), $backend, null)) {
            $output->writeln(r("<error>ERROR: Backend '{backend}' not found.</error>", ['backend' => $backend]));
            return self::FAILURE;
        }

        $opts = $backendOpts = [];

        if ($input->getOption('include-raw-response')) {
            $opts[Options::RAW_RESPONSE] = true;
        }

        if ($input->getOption('trace')) {
            $backendOpts = ag_set($backendOpts, 'options.' . Options::DEBUG_TRACE, true);
        }

        $libraries = $this->getBackend($backend, $backendOpts)->listLibraries(opts: $opts);

        if (count($libraries) < 1) {
            $arr = [
                'info' => sprintf('%s: No libraries were found.', $backend),
            ];
            $this->displayContent('table' === $mode ? [$arr] : $arr, $output, $mode);
            return self::FAILURE;
        }

        if ('table' === $mode) {
            $list = [];

            foreach ($libraries as $item) {
                foreach ($item as $key => $val) {
                    if (false === is_bool($val)) {
                        continue;
                    }
                    $item[$key] = $val ? 'Yes' : 'No';
                }
                $list[] = $item;
            }

            $libraries = $list;
        }

        $this->displayContent($libraries, $output, $mode);

        return self::SUCCESS;
    }
}
