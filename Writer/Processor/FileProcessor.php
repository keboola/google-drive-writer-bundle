<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 26/02/15
 * Time: 14:58
 */

namespace Keboola\Google\DriveWriterBundle\Writer\Processor;

use GuzzleHttp\Exception\BadResponseException;
use Keboola\Google\DriveWriterBundle\Entity\File;

class FileProcessor extends CommonProcessor
{
    public function process(File $file)
    {
        if (null == $file->getGoogleId() || $file->isOperationCreate()) {

            // create new file
            $response = $this->googleDriveApi->insertFile($file);

            // update file with googleId
            $file->setGoogleId($response['id']);
            $this->logger->info("File created", [
                'file' => $file->toArray(),
                'response' => $response
            ]);
        } else {
            // overwrite existing file
            try {
                $response = $this->googleDriveApi->updateFile($file);

                $this->logger->info("File updated", [
                    'file' => $file->toArray(),
                    'response' => $response
                ]);
            } catch (BadResponseException $e) {
                $statusCode = $e->getResponse()->getStatusCode();
                if ($statusCode == 404) {
                    // file not found - create new one and issue a warning
                    $response = $this->googleDriveApi->insertFile($file);
                    $file->setGoogleId($response['id']);
                    $this->logger->info("File not found, created new one", [
                        'file' => $file->toArray(),
                        'response' => $response
                    ]);
                } else {
                    throw $e;
                }
            }
        }

        return $file;
    }
}
