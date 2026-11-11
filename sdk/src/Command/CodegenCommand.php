<?php

namespace Dagger\Command;

use Dagger\Client\ModuleTarget;
use Dagger\Codegen\Codegen;
use Dagger\Codegen\Introspection\IntrospectionSchema;
use Dagger\Codegen\SchemaGenerator;
use Dagger\Connection;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('dagger:codegen')]
class CodegenCommand extends Command
{
    private const WRITE_DIR =
        __DIR__ . DIRECTORY_SEPARATOR .
        '..' .
        DIRECTORY_SEPARATOR .
        '..' .
        DIRECTORY_SEPARATOR .
        'generated';

    private Connection $daggerConnection;

    public function __construct()
    {
        parent::__construct();
        $this->daggerConnection = Connection::get();
    }

    public function configure(): void
    {
        $this->addOption(
            'schema-file',
            null,
            InputArgument::OPTIONAL,
            'Path to the schema json file',
            null
        );
        $this->addOption(
            'core',
            null,
            InputOption::VALUE_NONE,
            'Generate the types and fields no module contributes, the default',
        );
        $this->addOption(
            'client',
            null,
            InputOption::VALUE_REQUIRED,
            'Generate the types and fields the module contributes, into generated/Clients',
        );
        $this->addOption(
            'address',
            null,
            InputOption::VALUE_REQUIRED,
            'The address the client serves its module from, with --client',
        );
        $this->addOption(
            'pin',
            null,
            InputOption::VALUE_REQUIRED,
            'The commit a Git address is pinned to, with --client',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $module = $input->getOption('client');
        if ($input->getOption('core') && $module !== null) {
            throw new InvalidArgumentException('--core and --client exclude each other');
        }
        $address = $input->getOption('address');
        if (($module === null) !== ($address === null) || ($module === null && $input->getOption('pin') !== null)) {
            throw new InvalidArgumentException('--client needs --address, and --address and --pin need --client');
        }
        $target = $address === null ? null : new ModuleTarget($address, $input->getOption('pin'));

        if ($input->getOption('schema-file') !== null && file_exists($input->getOption('schema-file'))) {
            $fileContents = file_get_contents($input->getOption('schema-file'));
            $schemaArray = json_decode($fileContents, true);
            $schema = IntrospectionSchema::fromArray($schemaArray);
        } else {
            $client = $this->daggerConnection->connect();
            $schema = (new SchemaGenerator($client))->getSchema();
        }

        $codegen = new Codegen($schema, self::WRITE_DIR, $io, $module, $target);
        $codegen->generate();

        return Command::SUCCESS;
    }
}
