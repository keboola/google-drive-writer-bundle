<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 29/01/15
 * Time: 16:31
 */

namespace Keboola\Google\DriveWriterBundle\Job;


use Keboola\Google\DriveWriterBundle\Writer\Configuration;
use Keboola\Google\DriveWriterBundle\Writer\Writer;
use Syrup\ComponentBundle\Job\Executor as BaseExecutor;
use Syrup\ComponentBundle\Job\Metadata\Job;

class Executor extends BaseExecutor
{
	/** @var Writer */
	protected $writer;

	/** @var Configuration */
	protected $configuration;

	public function __construct($writer, $configuration)
	{
		$this->writer = $writer;
		$this->configuration = $configuration;
	}

	public function execute(Job $job)
	{
		$this->configuration->setStorageApi($this->storageApi);
		$this->writer->setConfiguration($this->configuration);

		$this->writer->uploadFiles($job->getParams());
	}

}
