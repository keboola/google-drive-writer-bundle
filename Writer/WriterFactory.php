<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 26/02/15
 * Time: 15:09
 */

namespace Keboola\Google\DriveWriterBundle\Writer;

use Keboola\Google\DriveWriterBundle\Entity\Account;
use Keboola\Google\DriveWriterBundle\GoogleDrive\RestApi;
use Monolog\Logger;

class WriterFactory
{
    /** @var RestApi */
    protected $googleDriveApi;

    /** @var Logger */
    protected $logger;

    /**
     * @param RestApi $googleDriveApi
     * @param Logger  $logger
     */
    public function __construct(RestApi $googleDriveApi, Logger $logger)
    {
        $this->googleDriveApi = $googleDriveApi;
        $this->logger = $logger;
    }

    /**
     * @param Account $account
     * @return Writer
     */
    public function create(Account $account)
    {
        return new Writer($this->googleDriveApi, $this->logger, $account);
    }

}
