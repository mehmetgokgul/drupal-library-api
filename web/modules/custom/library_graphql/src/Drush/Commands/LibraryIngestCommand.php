<?php

declare(strict_types=1);

namespace Drupal\library_graphql\Drush\Commands;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

#[AsCommand(
    name: self::NAME,
    description: 'Ingests books from the library API into Drupal nodes.',
)]
final class LibraryIngestCommand extends Command {

    use AutowireTrait;
    public const NAME = 'library:ingest';

    public function __construct(
        private readonly ModuleExtensionList $moduleExtensionList,
        private readonly QueueFactory $queueFactory,
        private readonly QueueWorkerManagerInterface $queueWorkerManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setDescription('Ingests books from the library API into Drupal nodes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        
        $modulePath = $this->moduleExtensionList->getPath('library_graphql') . '/data/mocky-kitap-dummy-data.json';
        $jsonData = file_get_contents($modulePath);
        $decodedData = json_decode($jsonData, true);

        foreach ($decodedData['books'] as $book) {
            $queue = $this->queueFactory->get('book_ingest');
            $queue->createItem($book);
        }

       foreach ($decodedData['movies'] as $movie) {
            $queue = $this->queueFactory->get('movie_ingest');
            $queue->createItem($movie);
        }

        $queue = $this->queueFactory->get('book_ingest');
        while ($item = $queue->claimItem()) {
            if ($item) {
                $queueWorker = $this->queueWorkerManager->createInstance('book_ingest');
                $queueWorker->processItem($item->data);
                $queue->deleteItem($item);
            } else {
                break;
            }
        }

        $queue = $this->queueFactory->get('movie_ingest');
        while ($item = $queue->claimItem()) {
            if ($item) {
                $queueWorker = $this->queueWorkerManager->createInstance('movie_ingest');
                $queueWorker->processItem($item->data);
                $queue->deleteItem($item);
            } else {
                break;
            }
        }
        return Command::SUCCESS;
        

    }
}

