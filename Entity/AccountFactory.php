<?php
/**
 * TableFactory.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 27.6.13
 */

namespace Keboola\Google\DriveWriterBundle\Entity;

use Keboola\Google\DriveWriterBundle\Writer\Configuration;

class AccountFactory
{
	/** @var Configuration  */
	protected $configuration;

	public function __construct(Configuration $configuration)
	{
		$this->configuration = $configuration;
	}

	public function get($accountId)
	{
		return new Account($this->configuration, $accountId);
	}

}
