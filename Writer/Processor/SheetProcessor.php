<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 26/02/15
 * Time: 14:59
 */

namespace Keboola\Google\DriveWriterBundle\Writer\Processor;

use GuzzleHttp\Exception\RequestException;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Syrup\Exception\UserException;
use Symfony\Component\DomCrawler\Crawler;

class SheetProcessor extends CommonProcessor
{
    public function process(File $file)
    {
        if (null == $file->getGoogleId() || $file->isOperationCreate()) {

            // create new file
            $fileRes = $this->googleDriveApi->insertFile($file);

            // get list of worksheets in file, there shall be only one
            $sheets = $this->googleDriveApi->getWorksheets($fileRes['id']);
            $sheet = array_shift($sheets);

            // update file
            $file->setGoogleId($fileRes['id']);
            $file->setSheetId($sheet['wsid']);

            $this->logger->info("Sheet created", [
                'file' => $file->toArray()
            ]);

        } else if ($file->isOperationUpdate()) {

            if (null == $file->getSheetId()) {
                // create new sheet in existing file
                $response = $this->googleDriveApi->createWorksheet($file);

                $crawler = new Crawler($response->getBody()->getContents());
                $uriArr = explode('/', $crawler->filter('default|id')->text());
                $file->setSheetId(array_pop($uriArr));

                $this->logger->info("Sheet created in existing file", [
                    'file' => $file->toArray()
                ]);
            } else {
                // update content of existing file

                try {
                    // update metadata first - cols and rows count
                    $this->googleDriveApi->updateWorksheet($file);

                    $this->logger->debug("Worksheet metadata updated", [
                        'file' => $file->toArray()
                    ]);

                    // update cells content
                    $timestart = microtime(true);
                    $status = $this->googleDriveApi->updateCells($file);
                    $timeend = microtime(true);
                    $apiCallDuration = $timeend - $timestart;

                    if (count($status['errors'])) {
                        $this->logger->warning("Some cells might not be imported properly", [
                            'errors' => $status['errors']
                        ]);
                    }

                    $this->logger->debug("Cells updated", [
                        'file' => $file->toArray(),
                        'apiCallDuration' => $apiCallDuration,
                        'status' => $status
                    ]);

                } catch (RequestException $e) {
                    $statusCode = $e->getResponse()->getStatusCode();
                    if ($statusCode >= 500 && $statusCode < 600) {
                        throw new UserException(
                            sprintf(
                                "Google Drive Server Error: %s - %s. Please try again later.",
                                $statusCode,
                                $e->getResponse()->getReasonPhrase()
                            ),
                            $e,
                            ['file' => $file->toArray()]
                        );
                    }
                    throw new UserException("Cells update failed: " . $e->getMessage(), $e, [
                        'file' => $file->toArray()
                    ]);
                }

                $this->logger->info("Sheet updated", [
                    'file' => $file->toArray()
                ]);
            }
        } else {

            // @TODO: append sheet
        }

        return $file;
    }
}
