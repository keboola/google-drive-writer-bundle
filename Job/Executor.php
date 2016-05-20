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
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\StorageApi\TableExporter;
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

            try {
                $this->configuration->create();
            } catch (\Exception $e) {
                // create configuration if not exists
            }

            $account = new Account($this->configuration, uniqid('external'));
            $account->fromArray($options['external']['account']);

            $writer = $this->writerFactory->create($account);

            if (!isset($options['external']['query'])) {
                throw new UserException("Missing field 'query'");
            }

            $queryString = $options['external']['query'];
            $queryString .= ' -tags:wr-google-drive-processed';

            if (isset($options['external']['filterByRunId']) && $options['external']['filterByRunId']) {
                $parentRunId = $this->getParentRunId($job->getRunId());
                if ($parentRunId) {
                    $queryString .= ' +tags:runId-' . $parentRunId;
                }
            }

            $uploadedFiles = $this->storageApi->listFiles((new ListFilesOptions())->setQuery($queryString));

            if (empty($uploadedFiles)) {
                throw new UserException("No file matches your query '" . $queryString . "'");
            }

            foreach ($uploadedFiles as $uploadedFile) {

                $tmpFile = $this->temp->createTmpFile('wr-gd');
                file_put_contents($tmpFile->getPathname(), fopen($uploadedFile['url'], 'r'));

                $file = new File([
                    'id' => $uploadedFile['id'],
                    'title' => $uploadedFile['name'],
                    'targetFolder' => isset($options['external']['targetFolder'])?$options['external']['targetFolder']:null,
                    'type' => File::TYPE_FILE,
                    'pathname' => $tmpFile,
                    'size' => $uploadedFile['sizeBytes']
                ]);

                $gdFiles = $writer->listFiles(
                    ['q' => "trashed=false and name='" . $uploadedFile['name'] . "'"]
                );

                if (!empty($gdFiles['files'])) {
                    $lastGdFile = array_shift($gdFiles['files']);
                    $file->setGoogleId($lastGdFile['id']);
                }

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

                    try {
                        $tableExporter = new TableExporter($this->storageApi);
                        $tableExporter->exportTable($file->getTableId(), $file->getPathname(), []);
                    } catch (ClientException $e) {
                        throw new UserException($e->getMessage(), $e, [
                            'file' => $file->toArray()
                        ]);
                    }

                    $file = $writer->process($file);
                    $status[$account->getAccountName()][$file->getTitle()] = 'ok';
                }

                // updated changes to files
                $account->save();
            }
        }

		return $status;
	}

    protected function getParentRunId($runId)
    {
        $runIdArr = explode('.', $runId);

        if (count($runIdArr) > 1) {
            return $runIdArr[0];
        }
        return null;
    }

}
