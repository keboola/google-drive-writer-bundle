<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 26/02/15
 * Time: 14:59
 */

namespace Keboola\Google\DriveWriterBundle\Writer\Processor;

use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Google\DriveWriterBundle\GoogleDrive\RestApi as GoogleDriveApi;
use Monolog\Logger;

class CommonProcessor implements ProcessorInterface
{
    /** @var GoogleDriveApi */
    protected $googleDriveApi;

    protected $logger;

    public function __construct(GoogleDriveApi $googleDriveApi, Logger $logger)
    {
        $this->googleDriveApi = $googleDriveApi;
        $this->logger = $logger;
    }

    public function process(File $file)
    {
        // TODO: Implement process() method.
    }
}
