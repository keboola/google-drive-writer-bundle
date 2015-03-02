<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 26/02/15
 * Time: 14:59
 */

namespace Keboola\Google\DriveWriterBundle\Writer\Processor;

use GuzzleHttp\Exception\RequestException;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Symfony\Component\DomCrawler\Crawler;
use Syrup\ComponentBundle\Exception\UserException;

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
                    $response = $this->googleDriveApi->updateCells($file);

                    $this->logger->debug("Worksheet cells updated", [
                        'file' => $file
                    ]);

                    // crawl through response - disabled - was too slow
//                    $crawler = new Crawler($response->getBody()->getContents());
//
//                    /** @var \DOMElement $entry */
//                    foreach ($crawler->filter('default|entry batch|status') as $entry) {
//                        if ('Success' != $entry->getAttribute('reason')) {
//                            throw new UserException("Update failed: " . $entry->getAttribute('reason'));
//                        }
//                    }

                } catch (RequestException $e) {
                    throw new UserException("Update failed: " . $e->getMessage(), $e, [
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
