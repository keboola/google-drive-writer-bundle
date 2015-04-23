<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 14/01/14
 * Time: 12:31
 */

namespace Keboola\Google\DriveWriterBundle\Exception;

use Keboola\Syrup\Exception\UserException;

class ConfigurationException extends UserException
{
	public function __construct($message)
	{
		parent::__construct("Wrong configuration: " . $message);
	}
}
