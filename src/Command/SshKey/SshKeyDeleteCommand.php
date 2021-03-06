<?php
namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SshKeyDeleteCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('ssh-key:delete')
            ->setDescription('Delete an SSH key')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'The ID of the SSH key to delete'
            );
        $this->addExample('Delete the key 123', '123');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getArgument('id');
        if (empty($id) || !is_numeric($id)) {
            $this->stdErr->writeln("<error>You must specify the ID of the SSH key to delete.</error>");
            $this->stdErr->writeln("List your SSH keys with: <info>" . self::$config->get('application.executable') . " ssh-keys</info>");

            return 1;
        }

        $key = $this->api->getClient()
                    ->getSshKey($id);
        if (!$key) {
            $this->stdErr->writeln("SSH key not found: <error>$id</error>");
        }

        $key->delete();

        $this->stdErr->writeln("The SSH key <info>$id</info> has been deleted from your " . self::$config->get('service.name') . " account.");

        return 0;
    }
}
