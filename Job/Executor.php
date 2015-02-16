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
use Keboola\Google\DriveWriterBundle\Writer\Writer;
use Keboola\Temp\Temp;
use Syrup\ComponentBundle\Exception\UserException;
use Syrup\ComponentBundle\Job\Executor as BaseExecutor;
use Syrup\ComponentBundle\Job\Metadata\Job;

class Executor extends BaseExecutor
{
	/** @var Writer */
	protected $writer;

	/** @var Configuration */
	protected $configuration;

	/** @var Temp */
	protected $temp;

	public function __construct(Writer $writer, Configuration $configuration, Temp $temp)
	{
		$this->writer = $writer;
		$this->configuration = $configuration;
		$this->temp = $temp;
	}

	public function execute(Job $job)
	{
		$this->configuration->setStorageApi($this->storageApi);

		$accounts = $this->configuration->getAccounts();

		$options = $job->getParams();

		if (isset($options['config'])) {
			if (!isset($accounts[$options['config']])) {
				throw new UserException("Config '" . $options['config'] . "' does not exist.");
			}
			$accounts = [
				$options['config'] => $accounts[$options['config']]
			];
		}

		$status = [];

		/** @var Account $account */
		foreach ($accounts as $accountId => $account) {

			$this->writer->initApi($account);

			$files = $account->getFiles();

			/** @var File $file */
			foreach ($files as $file) {

				$file->setPathname($this->temp->createTmpFile()->getPathname());
				$this->storageApi->exportTable($file->getTableId(), $file->getPathname());

				if ($file->getType() == File::TYPE_FILE) {
					$this->writer->processFile($file);
				} else {
					$this->writer->processSheet($file);
				}

				$status[$account->getAccountName()][$file->getTitle()] = 'ok';
			}

			// updated changes to files
			$account->save();
		}

		return $status;
	}

}
