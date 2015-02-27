<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 26/02/15
 * Time: 15:00
 */

namespace Keboola\Google\DriveWriterBundle\Writer\Processor;

use Keboola\Google\DriveWriterBundle\Entity\File;

interface ProcessorInterface
{
    public function process(File $file);
}
