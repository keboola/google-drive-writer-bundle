<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 29/01/15
 * Time: 16:31
 */

namespace Keboola\Google\DriveWriterBundle\Job;

use Keboola\Google\DriveWriterBundle\Entity\Account;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Google\DriveWriterBundle\Writer\Configuration;
use Keboola\Google\DriveWriterBundle\Writer\WriterFactory;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Temp\Temp;
use Monolog\Logger;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Job\Executor as BaseExecutor;
use Keboola\Syrup\Job\Metadata\Job;

class Executor extends BaseExecutor
{
	/** @var WriterFactory */
	protected $writerFactory;

	/** @var Configuration */
	protected $configuration;

	/** @var Temp */
	protected $temp;

    /** @var Logger */
    protected $logger;

	public function __construct(WriterFactory $writerFactory, Configuration $configuration, Temp $temp, Logger $logger)
	{
		$this->writerFactory = $writerFactory;
		$this->configuration = $configuration;
		$this->temp = $temp;
        $this->logger = $logger;
	}

	public function execute(Job $job)
	{
		$this->configuration->setStorageApi($this->storageApi);
		$accounts = $this->configuration->getAccounts();
		$options = $job->getParams();

        $status = [];

        if (isset($options['external'])) {
            // load files by tag from SAPI

            if (!isset($options['external']['account'])) {
                throw new UserException("Missing field 'account'");
            }

            $account = new Account($this->configuration, uniqid('external'));
            $account->fromArray($options['external']['account']);

            $writer = $this->writerFactory->create($account);

            if (!isset($options['external']['query'])) {
                throw new UserException("Missing field 'query'");
            }

            $queryString = $options['external']['query'];
            $queryString .= ' -tags:wr-google-drive-processed';

            $uploadedFiles = $this->storageApi->listFiles((new ListFilesOptions())->setQuery($queryString));

            if (empty($uploadedFiles)) {
                throw new UserException("No file matches your query '" . $queryString . "'");
            }

            foreach ($uploadedFiles as $uploadedFile) {

                $file = new File([
                    'id' => $uploadedFile['id'],
                    'title' => $uploadedFile['name'],
                    'targetFolder' => isset($options['targetFolder'])?$options['targetFolder']:null,
                    'type' => File::TYPE_FILE,
                    'pathname' => $uploadedFile['url'],
                    'size' => $uploadedFile['sizeBytes']
                ]);

                $gdFiles = $writer->listFiles(
                    ['q' => "trashed=false and title='" . $uploadedFile['name'] . "'"]
                );
                $lastGdFile = array_shift($gdFiles['items']);

                $file->setGoogleId($lastGdFile['id']);

                $file = $writer->process($file);

                // tag file 'wr-google-drive-processed'
                $this->storageApi->addFileTag($uploadedFile['id'], 'wr-google-drive-processed');
            }

        } else {
            $fileFilter = null;
            if (isset($options['config'])) {
                if (!isset($accounts[$options['config']])) {
                    throw new UserException("Config '" . $options['config'] . "' does not exist.");
                }
                $accounts = [
                    $options['config'] => $accounts[$options['config']]
                ];

                if (isset($options['file'])) {

                    /** @var Account $account */
                    $account = $accounts[$options['config']];
                    if (null == $account->getFile($options['file'])) {
                        throw new UserException("File '" . $options['file'] . "' not found");
                    }

                    $fileFilter = $options['file'];
                }
            }

            /** @var Account $account */
            foreach ($accounts as $accountId => $account) {

                $writer = $this->writerFactory->create($account);
                $files = $account->getFiles();

                /** @var File $file */
                foreach ($files as $file) {

                    if ($fileFilter != null && $file->getId() != $fileFilter) {
                        continue;
                    }

                    $file->setPathname($this->temp->createTmpFile()->getPathname());
                    $this->storageApi->exportTable($file->getTableId(), $file->getPathname());

                    $file = $writer->process($file);

                    $status[$account->getAccountName()][$file->getTitle()] = 'ok';
                }

                // updated changes to files
                $account->save();
            }
        }

		return $status;
	}

}
